<?php
namespace FFSP\Admin;

use FFSP\Contracts\Module;
use FFSP\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin module: menu, assets, page routing, notices. Handlers live in Actions/Ajax.
 */
class Admin implements Module {

	const SLUG = 'ffsp';

	/** @var Plugin */
	private $plugin;

	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public static function capability() {
		return apply_filters( 'ffsp/capability', 'manage_options' );
	}

	public static function url( $tab = 'dashboard', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public function register() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'menu' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FFSP_FILE ), array( $this, 'action_links' ) );

		( new Actions( $this->plugin ) )->register();
		( new Ajax( $this->plugin ) )->register();
	}

	public function menu() {
		$title = __( 'SharePoint Sync', 'fluent-sharepoint-sync' );
		global $admin_page_hooks;

		if ( isset( $admin_page_hooks['fluent_forms'] ) ) {
			add_submenu_page( 'fluent_forms', $title, $title, self::capability(), self::SLUG, array( $this, 'render' ) );
		} else {
			add_menu_page( $title, $title, self::capability(), self::SLUG, array( $this, 'render' ), 'dashicons-cloud-upload', 58 );
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open', 'fluent-sharepoint-sync' ) . '</a>' );
		return $links;
	}

	private function is_our_page() {
		return isset( $_GET['page'] ) && self::SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public function assets() {
		if ( ! $this->is_our_page() ) {
			return;
		}
		wp_enqueue_style( 'ffsp-admin', FFSP_URL . 'assets/admin.css', array(), FFSP_VERSION );
		wp_enqueue_script( 'ffsp-admin', FFSP_URL . 'assets/admin.js', array(), FFSP_VERSION, true );
		wp_localize_script(
			'ffsp-admin',
			'FFSP',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'ffsp_ajax' ),
				'i18n'  => array(
					'testing'  => __( 'Testing…', 'fluent-sharepoint-sync' ),
					'confirm'  => __( 'Are you sure?', 'fluent-sharepoint-sync' ),
					'copied'   => __( 'Copied', 'fluent-sharepoint-sync' ),
					'loading'  => __( 'Loading fields…', 'fluent-sharepoint-sync' ),
				),
			)
		);
	}

	public function notices() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		if ( ! Plugin::fluent_active() ) {
			echo '<div class="notice notice-warning"><p><strong>SharePoint Sync:</strong> ' . esc_html__( 'Fluent Forms is not active. Submissions will not be synced until it is activated.', 'fluent-sharepoint-sync' ) . '</p></div>';
		}
		if ( ! $this->is_our_page() ) {
			return;
		}
		// Flash message set by Actions::redirect().
		$flash = get_transient( 'ffsp_flash_' . get_current_user_id() );
		if ( $flash ) {
			delete_transient( 'ffsp_flash_' . get_current_user_id() );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $flash['type'] ), wp_kses_post( $flash['message'] ) );
		}
	}

	public static function tabs() {
		$tabs = array(
			'dashboard'    => __( 'Dashboard', 'fluent-sharepoint-sync' ),
			'integrations' => __( 'Integrations', 'fluent-sharepoint-sync' ),
			'logs'         => __( 'Sync Logs', 'fluent-sharepoint-sync' ),
			'settings'     => __( 'Settings', 'fluent-sharepoint-sync' ),
		);
		if ( \FFSP\Dev\MockReceiver::enabled() ) {
			$tabs['mock'] = __( 'Mock Receiver', 'fluent-sharepoint-sync' );
		}
		$tabs['help'] = __( 'Setup Guide', 'fluent-sharepoint-sync' );
		return apply_filters( 'ffsp/admin_tabs', $tabs );
	}

	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'fluent-sharepoint-sync' ) );
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'dashboard';
		}

		$pages = apply_filters(
			'ffsp/admin_pages',
			array(
				'dashboard'    => Pages\Dashboard::class,
				'integrations' => Pages\Integrations::class,
				'logs'         => Pages\Logs::class,
				'settings'     => Pages\Settings::class,
				'mock'         => Pages\Mock::class,
				'help'         => Pages\Help::class,
			)
		);

		echo '<div class="wrap ffsp-wrap">';
		echo '<div class="ffsp-header"><span class="dashicons dashicons-cloud-upload"></span><h1>' . esc_html__( 'Fluent Forms → SharePoint', 'fluent-sharepoint-sync' ) . '</h1><span class="ffsp-version">v' . esc_html( FFSP_VERSION ) . '</span>';
		echo '<span class="ffsp-env ffsp-env--' . esc_attr( wp_get_environment_type() ) . '">' . esc_html( wp_get_environment_type() ) . '</span></div>';
		echo '<nav class="nav-tab-wrapper ffsp-tabs">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab %s">%s</a>', esc_url( self::url( $key ) ), $key === $tab ? 'nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav><div class="ffsp-body">';

		if ( isset( $pages[ $tab ] ) && class_exists( $pages[ $tab ] ) ) {
			( new $pages[ $tab ]( $this->plugin ) )->render();
		} else {
			do_action( 'ffsp/admin_render_' . $tab, $this->plugin );
		}
		echo '</div></div>';
	}
}
