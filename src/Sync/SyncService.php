<?php
namespace FFSP\Sync;

use FFSP\Queue\Queue;
use FFSP\Repository\IntegrationRepository;
use FFSP\Repository\LogRepository;
use FFSP\Security\Security;
use FFSP\Support\Debug;
use FFSP\Support\Settings;
use FFSP\Transport\HttpClient;
use FFSP\Transport\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates: submission -> log row -> (queue) -> payload -> HTTP -> log result -> retry.
 */
class SyncService {

	private $integrations;
	private $logs;
	private $payload;
	private $http;
	private $queue;
	private $settings;
	private $security;

	public function __construct( IntegrationRepository $integrations, LogRepository $logs, PayloadBuilder $payload, HttpClient $http, Queue $queue, Settings $settings, Security $security ) {
		$this->integrations = $integrations;
		$this->logs         = $logs;
		$this->payload      = $payload;
		$this->http         = $http;
		$this->queue        = $queue;
		$this->settings     = $settings;
		$this->security     = $security;
	}

	/**
	 * Called by the Fluent listener for every new entry.
	 */
	public function handle_new_submission( $form_id, $submission_id ) {
		$integrations = $this->integrations->active_for_form( $form_id );
		if ( ! $integrations ) {
			return;
		}

		foreach ( $integrations as $integration ) {
			/**
			 * Return false to skip this submission for this integration (e.g. conditional sync).
			 */
			if ( ! apply_filters( 'ffsp/should_sync', true, $integration, $submission_id, $form_id ) ) {
				continue;
			}

			$log = $this->logs->ensure( $integration['id'], $form_id, $submission_id );
			if ( ! $log || 'success' === $log['status'] ) {
				continue; // Already delivered: idempotent on the WordPress side.
			}

			if ( $this->settings->get( 'async' ) ) {
				$this->logs->update( $log['id'], array( 'status' => 'queued' ) );
				$this->queue->push( $log['id'] );
			} else {
				$this->process( $log['id'] );
			}
		}
	}

	/**
	 * Manual resend from admin (single or bulk). Resets attempts, keeps request id.
	 *
	 * @param bool $now Process immediately instead of queueing.
	 */
	public function resend( $log_id, $now = true ) {
		$log = $this->logs->find( $log_id );
		if ( ! $log ) {
			return new \WP_Error( 'ffsp_not_found', __( 'Log not found.', 'fluent-sharepoint-sync' ) );
		}
		if ( ! $this->logs->reset_for_resend( $log_id ) ) {
			return new \WP_Error( 'ffsp_locked', __( 'This entry is being sent right now. Try again in a moment.', 'fluent-sharepoint-sync' ) );
		}
		if ( $now ) {
			return $this->process( $log_id, true );
		}
		$this->queue->push( $log_id, 0, true ); // Worker keeps the manual override.
		return true;
	}

	/**
	 * Send one log row. Safe to call multiple times.
	 *
	 * @param bool $manual Manual resend (ignores integration "inactive" state).
	 * @return true|\WP_Error
	 */
	public function process( $log_id, $manual = false ) {
		$log = $this->logs->find( $log_id );
		if ( ! $log ) {
			return new \WP_Error( 'ffsp_not_found', 'Log not found' );
		}
		if ( 'success' === $log['status'] && ! $manual ) {
			return true;
		}

		// Atomic DB claim: two workers can never send the same row concurrently.
		if ( ! $this->logs->claim( $log_id, $manual, max( 120, (int) $this->settings->get( 'timeout' ) * 4 ) ) ) {
			return new \WP_Error( 'ffsp_locked', 'Already processing' );
		}
		$log = $this->logs->find( $log_id ); // Fresh snapshot after the claim.

		try {
			return $this->do_process( $log, $manual );
		} catch ( \Throwable $e ) {
			$this->logs->update(
				$log_id,
				array(
					'status'        => 'failed',
					'error_code'    => 'exception',
					'error_message' => mb_substr( $this->security->redact( $e->getMessage() ), 0, 1000 ),
				)
			);
			Debug::log( 'Exception on log ' . $log_id . ': ' . $this->security->redact( $e->getMessage() ) );
			return new \WP_Error( 'ffsp_exception', $this->security->redact( $e->getMessage() ) );
		}
	}

