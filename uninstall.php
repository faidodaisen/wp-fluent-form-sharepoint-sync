<?php
/**
 * Uninstall: only removes data when the admin opted in (Settings > Delete data on uninstall).
 *
 * @package FFSP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ffsp_settings = get_option( 'ffsp_settings', array() );
if ( empty( $ffsp_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ffsp_logs" );         // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ffsp_integrations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ffsp_dl_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_ffsp_' ) . '%', $wpdb->esc_like( '_transient_timeout_ffsp_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

foreach ( array( 'ffsp_settings', 'ffsp_db_version', 'ffsp_mock_inbox', 'ffsp_mock_items' ) as $ffsp_opt ) {
	delete_option( $ffsp_opt );
}
delete_site_transient( 'ffsp_update_check' );
wp_clear_scheduled_hook( 'ffsp_daily_maintenance' );
wp_unschedule_hook( 'ffsp_process_log' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'fluent-sharepoint-sync' );
}
