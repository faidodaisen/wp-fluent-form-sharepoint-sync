<?php
namespace FFSP\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Global settings stored in a single wp_options row (`ffsp_settings`).
 */
class Settings {

	const OPTION = 'ffsp_settings';

	/** @var array|null */
	private $cache = null;

	public static function defaults() {
		$is_local = in_array( wp_get_environment_type(), array( 'local', 'development' ), true );

		return array(
			'timeout'            => 15,       // Seconds per HTTP request.
			'async'              => 1,        // Dispatch via background queue.
			'max_attempts'       => 5,        // Automatic attempts for transient failures.
			'retention_days'     => 90,       // Delete logs older than this (0 = keep).
			'payload_retention'  => 7,        // Days to keep stored payloads (when log_payload on).
			'log_payload'        => 0,        // Store the (masked) request payload on each log.
			'debug_mode'         => 0,        // Write debug lines to debug.log.
			'file_link_ttl'      => 24,       // Hours a signed file link stays valid.
			'file_max_downloads' => 5,        // Downloads allowed per signed link (0 = unlimited).
			'host_allowlist'     => '',       // One host per line; empty = any public host.
			'allow_insecure'     => $is_local ? 1 : 0, // Allow http:// + local hosts (dev only).
			'mock_receiver'      => $is_local ? 1 : 0, // Built-in fake Power Automate endpoint.
			'delete_on_uninstall' => 0,
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$saved       = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return $this->cache;
	}

	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function update( array $values ) {
		$clean = $this->sanitize( $values );
		update_option( self::OPTION, $clean, false );
		$this->cache = null;
		return $clean;
	}

	public function sanitize( array $in ) {
		$d   = self::defaults();
		$int = static function ( $v, $min, $max ) {
			return max( $min, min( $max, (int) $v ) );
		};

		$hosts = array();
		foreach ( preg_split( '/[\r\n,]+/', (string) ( $in['host_allowlist'] ?? '' ) ) as $h ) {
			$h = strtolower( trim( $h ) );
			if ( '' !== $h && preg_match( '/^(\*\.)?[a-z0-9.-]+$/', $h ) ) {
				$hosts[] = $h;
			}
		}

		return array(
			'timeout'             => $int( $in['timeout'] ?? $d['timeout'], 3, 60 ),
			'async'               => empty( $in['async'] ) ? 0 : 1,
			'max_attempts'        => $int( $in['max_attempts'] ?? $d['max_attempts'], 1, 10 ),
			'retention_days'      => $int( $in['retention_days'] ?? $d['retention_days'], 0, 3650 ),
			'payload_retention'   => $int( $in['payload_retention'] ?? $d['payload_retention'], 0, 365 ),
			'log_payload'         => empty( $in['log_payload'] ) ? 0 : 1,
			'debug_mode'          => empty( $in['debug_mode'] ) ? 0 : 1,
			'file_link_ttl'       => $int( $in['file_link_ttl'] ?? $d['file_link_ttl'], 1, 720 ),
			'file_max_downloads'  => $int( $in['file_max_downloads'] ?? $d['file_max_downloads'], 0, 100 ),
			'host_allowlist'      => implode( "\n", array_unique( $hosts ) ),
			'allow_insecure'      => empty( $in['allow_insecure'] ) ? 0 : 1,
			'mock_receiver'       => empty( $in['mock_receiver'] ) ? 0 : 1,
			'delete_on_uninstall' => empty( $in['delete_on_uninstall'] ) ? 0 : 1,
		);
	}
}