	private function do_process( array $log, $manual ) {
		$log_id      = (int) $log['id'];
		$integration = $this->integrations->find( $log['integration_id'], true );

		if ( ! $integration ) {
			$this->logs->update( $log_id, array( 'status' => 'skipped', 'error_code' => 'integration_missing', 'error_message' => __( 'Integration was deleted.', 'fluent-sharepoint-sync' ) ) );
			return new \WP_Error( 'ffsp_integration_missing', 'Integration missing' );
		}
		if ( 'active' !== $integration['status'] && ! $manual ) {
			$this->logs->update( $log_id, array( 'status' => 'skipped', 'error_code' => 'integration_inactive', 'error_message' => __( 'Integration is inactive.', 'fluent-sharepoint-sync' ) ) );
			return new \WP_Error( 'ffsp_inactive', 'Integration inactive' );
		}
		if ( ! $integration['endpoint'] ) {
			$this->logs->update( $log_id, array( 'status' => 'failed', 'error_code' => 'no_endpoint', 'error_message' => __( 'No endpoint configured.', 'fluent-sharepoint-sync' ) ) );
			return new \WP_Error( 'ffsp_no_endpoint', 'No endpoint' );
		}

		$attempt = (int) $log['attempt_count'] + 1;
		$this->logs->update( $log_id, array( 'status' => 'processing', 'attempt_count' => $attempt ) );

		$built = $this->payload->build( $integration, $log['submission_id'], $log['request_id'], $manual && (int) $log['attempt_count'] > 0 ? 'submission.resent' : 'submission.created' );
		if ( is_wp_error( $built ) ) {
			$this->logs->update( $log_id, array( 'status' => 'failed', 'error_code' => $built->get_error_code(), 'error_message' => $built->get_error_message() ) );
			return $built;
		}

		// Validation errors (required mapping empty, file missing) are permanent -> no retry.
		if ( $built['errors'] ) {
			$this->logs->update(
				$log_id,
				array(
					'status'        => 'failed',
					'error_code'    => 'validation',
					'error_message' => implode( "\n", $built['errors'] ),
					'payload'       => $this->store_payload( $built['payload'] ),
				)
			);
			do_action( 'ffsp/sync_failed', $log_id, $integration, $built['errors'] );
			return new \WP_Error( 'ffsp_validation', implode( ' ', $built['errors'] ) );
		}

		$response = $this->http->send( $integration, $built['payload'] );
		// Credentials must never reach logs, notes or debug.log, whatever the flow echoes back.
		$error   = mb_substr( $this->security->redact( $response->error, $integration ), 0, 2000 );
		$excerpt = $this->security->redact( $response->excerpt(), $integration );
		Debug::log( 'Sent log ' . $log_id, array( 'code' => $response->code, 'ms' => $response->duration, 'error' => $error ) );

		if ( $response->ok ) {
			$this->logs->update(
				$log_id,
				array(
					'status'           => 'success',
					'http_code'        => $response->code,
					'remote_item_id'   => $response->item_id(),
					'remote_item_url'  => $response->item_url(),
					'remote_files'     => wp_json_encode( $response->files() ),
					'error_code'       => '',
					'error_message'    => '',
					'response_excerpt' => $excerpt,
					'payload'          => $this->store_payload( $built['payload'] ),
					'duration_ms'      => $response->duration,
					'next_attempt_at'  => null,
				)
			);
			ffsp()->get( 'forms' )->add_entry_note( $log['submission_id'], $log['form_id'], sprintf( 'Sent to SharePoint via "%s"%s.', esc_html( $integration['name'] ), $response->item_id() ? ' — item #' . esc_html( $response->item_id() ) : '' ), 'success' );
			do_action( 'ffsp/sync_success', $log_id, $integration, $response );
			return true;
		}

		$max        = (int) $this->settings->get( 'max_attempts' );
		$retry_4xx  = ! empty( $integration['options']['retry_on_4xx'] ) && $response->code >= 400 && $response->code < 500;
		$will_retry = ( $response->retryable || $retry_4xx ) && $attempt < $max;
		$delay      = $will_retry ? $this->queue->backoff( $attempt, $response->retry_after ) : 0;

		$this->logs->update(
			$log_id,
			array(
				'status'           => $will_retry ? 'retrying' : 'failed',
				'http_code'        => $response->code,
				'error_code'       => $response->error_code,
				'error_message'    => $error,
				'response_excerpt' => $excerpt,
				'payload'          => $this->store_payload( $built['payload'] ),
				'duration_ms'      => $response->duration,
				'next_attempt_at'  => $will_retry ? gmdate( 'Y-m-d H:i:s', time() + $delay ) : null,
			)
		);

		if ( $will_retry ) {
			$this->queue->push( $log_id, $delay );
		} else {
			ffsp()->get( 'forms' )->add_entry_note( $log['submission_id'], $log['form_id'], sprintf( 'SharePoint sync failed via "%s": %s', esc_html( $integration['name'] ), esc_html( $error ) ), 'failed' );
			do_action( 'ffsp/sync_failed', $log_id, $integration, array( $error ) );
		}

		return new \WP_Error( 'ffsp_send_failed', $error );
	}

