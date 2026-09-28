<?php
namespace FFSP\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Every feature of the plugin is a module. A module only wires WordPress hooks in register();
 * it must not do work at construction time.
 */
interface Module {

	/**
	 * Hook the module into WordPress.
	 *
	 * @return void
	 */
	public function register();
}
