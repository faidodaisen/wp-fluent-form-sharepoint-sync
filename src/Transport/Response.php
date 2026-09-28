<?php
namespace FFSP\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized result of one HTTP attempt.
 *
 * Expected Power Automate "Response" body (all optional):
 *   { "status": "success|duplicate|error", "itemId": "123", "itemUrl": "https://...",
 *     "files": [ { "name": "", "url": "" } ], "message": "" }
 */
class Response {

	public $ok         = false;
	public $code       = 0;
	public $body       = '';
	public $data       = array();
	public $error_code = '';
	public $error      = '';
	public $retryable  = false;
	public $duration   = 0;
	public $retry_after = 0;

	public static function from_error( $code, $message, $retryable, $ms = 0 ) {
		$r             = new self();
		$r->error_code = (string) $code;
		$r->error      = (string) $message;
		$r->retryable  = (bool) $retryable;
		$r->duration   = (int) $ms;
		return $r;
	}

	public static function from_http( $code, $body, $ms, $headers = array() ) {
		$r           = new self();
		$r->code     = (int) $code;
		$r->body     = (string) $body;
		$r->duration = (int) $ms;
		$json        = json_decode( $r->body, true );
		$r->data     = is_array( $json ) ? $json : array();

		if ( $code >= 200 && $code < 300 ) {
			$status = strtolower( (string) ( $r->data['status'] ?? 'success' ) );
			if ( 'error' === $status ) {
				$r->error_code = 'remote_error';
				$r->error      = isset( $r->data['message'] ) && is_scalar( $r->data['message'] ) ? (string) $r->data['message'] : 'Flow returned status=error';
				$r->retryable  = ! empty( $r->data['retryable'] );
				return $r;
			}
			$r->ok = true;
			return $r;
		}

		$r->error_code = 'http_' . $code;
		$r->error      = self::describe( $code, $r->data );
		// 408, 409 (lock), 425, 429, 5xx are transient.
		$r->retryable  = in_array( $code, array( 408, 409, 425, 429 ), true ) || $code >= 500;
		if ( isset( $headers['retry-after'] ) && is_numeric( $headers['retry-after'] ) ) {
			$r->retry_after = (int) $headers['retry-after'];
		}
		return $r;
	}

	private static function describe( $code, array $data ) {
		$msg = '';
		if ( isset( $data['message'] ) && is_scalar( $data['message'] ) ) {
			$msg = (string) $data['message'];
		} elseif ( isset( $data['error']['message'] ) && is_scalar( $data['error']['message'] ) ) {
			$msg = (string) $data['error']['message'];
		}
		$hints = array(
			400 => 'Bad request — payload does not match the flow schema.',
			401 => 'Unauthorized — check the shared key / flow URL signature.',
			403 => 'Forbidden — key rejected or flow access revoked.',
			404 => 'Endpoint not found — the flow URL changed or flow was deleted.',
			413 => 'Payload too large — switch file mode to signed link.',
			429 => 'Rate limited by Power Automate — will retry.',
			502 => 'Bad gateway — SharePoint/Flow temporarily unavailable.',
			503 => 'Service unavailable — will retry.',
			504 => 'Gateway timeout — will retry.',
		);
		$hint = isset( $hints[ $code ] ) ? $hints[ $code ] : 'HTTP ' . $code;
		return trim( $hint . ( $msg ? ' ' . mb_substr( $msg, 0, 500 ) : '' ) );
	}

	public function item_id() {
		return isset( $this->data['itemId'] ) && is_scalar( $this->data['itemId'] ) ? sanitize_text_field( substr( (string) $this->data['itemId'], 0, 190 ) ) : '';
	}

	public function item_url() {
		return isset( $this->data['itemUrl'] ) && is_string( $this->data['itemUrl'] ) ? esc_url_raw( mb_substr( $this->data['itemUrl'], 0, 2000 ), array( 'http', 'https' ) ) : '';
	}

	/**
	 * Remote file list, normalized: max 50 entries, scalar strings, http(s) URLs only.
	 */
	public function files() {
		if ( empty( $this->data['files'] ) || ! is_array( $this->data['files'] ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $this->data['files'], 0, 50 ) as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$name = isset( $f['name'] ) && is_scalar( $f['name'] ) ? mb_substr( sanitize_text_field( (string) $f['name'] ), 0, 200 ) : '';
			$url  = isset( $f['url'] ) && is_string( $f['url'] ) ? esc_url_raw( mb_substr( $f['url'], 0, 2000 ), array( 'http', 'https' ) ) : '';
			if ( '' !== $name || '' !== $url ) {
				$out[] = array( 'name' => $name, 'url' => $url );
			}
		}
		return $out;
	}

	public function excerpt( $len = 1000 ) {
		return mb_substr( $this->body, 0, $len );
	}
}
