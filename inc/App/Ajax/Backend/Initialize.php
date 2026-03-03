<?php
/**
 * Backend Ajax Class: Initialize
 *
 * Initializes Demo Import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use WP_Filesystem_Direct;
use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Functions\SessionManager,
	Abstracts\ImporterAjax
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: Initialize
 *
 * @since 1.0.0
 */
class Initialize extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		parent::register();

		add_action( 'wp_ajax_sd_edi_install_demo', [ $this, 'response' ] );
		add_action( 'wp_ajax_sd_edi_cancel_session', [ $this, 'cancelSession' ] );
	}

	/**
	 * Release the active import session lock.
	 *
	 * Called by the "Start Over" button, so the user can restart after a failed import
	 * without waiting for the 30-minute lock TTL to expire.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function cancelSession() {
		Helpers::verifyAjaxCall();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = ! empty( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		if ( empty( $session_id ) ) {
			wp_send_json_error( [ 'errorMessage' => __( 'Missing session ID.', 'easy-demo-importer' ) ], 400 );
			return;
		}

		SessionManager::release( $session_id );
		wp_send_json_success();
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		// Reject if another import is already running.
		if ( SessionManager::isLocked() ) {
			$this->prepareResponse(
				'',
				'',
				'',
				true,
				esc_html__( 'Another import is already in progress.', 'easy-demo-importer' ),
				esc_html__( 'Wait for the current import to finish before starting a new one. If you believe the previous import has crashed, wait 30 minutes for the lock to expire automatically, then try again.', 'easy-demo-importer' )
			);
			return;
		}

		// Start a new import session and acquire the mutex lock.
		$session         = SessionManager::start();
		$this->sessionId = $session['session_id'];

		// Resetting database.
		if ( $this->reset ) {
			$this->databaseReset();
		}

		// Truncate the import table.
		$this->truncateImportTable();

		// Update option.
		update_option( 'sd_edi_plugin_deactivate_notice', 'true' );

		/**
		 * Action Hook: 'sd/edi/importer_init'
		 *
		 * Performs special actions when the importer initializes.
		 *
		 * @hooked SigmaDevs\EasyDemoImporter\Common\Functions\Actions::initImportActions 10
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/importer_init', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Response.
		$this->prepareResponse(
			'sd_edi_install_plugins',
			esc_html__( 'Let us install the required plugins.', 'easy-demo-importer' ),
			( $this->reset ) ? esc_html__( 'Database reset completed.', 'easy-demo-importer' ) : esc_html__( 'Minor cleanups done.', 'easy-demo-importer' )
		);
	}

	/**
	 * Truncate the import table.
	 *
	 * @since 1.0.0
	 */
	public function truncateImportTable() {
		global $wpdb;

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );

		// Check if the table exists before truncation.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) === $tableName ) {
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$wpdb->prepare( 'TRUNCATE TABLE %1$s', $tableName )
			);
		}
	}

	/**
	 * Database reset.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function databaseReset() {
		global $wpdb;

		$coreTables    = [
			'commentmeta',
			'comments',
			'links',
			'postmeta',
			'posts',
			'term_relationships',
			'term_taxonomy',
			'termmeta',
			'terms',
		];
		$excludeTables = [ 'options', 'usermeta', 'users' ];

		$coreTables = array_map(
			function ( $tbl ) {
				global $wpdb;

				return $wpdb->prefix . $tbl;
			},
			$coreTables
		);

		$excludeTables = array_map(
			function ( $tbl ) {
				global $wpdb;

				return $wpdb->prefix . $tbl;
			},
			$excludeTables
		);

		$customTables = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tableStatus = $wpdb->get_results( 'SHOW TABLE STATUS' );

		if ( is_array( $tableStatus ) ) {
			foreach ( $tableStatus as $table ) {
				if ( 0 !== stripos( $table->Name, $wpdb->prefix ) ) {
					continue;
				}

				if ( empty( $table->Engine ) ) {
					continue;
				}

				if ( false === in_array( $table->Name, $coreTables, true ) && false === in_array( $table->Name, $excludeTables, true ) ) {
					$customTables[] = $table->Name;
				}
			}
		}

		$customTables = array_merge( $coreTables, $customTables );

		foreach ( $customTables as $tbl ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'SET foreign_key_checks = 0' );
			$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$wpdb->prepare( 'TRUNCATE TABLE %1$s', $tbl )
			);
		}

		// Delete some options from wp_options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}options 
			WHERE option_name LIKE 'elementor_%' 
			OR option_name LIKE '_elementor_%' 
			OR option_name LIKE 'rtsb_%' 
			OR option_name LIKE 'woocommerce_%';"
		);

		// Delete Widgets.
		Helpers::deleteWidgets();

		// Delete ThemeMods.
		Helpers::deleteThemeMods();

		// Clear "uploads" folder.
		if ( is_array( $this->uploadsDir ) && isset( $this->uploadsDir['basedir'] ) ) {
			$this->clearUploads( $this->uploadsDir['basedir'] );
		}
	}

	/**
	 * Clear 'uploads' folder
	 *
	 * @param string $dir Directory to clean.
	 *
	 * @return bool|void
	 * @since 1.0.0
	 */
	private function clearUploads( $dir ) {
		$scanned = scandir( $dir );

		if ( false === $scanned ) {
			return;
		}

		$files = array_diff( $scanned, [ '.', '..' ] );

		foreach ( $files as $file ) {
			// Never follow symlinks — skip them entirely to prevent deleting files outside the uploads directory.
			if ( is_link( "$dir/$file" ) ) {
				continue;
			}

			( is_dir( "$dir/$file" ) ) ? $this->clearUploads( "$dir/$file" ) : wp_delete_file( "$dir/$file" );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		$fileSystemDirect = new WP_Filesystem_Direct( false );

		if ( is_array( $this->uploadsDir ) && isset( $this->uploadsDir['basedir'] ) ) {
			return ! ( $dir !== $this->uploadsDir['basedir'] ) || $fileSystemDirect->rmdir( $dir, true );
		}
	}
}
