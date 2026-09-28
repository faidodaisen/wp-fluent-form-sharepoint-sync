<?php
namespace FFSP\Repository;

use FFSP\Database\Schema;
use FFSP\Security\Security;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for integration profiles. Endpoint + secret are encrypted at rest and only
 * decrypted when explicitly asked for (`$with_secrets = true`).
 */
class IntegrationRepository {

	const STATUSES     = array( 'active', 'inactive' );
	const ENVIRONMENTS = array( 'development', 'staging', 'production' );
	const DESTINATIONS = array( 'list', 'library', 'both' );
	const AUTH_MODES   = array( 'shared_key', 'hmac' );

	/** @var Security */
	private $security;

	public function __construct( Security $security ) {
		$this->security = $security;
	}

	public static function default_options() {
		return array(
			'send_files'        => 1,
			'file_mode'         => 'signed_link', // signed_link | inline_base64.
			'inline_max_kb'     => 2048,          // Per file limit for inline mode.
			'include_meta'      => 1,             // Submission meta (ip, url, user agent...).
			'only_mapped'       => 1,             // Send only mapped fields (privacy by default).
			'retry_on_4xx'      => 0,
			'folder_template'   => '{form_title}/{submission_id}',
			'title_template'    => '{form_title} #{submission_id}',
		);
	}

	private function table() {
		return Schema::integrations_table();
	}

	/**
	 * Decode a DB row into a normalized array.
	 */
	private function hydrate( $row, $with_secrets = false ) {
		if ( ! $row ) {
			return null;
		}
		$row = (array) $row;

		$endpoint = $this->security->decrypt( $row['endpoint'] );
		$secret   = $this->security->decrypt( $row['secret'] );

		$item = array(
			'id'              => (int) $row['id'],
			'name'            => $row['name'],
			'slug'            => $row['slug'],
			'form_id'         => (int) $row['form_id'],
			'status'          => $row['status'],
			'environment'     => $row['environment'],
			'destination'     => $row['destination'],
			'auth_mode'       => $row['auth_mode'],
			'mapping'         => json_decode( (string) $row['mapping_json'], true ) ?: array(),
			'options'         => wp_parse_args( json_decode( (string) $row['options_json'], true ) ?: array(), self::default_options() ),
			'has_endpoint'    => '' !== $endpoint,
			'has_secret'      => '' !== $secret,
			'endpoint_masked' => $this->security->mask_url( $endpoint ),
			'secret_masked'   => $this->security->mask_secret( $secret ),
			'created_at'      => $row['created_at'],
			'updated_at'      => $row['updated_at'],
		);

		if ( $with_secrets ) {
			$item['endpoint'] = $endpoint;
			$item['secret']   = $secret;
		}
		return $item;
	}

	public function find( $id, $with_secrets = false ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->hydrate( $row, $with_secrets );
	}