	/**
	 * Store a masked copy of the payload only when enabled; never store base64 content.
	 */
	private function store_payload( $payload ) {
		if ( ! $this->settings->get( 'log_payload' ) ) {
			return null;
		}
		$copy = json_decode( wp_json_encode( $payload ), true );
		if ( ! empty( $copy['files'] ) ) {
			foreach ( $copy['files'] as &$f ) {
				if ( isset( $f['contentBytes'] ) ) {
					$f['contentBytes'] = '[' . strlen( $f['contentBytes'] ) . ' base64 chars omitted]';
				}
				if ( isset( $f['downloadUrl'] ) ) {
					$f['downloadUrl'] = '[signed link]';
				}
			}
			unset( $f );
		}
		return wp_json_encode( $this->security->mask_deep( $copy ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	/**
	 * Send a synthetic test payload (admin "Test connection"). Nothing is logged.
	 *
	 * @return Response
	 */
	public function test_connection( $integration_id, $submission_id = 0 ) {
		$integration = $this->integrations->find( $integration_id, true );
		if ( ! $integration || ! $integration['endpoint'] ) {
			return Response::from_error( 'no_endpoint', __( 'Save an endpoint URL first.', 'fluent-sharepoint-sync' ), false );
		}

		if ( $submission_id ) {
			$built = $this->payload->build( $integration, $submission_id, 'test-' . wp_generate_uuid4(), 'connection.test' );
			if ( is_wp_error( $built ) ) {
				return Response::from_error( $built->get_error_code(), $built->get_error_message(), false );
			}
			$payload         = $built['payload'];
			$payload['test'] = true;
		} else {
			$payload = array(
				'schemaVersion' => PayloadBuilder::SCHEMA_VERSION,
				'requestId'     => 'test-' . wp_generate_uuid4(),
				'event'         => 'connection.test',
				'test'          => true,
				'environment'   => $integration['environment'],
				'destination'   => $integration['destination'],
				'integration'   => array( 'id' => (int) $integration['id'], 'slug' => $integration['slug'] ),
				'source'        => array( 'site' => home_url( '/' ), 'formId' => $integration['form_id'] ),
				'sharepoint'    => array( 'title' => 'Connection test', 'folderPath' => '' ),
				'fields'        => new \stdClass(),
				'files'         => array(),
			);
		}
		$r        = $this->http->send( $integration, $payload );
		$r->error = $this->security->redact( $r->error, $integration );
		$r->body  = $this->security->redact( $r->body, $integration );
		return $r;
	}
}
