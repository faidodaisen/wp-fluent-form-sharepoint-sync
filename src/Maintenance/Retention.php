<?php
namespace FFSP\Maintenance;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Daily housekeeping: purge old logs, drop stored payloads, re-queue stuck rows.
 */
class Retention implements Module {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'ffsp_daily_maintenance', array( $this, 'run' ) );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'ffsp_daily_maintenance' ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ffsp_daily_maintenance' );
				}
			}
		);
	}

	public function run() {
		$settings = $this->plugin->get( 'settings' );
		$logs     = $this->plugin->get( 'logs' );

		$logs->purge_older_than( (int) $settings->get( 'retention_days' ) );
		$logs->strip_payloads_older_than( (int) $settings->get( 'payload_retention' ) );

		// Rows stuck in "processing" (worker died), "queued" without a job, or "retrying" whose
		// retry time passed long ago (job lost): re-queue.
		global $wpdb;
		$table = \FFSP\Database\Schema::logs_table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$stuck = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE ( status IN ('processing','queued') AND updated_at < %s ) OR ( status = 'retrying' AND next_attempt_at < %s ) LIMIT 200", $cut, $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Expired per-link download counters.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) < %d", $wpdb->esc_like( 'ffsp_dl_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( (array) $stuck as $id ) {
			$this->plugin->get( 'queue' )->push( (int) $id );
		}
	}
}