	public function all() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Active integrations for a form (with secrets — used by the sync engine only).
	 */
	public function active_for_form( $form_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE form_id = %d AND status = 'active'", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[] = $this->hydrate( $row, true );
		}
		return $out;
	}

	/**
	 * Create or update. Empty endpoint/secret on update = keep the existing value.
	 *
	 * @return int|\WP_Error Integration id.
	 */
	public function save( array $data, $id = 0 ) {
		global $wpdb;

		$existing = $id ? $this->find( $id, true ) : null;
		if ( $id && ! $existing ) {
			return new \WP_Error( 'ffsp_not_found', __( 'Integration not found.', 'fluent-sharepoint-sync' ) );
		}

		$name = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			return new \WP_Error( 'ffsp_name_required', __( 'Please give the integration a name.', 'fluent-sharepoint-sync' ) );
		}

		$form_id = absint( $data['form_id'] ?? 0 );
		if ( ! $form_id ) {
			return new \WP_Error( 'ffsp_form_required', __( 'Please choose a Fluent Form.', 'fluent-sharepoint-sync' ) );
		}
		// Queued entries of the old form must never be delivered with another form's mapping.
		if ( $existing && (int) $existing['form_id'] !== $form_id ) {
			$pending = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::logs_table() . " WHERE integration_id = %d AND status IN ('pending','queued','processing','retrying')", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $pending ) {
				return new \WP_Error( 'ffsp_form_locked', __( 'This integration still has entries waiting to be sent. Let them finish (or delete those logs) before switching it to another form.', 'fluent-sharepoint-sync' ) );
			}
		}

		$endpoint = trim( (string) ( $data['endpoint'] ?? '' ) );
		if ( '' === $endpoint && $existing ) {
			$endpoint = $existing['endpoint'];
		}
		if ( '' !== $endpoint ) {
			$valid = $this->security->validate_endpoint( $endpoint );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$endpoint = esc_url_raw( $endpoint, array( 'https', 'http' ) );
		}

		$secret = trim( (string) ( $data['secret'] ?? '' ) );
		if ( '' === $secret && $existing ) {
			$secret = $existing['secret'];
		}
		if ( '' === $secret ) {
			$secret = $this->security->generate_secret();
		}

		$status = in_array( $data['status'] ?? '', self::STATUSES, true ) ? $data['status'] : 'inactive';
		if ( 'active' === $status && '' === $endpoint ) {
			return new \WP_Error( 'ffsp_endpoint_required', __( 'An endpoint URL is required before the integration can be active.', 'fluent-sharepoint-sync' ) );
		}

		$options = wp_parse_args( (array) ( $data['options'] ?? array() ), self::default_options() );
		$options = array(
			'send_files'      => empty( $options['send_files'] ) ? 0 : 1,
			'file_mode'       => in_array( $options['file_mode'], array( 'signed_link', 'inline_base64' ), true ) ? $options['file_mode'] : 'signed_link',
			'inline_max_kb'   => max( 64, min( 20480, (int) $options['inline_max_kb'] ) ),
			'include_meta'    => empty( $options['include_meta'] ) ? 0 : 1,
			'only_mapped'     => empty( $options['only_mapped'] ) ? 0 : 1,
			'retry_on_4xx'    => empty( $options['retry_on_4xx'] ) ? 0 : 1,
			'folder_template' => sanitize_text_field( $options['folder_template'] ),
			'title_template'  => sanitize_text_field( $options['title_template'] ),
		);

		$mapping = \FFSP\Mapping\FieldMapper::sanitize_mapping( (array) ( $data['mapping'] ?? array() ) );

		$now = current_time( 'mysql', true );
		$row = array(
			'name'         => $name,
			'slug'         => sanitize_title( $data['slug'] ?? $name ),
			'form_id'      => $form_id,
			'status'       => $status,
			'environment'  => in_array( $data['environment'] ?? '', self::ENVIRONMENTS, true ) ? $data['environment'] : 'development',
			'destination'  => in_array( $data['destination'] ?? '', self::DESTINATIONS, true ) ? $data['destination'] : 'list',
			'auth_mode'    => in_array( $data['auth_mode'] ?? '', self::AUTH_MODES, true ) ? $data['auth_mode'] : 'hmac',
			'endpoint'     => $this->security->encrypt( $endpoint ),
			'secret'       => $this->security->encrypt( $secret ),
			'mapping_json' => wp_json_encode( $mapping ),
			'options_json' => wp_json_encode( $options ),
			'updated_at'   => $now,
		);

		if ( $existing ) {
			if ( false === $wpdb->update( $this->table(), $row, array( 'id' => $id ) ) ) {
				return new \WP_Error( 'ffsp_db', __( 'The integration could not be saved (database error).', 'fluent-sharepoint-sync' ) );
			}
			do_action( 'ffsp/integration_saved', $id, false );
			return (int) $id;
		}

		$row['created_at'] = $now;
		if ( ! $wpdb->insert( $this->table(), $row ) || ! $wpdb->insert_id ) {
			return new \WP_Error( 'ffsp_db', __( 'The integration could not be saved (database error).', 'fluent-sharepoint-sync' ) );
		}
		$new_id = (int) $wpdb->insert_id;
		do_action( 'ffsp/integration_saved', $new_id, true );
		return $new_id;
	}

	/**
	 * @return string|\WP_Error The new secret, only once it is persisted.
	 */
	public function rotate_secret( $id ) {
		global $wpdb;
		if ( ! $this->find( $id ) ) {
			return new \WP_Error( 'ffsp_not_found', __( 'Integration not found.', 'fluent-sharepoint-sync' ) );
		}
		$secret = $this->security->generate_secret();
		$ok     = $wpdb->update(
			$this->table(),
			array(
				'secret'     => $this->security->encrypt( $secret ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		$check = $this->find( $id, true );
		if ( false === $ok || ! $check || ! hash_equals( $secret, (string) $check['secret'] ) ) {
			return new \WP_Error( 'ffsp_db', __( 'A new key could not be saved (database error). The current key is still in use.', 'fluent-sharepoint-sync' ) );
		}
		return $secret;
	}

	public function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		if ( 'active' === $status ) {
			$item = $this->find( $id );
			if ( ! $item || ! $item['has_endpoint'] ) {
				return false;
			}
		}
		return false !== $wpdb->update( $this->table(), array( 'status' => $status ), array( 'id' => (int) $id ) );
	}

	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}
}
