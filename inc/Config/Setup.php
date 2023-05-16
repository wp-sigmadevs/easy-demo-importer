<?php
/**
 * Config Class: Setup.
 *
 * Plugin setup hooks (activation, deactivation, uninstall)
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Config;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Config Class: Setup.
 *
 * @since 1.0.0
 */
class Setup {
	/**
	 * Run only once after plugin is activated.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function activation() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Clear the permalinks.
		\flush_rewrite_rules();

		// Uncomment the following line to see the function in action
		// exit( var_dump( $_GET ) );
	}

	/**
	 * Run only once after plugin is deactivated.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivation() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Clear the permalinks.
		\flush_rewrite_rules();

		// Uncomment the following line to see the function in action
		// exit( var_dump( $_GET ) );
	}

	/**
	 * Run only once after plugin is uninstalled.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function uninstall() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Uncomment the following line to see the function in action
		// exit( var_dump( $_GET ) );
	}
}
