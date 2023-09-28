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

		update_option( 'sd_edi_plugin_deactivate_notice', 'true' );

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'sd_edi_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		self::createTable();

		delete_transient( 'sd_edi_installing' );

		self::createDemoDir();

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
		flush_rewrite_rules();
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

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );

		$collate      = '';
		$table_schema = [];

		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) !== $tableName ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$table_schema[] = "CREATE TABLE $tableName (
                      original_id BIGINT UNSIGNED NOT NULL,
                      new_id BIGINT UNSIGNED NOT NULL,
                      slug varchar(200) NOT NULL,
                      PRIMARY KEY (original_id)
                      ) $collate;";
		}

		return $table_schema;
	}

	/**
	 * Create demo download directory.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function createDemoDir() {
		$uploadsDir = wp_get_upload_dir();
		$demoDir    = 'easy-demo-importer';

		if ( ! is_dir( $uploadsDir['basedir'] . '/' . $demoDir ) ) {
			wp_mkdir_p( $uploadsDir['basedir'] . '/' . $demoDir );
		}
	}
}
