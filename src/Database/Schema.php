<?php
namespace FFSP\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Custom tables: {prefix}ffsp_integrations and {prefix}ffsp_logs.
 */
class Schema {

	const OPTION = 'ffsp_db_version';

	public static function integrations_table() {
		global $wpdb;
		return $wpdb->prefix . 'ffsp_integrations';
	}

	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ffsp_logs';
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
		if ( ! wp_next_scheduled( 'ffsp_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ffsp_daily_maintenance' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== FFSP_DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$int     = self::integrations_table();
		$logs    = self::logs_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		$sql = "CREATE TABLE {$int} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'inactive',
			environment varchar(20) NOT NULL DEFAULT 'development',
			destination varchar(20) NOT NULL DEFAULT 'list',
			endpoint text NULL,
			secret text NULL,
			auth_mode varchar(20) NOT NULL DEFAULT 'shared_key',
			mapping_json longtext NULL,
			options_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY status (status)
		) {$charset};
		CREATE TABLE {$logs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			integration_id bigint(20) unsigned NOT NULL DEFAULT 0,
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
			request_id varchar(64) NOT NULL DEFAULT '',
			remote_item_id varchar(190) NOT NULL DEFAULT '',
			remote_item_url text NULL,
			remote_files longtext NULL,
			error_code varchar(50) NOT NULL DEFAULT '',
			error_message text NULL,
			response_excerpt text NULL,
			payload longtext NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			next_attempt_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY integration_submission (integration_id,submission_id),
			KEY status (status),
			KEY form_id (form_id),
			KEY created_at (created_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::OPTION, FFSP_DB_VERSION, false );
	}

	/**
	 * Drop tables (only called from uninstall.php when the admin opted in).
	 */
	public static function drop() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::logs_table() );         // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::integrations_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::OPTION );
	}
}
