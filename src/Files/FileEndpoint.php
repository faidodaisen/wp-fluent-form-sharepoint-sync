<?php
namespace FFSP\Files;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/ffsp/v1/file?token=...
 *
 * Streams a submission file to Power Automate (HTTP "Get file" step). The token is an
 * HMAC-signed, expiring reference; no WordPress login is needed and no directory is exposed.
 */
class FileEndpoint implements Module {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			'ffsp/v1',
			'/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => '__return_true', // Auth = signed token.
				'args'                => array(
					'token' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function serve( \WP_REST_Request $request ) {
		$security = $this->plugin->get( 'security' );
		$settings = $this->plugin->get( 'settings' );
		$token    = (string) $request->get_param( 'token' );
		$data     = $security->read_token( $token );

		if ( ! $data || empty( $data['p'] ) || empty( $data['e'] ) ) {
			return new \WP_Error( 'ffsp_bad_token', 'Invalid link.', array( 'status' => 403 ) );
		}
		if ( time() > (int) $data['e'] ) {
			return new \WP_Error( 'ffsp_expired', 'Link expired.', array( 'status' => 410 ) );
		}

		$limit = (int) $settings->get( 'file_max_downloads' );

		$uploads = wp_get_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		$path    = realpath( trailingslashit( $uploads['basedir'] ) . $data['p'] );
		if ( ! $path || ! \FFSP\Security\Security::path_inside( $path, $root ) || ! is_file( $path ) ) {
			return new \WP_Error( 'ffsp_missing', 'File not found.', array( 'status' => 404 ) );
		}

		// Download limit per link — consumed atomically before streaming.
		if ( $limit > 0 && ! $this->consume( $token, $limit, (int) $data['e'] ) ) {
			return new \WP_Error( 'ffsp_limit', 'Download limit reached.', array( 'status' => 429 ) );
		}

		$type = wp_check_filetype( $path );
		$name = $this->plugin->get( 'files' )->clean_name( basename( $path ) );

		nocache_headers();
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Atomic per-link counter in the logs DB table's sibling option row (one row per link).
	 * INSERT ... ON DUPLICATE KEY + conditional UPDATE, so parallel requests cannot overshoot.
	 */
	private function consume( $token, $limit, $expires ) {
		global $wpdb;
		$name = 'ffsp_dl_' . md5( $token );
		// Row value format: "<count>|<expires>".
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $name, '0|' . (int) $expires ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CONCAT(CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) + 1, '|', SUBSTRING_INDEX(option_value, '|', -1)) WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
				$name,
				(int) $limit
			)
		);
		return 1 === (int) $updated;
	}
}
