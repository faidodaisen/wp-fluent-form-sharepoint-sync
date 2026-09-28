<?php
namespace FFSP\Sync;

use FFSP\Files\FileHandler;
use FFSP\Fluent\FormGateway;
use FFSP\Mapping\FieldMapper;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON contract sent to Power Automate.
 *
 * {
 *   "schemaVersion": "1.0",
 *   "requestId": "uuid",           // idempotency key (stable per integration+submission)
 *   "event": "submission.created",
 *   "environment": "production",
 *   "destination": "list|library|both",
 *   "source": { site, formId, formTitle, submissionId, serialNumber, submittedAt, entryUrl, sourceUrl },
 *   "sharepoint": { title, folderPath },
 *   "fields": { "<SharePointColumn>": value, ... },
 *   "files": [ { field, name, size, mimeType, sha256, downloadUrl, expiresAt, contentBytes? } ],
 *   "meta": { ip, browser, device, userId },   // when include_meta
 *   "raw": { ...all fluent values... }         // only when only_mapped = off
 * }
 */
class PayloadBuilder {

	const SCHEMA_VERSION = '1.0';

	/** @var FormGateway */
	private $forms;

	/** @var FieldMapper */
	private $mapper;

	/** @var FileHandler */
	private $files;

	public function __construct( FormGateway $forms, FieldMapper $mapper, FileHandler $files ) {
		$this->forms  = $forms;
		$this->mapper = $mapper;
		$this->files  = $files;
	}

	/**
	 * @return array{payload: array, errors: array}|\WP_Error
	 */
	public function build( array $integration, $submission_id, $request_id, $event = 'submission.created' ) {
		$submission = $this->forms->submission( $submission_id );
		if ( ! $submission ) {
			return new \WP_Error( 'ffsp_no_submission', __( 'Submission not found (deleted?).', 'fluent-sharepoint-sync' ) );
		}
		if ( (int) $submission['form_id'] !== (int) $integration['form_id'] ) {
			return new \WP_Error( 'ffsp_form_mismatch', __( 'This entry belongs to a different form than the integration.', 'fluent-sharepoint-sync' ) );
		}
		$form = $this->forms->form( $submission['form_id'] );
		if ( ! $form ) {
			return new \WP_Error( 'ffsp_no_form', __( 'Form not found.', 'fluent-sharepoint-sync' ) );
		}

		$fields     = (array) $submission['fields'];
		$form_title = (string) $form->title;
		$submitted  = $submission['created_at'] ? gmdate( 'c', strtotime( $submission['created_at'] . ' UTC' ) ) : gmdate( 'c' );

		$context = array(
			'submission_id' => (int) $submission['id'],
			'serial_number' => (int) $submission['serial_number'],
			'form_id'       => (int) $form->id,
			'form_title'    => $form_title,
			'submitted_at'  => $submitted,
			'source_url'    => (string) $submission['source_url'],
			'entry_url'     => $this->forms->entry_admin_url( $form->id, $submission['id'] ),
			'site_url'      => home_url( '/' ),
			'date'          => wp_date( 'Y-m-d' ),
			'year'          => wp_date( 'Y' ),
			'month'         => wp_date( 'm' ),
		);

		$mapped = $this->mapper->map( $integration['mapping'], $fields, $context );
		$errors = $mapped['errors'];

		// Files.
		$files = array();
		// Files are sent for every destination: list => attachments, library/both => documents.
		if ( $integration['options']['send_files'] ) {
			foreach ( $this->forms->fields( $form ) as $key => $def ) {
				if ( ! $def['is_file'] || empty( $fields[ $key ] ) ) {
					continue;
				}
				$res    = $this->files->describe( $key, $fields[ $key ], $integration, $submission_id );
				$files  = array_merge( $files, $res['files'] );
				$errors = array_merge( $errors, $res['errors'] );
			}
		}

		$payload = array(
			'schemaVersion' => self::SCHEMA_VERSION,
			'requestId'     => $request_id,
			'event'         => $event,
			'environment'   => $integration['environment'],
			'destination'   => $integration['destination'],
			'integration'   => array(
				'id'   => (int) $integration['id'],
				'slug' => $integration['slug'],
			),
			'source'        => array(
				'site'         => home_url( '/' ),
				'formId'       => $context['form_id'],
				'formTitle'    => $form_title,
				'submissionId' => $context['submission_id'],
				'serialNumber' => $context['serial_number'],
				'submittedAt'  => $submitted,
				'entryUrl'     => $context['entry_url'],
				'sourceUrl'    => $context['source_url'],
			),
			'sharepoint'    => array(
				'title'      => mb_substr( FieldMapper::render_template( $integration['options']['title_template'], $context, $fields ), 0, 255 ),
				'folderPath' => $this->clean_folder( FieldMapper::render_template( $integration['options']['folder_template'], $context, $fields ) ),
			),
			'fields'        => (object) $mapped['fields'],
			'files'         => $files,
		);

		if ( $integration['options']['include_meta'] ) {
			$payload['meta'] = array(
				'ip'      => (string) $submission['ip'],
				'browser' => (string) $submission['browser'],
				'device'  => (string) $submission['device'],
				'userId'  => (int) $submission['user_id'],
			);
		}
		if ( ! $integration['options']['only_mapped'] ) {
			$raw = $fields;
			unset( $raw['_wp_http_referer'], $raw['__fluent_form_embded_post_id'], $raw['_fluentform_' . $form->id . '_fluentformnonce'] );
			foreach ( array_keys( $raw ) as $k ) {
				if ( 0 === strpos( (string) $k, '_' ) ) {
					unset( $raw[ $k ] );
				}
			}
			$payload['raw'] = $raw;
		}

		/**
		 * Final chance to alter the payload per integration.
		 */
		$payload = apply_filters( 'ffsp/payload', $payload, $integration, $submission, $form );

		return array(
			'payload' => $payload,
			'errors'  => $errors,
		);
	}

	private function clean_folder( $path ) {
		$parts = array();
		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $p ) {
			$p = trim( preg_replace( '/["*:<>?|#%~&{}]+/', '-', $p ), " .\t" );
			if ( '' !== $p ) {
				$parts[] = mb_substr( $p, 0, 120 );
			}
		}
		return implode( '/', $parts );
	}
}
