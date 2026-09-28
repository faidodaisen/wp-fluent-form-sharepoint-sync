<?php
namespace FFSP\Files;

use FFSP\Security\Security;
use FFSP\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Fluent file field values (URLs) into a SharePoint friendly descriptor list:
 * name, size, mime, sha256, a short-lived signed download link and (optionally) base64 content.
 */
class FileHandler {

	/** @var Settings */
	private $settings;

	/** @var Security */
	private $security;

	public function __construct( Settings $settings, Security $security ) {
		$this->settings = $settings;
		$this->security = $security;
	}

	/**
	 * Resolve an uploaded file URL to an absolute path inside wp-content/uploads.
	 *
	 * @return string|null
	 */
	public function url_to_path( $url ) {
		$url     = (string) $url;
		$uploads = wp_get_upload_dir();
		$base    = set_url_scheme( $uploads['baseurl'], 'https' );
		$url_h   = set_url_scheme( $url, 'https' );

		if ( 0 !== strpos( $url_h, $base ) ) {
			return null;
		}
		$rel = ltrim( rawurldecode( substr( strtok( $url_h, '?' ), strlen( $base ) ) ), '/' );
		if ( '' === $rel || preg_match( '#(^|[\\\\/])\.\.([\\\\/]|$)#', $rel ) ) {
			return null;
		}
		$path = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . $rel );
		$real = realpath( $path );
		$root = realpath( $uploads['basedir'] );
		if ( ! $real || ! Security::path_inside( $real, $root ) || ! is_file( $real ) ) {
			return null;
		}
		return $real;
	}

	/**
	 * Build file descriptors for a single Fluent file field.
	 *
	 * @param string $field_key     Fluent field name.
	 * @param mixed  $value         Array/string of URLs.
	 * @param array  $integration   Integration (options used).
	 * @param int    $submission_id Entry id.
	 * @return array{files: array, errors: array}
	 */
	public function describe( $field_key, $value, array $integration, $submission_id ) {
		$files  = array();
		$errors = array();
		$mode   = $integration['options']['file_mode'];
		$max    = (int) $integration['options']['inline_max_kb'] * 1024;
		$ttl    = (int) $this->settings->get( 'file_link_ttl' ) * HOUR_IN_SECONDS;

		foreach ( array_filter( (array) $value ) as $url ) {
			if ( ! is_string( $url ) ) {
				continue;
			}
			$path = $this->url_to_path( $url );
			if ( ! $path ) {
				/* translators: %s file name */
				$errors[] = sprintf( __( 'File not found on server: %s', 'fluent-sharepoint-sync' ), basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
				continue;
			}

			$size = (int) filesize( $path );
			$type = wp_check_filetype( $path );
			$name = $this->clean_name( basename( $path ) );

			$item = array(
				'field'    => $field_key,
				'name'     => $name,
				'size'     => $size,
				'mimeType' => $type['type'] ? $type['type'] : 'application/octet-stream',
				'sha256'   => hash_file( 'sha256', $path ),
			);

			$uploads    = wp_get_upload_dir();
			$rel        = ltrim( substr( wp_normalize_path( $path ), strlen( wp_normalize_path( realpath( $uploads['basedir'] ) ) ) ), '/' );
			$expires    = time() + $ttl;
			$token      = $this->security->make_token(
				array(
					'p' => $rel,
					'e' => $expires,
					's' => (int) $submission_id,
					'i' => (int) $integration['id'],
					'n' => wp_generate_password( 8, false ),
				)
			);
			$item['downloadUrl'] = add_query_arg( 'token', rawurlencode( $token ), rest_url( 'ffsp/v1/file' ) );
			$item['expiresAt']   = gmdate( 'c', $expires );

			if ( 'inline_base64' === $mode ) {
				if ( $size <= $max ) {
					$item['contentBytes'] = base64_encode( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				} else {
					$item['inlineSkipped'] = 'too_large';
				}
			}

			$files[] = apply_filters( 'ffsp/file_descriptor', $item, $path, $integration );
		}

		return array(
			'files'  => $files,
			'errors' => $errors,
		);
	}

	/**
	 * SharePoint rejects: " * : < > ? / \ | # % and leading/trailing spaces/dots.
	 */
	public function clean_name( $name ) {
		// Fluent prefixes uploads with "ff-<hash>-" (Pro adds a second "ff-" before the original name); strip both.
		$name = preg_replace( '/^ff-[a-f0-9]{16,}-(ff-)?/i', '', $name );
		$name = preg_replace( '/["*:<>?\/\\\\|#%~&{}]+/', '-', $name );
		$name = trim( $name, " .\t\n\r" );
		return '' === $name ? 'file' : mb_substr( $name, 0, 200 );
	}
}
