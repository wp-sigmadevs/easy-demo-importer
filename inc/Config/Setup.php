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
		self::createImportTables();

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
	 * Cleanup is handled by uninstall.php in the plugin root.
	 * WordPress calls that file automatically on deletion, which takes
	 * precedence over register_uninstall_hook() callbacks.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function uninstall() {
		// See uninstall.php.
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
	 * Create Phase 2 import tables.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	private static function createImportTables() {
		global $wpdb;

		$wpdb->hide_errors();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		$log_table   = $wpdb->prefix . 'sd_edi_import_log';
		$queue_table = $wpdb->prefix . 'sd_edi_import_queue';

		dbDelta( "CREATE TABLE $log_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(36) NOT NULL,
        timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
        message TEXT NOT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY timestamp (timestamp)
    ) $collate;" );

		dbDelta( "CREATE TABLE $queue_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(36) NOT NULL,
        item_index INT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        post_type VARCHAR(20) NOT NULL DEFAULT '',
        post_title TEXT NOT NULL,
        status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
        PRIMARY KEY (id),
        KEY session_status (session_id, status)
    ) $collate;" );

		update_option( 'sd_edi_db_version', '1.3.0' );
	}

	/**
	 * Run DB upgrades if plugin was updated without reactivation.
	 *
	 * Hook: plugins_loaded
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public static function maybeUpgradeDb() {
		if ( get_option( 'sd_edi_db_version' ) !== '1.3.0' ) {
			self::createImportTables();
		}
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
