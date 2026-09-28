<?php
namespace FFSP\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Value transformers that turn Fluent values into SharePoint-friendly values.
 * Add your own with the `ffsp/transformers` filter: [ 'slug' => [ 'label' => '', 'callback' => callable ] ].
 */
class Transformers {

	/** @var array|null */
	private $registry = null;

	public function registry() {
		if ( null === $this->registry ) {
			$core = array(
				'text'        => array( 'label' => __( 'Single line text', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'text' ) ),
				'multiline'   => array( 'label' => __( 'Multiple lines of text', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'multiline' ) ),
				'number'      => array( 'label' => __( 'Number', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'number' ) ),
				'date'        => array( 'label' => __( 'Date (YYYY-MM-DD)', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'date' ) ),
				'datetime'    => array( 'label' => __( 'Date & time (ISO 8601)', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'datetime' ) ),
				'boolean'     => array( 'label' => __( 'Yes / No', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'boolean' ) ),
				'choice'      => array( 'label' => __( 'Choice', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'text' ) ),
				'multichoice' => array( 'label' => __( 'Multi choice (array)', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'multichoice' ) ),
				'email'       => array( 'label' => __( 'Email', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'email' ) ),
				'phone'       => array( 'label' => __( 'Phone', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'phone' ) ),
				'url'         => array( 'label' => __( 'Hyperlink', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'url' ) ),
				'raw'         => array( 'label' => __( 'Raw (no change)', 'fluent-sharepoint-sync' ), 'callback' => array( $this, 'raw' ) ),
			);
			$this->registry = (array) apply_filters( 'ffsp/transformers', $core );
		}
		return $this->registry;
	}

	public function options() {
		$out = array();
		foreach ( $this->registry() as $k => $t ) {
			$out[ $k ] = $t['label'];
		}
		return $out;
	}

	public function apply( $type, $value ) {
		$reg = $this->registry();
		$cb  = isset( $reg[ $type ]['callback'] ) ? $reg[ $type ]['callback'] : $reg['text']['callback'];
		return call_user_func( $cb, $value );
	}

	/* ---- core transformers ---------------------------------------------- */

	public static function flatten( $value, $glue = ', ' ) {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $v ) {
				$v = self::flatten( $v, $glue );
				if ( '' !== $v ) {
					$parts[] = $v;
				}
			}
			return implode( $glue, $parts );
		}
		return null === $value ? '' : trim( (string) $value );
	}

	public function text( $v ) {
		// SharePoint single line text max = 255 chars.
		return mb_substr( sanitize_text_field( self::flatten( $v ) ), 0, 255 );
	}

	public function multiline( $v ) {
		return sanitize_textarea_field( self::flatten( $v, "\n" ) );
	}

	public function number( $v ) {
		$v = str_replace( array( ',', ' ' ), '', self::flatten( $v ) );
		return is_numeric( $v ) ? $v + 0 : null;
	}

	public function date( $v ) {
		$ts = $this->to_timestamp( $v );
		return $ts ? wp_date( 'Y-m-d', $ts ) : null;
	}

	public function datetime( $v ) {
		$ts = $this->to_timestamp( $v );
		return $ts ? wp_date( 'c', $ts ) : null;
	}

	private function to_timestamp( $v ) {
		$v = self::flatten( $v );
		if ( '' === $v ) {
			return 0;
		}
		// Support d/m/Y (common in MY forms) before strtotime which reads m/d/Y.
		if ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m ) ) {
			$dt = \DateTime::createFromFormat( 'Y-m-d', "{$m[3]}-{$m[2]}-{$m[1]}", wp_timezone() );
			return $dt ? $dt->getTimestamp() : 0;
		}
		try {
			$dt = new \DateTime( $v, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	public function boolean( $v ) {
		$v = strtolower( self::flatten( $v ) );
		return in_array( $v, array( '1', 'yes', 'ya', 'true', 'on', 'checked', 'agree', 'accepted' ), true );
	}

	public function multichoice( $v ) {
		return array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $v ) ), 'strlen' ) );
	}

	public function email( $v ) {
		$v = sanitize_email( self::flatten( $v ) );
		return is_email( $v ) ? $v : '';
	}

	public function phone( $v ) {
		return preg_replace( '/[^\d+]/', '', self::flatten( $v ) );
	}

	public function url( $v ) {
		return esc_url_raw( self::flatten( $v ) );
	}

	public function raw( $v ) {
		return $v;
	}
}
