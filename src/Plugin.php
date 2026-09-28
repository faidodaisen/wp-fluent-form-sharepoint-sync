<?php
namespace FFSP;

use FFSP\Contracts\Module;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin kernel: a tiny service container + module loader.
 *
 * Services are lazily created shared objects (repositories, mapper, http client...).
 * Modules are feature units that hook into WordPress. Third parties can add/replace
 * modules through the `ffsp/modules` filter.
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var array<string, callable> */
	private $factories = array();

	/** @var array<string, object> */
	private $services = array();

	/** @var array<string, Module> */
	private $modules = array();

	/** @var bool */
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->register_services();
	}

	/**
	 * Register shared services.
	 */
	private function register_services() {
		$this->factories = array(
			'settings'     => static function () {
				return new Support\Settings();
			},
			'security'     => static function ( Plugin $c ) {
				return new Security\Security( $c->get( 'settings' ) );
			},
			'integrations' => static function ( Plugin $c ) {
				return new Repository\IntegrationRepository( $c->get( 'security' ) );
			},
			'logs'         => static function () {
				return new Repository\LogRepository();
			},
			'forms'        => static function () {
				return new Fluent\FormGateway();
			},
			'mapper'       => static function () {
				return new Mapping\FieldMapper( new Mapping\Transformers() );
			},
			'files'        => static function ( Plugin $c ) {
				return new Files\FileHandler( $c->get( 'settings' ), $c->get( 'security' ) );
			},
			'payload'      => static function ( Plugin $c ) {
				return new Sync\PayloadBuilder( $c->get( 'forms' ), $c->get( 'mapper' ), $c->get( 'files' ) );
			},
			'http'         => static function ( Plugin $c ) {
				return new Transport\HttpClient( $c->get( 'settings' ), $c->get( 'security' ) );
			},
			'queue'        => static function () {
				return new Queue\Queue();
			},
			'sync'         => static function ( Plugin $c ) {
				return new Sync\SyncService(
					$c->get( 'integrations' ),
					$c->get( 'logs' ),
					$c->get( 'payload' ),
					$c->get( 'http' ),
					$c->get( 'queue' ),
					$c->get( 'settings' ),
					$c->get( 'security' )
				);
			},
		);
	}

	/**
	 * Get a shared service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	public function get( $id ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			if ( ! isset( $this->factories[ $id ] ) ) {
				throw new \InvalidArgumentException( 'FFSP: unknown service ' . esc_html( $id ) );
			}
			$this->services[ $id ] = call_user_func( $this->factories[ $id ], $this );
		}
		return $this->services[ $id ];
	}

	/**
	 * Override / add a service (useful for tests or extensions).
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory receiving the plugin instance.
	 */
	public function set( $id, callable $factory ) {
		$this->factories[ $id ] = $factory;
		unset( $this->services[ $id ] );
	}

	/**
	 * Boot: run DB upgrades, load modules.
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'fluent-sharepoint-sync', false, dirname( plugin_basename( FFSP_FILE ) ) . '/languages' );

		Database\Schema::maybe_upgrade();

		$modules = array(
			'fluent_listener' => Fluent\SubmissionListener::class,
			'queue'           => Queue\QueueModule::class,
			'file_endpoint'   => Files\FileEndpoint::class,
			'maintenance'     => Maintenance\Retention::class,
			'admin'           => Admin\Admin::class,
			'mock_receiver'   => Dev\MockReceiver::class,
			'cli'             => Cli\CliModule::class,
			'updater'         => Update\Updater::class,
		);

		/**
		 * Filter the list of modules. Remove a key to disable a module, or add your own class
		 * implementing FFSP\Contracts\Module.
		 */
		$modules = apply_filters( 'ffsp/modules', $modules );

		foreach ( $modules as $key => $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}
			$module = new $class( $this );
			if ( $module instanceof Module ) {
				$module->register();
				$this->modules[ $key ] = $module;
			}
		}

		do_action( 'ffsp/booted', $this );
	}

	/**
	 * Is Fluent Forms active?
	 *
	 * @return bool
	 */
	public static function fluent_active() {
		return defined( 'FLUENTFORM' ) || function_exists( 'wpFluent' );
	}

	/**
	 * Deactivation: clear this plugin's scheduled events. Data is never touched here.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ffsp_daily_maintenance' );
		Queue\Queue::clear_all();
	}
}
