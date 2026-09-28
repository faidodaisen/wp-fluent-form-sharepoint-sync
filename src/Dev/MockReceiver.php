<?php
namespace FFSP\Dev;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Local "fake Power Automate" so the whole pipeline can be tested before a real
 * flow URL is available.
 *
 *   POST /wp-json/ffsp-mock/v1/flow          -> behaves like a flow returning 200 + itemId
 *   POST /wp-json/ffsp-mock/v1/flow?fail=503 -> simulate failures (401, 429, 500, 503...)
 *   POST /wp-json/ffsp-mock/v1/flow?fail=flaky -> fails first 2 attempts per requestId, then OK
 *
 * It verifies the HMAC signature (using the secret of the integration named in the payload),
 * downloads each signed file link like the real flow would, and keeps the last 50 requests
 * in an option for inspection in the admin "Mock receiver" tab.
 *
 * Only enabled when Settings > "Mock receiver" is on (default on for local env).
 */
class MockReceiver implements Module {

	const OPTION = 'ffsp_mock_inbox';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Enabled only when switched on AND the site is not production.
	 */
	public static function enabled() {
		return (bool) ffsp()->get( 'settings' )->get( 'mock_receiver' ) && 'production' !== wp_get_environment_type();
	}

	public function register() {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public static function url( $query = array() ) {
		return add_query_arg( $query, rest_url( 'ffsp-mock/v1/flow' ) );
	}

	public function routes() {
		register_rest_route(
			'ffsp-mock/v1',
			'/flow',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function receive( \WP_REST_Request $request ) {
		$body    = (string) $request->get_body();
		$payload = json_decode( $body, true );
		$fail    = sanitize_key( (string) $request->get_param( 'fail' ) );
		$checks  = array();

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Body is not JSON' ), 400 );
		}

		// Signature check (like a real flow condition would do).
		$integration = null;
		if ( ! empty( $payload['integration']['id'] ) ) {
			$integration = $this->plugin->get( 'integrations' )->find( (int) $payload['integration']['id'], true );
		}
		$sig_ok = false;
		if ( $integration ) {
			$sig_ok = $this->plugin->get( 'security' )->verify_signature( $body, $integration['secret'], $request->get_header( 'x_ffsp_timestamp' ), $request->get_header( 'x_ffsp_signature' ) );
			$key    = $request->get_header( 'x_ffsp_key' );
			if ( 'shared_key' === $integration['auth_mode'] && ! hash_equals( (string) $integration['secret'], (string) $key ) ) {
				$sig_ok = false;
			}
		}
		$checks['signature'] = $sig_ok ? 'valid' : 'INVALID';

		// Unauthenticated calls are rejected before any work or storage (no downloads, no inbox writes).
		if ( ! $sig_ok ) {
			return new \WP_REST_Response( array( 'status' => 'error', 'message' => 'Signature / key rejected by mock flow' ), 401 );
		}

		// Flaky simulation keyed by requestId.
		$request_id = (string) ( $payload['requestId'] ?? '' );
		if ( 'flaky' === $fail ) {
			$k = 'ffsp_mock_flaky_' . md5( $request_id );
			$n = (int) get_transient( $k ) + 1;
			set_transient( $k, $n, HOUR_IN_SECONDS );
			$fail = $n <= 2 ? '503' : '';
		}

		// Idempotency: same requestId already stored => duplicate.
		$items     = get_option( 'ffsp_mock_items', array() );
		$duplicate = $request_id && isset( $items[ $request_id ] ) && empty( $payload['test'] );

		// Download files through their signed links (the real flow does this with an HTTP action).
		$file_results = array();
		$own_files = rest_url( 'ffsp/v1/file' );
		foreach ( array_slice( (array) ( $payload['files'] ?? array() ), 0, 10 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$res = array( 'name' => sanitize_file_name( (string) ( $f['name'] ?? '' ) ) );
			if ( ! empty( $f['contentBytes'] ) ) {
				$bin               = base64_decode( $f['contentBytes'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				$res['via']        = 'inline';
				$res['sha256_ok']  = $bin !== false && hash( 'sha256', $bin ) === ( $f['sha256'] ?? '' );
			} elseif ( ! empty( $f['downloadUrl'] ) && is_string( $f['downloadUrl'] ) && 0 === strpos( $f['downloadUrl'], $own_files ) && 'none' !== $request->get_param( 'download' ) ) {
				// Only this site's own signed file route is fetched — never an arbitrary URL.
				$dl               = wp_remote_get(
					$f['downloadUrl'],
					array(
						'timeout'             => 15,
						'redirection'         => 0,
						'limit_response_size' => 25 * MB_IN_BYTES,
						'sslverify'           => 'local' !== wp_get_environment_type(),
					)
				);
				$res['via']       = 'link';
				$res['http']      = is_wp_error( $dl ) ? $dl->get_error_message() : wp_remote_retrieve_response_code( $dl );
				$res['sha256_ok'] = ! is_wp_error( $dl ) && hash( 'sha256', wp_remote_retrieve_body( $dl ) ) === ( $f['sha256'] ?? '' );
			}
			$res['url']     = 'https://contoso.sharepoint.com/sites/demo/Shared%20Documents/' . rawurlencode( $payload['sharepoint']['folderPath'] ?? '' ) . '/' . rawurlencode( $res['name'] );
			$file_results[] = $res;
		}
		$checks['files'] = $file_results;

		$code = 200;
		$resp = array();
		if ( $fail && is_numeric( $fail ) ) {
			$code = (int) $fail;
			$resp = array( 'status' => 'error', 'message' => 'Simulated failure ' . $code );
		} elseif ( $duplicate ) {
			$resp = array(
				'status'  => 'duplicate',
				'itemId'  => $items[ $request_id ],
				'itemUrl' => 'https://contoso.sharepoint.com/sites/demo/Lists/Submissions/DispForm.aspx?ID=' . $items[ $request_id ],
				'message' => 'Already processed; returning existing item.',
			);
		} else {
			$item_id = empty( $payload['test'] ) ? (string) ( count( $items ) + 1001 ) : 'test';
			if ( empty( $payload['test'] ) && $request_id ) {
				$items[ $request_id ] = $item_id;
				update_option( 'ffsp_mock_items', $items, false );
			}
			$resp = array(
				'status'  => 'success',
				'itemId'  => $item_id,
				'itemUrl' => 'https://contoso.sharepoint.com/sites/demo/Lists/Submissions/DispForm.aspx?ID=' . $item_id,
				'files'   => array_map(
					static function ( $r ) {
						return array( 'name' => $r['name'], 'url' => $r['url'] );
					},
					$file_results
				),
			);
		}

		$this->remember(
			array(
				'time'     => gmdate( 'c' ),
				'code'     => $code,
				'checks'   => $checks,
				'headers'  => array(
					'request_id' => $request->get_header( 'x_ffsp_request_id' ),
					'timestamp'  => $request->get_header( 'x_ffsp_timestamp' ),
					'has_key'    => (bool) $request->get_header( 'x_ffsp_key' ),
				),
				'payload'  => $this->plugin->get( 'security' )->mask_deep( $this->strip_bytes( $payload ) ),
				'response' => $resp,
			)
		);

		return new \WP_REST_Response( $resp, $code );
	}

	private function strip_bytes( array $payload ) {
		if ( ! empty( $payload['files'] ) ) {
			foreach ( $payload['files'] as &$f ) {
				if ( isset( $f['contentBytes'] ) ) {
					$f['contentBytes'] = '[' . strlen( $f['contentBytes'] ) . ' chars]';
				}
			}
			unset( $f );
		}
		return $payload;
	}

	private function remember( array $entry ) {
		$inbox = get_option( self::OPTION, array() );
		array_unshift( $inbox, $entry );
		update_option( self::OPTION, array_slice( $inbox, 0, 50 ), false );
	}

	public static function inbox() {
		// Written by another request; bypass the per-request option cache.
		wp_cache_delete( self::OPTION, 'options' );
		return (array) get_option( self::OPTION, array() );
	}

	public static function clear() {
		delete_option( self::OPTION );
		delete_option( 'ffsp_mock_items' );
	}
}
