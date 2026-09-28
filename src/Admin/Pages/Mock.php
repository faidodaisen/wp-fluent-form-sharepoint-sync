<?php
namespace FFSP\Admin\Pages;

use FFSP\Dev\MockReceiver;

defined( 'ABSPATH' ) || exit;

class Mock extends Page {

	public function render() {
		$inbox = MockReceiver::inbox();

		echo '<div class="ffsp-panel"><h2>' . esc_html__( 'Mock Power Automate receiver', 'fluent-sharepoint-sync' ) . '</h2>';
		echo '<p>' . esc_html__( 'A fake flow endpoint that behaves like the real one (verifies the signature, downloads files via signed links, returns an itemId, detects duplicates by requestId).', 'fluent-sharepoint-sync' ) . '</p>';
		echo '<p><code class="ffsp-mono">' . esc_html( MockReceiver::url() ) . '</code></p>';
		echo '<p class="ffsp-muted">' . esc_html__( 'Query options:', 'fluent-sharepoint-sync' ) . ' <code>?fail=500</code> <code>?fail=429</code> <code>?fail=401</code> <code>?fail=flaky</code> (' . esc_html__( 'fails twice then succeeds', 'fluent-sharepoint-sync' ) . ') <code>?download=none</code></p>';
		echo '<p><a class="button" data-confirm="1" href="' . esc_url( $this->action_url( 'ffsp_mock_clear' ) ) . '">' . esc_html__( 'Clear inbox', 'fluent-sharepoint-sync' ) . '</a></p></div>';

		if ( ! $inbox ) {
			echo '<div class="ffsp-panel ffsp-empty"><p>' . esc_html__( 'Nothing received yet.', 'fluent-sharepoint-sync' ) . '</p></div>';
			return;
		}

		foreach ( $inbox as $i => $e ) {
			$sig = $e['checks']['signature'] ?? '';
			printf(
				'<details class="ffsp-panel ffsp-mock" %s><summary><b>HTTP %d</b> · %s · %s · signature <b class="%s">%s</b></summary>',
				0 === $i ? 'open' : '',
				(int) $e['code'],
				esc_html( $e['time'] ),
				esc_html( $e['payload']['event'] ?? '' ) . ' ' . esc_html( isset( $e['payload']['source']['submissionId'] ) ? '#' . $e['payload']['source']['submissionId'] : '' ),
				'valid' === $sig ? 'ffsp-ok' : 'ffsp-bad',
				esc_html( $sig )
			);
			echo '<div class="ffsp-grid"><div class="ffsp-col-main"><h4>' . esc_html__( 'Payload received (masked)', 'fluent-sharepoint-sync' ) . '</h4><pre class="ffsp-pre">' . esc_html( wp_json_encode( $e['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre></div>';
			echo '<div class="ffsp-col-side"><h4>' . esc_html__( 'File checks', 'fluent-sharepoint-sync' ) . '</h4><pre class="ffsp-pre">' . esc_html( wp_json_encode( $e['checks']['files'] ?? array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
			echo '<h4>' . esc_html__( 'Response sent back', 'fluent-sharepoint-sync' ) . '</h4><pre class="ffsp-pre">' . esc_html( wp_json_encode( $e['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></div></div></details>';
		}
	}
}
