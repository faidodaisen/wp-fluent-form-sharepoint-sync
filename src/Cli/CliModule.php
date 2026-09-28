<?php
namespace FFSP\Cli;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI commands:
 *   wp ffsp status
 *   wp ffsp integrations
 *   wp ffsp send <submission_id> [--integration=<id>]   Send now (sync) for one entry.
 *   wp ffsp resend <log_id>...                          Resend logs now.
 *   wp ffsp retry-failed [--limit=<n>]                  Resend all failed logs.
 *   wp ffsp test <integration_id> [--submission=<id>]   Test connection.
 */
class CliModule implements Module {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ffsp', $this );
		}
	}

	/**
	 * Show sync counts.
	 */
	public function status() {
		$counts = $this->plugin->get( 'logs' )->counts();
		foreach ( $counts as $k => $v ) {
			\WP_CLI::line( str_pad( $k, 12 ) . $v );
		}
		\WP_CLI::line( 'queue: ' . ( $this->plugin->get( 'queue' )->uses_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ) );
	}

	/**
	 * List integrations.
	 */
	public function integrations() {
		$rows = array_map(
			static function ( $i ) {
				return array(
					'id'          => $i['id'],
					'name'        => $i['name'],
					'form_id'     => $i['form_id'],
					'status'      => $i['status'],
					'environment' => $i['environment'],
					'destination' => $i['destination'],
					'endpoint'    => $i['endpoint_masked'],
					'mappings'    => count( $i['mapping'] ),
				);
			},
			$this->plugin->get( 'integrations' )->all()
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'form_id', 'status', 'environment', 'destination', 'endpoint', 'mappings' ) );
	}

	/**
	 * Send one submission synchronously.
	 *
	 * <submission_id>
	 * [--integration=<id>]
	 */
	public function send( $args, $assoc ) {
		$sub = $this->plugin->get( 'forms' )->submission( (int) $args[0] );
		if ( ! $sub ) {
			\WP_CLI::error( 'Submission not found' );
		}
		$ints = $this->plugin->get( 'integrations' )->active_for_form( $sub['form_id'] );
		if ( ! empty( $assoc['integration'] ) ) {
			$ints = array_filter(
				$ints,
				static function ( $i ) use ( $assoc ) {
					return (int) $i['id'] === (int) $assoc['integration'];
				}
			);
		}
		if ( ! $ints ) {
			\WP_CLI::error( 'No active integration for form ' . $sub['form_id'] );
		}
		foreach ( $ints as $i ) {
			$log = $this->plugin->get( 'logs' )->ensure( $i['id'], $sub['form_id'], $sub['id'] );
			$res = $this->plugin->get( 'sync' )->resend( $log['id'], true );
			$this->report( $log['id'], $res );
		}
	}

	/**
	 * Resend logs now.
	 *
	 * <log_id>...
	 */
	public function resend( $args ) {
		foreach ( $args as $id ) {
			$this->report( (int) $id, $this->plugin->get( 'sync' )->resend( (int) $id, true ) );
		}
	}

	/**
	 * Resend all failed logs.
	 *
	 * [--limit=<n>]
	 *
	 * @subcommand retry-failed
	 */
	public function retry_failed( $args, $assoc ) {
		$ids = $this->plugin->get( 'logs' )->ids_by_status( 'failed', (int) ( $assoc['limit'] ?? 100 ) );
		foreach ( $ids as $id ) {
			$this->report( $id, $this->plugin->get( 'sync' )->resend( $id, true ) );
		}
		\WP_CLI::success( count( $ids ) . ' processed' );
	}

	/**
	 * Test an integration endpoint.
	 *
	 * <integration_id>
	 * [--submission=<id>]
	 */
	public function test( $args, $assoc ) {
		$r = $this->plugin->get( 'sync' )->test_connection( (int) $args[0], (int) ( $assoc['submission'] ?? 0 ) );
		if ( $r->ok ) {
			\WP_CLI::success( 'HTTP ' . $r->code . ' in ' . $r->duration . 'ms: ' . $r->excerpt( 300 ) );
		} else {
			\WP_CLI::error( ( $r->code ? 'HTTP ' . $r->code . ' ' : '' ) . $r->error, false );
		}
	}

	private function report( $log_id, $res ) {
		$log = $this->plugin->get( 'logs' )->find( $log_id );
		$msg = sprintf( 'log #%d → %s (HTTP %d) %s', $log_id, $log['status'] ?? '?', $log['http_code'] ?? 0, $log['remote_item_id'] ? 'item ' . $log['remote_item_id'] : '' );
		if ( true === $res ) {
			\WP_CLI::success( $msg );
		} else {
			\WP_CLI::warning( $msg . ' — ' . ( is_wp_error( $res ) ? $res->get_error_message() : '' ) );
		}
	}
}
