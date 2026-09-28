<?php
namespace FFSP\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Fluent entry values to SharePoint column names.
 *
 * Mapping row shape:
 *   source   string  Fluent field key (`email`, `names.first_name`) or meta token (`@submission_id`) or `@static`
 *   target   string  SharePoint column internal name (e.g. `Email`, `ApplicantName`)
 *   type     string  Transformer slug (text, number, date...)
 *   required bool    Fail validation when empty
 *   default  string  Used when the value is empty
 *   value    string  Static value (source = @static). Supports {tokens}.
 */
class FieldMapper {

	/** @var Transformers */
	private $transformers;

	public function __construct( Transformers $transformers ) {
		$this->transformers = $transformers;
	}

	public function transformers() {
		return $this->transformers;
	}

	/**
	 * Meta sources that are always available.
	 */
	public static function meta_sources() {
		return array(
			'@submission_id' => __( 'Submission ID', 'fluent-sharepoint-sync' ),
			'@serial_number' => __( 'Entry serial number', 'fluent-sharepoint-sync' ),
			'@form_id'       => __( 'Form ID', 'fluent-sharepoint-sync' ),
			'@form_title'    => __( 'Form title', 'fluent-sharepoint-sync' ),
			'@submitted_at'  => __( 'Submitted at (ISO 8601)', 'fluent-sharepoint-sync' ),
			'@source_url'    => __( 'Page URL', 'fluent-sharepoint-sync' ),
			'@entry_url'     => __( 'WP admin entry link', 'fluent-sharepoint-sync' ),
			'@site_url'      => __( 'Site URL', 'fluent-sharepoint-sync' ),
			'@static'        => __( '— Static value —', 'fluent-sharepoint-sync' ),
		);
	}

	public static function sanitize_mapping( array $rows ) {
		$out  = array();
		$seen = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$source = sanitize_text_field( $row['source'] ?? '' );
			// SharePoint internal names: letters, digits, underscore, _x0020_ escapes.
			$target = preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $row['target'] ?? '' ) );
			if ( '' === $source || '' === $target || isset( $seen[ $target ] ) ) {
				continue;
			}
			$seen[ $target ] = true;
			$out[]           = array(
				'source'   => $source,
				'target'   => $target,
				'type'     => sanitize_key( $row['type'] ?? 'text' ) ?: 'text',
				'required' => empty( $row['required'] ) ? 0 : 1,
				'default'  => sanitize_text_field( $row['default'] ?? '' ),
				'value'    => sanitize_text_field( $row['value'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Read a value from the submission using a dot key.
	 */
	public static function read( array $fields, $key ) {
		if ( array_key_exists( $key, $fields ) ) {
			return $fields[ $key ];
		}
		$value = $fields;
		foreach ( explode( '.', $key ) as $part ) {
			if ( is_array( $value ) && array_key_exists( $part, $value ) ) {
				$value = $value[ $part ];
			} else {
				return null;
			}
		}
		return $value;
	}

	/**
	 * Replace {tokens} in a template using context + fields.
	 */
	public static function render_template( $template, array $context, array $fields = array() ) {
		return preg_replace_callback(
			'/\{([a-zA-Z0-9_.\-]+)\}/',
			static function ( $m ) use ( $context, $fields ) {
				if ( isset( $context[ $m[1] ] ) ) {
					return Transformers::flatten( $context[ $m[1] ] );
				}
				$v = self::read( $fields, $m[1] );
				return null === $v ? '' : Transformers::flatten( $v );
			},
			(string) $template
		);
	}

	/**
	 * @param array $mapping Mapping rows.
	 * @param array $fields  Submission field values.
	 * @param array $context Meta values (submission_id, form_title...).
	 * @return array{fields: array, errors: array}
	 */
	public function map( array $mapping, array $fields, array $context ) {
		$out    = array();
		$errors = array();

		foreach ( $mapping as $row ) {
			$source = $row['source'];
			if ( '@static' === $source ) {
				// Static rows keep their value in `default` (admin UI) or legacy `value`.
				$raw = self::render_template( '' !== $row['value'] ? $row['value'] : $row['default'], $context, $fields );
				$row['default'] = '';
			} elseif ( 0 === strpos( $source, '@' ) ) {
				$raw = isset( $context[ substr( $source, 1 ) ] ) ? $context[ substr( $source, 1 ) ] : null;
			} else {
				$raw = self::read( $fields, $source );
			}

			if ( ( null === $raw || '' === $raw || array() === $raw ) && '' !== $row['default'] ) {
				$raw = self::render_template( $row['default'], $context, $fields );
			}

			$value = $this->transformers->apply( $row['type'], $raw );

			if ( $row['required'] && ( null === $value || '' === $value || array() === $value ) ) {
				/* translators: 1: SharePoint column, 2: source */
				$errors[] = sprintf( __( 'Required column "%1$s" is empty (source: %2$s).', 'fluent-sharepoint-sync' ), $row['target'], $source );
			}

			$out[ $row['target'] ] = $value;
		}

		return array(
			'fields' => $out,
			'errors' => $errors,
		);
	}
}
