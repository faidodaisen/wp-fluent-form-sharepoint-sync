<?php
namespace FFSP\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Background job dispatcher. Uses Action Scheduler (bundled with Fluent Forms / WooCommerce)
 * when available, otherwise falls back to single WP-Cron events.
 */
class Queue {

	const HOOK  = 'ffsp_process_log';
	const GROUP = 'fluent-sharepoint-sync';

	public function uses_action_scheduler() {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Schedule processing of a log row.
	 *
	 * @param int $log_id Log id.
	 * @param int $delay  Seconds from now.
	 */
	public function push( $log_id, $delay = 0, $manual = false ) {
		$args = array( 'log_id' => (int) $log_id );
		if ( $manual ) {
			$args['manual'] = 1; // Positional arg #2 for the worker.
		}
		$when = time() + max( 0, (int) $delay );

		if ( $this->uses_action_scheduler() ) {
			// Only a *pending* job counts as a duplicate. as_has_scheduled_action() also matches the
			// running action, which would stop a worker from scheduling its own retry.
			$pending = function_exists( 'as_get_scheduled_actions' ) && class_exists( '\ActionScheduler_Store' ) ? as_get_scheduled_actions(
				array(
					'hook'     => self::HOOK,
					'args'     => $args,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
				),
				'ids'
			) : array();
			if ( $pending ) {
				return;
			}
			if ( 0 === (int) $delay && function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			} else {
				as_schedule_single_action( $when, self::HOOK, $args, self::GROUP );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( $when, self::HOOK, $args );
		}
	}

	/**
	 * Remove every queued job of this plugin (any arguments).
	 */
	public static function clear_all() {
		wp_unschedule_hook( self::HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/**
	 * Exponential backoff with jitter: 1m, 5m, 15m, 1h, 3h ...
	 */
	public function backoff( $attempt, $retry_after = 0 ) {
		$steps = array( 60, 300, 900, 3600, 10800, 21600 );
		$base  = $steps[ min( max( 0, $attempt - 1 ), count( $steps ) - 1 ) ];
		$base  = max( $base, (int) $retry_after );
		return $base + wp_rand( 0, (int) ( $base * 0.2 ) );
	}
}
