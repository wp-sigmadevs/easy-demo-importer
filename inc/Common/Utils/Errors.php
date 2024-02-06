<?php
/**
 * Utility Class: Error
 *
 * Utility to show plugin errors.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Config\Plugin;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: Error
 *
 * @since 1.0.0
 */
class Errors {
	/**
	 * Get the plugin data in static form
	 *
	 * @static
	 * @return array
	 * @since 1.0.0
	 */
	public static function getPluginData() {
		return ( new Plugin() )->data();
	}

	/**
	 * Deactivates the plugin.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function pluginDie() {
		add_action( 'admin_init', [ __CLASS__, 'forceDeactivate' ] );
	}

	/**
	 * Shows notice error in back-end.
	 *
	 * @static
	 *
	 * @param string $title General title of the error.
	 * @param string $message The error message.
	 * @param string $source File source of the error.
	 * @param string $subtitle Specified title of the error.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function errorMessage( $title = '', $message = '', $source = '', $subtitle = '' ) {
		$error = '';

		if ( $message ) {
			$plugin   = self::getPluginData();
			$title    = $title ? esc_html( $title ) : $plugin['name'] . ' ' . $plugin['version'] . ' ' . esc_html__( '&rsaquo; Fatal Error', 'easy-demo-importer' );
			$subtitle = $subtitle ? esc_html( $subtitle ) : $plugin['name'] . ' ' . $plugin['version'] . ' ' . esc_html__( '&#10230; Plugin Disabled', 'easy-demo-importer' );
			$footer   = $source ? '<small>' .
								sprintf( /* translators: %s: file path */
									esc_html__( 'Error source: %s', 'easy-demo-importer' ),
									esc_html( $source )
								) . '</small>' : '';
			$error    = "<h3>$title</h3><strong>$subtitle</strong><p>$message</p><hr><p>$footer</p>";
		}

		return $error;
	}

	/**
	 * Force deactivate plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function forceDeactivate() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( plugin_basename( SD_EDI_ROOT_FILE ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			unset( $_GET['activate'] );
		}
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate() {
		$plugin = sd_edi()->getData()['plugin_active_file'];

		if ( is_plugin_active( $plugin ) ) {
			deactivate_plugins( $plugin );
		}

		// Redirect to main dashboard page.
		wp_safe_redirect( admin_url( 'plugins.php' ) );
	}
}
