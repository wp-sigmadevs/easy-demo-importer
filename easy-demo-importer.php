<?php
/**
 * Plugin Name: Easy Demo Importer - A Modern One-Click Demo Import Solution
 * Plugin URI: https://github.com/wp-sigmadevs/easy-demo-importer/
 * Description: A one-click, user-friendly WordPress plugin for effortlessly importing theme demos and customizing your website in no time.
 * Version: 1.1.6
 * Author: Sigma Devs
 * Author URI: https://profiles.wordpress.org/sigmadevs/
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: easy-demo-importer
 * Domain Path: /languages
 * Requires at least: 5.5
 * Requires PHP: 7.4
 * Namespace: SigmaDevs\EasyDemoImporter
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

use SigmaDevs\EasyDemoImporter\Bootstrap;
use SigmaDevs\EasyDemoImporter\Config\Setup;
use SigmaDevs\EasyDemoImporter\Common\Functions\Functions;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Define the default root file of the plugin.
 *
 * @since 1.0.0
 */
define( 'SD_EDI_ROOT_FILE', __FILE__ );

/**
 * Load PSR4 autoloader.
 *
 * @since 1.0.0
 */
$sd_edi_autoloader = require plugin_dir_path( SD_EDI_ROOT_FILE ) . 'vendor/autoload.php';

/**
 * Setup hooks (activation, deactivation, uninstall)
 *
 * @since 1.0.0
 */
register_activation_hook( SD_EDI_ROOT_FILE, [ setup::class, 'activation' ] );
register_deactivation_hook( SD_EDI_ROOT_FILE, [ setup::class, 'deactivation' ] );
register_uninstall_hook( SD_EDI_ROOT_FILE, [ setup::class, 'uninstall' ] );

if ( ! class_exists( 'SigmaDevs\EasyDemoImporter\\Bootstrap' ) ) {
	wp_die( esc_html__( 'Easy Demo Importer is unable to find the Bootstrap class.', 'easy-demo-importer' ) );
}

/**
 * Bootstrap the plugin.
 *
 * @param object $sd_edi_autoloader Autoloader Object.
 *
 * @since 1.0.0
 */
add_action(
	'init',
	static function () use ( $sd_edi_autoloader ) {
		$app = new Bootstrap();
		$app->registerServices( $sd_edi_autoloader );
	}
);

/**
 * Create a function for external uses.
 *
 * @return Functions
 * @since 1.0.0
 */
function sd_edi() {
	return new Functions();
}
