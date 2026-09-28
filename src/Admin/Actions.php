<?php
namespace FFSP\Admin;

use FFSP\Dev\MockReceiver;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * admin-post.php handlers. Every handler: capability + nonce check, then PRG redirect.
 */
class Actions {

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function register() {
		$map = array(
			'ffsp_save_integration'   => 'save_integration',
			'ffsp_delete_integration' => 'delete_integration',
			'ffsp_toggle_integration' => 'toggle_integration',
			'ffsp_rotate_secret'      => 'rotate_secret',
			'ffsp_save_settings'      => 'save_settings',
			'ffsp_log_action'         => 'log_action',
			'ffsp_mock_clear'         => 'mock_clear',
			'ffsp_use_mock'           => 'use_mock',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'admin_post_' . $action, array( $this, $method ) );
		}
	}

	private function guard( $action ) {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'Not allowed.', 'fluent-sharepoint-sync' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function flash( $message, $type = 'success' ) {
		set_transient(
			'ffsp_flash_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
	}

	private function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------ */

	public function save_integration() {
		$this->guard( 'ffsp_save_integration' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$post = wp_unslash( $_POST );

		$data = array(
			'name'        => $post['name'] ?? '',
			'form_id'     => $post['form_id'] ?? 0,
			'status'      => $post['status'] ?? 'inactive',
			'environment' => $post['environment'] ?? 'development',
			'destination' => $post['destination'] ?? 'list',
			'auth_mode'   => $post['auth_mode'] ?? 'hmac',
			'endpoint'    => $post['endpoint'] ?? '',
			'secret'      => $post['secret'] ?? '',
			'options'     => (array) ( $post['options'] ?? array() ),
			'mapping'     => array_values( (array) ( $post['mapping'] ?? array() ) ),
		);
		// Unchecked checkboxes are absent.
		foreach ( array( 'send_files', 'include_meta', 'only_mapped', 'retry_on_4xx' ) as $cb ) {
			$data['options'][ $cb ] = empty( $data['options'][ $cb ] ) ? 0 : 1;
		}

		$result = $this->plugin->get( 'integrations' )->save( $data, $id );
		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message(), 'error' );
			// Keep what the user typed (except secrets) so nothing is lost.
			unset( $data['endpoint'], $data['secret'] );
			set_transient( 'ffsp_form_state_' . get_current_user_id(), $data, 120 );
			$this->redirect( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $id ) ) );
		}

		self::flash( __( 'Integration saved.', 'fluent-sharepoint-sync' ) );
		$this->redirect( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $result ) ) );
	}

	public function delete_integration() {
		$this->guard( 'ffsp_delete_integration' );
		$this->plugin->get( 'integrations' )->delete( absint( $_GET['id'] ?? 0 ) );
		self::flash( __( 'Integration deleted. Its logs are kept.', 'fluent-sharepoint-sync' ) );
		$this->redirect( Admin::url( 'integrations' ) );
	}

	public function toggle_integration() {
		$this->guard( 'ffsp_toggle_integration' );
		$id     = absint( $_GET['id'] ?? 0 );
		$status = 'active' === sanitize_key( wp_unslash( $_GET['to'] ?? '' ) ) ? 'active' : 'inactive';
		if ( $this->plugin->get( 'integrations' )->set_status( $id, $status ) ) {
			self::flash( 'active' === $status ? __( 'Integration activated.', 'fluent-sharepoint-sync' ) : __( 'Integration paused.', 'fluent-sharepoint-sync' ) );
		} else {
			self::flash( __( 'Could not change status — make sure an endpoint URL is saved.', 'fluent-sharepoint-sync' ), 'error' );
		}
		$this->redirect( wp_get_referer() ? wp_get_referer() : Admin::url( 'integrations' ) );
	}

	public function rotate_secret() {
		$this->guard( 'ffsp_rotate_secret' );
		$id     = absint( $_GET['id'] ?? 0 );
		$secret = $this->plugin->get( 'integrations' )->rotate_secret( $id );
		if ( is_wp_error( $secret ) ) {
			self::flash( $secret->get_error_message(), 'error' );
			$this->redirect( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $id ) ) );
		}
		// Show the new secret once so it can be copied into the flow (encrypted at rest, bound to user + integration).
		set_transient( 'ffsp_reveal_' . get_current_user_id(), $this->plugin->get( 'security' )->encrypt( $id . '|' . $secret ), 120 );
		self::flash( __( 'New shared key generated. Copy it into your Power Automate flow now — it will be hidden after you leave this page.', 'fluent-sharepoint-sync' ), 'warning' );
		$this->redirect( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $id ) ) );
	}

	public function save_settings() {
		$this->guard( 'ffsp_save_settings' );
		// Settings::sanitize() whitelists and casts every key.
		$this->plugin->get( 'settings' )->update( (array) wp_unslash( $_POST['settings'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::flash( __( 'Settings saved.', 'fluent-sharepoint-sync' ) );
		$this->redirect( Admin::url( 'settings' ) );
	}

	public function log_action() {
		$this->guard( 'ffsp_log_action' );
		$do  = sanitize_key( $_REQUEST['do'] ?? '' );
		$ids = array_filter( array_map( 'absint', (array) ( $_REQUEST['ids'] ?? array() ) ) );
		if ( ! empty( $_REQUEST['id'] ) ) {
			$ids[] = absint( $_REQUEST['id'] );
		}
		$ids = array_unique( $ids );

		if ( ! $ids ) {
			self::flash( __( 'No logs selected.', 'fluent-sharepoint-sync' ), 'warning' );
		} elseif ( 'resend' === $do ) {
			$ok   = 0;
			$sync = $this->plugin->get( 'sync' );
			// Up to 10 inline so the admin sees the result; rest queued in background.
			foreach ( $ids as $i => $id ) {
				if ( $i < 10 ) {
					$ok += true === $sync->resend( $id, true ) ? 1 : 0;
				} else {
					$sync->resend( $id, false );
				}
			}
			$queued = max( 0, count( $ids ) - 10 );
			/* translators: 1: sent ok, 2: attempted inline, 3: queued */
			self::flash( sprintf( __( 'Resent: %1$d of %2$d succeeded. %3$d queued in background.', 'fluent-sharepoint-sync' ), $ok, min( 10, count( $ids ) ), $queued ), $ok === min( 10, count( $ids ) ) ? 'success' : 'warning' );
		} elseif ( 'delete' === $do ) {
			$n = $this->plugin->get( 'logs' )->delete( $ids );
			/* translators: %d count */
			self::flash( sprintf( __( '%d log(s) deleted.', 'fluent-sharepoint-sync' ), $n ) );
		}

		$back = wp_get_referer() ? remove_query_arg( array( 'view' ), wp_get_referer() ) : Admin::url( 'logs' );
		$this->redirect( $back );
	}

	public function mock_clear() {
		$this->guard( 'ffsp_mock_clear' );
		MockReceiver::clear();
		self::flash( __( 'Mock inbox cleared.', 'fluent-sharepoint-sync' ) );
		$this->redirect( Admin::url( 'mock' ) );
	}

	/**
	 * One click: point an integration at the local mock receiver.
	 */
	public function use_mock() {
		$this->guard( 'ffsp_use_mock' );
		$id   = absint( $_GET['id'] ?? 0 );
		$item = $this->plugin->get( 'integrations' )->find( $id, true );
		if ( ! $item ) {
			$this->redirect( Admin::url( 'integrations' ) );
		}
		$fail = sanitize_key( $_GET['fail'] ?? '' );
		$item['endpoint'] = MockReceiver::url( $fail ? array( 'fail' => $fail ) : array() );
		$result           = $this->plugin->get( 'integrations' )->save( $item, $id );
		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message() . ' ' . __( '(Tip: enable "Allow insecure / local endpoints" in Settings for local testing.)', 'fluent-sharepoint-sync' ), 'error' );
		} else {
			self::flash( __( 'Endpoint set to the local mock receiver.', 'fluent-sharepoint-sync' ) );
		}
		$this->redirect( Admin::url( 'integrations', array( 'action' => 'edit', 'id' => $id ) ) );
	}
}
