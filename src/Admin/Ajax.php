<?php
namespace FFSP\Admin;

use FFSP\Mapping\FieldMapper;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Small AJAX endpoints for the integration editor.
 */
class Ajax {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( 'wp_ajax_ffsp_form_fields', array( $this, 'form_fields' ) );
		add_action( 'wp_ajax_ffsp_test', array( $this, 'test' ) );
		add_action( 'wp_ajax_ffsp_preview', array( $this, 'preview' ) );
	}

	private function guard() {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'ffsp_ajax', 'nonce' );
	}

	/**
	 * Fields + recent entries for a form.
	 */
	public function form_fields() {
		$this->guard();
		$form_id = absint( $_POST['form_id'] ?? 0 );
		$forms   = $this->plugin->get( 'forms' );
		$fields  = array();
		foreach ( $forms->fields( $form_id ) as $f ) {
			$fields[] = $f;
		}
		$subs = array();
		foreach ( $forms->recent_submissions( $form_id ) as $s ) {
			$subs[] = array(
				'id'    => (int) $s->id,
				'label' => '#' . $s->serial_number . ' — ' . $s->created_at,
			);
		}
		wp_send_json_success(
			array(
				'fields'      => $fields,
				'meta'        => FieldMapper::meta_sources(),
				'submissions' => $subs,
			)
		);
	}

	public function test() {
		$this->guard();
		$r = $this->plugin->get( 'sync' )->test_connection( absint( $_POST['id'] ?? 0 ), absint( $_POST['submission_id'] ?? 0 ) );
		wp_send_json_success(
			array(
				'ok'       => $r->ok,
				'code'     => $r->code,
				'ms'       => $r->duration,
				'error'    => $r->error,
				'response' => $r->excerpt( 1500 ),
			)
		);
	}

	/**
	 * Build (not send) the payload for a real entry so the admin sees exactly what goes out.
	 */
	public function preview() {
		$this->guard();
		$integration = $this->plugin->get( 'integrations' )->find( absint( $_POST['id'] ?? 0 ), true );
		if ( ! $integration ) {
			wp_send_json_error( array( 'message' => 'Integration not found' ) );
		}
		$built = $this->plugin->get( 'payload' )->build( $integration, absint( $_POST['submission_id'] ?? 0 ), 'preview', 'preview' );
		if ( is_wp_error( $built ) ) {
			wp_send_json_error( array( 'message' => $built->get_error_message() ) );
		}
		foreach ( $built['payload']['files'] as &$f ) {
			if ( isset( $f['contentBytes'] ) ) {
				$f['contentBytes'] = '[' . strlen( $f['contentBytes'] ) . ' base64 chars]';
			}
		}
		unset( $f );
		wp_send_json_success(
			array(
				'payload' => wp_json_encode( $built['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'errors'  => $built['errors'],
			)
		);
	}
}
