<?php
namespace FFSP\Queue;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Worker: processes a queued log row in the background.
 */
class QueueModule implements Module {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		add_action( Queue::HOOK, array( $this, 'process' ), 10, 2 );
	}

	/**
	 * @param int|array $log_id Log id (AS passes named args as the first param).
	 */
	public function process( $log_id, $manual = 0 ) {
		if ( is_array( $log_id ) ) {
			$manual = ! empty( $log_id['manual'] );
			$log_id = isset( $log_id['log_id'] ) ? $log_id['log_id'] : 0;
		}
		$this->plugin->get( 'sync' )->process( (int) $log_id, (bool) $manual );
	}
}
