<?php
namespace FFSP\Admin\Pages;

use FFSP\Admin\Admin;
use FFSP\Repository\LogRepository;

defined( 'ABSPATH' ) || exit;

class Logs extends Page {

	public function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$view = absint( $_GET['view'] ?? 0 );
		if ( $view ) {
			$this->detail( $view );
			return;
		}

		$args = array(
			'status'         => sanitize_key( $_GET['status'] ?? '' ),
			'form_id'        => absint( $_GET['form_id'] ?? 0 ),
			'integration_id' => absint( $_GET['integration_id'] ?? 0 ),
			'search'         => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			'page'           => max( 1, absint( $_GET['paged'] ?? 1 ) ),
			'per_page'       => 25,
		);
		// phpcs:enable

		$repo   = $this->plugin->get( 'logs' );
		$result = $repo->query( $args );
		$counts = $repo->counts();
		$forms  = $this->plugin->get( 'forms' )->forms();
		$ints   = $this->plugin->get( 'integrations' )->all();

		// Status filter links.
		echo '<ul class="subsubsub">';
		$links = array( '' => __( 'All', 'fluent-sharepoint-sync' ) . ' (' . array_sum( $counts ) . ')' );
		foreach ( LogRepository::STATUSES as $s ) {
			if ( $counts[ $s ] ) {
				$links[ $s ] = ucfirst( $s ) . ' (' . $counts[ $s ] . ')';
			}
		}
		$out = array();
		foreach ( $links as $s => $label ) {
			$out[] = '<li><a class="' . ( $args['status'] === $s ? 'current' : '' ) . '" href="' . esc_url( Admin::url( 'logs', $s ? array( 'status' => $s ) : array() ) ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo implode( ' | ', $out ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Filters.
		echo '<form method="get" class="ffsp-filters"><input type="hidden" name="page" value="ffsp"><input type="hidden" name="tab" value="logs">';
		if ( $args['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $args['status'] ) . '">';
		}
		echo '<select name="form_id"><option value="">' . esc_html__( 'All forms', 'fluent-sharepoint-sync' ) . '</option>';
		foreach ( $forms as $id => $t ) {
			printf( '<option value="%d" %s>%s</option>', (int) $id, selected( $args['form_id'], $id, false ), esc_html( $t ) );
		}
		echo '</select> <select name="integration_id"><option value="">' . esc_html__( 'All integrations', 'fluent-sharepoint-sync' ) . '</option>';
		foreach ( $ints as $i ) {
			printf( '<option value="%d" %s>%s</option>', (int) $i['id'], selected( $args['integration_id'], $i['id'], false ), esc_html( $i['name'] ) );
		}
		echo '</select> <input type="search" name="s" value="' . esc_attr( $args['search'] ) . '" placeholder="' . esc_attr__( 'Entry ID, error, item ID…', 'fluent-sharepoint-sync' ) . '"> <button class="button">' . esc_html__( 'Filter', 'fluent-sharepoint-sync' ) . '</button></form>';

		// Bulk form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ffsp_log_action' );
		echo '<input type="hidden" name="action" value="ffsp_log_action">';
		echo '<div class="tablenav top"><div class="alignleft actions"><select name="do"><option value="resend">' . esc_html__( 'Resend', 'fluent-sharepoint-sync' ) . '</option><option value="delete">' . esc_html__( 'Delete', 'fluent-sharepoint-sync' ) . '</option></select> <button class="button" data-confirm="1">' . esc_html__( 'Apply to selected', 'fluent-sharepoint-sync' ) . '</button></div>';
		$this->pagination( $result['total'], $args['page'], $args['per_page'] );
		echo '</div>';

		echo '<table class="widefat striped ffsp-table"><thead><tr><td class="check-column"><input type="checkbox" class="ffsp-check-all"></td>';
		foreach ( array( '#', __( 'Entry', 'fluent-sharepoint-sync' ), __( 'Form / Integration', 'fluent-sharepoint-sync' ), __( 'Status', 'fluent-sharepoint-sync' ), 'HTTP', __( 'Attempts', 'fluent-sharepoint-sync' ), __( 'SharePoint item', 'fluent-sharepoint-sync' ), __( 'Message', 'fluent-sharepoint-sync' ), __( 'Updated', 'fluent-sharepoint-sync' ), '' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( ! $result['items'] ) {
			echo '<tr><td colspan="11" class="ffsp-empty-row">' . esc_html__( 'No sync logs yet. Submit a form that has an active integration.', 'fluent-sharepoint-sync' ) . '</td></tr>';
		}
		$fg = $this->plugin->get( 'forms' );
		foreach ( $result['items'] as $l ) {
			$detail = Admin::url( 'logs', array( 'view' => $l['id'] ) );
			echo '<tr>';
			echo '<th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $l['id'] . '"></th>';
			echo '<td><a href="' . esc_url( $detail ) . '">' . (int) $l['id'] . '</a></td>';
			echo '<td><a href="' . esc_url( $fg->entry_admin_url( $l['form_id'], $l['submission_id'] ) ) . '">#' . (int) $l['submission_id'] . '</a></td>';
			echo '<td>' . esc_html( $forms[ $l['form_id'] ] ?? '#' . $l['form_id'] ) . '<br><small>' . esc_html( $l['integration_name'] ? $l['integration_name'] : '(deleted)' ) . '</small></td>';
			echo '<td>' . $this->badge( $l['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( 'retrying' === $l['status'] && $l['next_attempt_at'] ) {
				echo '<br><small>' . esc_html__( 'next:', 'fluent-sharepoint-sync' ) . ' ' . esc_html( wp_date( 'H:i', strtotime( $l['next_attempt_at'] . ' UTC' ) ) ) . '</small>';
			}
			echo '</td>';
			echo '<td>' . ( $l['http_code'] ? (int) $l['http_code'] : '—' ) . '</td>';
			echo '<td>' . (int) $l['attempt_count'] . '</td>';
			echo '<td>' . ( $l['remote_item_url'] ? '<a href="' . esc_url( $l['remote_item_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $l['remote_item_id'] ? $l['remote_item_id'] : 'open' ) . ' ↗</a>' : esc_html( $l['remote_item_id'] ? $l['remote_item_id'] : '—' ) ) . '</td>';
			echo '<td class="ffsp-msg">' . esc_html( mb_strimwidth( (string) $l['error_message'], 0, 120, '…' ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->time( $l['updated_at'] ) ) . '</td>';
			echo '<td class="ffsp-row-actions"><a href="' . esc_url( $detail ) . '">' . esc_html__( 'View', 'fluent-sharepoint-sync' ) . '</a>';
			if ( 'success' !== $l['status'] ) {
				echo ' · <a href="' . esc_url( $this->action_url( 'ffsp_log_action', array( 'do' => 'resend', 'id' => $l['id'] ) ) ) . '">' . esc_html__( 'Resend', 'fluent-sharepoint-sync' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></form>';
	}

	private function pagination( $total, $page, $per ) {
		$pages = (int) ceil( $total / $per );
		echo '<div class="tablenav-pages"><span class="displaying-num">' . (int) $total . ' ' . esc_html__( 'items', 'fluent-sharepoint-sync' ) . '</span>';
		if ( $pages > 1 ) {
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
		}
		echo '</div>';
	}

	private function detail( $id ) {
		$l = $this->plugin->get( 'logs' )->find( $id );
		echo '<p><a href="' . esc_url( Admin::url( 'logs' ) ) . '">← ' . esc_html__( 'All logs', 'fluent-sharepoint-sync' ) . '</a></p>';
		if ( ! $l ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Log not found.', 'fluent-sharepoint-sync' ) . '</p></div>';
			return;
		}
		$int = $this->plugin->get( 'integrations' )->find( $l['integration_id'] );

		echo '<div class="ffsp-grid"><div class="ffsp-col-main">';
		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Sync log', 'fluent-sharepoint-sync' ) . ' #' . (int) $l['id'] . ' ' . $this->badge( $l['status'] ) . '</h2><table class="ffsp-kv">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$rows = array(
			__( 'Entry', 'fluent-sharepoint-sync' )           => '<a href="' . esc_url( $this->plugin->get( 'forms' )->entry_admin_url( $l['form_id'], $l['submission_id'] ) ) . '">#' . (int) $l['submission_id'] . '</a>',
			__( 'Integration', 'fluent-sharepoint-sync' )     => $int ? '<a href="' . esc_url( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $int['id'] ) ) ) . '">' . esc_html( $int['name'] ) . '</a>' : '(deleted)',
			__( 'Request ID', 'fluent-sharepoint-sync' )      => '<code>' . esc_html( $l['request_id'] ) . '</code>',
			__( 'HTTP status', 'fluent-sharepoint-sync' )     => $l['http_code'] ? (int) $l['http_code'] : '—',
			__( 'Attempts', 'fluent-sharepoint-sync' )        => (int) $l['attempt_count'],
			__( 'Duration', 'fluent-sharepoint-sync' )        => (int) $l['duration_ms'] . ' ms',
			__( 'SharePoint item', 'fluent-sharepoint-sync' ) => $l['remote_item_url'] ? '<a target="_blank" rel="noopener" href="' . esc_url( $l['remote_item_url'] ) . '">' . esc_html( $l['remote_item_id'] ? $l['remote_item_id'] : $l['remote_item_url'] ) . ' ↗</a>' : esc_html( $l['remote_item_id'] ? $l['remote_item_id'] : '—' ),
			__( 'Next attempt', 'fluent-sharepoint-sync' )    => $l['next_attempt_at'] ? esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $l['next_attempt_at'] . ' UTC' ) ) ) : '—',
			__( 'Created', 'fluent-sharepoint-sync' )         => esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $l['created_at'] . ' UTC' ) ) ),
			__( 'Updated', 'fluent-sharepoint-sync' )         => esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $l['updated_at'] . ' UTC' ) ) ),
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . wp_kses_post( (string) $v ) . '</td></tr>';
		}
		echo '</table>';
		if ( $l['error_message'] ) {
			echo '<h3>' . esc_html__( 'Error', 'fluent-sharepoint-sync' ) . ' <code>' . esc_html( $l['error_code'] ) . '</code></h3><pre class="ffsp-pre ffsp-pre--error">' . esc_html( $l['error_message'] ) . '</pre>';
		}
		$files = json_decode( (string) $l['remote_files'], true );
		if ( $files ) {
			echo '<h3>' . esc_html__( 'Files in SharePoint', 'fluent-sharepoint-sync' ) . '</h3><ul>';
			foreach ( (array) $files as $f ) {
				if ( ! is_array( $f ) || ( isset( $f['url'] ) && ! is_string( $f['url'] ) ) || ( isset( $f['name'] ) && ! is_scalar( $f['name'] ) ) ) {
					continue;
				}
				echo '<li>' . ( ! empty( $f['url'] ) ? '<a target="_blank" rel="noopener" href="' . esc_url( $f['url'] ) . '">' . esc_html( $f['name'] ?? $f['url'] ) . '</a>' : esc_html( $f['name'] ?? '' ) ) . '</li>';
			}
			echo '</ul>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $this->action_url( 'ffsp_log_action', array( 'do' => 'resend', 'id' => $l['id'] ) ) ) . '">' . esc_html__( 'Resend now', 'fluent-sharepoint-sync' ) . '</a></p>';
		echo '</div></div><div class="ffsp-col-side">';
		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Response', 'fluent-sharepoint-sync' ) . '</h2><pre class="ffsp-pre">' . esc_html( $l['response_excerpt'] ? $l['response_excerpt'] : '—' ) . '</pre></div>';
		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Payload (masked)', 'fluent-sharepoint-sync' ) . '</h2>';
		if ( $l['payload'] ) {
			echo '<pre class="ffsp-pre">' . esc_html( $l['payload'] ) . '</pre>';
		} else {
			echo '<p class="ffsp-muted">' . esc_html__( 'Not stored. Enable "Store payload in logs" in Settings to keep a masked copy for debugging.', 'fluent-sharepoint-sync' ) . '</p>';
		}
		echo '</div></div></div>';
	}
}
