<?php
namespace FFSP\Admin\Pages;

defined( 'ABSPATH' ) || exit;

class Settings extends Page {

	public function render() {
		$s = $this->plugin->get( 'settings' )->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ffsp-form">';
		wp_nonce_field( 'ffsp_save_settings' );
		echo '<input type="hidden" name="action" value="ffsp_save_settings">';

		$this->section( __( 'Delivery', 'fluent-sharepoint-sync' ) );
		$this->checkbox( 'async', $s, __( 'Send in background (recommended)', 'fluent-sharepoint-sync' ), __( 'The visitor gets the thank-you message instantly; the sync runs via Action Scheduler / WP-Cron. Turn off only for debugging.', 'fluent-sharepoint-sync' ) );
		$this->number( 'timeout', $s, __( 'HTTP timeout (seconds)', 'fluent-sharepoint-sync' ), 3, 60 );
		$this->number( 'max_attempts', $s, __( 'Max automatic attempts', 'fluent-sharepoint-sync' ), 1, 10, __( 'Backoff: 1 min, 5 min, 15 min, 1 h, 3 h …', 'fluent-sharepoint-sync' ) );
		echo '</table></div>';

		$this->section( __( 'Files', 'fluent-sharepoint-sync' ) );
		$this->number( 'file_link_ttl', $s, __( 'Signed link lifetime (hours)', 'fluent-sharepoint-sync' ), 1, 720, __( 'Must be longer than your retry window.', 'fluent-sharepoint-sync' ) );
		$this->number( 'file_max_downloads', $s, __( 'Downloads per link', 'fluent-sharepoint-sync' ), 0, 100, __( '0 = unlimited.', 'fluent-sharepoint-sync' ) );
		echo '</table></div>';

		$this->section( __( 'Security', 'fluent-sharepoint-sync' ) );
		echo '<tr><th><label for="ffsp-hosts">' . esc_html__( 'Allowed endpoint hosts', 'fluent-sharepoint-sync' ) . '</label></th><td><textarea id="ffsp-hosts" name="settings[host_allowlist]" rows="4" class="large-text ffsp-mono" placeholder="*.logic.azure.com&#10;*.environment.api.powerplatform.com">' . esc_textarea( $s['host_allowlist'] ) . '</textarea>' . $this->help( __( 'One per line, <code>*.</code> wildcard allowed. Empty = any public HTTPS host. Recommended on production.', 'fluent-sharepoint-sync' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		$this->checkbox( 'allow_insecure', $s, __( 'Allow insecure / local endpoints (http://, .test, 127.0.0.1)', 'fluent-sharepoint-sync' ), __( '<b>Local development only.</b> Also disables TLS verification on a <code>local</code> environment.', 'fluent-sharepoint-sync' ) );
		echo '</table></div>';

		$this->section( __( 'Logs & privacy', 'fluent-sharepoint-sync' ) );
		$this->number( 'retention_days', $s, __( 'Keep logs for (days)', 'fluent-sharepoint-sync' ), 0, 3650, __( '0 = keep forever. Pending/retrying rows are never purged.', 'fluent-sharepoint-sync' ) );
		$this->checkbox( 'log_payload', $s, __( 'Store payload in logs (masked)', 'fluent-sharepoint-sync' ), __( 'Emails, ID/phone numbers and secrets are masked. File contents are never stored.', 'fluent-sharepoint-sync' ) );
		$this->number( 'payload_retention', $s, __( 'Drop stored payloads after (days)', 'fluent-sharepoint-sync' ), 0, 365 );
		$this->checkbox( 'debug_mode', $s, __( 'Debug mode', 'fluent-sharepoint-sync' ), __( 'Writes masked lines to the PHP error log (wp-content/debug.log when WP_DEBUG_LOG is on).', 'fluent-sharepoint-sync' ) );
		echo '</table></div>';

		$this->section( __( 'Developer', 'fluent-sharepoint-sync' ) );
		$this->checkbox( 'mock_receiver', $s, __( 'Enable mock Power Automate receiver', 'fluent-sharepoint-sync' ), __( 'Adds a fake flow at <code>/wp-json/ffsp-mock/v1/flow</code> for testing without SharePoint. Keep OFF on production.', 'fluent-sharepoint-sync' ) );
		$this->checkbox( 'delete_on_uninstall', $s, __( 'Delete all plugin data on uninstall', 'fluent-sharepoint-sync' ), __( 'Removes integrations, logs and settings when the plugin is deleted.', 'fluent-sharepoint-sync' ) );
		echo '</table></div>';

		echo '<p><button class="button button-primary button-large">' . esc_html__( 'Save settings', 'fluent-sharepoint-sync' ) . '</button></p></form>';
	}

	private function section( $title ) {
		echo '<div class="ffsp-panel"><h2>' . esc_html( $title ) . '</h2><table class="form-table ffsp-settings-table">';
	}

	private function checkbox( $key, $s, $label, $help = '' ) {
		printf(
			'<tr><th>%1$s</th><td><label><input type="checkbox" name="settings[%2$s]" value="1" %3$s> %1$s</label>%4$s</td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( $s[ $key ], 1, false ),
			$help ? $this->help( $help ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	private function number( $key, $s, $label, $min, $max, $help = '' ) {
		printf(
			'<tr><th><label for="ffsp-%2$s">%1$s</label></th><td><input id="ffsp-%2$s" type="number" class="small-text" name="settings[%2$s]" min="%3$d" max="%4$d" value="%5$d">%6$s</td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			(int) $min,
			(int) $max,
			(int) $s[ $key ],
			$help ? $this->help( $help ) : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
}
