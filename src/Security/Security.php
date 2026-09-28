<?php
namespace FFSP\Security;

use FFSP\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Secret encryption at rest, request signing, masking and endpoint (SSRF) validation.
 */
class Security {

	const CIPHER_PREFIX = 'ffsp1:';

	/** @var Settings */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/* ---------------------------------------------------------------------
	 * Encryption at rest (endpoint URL + shared secret).
	 * Key is derived from WP salts, so a DB dump alone does not reveal secrets.
	 * ------------------------------------------------------------------- */

	private function key() {
		$material = ( defined( 'FFSP_ENCRYPTION_KEY' ) ? FFSP_ENCRYPTION_KEY : '' )
			. ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' );
		if ( '' === $material ) {
			$material = wp_salt( 'auth' );
		}
		return hash( 'sha256', 'ffsp|' . $material, true );
	}

	public function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = sodium_crypto_secretbox( $plain, $nonce, $this->key() );
			return self::CIPHER_PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv  = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv );
		$mac = hash_hmac( 'sha256', $iv . $enc, $this->key(), true );
		return 'ffsp2:' . base64_encode( $iv . $mac . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, self::CIPHER_PREFIX ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return ''; // Never hand ciphertext back as if it were the plain value.
			}
			$raw   = base64_decode( substr( $stored, strlen( self::CIPHER_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$nonce = substr( (string) $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( (string) $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = @sodium_crypto_secretbox_open( $box, $nonce, $this->key() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false === $plain ? '' : $plain;
		}
		if ( 0 === strpos( $stored, 'ffsp2:' ) ) {
			$raw = base64_decode( substr( $stored, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$iv  = substr( (string) $raw, 0, 16 );
			$mac = substr( (string) $raw, 16, 32 );
			$enc = substr( (string) $raw, 48 );
			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $enc, $this->key(), true ), $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $enc, 'aes-256-cbc', $this->key(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}
		return $stored; // Legacy/plain value.
	}

	public function generate_secret() {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/* ---------------------------------------------------------------------
	 * Request signing.
	 * ------------------------------------------------------------------- */

	/**
	 * HMAC-SHA256 over "{timestamp}.{body}".
	 */
	public function sign( $body, $secret, $timestamp ) {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, (string) $secret );
	}

	public function verify_signature( $body, $secret, $timestamp, $signature, $tolerance = 300 ) {
		if ( abs( time() - (int) $timestamp ) > $tolerance ) {
			return false;
		}
		return hash_equals( $this->sign( $body, $secret, $timestamp ), (string) $signature );
	}

	/**
	 * Stateless signed token (used for file links). Format: base64url(json).base64url(hmac)
	 */
	public function make_token( array $data ) {
		$json = wp_json_encode( $data );
		$b64  = $this->b64url( $json );
		return $b64 . '.' . $this->b64url( hash_hmac( 'sha256', $b64, $this->key() . 'files', true ) );
	}

	public function read_token( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$expected = $this->b64url( hash_hmac( 'sha256', $parts[0], $this->key() . 'files', true ) );
		if ( ! hash_equals( $expected, $parts[1] ) ) {
			return null;
		}
		$data = json_decode( (string) base64_decode( strtr( $parts[0], '-_', '+/' ), true ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return is_array( $data ) ? $data : null;
	}

	private function b64url( $raw ) {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * True when $path (already realpath'd) is inside $root, on a directory boundary.
	 */
	public static function path_inside( $path, $root ) {
		if ( ! $path || ! $root ) {
			return false;
		}
		$path = wp_normalize_path( $path );
		$root = untrailingslashit( wp_normalize_path( $root ) ) . '/';
		return 0 === strpos( $path, $root );
	}

	/**
	 * Remove credentials from any text that is about to be stored, shown or logged
	 * (remote error messages, response excerpts, entry notes).
	 */
	public function redact( $text, array $integration = array() ) {
		$text    = (string) $text;
		$needles = array();
		if ( ! empty( $integration['endpoint'] ) ) {
			$e         = (string) $integration['endpoint'];
			$needles[] = $e;
			$needles[] = str_replace( '/', '\\/', $e ); // JSON-escaped.
			$needles[] = rawurlencode( $e );
			$needles[] = esc_html( $e );
		}
		if ( ! empty( $integration['secret'] ) && strlen( (string) $integration['secret'] ) >= 8 ) {
			$needles[] = (string) $integration['secret'];
		}
		foreach ( array_filter( array_unique( $needles ) ) as $n ) {
			$text = str_replace( $n, '[redacted]', $text );
		}
		// Generic: URL signatures and signed file tokens.
		$text = preg_replace( '/([?&](?:sig|signature|code|token|key)=)[^&\s"\'<>]+/i', '$1[redacted]', $text );
		$text = preg_replace( '/sha256=[a-f0-9]{64}/i', 'sha256=[redacted]', $text );
		return $text;
	}

	/* ---------------------------------------------------------------------
	 * Masking.
	 * ------------------------------------------------------------------- */

	public function mask_secret( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		return strlen( $value ) <= 8 ? str_repeat( '•', 8 ) : substr( $value, 0, 4 ) . str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	/**
	 * Hide query string (Power Automate URLs carry the `sig` token there).
	 */
	public function mask_url( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? $p['path'] : '';
		if ( strlen( $path ) > 40 ) {
			$path = substr( $path, 0, 28 ) . '…' . substr( $path, -8 );
		}
		return ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . $path . ( isset( $p['query'] ) ? '?••••' : '' );
	}

	public function mask_email( $email ) {
		$email = (string) $email;
		$at    = strpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		return substr( $email, 0, 1 ) . str_repeat( '•', max( 2, $at - 1 ) ) . substr( $email, $at );
	}

	/**
	 * Mask a single scalar based on key name + content (emails, national ID, phone, tokens, URLs).
	 */
	public function mask_value( $key, $value ) {
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$key   = strtolower( (string) $key );
		$orig  = $value;
		$value = (string) $value;

		if ( preg_match( '/(secret|token|password|pass|sig|key|authorization|signature)/', $key ) ) {
			return $this->mask_secret( $value );
		}
		if ( is_email( $value ) ) {
			return $this->mask_email( $value );
		}
		// National ID formats (e.g. 123456-12-1234) or long digit runs (ID / passport / account numbers). Dates are left alone.
		$is_date = (bool) preg_match( '/^\d{4}-\d{2}-\d{2}([T ][\d:.+-]+Z?)?$/', $value );
		if ( ! is_int( $orig ) && ! $is_date && ( preg_match( '/^\d{6}-?\d{2}-?\d{4}$/', $value ) || preg_match( '/^\+?\d[\d\s-]{8,}$/', $value ) ) ) {
			return substr( $value, 0, 2 ) . str_repeat( '•', max( 4, strlen( $value ) - 4 ) ) . substr( $value, -2 );
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return $this->mask_url( $value );
		}
		return $orig;
	}

	public function mask_deep( $data, $parent_key = '' ) {
		if ( is_array( $data ) ) {
			$out = array();
			foreach ( $data as $k => $v ) {
				$out[ $k ] = $this->mask_deep( $v, is_string( $k ) ? $k : $parent_key );
			}
			return $out;
		}
		return $this->mask_value( $parent_key, $data );
	}

	/* ---------------------------------------------------------------------
	 * Endpoint validation (HTTPS + SSRF guard + optional allow-list).
	 * ------------------------------------------------------------------- */

	/**
	 * @return true|\WP_Error
	 */
	public function validate_endpoint( $url ) {
		$url = trim( (string) $url );
		$p   = wp_parse_url( $url );

		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return new \WP_Error( 'ffsp_invalid_url', __( 'Endpoint URL is not a valid URL.', 'fluent-sharepoint-sync' ) );
		}
		$scheme   = strtolower( $p['scheme'] );
		$host     = strtolower( $p['host'] );
		$insecure = (bool) $this->settings->get( 'allow_insecure' );

		if ( ! in_array( $scheme, array( 'https', 'http' ), true ) || ( 'http' === $scheme && ! $insecure ) ) {
			return new \WP_Error( 'ffsp_https_required', __( 'Endpoint must use HTTPS.', 'fluent-sharepoint-sync' ) );
		}
		if ( ! empty( $p['user'] ) || ! empty( $p['pass'] ) ) {
			return new \WP_Error( 'ffsp_invalid_url', __( 'Credentials inside the URL are not allowed.', 'fluent-sharepoint-sync' ) );
		}

		$allow = array_filter( array_map( 'trim', explode( "\n", (string) $this->settings->get( 'host_allowlist' ) ) ) );
		if ( $allow && ! $this->host_allowed( $host, $allow ) ) {
			/* translators: %s host name */
			return new \WP_Error( 'ffsp_host_not_allowed', sprintf( __( 'Host %s is not in the allow-list (Settings).', 'fluent-sharepoint-sync' ), $host ) );
		}

		if ( ! $insecure ) {
			if ( $this->is_private_host( $host ) ) {
				return new \WP_Error( 'ffsp_private_host', __( 'Endpoint resolves to a private / local address. Blocked for security.', 'fluent-sharepoint-sync' ) );
			}
			if ( ! $this->resolve_public( $host ) ) {
				return new \WP_Error( 'ffsp_dns', __( 'Endpoint host could not be resolved.', 'fluent-sharepoint-sync' ) );
			}
		}

		return true;
	}

	/**
	 * All A + AAAA answers for a host (literal IPs pass through).
	 *
	 * @return string[]
	 */
	public function resolve( $host ) {
		$host = trim( (string) $host, '[]' );
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}
		$ips = (array) gethostbynamel( $host );
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			foreach ( (array) $aaaa as $r ) {
				if ( ! empty( $r['ipv6'] ) ) {
					$ips[] = $r['ipv6'];
				}
			}
		}
		return array_values( array_unique( array_filter( $ips ) ) );
	}

	/**
	 * First public IPv4/IPv6 for a host, or '' when unresolvable / any answer is private.
	 * The HTTP client pins the connection to this address (no DNS rebinding window).
	 */
	public function resolve_public( $host ) {
		$ips = $this->resolve( $host );
		if ( ! $ips ) {
			return '';
		}
		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return '';
			}
		}
		return $ips[0];
	}

	private function host_allowed( $host, array $allow ) {
		foreach ( $allow as $rule ) {
			if ( $rule === $host ) {
				return true;
			}
			if ( 0 === strpos( $rule, '*.' ) && substr( $host, -strlen( $rule ) + 1 ) === substr( $rule, 1 ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_private_host( $host ) {
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) || preg_match( '/\.(test|local|localhost|internal)$/', $host ) ) {
			return true;
		}
		foreach ( $this->resolve( $host ) as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return true;
			}
		}
		return false;
	}
}
