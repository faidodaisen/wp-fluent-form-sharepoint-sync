<?php
namespace FFSP\Fluent;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to Fluent Forms after the entry is saved, then hands off to the sync service.
 * The visitor's submission is never blocked: work is queued (async) by default.
 */
class SubmissionListener implements Module {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		// Fired after the entry + entry details are stored.
		add_action( 'fluentform/submission_inserted', array( $this, 'on_submission' ), 20, 3 );
	}

	/**
	 * @param int    $submission_id Entry id.
	 * @param array  $form_data     Posted data.
	 * @param object $form          Form row.
	 */
	public function on_submission( $submission_id, $form_data, $form ) {
		try {
			$form_id = is_object( $form ) ? (int) $form->id : (int) $form;
			if ( ! $form_id || ! $submission_id ) {
				return;
			}
			$this->plugin->get( 'sync' )->handle_new_submission( $form_id, (int) $submission_id );
		} catch ( \Throwable $e ) {
			// Never break the visitor's form submit.
			\FFSP\Support\Debug::log( 'Listener error: ' . $e->getMessage() );
		}
	}
}
