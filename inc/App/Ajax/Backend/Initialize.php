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
	Functions\ImportLogger,
	Functions\SessionManager,
	Importer\ImportState,
	Utils\Snapshot,
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
		$posted_session_id = ! empty( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		// The client's copy of the session ID can be stale or missing entirely —
		// e.g. the page's own request was rejected by the lock check before a
		// session ever existed for it, so it has nothing valid to send. The
		// server always knows the actual locked session regardless of what the
		// client sent, so resolve it from there and force-release it: this
		// handler exists specifically for the user to break out of a stuck lock.
		$active     = SessionManager::get();
		$session_id = $active['session_id'] ?? $posted_session_id;

		if ( '' !== $session_id ) {
			// Discard any resumable chunked-import state for this session so a
			// cancelled import cannot be resumed with stale content.
			ImportState::forSession( $this->demoUploadDir( $this->demoDir() ), $session_id )->delete();
		}

		SessionManager::forceRelease();
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

		// Ensure the activity-log table exists before anything below can log to
		// it — this is the earliest point in the pipeline a message can be
		// logged (e.g. the lock rejection just below), so it can't wait for
		// InstallDemo's lazy install on installs that never re-ran activation.
		ImportLogger::maybeInstall();

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

		// Opt-in restore point: snapshot the content/options tables here, at the
		// very start of the pipeline — BEFORE the database reset below wipes them.
		// Taken in InstallDemo previously, which runs after this reset, so a
		// reset import snapshotted the already-emptied tables and a rollback
		// restored the site to that empty state. Guarded so it runs once.
		if ( $this->snapshot && ! Snapshot::exists() ) {
			if ( Snapshot::create() ) {
				ImportLogger::info(
					esc_html__( 'Restore point created — this import can be rolled back.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);
			} else {
				ImportLogger::warning(
					esc_html__( 'Restore point skipped — this site is too large to snapshot safely; the import will continue without rollback.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);
			}
		}

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

		// Response. The modal gets a friendlier "Cleanup done!" for the
		// non-reset branch; the activity log keeps the neutral "Cleanup
		// completed." (the reset branch's text is already neutral, so it's
		// used as-is in both places — passing null for logMessage there).
		$this->prepareResponse(
			'sd_edi_install_plugins',
			esc_html__( 'Installing your plugins...', 'easy-demo-importer' ),
			( $this->reset ) ? esc_html__( 'Database reset completed.', 'easy-demo-importer' ) : esc_html__( 'Cleanup done!', 'easy-demo-importer' ),
			false,
			'',
			'',
			( $this->reset ) ? null : esc_html__( 'Cleanup completed.', 'easy-demo-importer' )
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
		// sd_edi_import_log is the plugin's persistent activity log (see
		// ImportLogger) — it's meant to survive across imports so each run
		// appends its own accordion on the Import Log tab, not get wiped by a
		// "reset database" import. Listed unprefixed like the rest: the
		// array_map below adds $wpdb->prefix to every entry once.
		$excludeTables = [ 'options', 'usermeta', 'users', ImportLogger::TABLE ];

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

		// Snapshot shadow tables ({prefix}sd_edi_snap_*) hold the pre-import
		// restore point, which is created (in response(), above) BEFORE this
		// reset runs. They must never be swept into the truncate list below —
		// doing so empties the restore point, so a later rollback would wipe the
		// site instead of reverting it.
		$snapshotInfix = $wpdb->prefix . Snapshot::INFIX;

		if ( is_array( $tableStatus ) ) {
			foreach ( $tableStatus as $table ) {
				if ( 0 !== stripos( $table->Name, $wpdb->prefix ) ) {
					continue;
				}

				if ( empty( $table->Engine ) ) {
					continue;
				}

				if ( 0 === stripos( $table->Name, $snapshotInfix ) ) {
					continue;
				}

				if ( false === in_array( $table->Name, $coreTables, true ) && false === in_array( $table->Name, $excludeTables, true ) ) {
					$customTables[] = $table->Name;
				}
			}
		}

		$customTables = array_merge( $coreTables, $customTables );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET foreign_key_checks = 0' );

		foreach ( $customTables as $tbl ) {
			$wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				$wpdb->prepare( 'TRUNCATE TABLE %1$s', $tbl )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'SET foreign_key_checks = 1' );

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
