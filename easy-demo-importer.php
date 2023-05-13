<?php
/**
 * Plugin Name: Easy Demo Importer
 * Plugin URI: https://github.com/wp-sigmadevs/easy-demo-importer
 * Description: A one-click, user-friendly WordPress plugin for effortlessly importing theme demos and customizing your website in no time.
 * Version: 1.0.0
 * Author: Sigma Devs
 * Author URI: https://github.com/wp-sigmadevs/
 * License: GPLv2 or later
 * Text Domain: easy-demo-importer
 * Domain Path: /languages
 * Namespace: SigmaDevs\EasyDemoImporter
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

declare( strict_types=1 );

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Define the default root file of the plugin.
 *
 * @since 1.0.0
 */
define( 'SD_EDI_PLUGIN_ROOT_FILE', __FILE__ );

/**
 * Load PSR4 autoloader.
 *
 * @since 1.0.0
 */
$sd_edi_autoloader = require plugin_dir_path( SD_EDI_PLUGIN_ROOT_FILE ) . 'vendor/autoload.php';

/**
 * Setup hooks (activation, deactivation, uninstall)
 *
 * @since 1.0.0
 */
register_activation_hook( SD_EDI_PLUGIN_ROOT_FILE, [ 'SigmaDevs\EasyDemoImporter\Config\Setup', 'activation' ] );
register_deactivation_hook( SD_EDI_PLUGIN_ROOT_FILE, [ 'SigmaDevs\EasyDemoImporter\Config\Setup', 'deactivation' ] );
register_uninstall_hook( SD_EDI_PLUGIN_ROOT_FILE, [ 'SigmaDevs\EasyDemoImporter\Config\Setup', 'uninstall' ] );

/**
 * Bootstrap the plugin.
 *
 * @param object $sd_edi_autoloader Autoloader Object.
 *
 * @since 1.0.0
 */
if ( ! class_exists( 'SigmaDevs\EasyDemoImporter\\Bootstrap' ) ) {
	wp_die( esc_html__( 'Easy Demo Importer is unable to find the Bootstrap class.', 'easy-demo-importer' ) );
}

add_action(
	'plugins_loaded',
	static function () use ( $sd_edi_autoloader ) {
		$app = \SigmaDevs\EasyDemoImporter\Bootstrap::instance();
		$app->registerServices( $sd_edi_autoloader );
	}
);

/**
 * Create a function for external uses.
 *
 * @return \SigmaDevs\EasyDemoImporter\Common\Functions\Functions
 * @since 1.0.0
 */
function sd_edi() {
	return new \SigmaDevs\EasyDemoImporter\Common\Functions\Functions();
}
