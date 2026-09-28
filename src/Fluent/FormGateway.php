<?php
namespace FFSP\Fluent;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only adapter around Fluent Forms. All Fluent specifics live here, so a
 * Fluent Forms update only ever touches this one class.
 */
class FormGateway {

	/** Elements that never carry user data. */
	const SKIP_ELEMENTS = array( 'button', 'custom_html', 'section_break', 'shortcode', 'recaptcha', 'hcaptcha', 'turnstile', 'action_hook', 'form_step', 'container', 'custom_submit_button', 'save_progress_button' );

	/** Elements with uploaded files. */
	const FILE_ELEMENTS = array( 'input_file', 'input_image', 'featured_image', 'signature' );

	public function available() {
		return function_exists( 'wpFluent' );
	}

	/**
	 * @return array<int, string> id => title
	 */
	public function forms() {
		if ( ! $this->available() ) {
			return array();
		}
		$out = array();
		foreach ( wpFluent()->table( 'fluentform_forms' )->select( array( 'id', 'title' ) )->orderBy( 'id', 'DESC' )->get() as $f ) {
			$out[ (int) $f->id ] = $f->title;
		}
		return $out;
	}

	public function form( $form_id ) {
		if ( ! $this->available() ) {
			return null;
		}
		return wpFluent()->table( 'fluentform_forms' )->find( (int) $form_id );
	}

	/**
	 * Flattened list of data fields for a form.
	 * Composite fields (name, address) are exposed both as a whole and per sub-field
	 * using dot keys, e.g. `names.first_name`.
	 *
	 * @return array<string, array{key:string,label:string,element:string,is_file:bool,required:bool}>
	 */
	public function fields( $form_id ) {
		$form = is_object( $form_id ) ? $form_id : $this->form( $form_id );
		if ( ! $form ) {
			return array();
		}
		$decoded = json_decode( (string) $form->form_fields, true );
		$out     = array();
		$this->walk( isset( $decoded['fields'] ) ? $decoded['fields'] : array(), $out );
		return $out;
	}

	private function walk( array $fields, array &$out ) {
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$element = isset( $field['element'] ) ? $field['element'] : '';

			// Layout containers (columns) and repeat containers.
			if ( ! empty( $field['columns'] ) && is_array( $field['columns'] ) ) {
				foreach ( $field['columns'] as $col ) {
					$this->walk( isset( $col['fields'] ) ? (array) $col['fields'] : array(), $out );
				}
				continue;
			}
			if ( in_array( $element, self::SKIP_ELEMENTS, true ) ) {
				continue;
			}

			$name = isset( $field['attributes']['name'] ) ? (string) $field['attributes']['name'] : '';
			if ( '' === $name ) {
				continue;
			}

			$label = $this->label( $field, $name );
			$out[ $name ] = array(
				'key'      => $name,
				'label'    => $label,
				'element'  => $element,
				'is_file'  => in_array( $element, self::FILE_ELEMENTS, true ),
				'required' => ! empty( $field['settings']['validation_rules']['required']['value'] ),
			);

			// Composite sub-fields (input_name, address).
			if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) && in_array( $element, array( 'input_name', 'address' ), true ) ) {
				foreach ( $field['fields'] as $sub_key => $sub ) {
					if ( isset( $sub['settings']['visible'] ) && ! $sub['settings']['visible'] ) {
						continue;
					}
					$sub_name = isset( $sub['attributes']['name'] ) ? $sub['attributes']['name'] : $sub_key;
					$key      = $name . '.' . $sub_name;
					$out[ $key ] = array(
						'key'      => $key,
						'label'    => $label . ' › ' . $this->label( $sub, $sub_name ),
						'element'  => isset( $sub['element'] ) ? $sub['element'] : 'input_text',
						'is_file'  => false,
						'required' => ! empty( $sub['settings']['validation_rules']['required']['value'] ),
					);
				}
			}
		}
	}

	private function label( array $field, $fallback ) {
		foreach ( array( 'admin_field_label', 'label' ) as $k ) {
			if ( ! empty( $field['settings'][ $k ] ) ) {
				return wp_strip_all_tags( $field['settings'][ $k ] );
			}
		}
		if ( ! empty( $field['attributes']['placeholder'] ) ) {
			return wp_strip_all_tags( $field['attributes']['placeholder'] );
		}
		return $fallback;
	}

	/**
	 * Submission row + decoded response.
	 *
	 * @return array|null
	 */
	public function submission( $submission_id ) {
		if ( ! $this->available() ) {
			return null;
		}
		$row = wpFluent()->table( 'fluentform_submissions' )->find( (int) $submission_id );
		if ( ! $row ) {
			return null;
		}
		$row           = (array) $row;
		$row['fields'] = json_decode( (string) $row['response'], true ) ?: array();
		unset( $row['response'] );
		return $row;
	}

	/**
	 * Latest submission ids for a form (for the admin "test with real entry" picker).
	 */
	public function recent_submissions( $form_id, $limit = 10 ) {
		if ( ! $this->available() ) {
			return array();
		}
		return wpFluent()->table( 'fluentform_submissions' )
			->select( array( 'id', 'serial_number', 'created_at' ) )
			->where( 'form_id', (int) $form_id )
			->orderBy( 'id', 'DESC' )
			->limit( $limit )
			->get();
	}

	/**
	 * Entry admin URL in Fluent Forms.
	 */
	public function entry_admin_url( $form_id, $submission_id ) {
		return admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $form_id . '#/entries/' . (int) $submission_id );
	}

	/**
	 * Add a note to the Fluent entry timeline (best effort).
	 */
	public function add_entry_note( $submission_id, $form_id, $message, $status = 'info' ) {
		if ( ! $this->available() ) {
			return;
		}
		try {
			wpFluent()->table( 'fluentform_logs' )->insert(
				array(
					'parent_source_id' => (int) $form_id,
					'source_type'      => 'submission_item',
					'source_id'        => (int) $submission_id,
					'component'        => 'SharePoint Sync',
					'status'           => $status,
					'title'            => 'SharePoint Sync',
					'description'      => wp_kses_post( $message ),
					'created_at'       => current_time( 'mysql' ),
				)
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Never break the flow because of a note.
		}
	}
}
