<?php
namespace FFSP\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Debug logger — writes to PHP error log (wp-content/debug.log) only when debug mode is on.
 * Never pass raw secrets here; callers mask values first.
 */
class Debug {

	public static function log( $message, array $context = array() ) {
		$settings = ffsp()->get( 'settings' );
		if ( ! $settings->get( 'debug_mode' ) ) {
			return;
		}
		if ( $context ) {
			$message .= ' ' . wp_json_encode( ffsp()->get( 'security' )->mask_deep( $context ) );
		}
		error_log( '[FFSP] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
