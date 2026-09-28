<?php
namespace FFSP\Repository;

use FFSP\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Sync logs: one row per (integration, submission). Re-sends update the same row
 * (attempt_count++), which also keeps delivery idempotent on the WordPress side.
 */
class LogRepository {

	const STATUSES = array( 'pending', 'queued', 'processing', 'success', 'failed', 'retrying', 'skipped' );

	private function table() {
		return Schema::logs_table();
	}

	public function find( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function find_by( $integration_id, $submission_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE integration_id = %d AND submission_id = %d", $integration_id, $submission_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get or create the log row for a (integration, submission) pair.
	 */
	public function ensure( $integration_id, $form_id, $submission_id ) {
		global $wpdb;
		$row = $this->find_by( $integration_id, $submission_id );
		if ( $row ) {
			return $row;
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			$this->table(),
			array(
				'integration_id' => (int) $integration_id,
				'form_id'        => (int) $form_id,
				'submission_id'  => (int) $submission_id,
				'status'         => 'pending',
				'request_id'     => wp_generate_uuid4(),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Atomically claim a row for sending. Only one worker can win; a "processing" row older
	 * than the lease is considered abandoned (worker died) and may be re-claimed.
	 *
	 * @param bool $manual Manual resend may also re-send a row that already succeeded.
	 * @return bool
	 */
	public function claim( $id, $manual = false, $lease = 300 ) {
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$stale = gmdate( 'Y-m-d H:i:s', time() - (int) $lease );
		$not   = $manual ? "'processing'" : "'processing','success'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'processing', updated_at = %s WHERE id = %d AND ( status NOT IN ({$not}) OR ( status = 'processing' AND updated_at < %s ) )", $now, $id, $stale ) );
		return 1 === (int) $n;
	}

	/**
	 * Reset a row for a manual resend unless a worker is sending it right now.
	 */
	public function reset_for_resend( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'queued', attempt_count = 0, next_attempt_at = NULL, updated_at = %s WHERE id = %d AND status <> 'processing'", current_time( 'mysql', true ), $id ) );
		return false !== $n && (int) $n > 0;
	}

	public function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( $this->table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Paged listing with filters (status, form_id, integration_id, search).
	 */
	public function query( array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'status'         => '',
				'form_id'        => 0,
				'integration_id' => 0,
				'search'         => '',
				'per_page'       => 20,
				'page'           => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();
		if ( $args['status'] && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'l.status = %s';
			$params[] = $args['status'];
		}
		if ( $args['form_id'] ) {
			$where[]  = 'l.form_id = %d';
			$params[] = (int) $args['form_id'];
		}
		if ( $args['integration_id'] ) {
			$where[]  = 'l.integration_id = %d';
			$params[] = (int) $args['integration_id'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(l.submission_id = %d OR l.error_message LIKE %s OR l.request_id LIKE %s OR l.remote_item_id LIKE %s)';
			$params[] = (int) $args['search'];
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$int_table = Schema::integrations_table();
		$where_sql = implode( ' AND ', $where );
		$limit     = max( 1, (int) $args['per_page'] );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $limit;

		$count_sql = "SELECT COUNT(*) FROM {$this->table()} l WHERE {$where_sql}";
		$list_sql  = "SELECT l.*, i.name AS integration_name FROM {$this->table()} l LEFT JOIN {$int_table} i ON i.id = l.integration_id WHERE {$where_sql} ORDER BY l.id DESC LIMIT {$limit} OFFSET {$offset}";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$items = $params ? $wpdb->get_results( $wpdb->prepare( $list_sql, $params ), ARRAY_A ) : $wpdb->get_results( $list_sql, ARRAY_A );
		// phpcs:enable

		return array(
			'items' => (array) $items,
			'total' => $total,
		);
	}

	public function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$this->table()} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	public function counts_since( $since_mysql ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) c FROM {$this->table()} WHERE updated_at >= %s GROUP BY status", $since_mysql ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	public function last_success( $integration_id = 0 ) {
		global $wpdb;
		if ( $integration_id ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT MAX(updated_at) FROM {$this->table()} WHERE status = 'success' AND integration_id = %d", $integration_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_var( "SELECT MAX(updated_at) FROM {$this->table()} WHERE status = 'success'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function ids_by_status( $status, $limit = 100 ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE status = %s ORDER BY id ASC LIMIT %d", $status, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( "DELETE FROM {$this->table()} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	}

	public function purge_older_than( $days ) {
		global $wpdb;
		if ( $days <= 0 ) {
			return 0;
		}
		$cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE created_at < %s AND status IN ('success','skipped','failed')", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function strip_payloads_older_than( $days ) {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET payload = NULL WHERE payload IS NOT NULL AND created_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
