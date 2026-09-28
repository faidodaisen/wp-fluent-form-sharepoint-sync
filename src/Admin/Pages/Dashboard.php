<?php
namespace FFSP\Admin\Pages;

use FFSP\Admin\Admin;

defined( 'ABSPATH' ) || exit;

class Dashboard extends Page {

	public function render() {
		$logs   = $this->plugin->get( 'logs' );
		$ints   = $this->plugin->get( 'integrations' )->all();
		$all    = $logs->counts();
		$day    = $logs->counts_since( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$last   = $logs->last_success();
		$active = count(
			array_filter(
				$ints,
				static function ( $i ) {
					return 'active' === $i['status'];
				}
			)
		);
		$pending = $all['queued'] + $all['retrying'] + $all['processing'] + $all['pending'];

		echo '<div class="ffsp-cards">';
		$this->card( __( 'Active integrations', 'fluent-sharepoint-sync' ), $active . ' / ' . count( $ints ), Admin::url( 'integrations' ) );
		$this->card( __( 'Sent (24h)', 'fluent-sharepoint-sync' ), $day['success'], Admin::url( 'logs', array( 'status' => 'success' ) ), 'success' );
		$this->card( __( 'Failed (total)', 'fluent-sharepoint-sync' ), $all['failed'], Admin::url( 'logs', array( 'status' => 'failed' ) ), $all['failed'] ? 'failed' : '' );
		$this->card( __( 'In queue / retrying', 'fluent-sharepoint-sync' ), $pending, Admin::url( 'logs', array( 'status' => 'retrying' ) ), $pending ? 'retrying' : '' );
		$this->card( __( 'Last successful sync', 'fluent-sharepoint-sync' ), $last ? $this->time( $last ) : '—', Admin::url( 'logs' ) );
		echo '</div>';

		// Health checklist.
		$settings = $this->plugin->get( 'settings' );
		$checks   = array(
			array( \FFSP\Plugin::fluent_active(), __( 'Fluent Forms is active', 'fluent-sharepoint-sync' ) ),
			array( defined( 'FLUENTFORMPRO' ), __( 'Fluent Forms Pro is active (file upload fields)', 'fluent-sharepoint-sync' ), true ),
			array( count( $ints ) > 0, __( 'At least one integration is configured', 'fluent-sharepoint-sync' ) ),
			array( $active > 0, __( 'At least one integration is active', 'fluent-sharepoint-sync' ) ),
			array( $this->plugin->get( 'queue' )->uses_action_scheduler(), __( 'Action Scheduler available for background queue (fallback: WP-Cron)', 'fluent-sharepoint-sync' ), true ),
			array( ! ( 'production' === wp_get_environment_type() && $settings->get( 'allow_insecure' ) ), __( 'Insecure/local endpoints are disabled on production', 'fluent-sharepoint-sync' ) ),
			array( ! ( 'production' === wp_get_environment_type() && $settings->get( 'mock_receiver' ) ), __( 'Mock receiver is disabled on production', 'fluent-sharepoint-sync' ) ),
			array( is_ssl() || 'local' === wp_get_environment_type(), __( 'Site uses HTTPS (signed file links are fetched by Power Automate)', 'fluent-sharepoint-sync' ), true ),
		);

		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Health check', 'fluent-sharepoint-sync' ) . '</h2><ul class="ffsp-checks">';
		foreach ( $checks as $c ) {
			$cls = $c[0] ? 'ok' : ( ! empty( $c[2] ) ? 'warn' : 'bad' );
			$ico = $c[0] ? 'yes-alt' : ( ! empty( $c[2] ) ? 'warning' : 'dismiss' );
			echo '<li class="ffsp-check--' . esc_attr( $cls ) . '"><span class="dashicons dashicons-' . esc_attr( $ico ) . '"></span>' . esc_html( $c[1] ) . '</li>';
		}
		echo '</ul></div>';

		if ( ! $ints ) {
			echo '<div class="ffsp-panel ffsp-empty"><h2>' . esc_html__( 'Get started', 'fluent-sharepoint-sync' ) . '</h2><ol>';
			echo '<li>' . esc_html__( 'Create an integration and choose the Fluent Form.', 'fluent-sharepoint-sync' ) . '</li>';
			echo '<li>' . esc_html__( 'Map form fields to SharePoint column names.', 'fluent-sharepoint-sync' ) . '</li>';
			echo '<li>' . esc_html__( 'Paste the Power Automate HTTP URL (or use the mock receiver locally), test, then activate.', 'fluent-sharepoint-sync' ) . '</li>';
			echo '</ol><a class="button button-primary" href="' . esc_url( Admin::url( 'integrations', array( 'action' => 'new' ) ) ) . '">' . esc_html__( 'Create integration', 'fluent-sharepoint-sync' ) . '</a></div>';
		}
	}

	private function card( $label, $value, $url, $tone = '' ) {
		printf(
			'<a class="ffsp-card %s" href="%s"><span class="ffsp-card__value">%s</span><span class="ffsp-card__label">%s</span></a>',
			$tone ? 'ffsp-card--' . esc_attr( $tone ) : '',
			esc_url( $url ),
			wp_kses_post( (string) $value ),
			esc_html( $label )
		);
	}
}
