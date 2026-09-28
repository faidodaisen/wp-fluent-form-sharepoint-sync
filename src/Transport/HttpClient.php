<?php
namespace FFSP\Transport;

use FFSP\Security\Security;
use FFSP\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Posts JSON to the integration endpoint (Power Automate "When a HTTP request is received").
 *
 * Headers sent:
 *   X-FFSP-Request-Id  idempotency key
 *   X-FFSP-Timestamp   unix seconds
 *   X-FFSP-Key         shared secret           (auth_mode = shared_key)
 *   X-FFSP-Signature   sha256=HMAC(ts.body)    (auth_mode = hmac, always sent)
 */
class HttpClient {

	/** @var Settings */
	private $settings;

	/** @var Security */
	private $security;

	public function __construct( Settings $settings, Security $security ) {
		$this->settings = $settings;
		$this->security = $security;
	}

	/**
	 * @return Response
	 */
	public function send( array $integration, array $payload ) {
		$endpoint = (string) $integration['endpoint'];
		$valid    = $this->security->validate_endpoint( $endpoint );
		if ( is_wp_error( $valid ) ) {
			return Response::from_error( $valid->get_error_code(), $valid->get_error_message(), false );
		}

		$body = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$ts   = (string) time();

		$headers = array(
			'Content-Type'      => 'application/json; charset=utf-8',
			'Accept'            => 'application/json',
			'X-FFSP-Request-Id' => (string) $payload['requestId'],
			'X-FFSP-Timestamp'  => $ts,
			'X-FFSP-Signature'  => $this->security->sign( $body, $integration['secret'], $ts ),
			'X-FFSP-Version'    => FFSP_VERSION,
		);
		if ( 'shared_key' === $integration['auth_mode'] ) {
			$headers['X-FFSP-Key'] = (string) $integration['secret'];
		}

		$args = apply_filters(
			'ffsp/http_args',
			array(
				'method'      => 'POST',
				'timeout'     => (int) $this->settings->get( 'timeout' ),
				'redirection' => 0,
				// Flow replies are tiny JSON; never buffer an unbounded body.
				'limit_response_size' => 256 * KB_IN_BYTES,
				'headers'     => $headers,
				'body'        => $body,
				'data_format' => 'body',
				'user-agent'  => 'FluentSharePointSync/' . FFSP_VERSION . '; ' . home_url( '/' ),
				// Only a local dev site with "allow insecure" on may skip TLS verification (self-signed .test certs).
				'sslverify'   => ! ( $this->settings->get( 'allow_insecure' ) && 'local' === wp_get_environment_type() ),
			),
			$integration
		);

		// Pin the connection to the address that passed validation (no DNS-rebinding window).
		$pin = null;
		if ( ! $this->settings->get( 'allow_insecure' ) ) {
			$host = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
			$ip   = $this->security->resolve_public( $host );
			if ( '' === $ip ) {
				return Response::from_error( 'ffsp_dns', __( 'Endpoint host did not resolve to a public address.', 'fluent-sharepoint-sync' ), true );
			}
			$port = (int) wp_parse_url( $endpoint, PHP_URL_PORT );
			$port = $port ? $port : ( 'http' === wp_parse_url( $endpoint, PHP_URL_SCHEME ) ? 80 : 443 );
			$pin  = static function ( $handle, $r, $url ) use ( $host, $port, $ip, $endpoint ) {
				if ( $url === $endpoint && defined( 'CURLOPT_RESOLVE' ) ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( $host . ':' . $port . ':' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
				}
			};
			add_action( 'http_api_curl', $pin, 10, 3 );
		}

		$start = microtime( true );
		$res   = wp_remote_request( $endpoint, $args );
		$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( $pin ) {
			remove_action( 'http_api_curl', $pin, 10 );
		}

		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			// Timeouts may have reached Power Automate — still safe to retry thanks to requestId.
			return Response::from_error( 'http_request_failed', $msg, true, $ms );
		}

		return Response::from_http( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ), $ms, wp_remote_retrieve_headers( $res ) );
	}
}
