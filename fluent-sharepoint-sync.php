<?php
/**
 * Plugin Name:       Fluent Forms → SharePoint Sync
 * Plugin URI:        https://github.com/faidodaisen/wp-fluent-form-sharepoint-sync
 * Description:       Send Fluent Forms submissions (fields + uploaded files) to Microsoft SharePoint through a Power Automate HTTP endpoint. Per-form integration profiles, field mapping, signed file links, logs and retry.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Armiena Group
 * Author URI:        https://armiena.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       fluent-sharepoint-sync
 * Domain Path:       /languages
 * Update URI:        https://github.com/faidodaisen/wp-fluent-form-sharepoint-sync
 *
 * @package FFSP
 */

defined( 'ABSPATH' ) || exit;

define( 'FFSP_VERSION', '1.0.0' );
define( 'FFSP_DB_VERSION', '1.0.0' );
define( 'FFSP_FILE', __FILE__ );
define( 'FFSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFSP_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 style autoloader: FFSP\Admin\Pages\Logs => src/Admin/Pages/Logs.php
 */
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'FFSP\\' ) ) {
			return;
		}
		$path = FFSP_DIR . 'src/' . str_replace( '\\', '/', substr( $class, 5 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'FFSP\\Database\\Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FFSP\\Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		FFSP\Plugin::instance()->boot();
	},
	20
);

/**
 * Global accessor, e.g. ffsp()->get( 'sync' ).
 *
 * @return FFSP\Plugin
 */
function ffsp() {
	return FFSP\Plugin::instance();
}
