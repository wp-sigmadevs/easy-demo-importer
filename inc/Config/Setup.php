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

		if ( ! is_blog_installed() ) {
			return;
		}

		// Check if we are not already running this routine.
		if ( 'yes' === get_transient( 'sd_edi_installing' ) ) {
			return;
		}

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'sd_edi_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		self::createTable();

		delete_transient( 'sd_edi_installing' );

		// Clear the permalinks.
		flush_rewrite_rules();
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

	/**
	 * Create temp table.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function createTable() {
		global $wpdb;

		$wpdb->hide_errors();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::getTableSchema() );
	}

	/**
	 * Get table schema.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private static function getTableSchema() {
		global $wpdb;

		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$tableName    = $wpdb->prefix . 'sd_edi_taxonomy_import';
		$table_schema = [];

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) !== $tableName ) {
			$table_schema[] = "CREATE TABLE $tableName (
                      original_id BIGINT UNSIGNED NOT NULL,
                      new_id BIGINT UNSIGNED NOT NULL,
                      slug varchar(200) NOT NULL,
                      PRIMARY KEY (original_id)
                      ) $collate;";
		}

		return $table_schema;
	}
}
