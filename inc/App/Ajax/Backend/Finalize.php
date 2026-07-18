<?php
/**
 * Backend Ajax Class: Finalize
 *
 * Finalizes the demo import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Actions,
	Functions\Helpers,
	Functions\ImportLogger,
	Functions\SessionManager,
	Abstracts\ImporterAjax,
	Utils\FailedMedia
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: CustomizerImport
 *
 * @since 1.0.0
 */
class Finalize extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_finalize_demo', [ $this, 'response' ] );
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

		// Third-party theme scripts hooked to 'sd/edi/after_import' (nav menu
		// setup, asset generation, dependent-plugin bootstrapping) can be
		// memory-heavy and are outside our control, so give them the same
		// headroom every content-import phase already gets. Using the shared
		// boost rather than wp_raise_memory_limit() keeps one mechanism across
		// the pipeline: the same 'sd/edi/temp_boost_memory_limit' target (which
		// a theme can filter), not WP_MAX_MEMORY_LIMIT's lower default.
		//
		// The boost is deliberately not undone afterwards. PHP enforces
		// memory_limit at allocation time, so lowering it back while the hooks'
		// memory is still held would fatal on the next allocation
		// (flush_rewrite_rules() below) — the very failure the raise prevents.
		// The limit is per-request and this is the wizard's last request.
		Actions::beforeImportActions();

		// A host that forbids changing memory_limit is worth surfacing: an
		// import that dies in the hooks below otherwise leaves no clue why.
		if ( ! wp_is_ini_value_changeable( 'memory_limit' ) ) {
			ImportLogger::info(
				esc_html__( 'This host does not allow the PHP memory limit to be raised for the finishing step.', 'easy-demo-importer' ),
				$this->sessionId,
				$this->demoSlug
			);
		}

		/**
		 * Action Hook: 'sd/edi/after_import'
		 *
		 * Performs special actions after demo import.
		 *
		 * @hooked SigmaDevs\EasyDemoImporter\Common\Functions\Actions::afterImportActions 10
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/after_import', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Resetting permalink.
		flush_rewrite_rules();

		// Free the extracted demo payload now that every phase has consumed it.
		$this->cleanupDemoFiles();

		// Release the import session lock.
		if ( ! empty( $this->sessionId ) ) {
			SessionManager::release( $this->sessionId );
		}

		// Response. The celebratory headline is the final modal card and the
		// Success screen's title — the log keeps a neutral completion line.
		$this->prepareResponse(
			'',
			'',
			esc_html__( 'Hooray! You are all set! Now go out and have a blast!', 'easy-demo-importer' ),
			false,
			'',
			'',
			esc_html__( 'Import completed successfully.', 'easy-demo-importer' )
		);
	}

	/**
	 * Deletes this demo's extracted working directory once the import is done.
	 *
	 * Every phase reads its input from here (content.xml, customizer.dat,
	 * widget.wie, the settings/forms JSON, slider zips and any bundled
	 * uploads/), but nothing ever removed it — so each run left its full
	 * extracted size on disk, and repeated imports stacked up until the volume
	 * filled. The zip itself is already discarded right after extraction, and
	 * the chunked importer drops its own state file at finalize, so by the time
	 * this runs the directory is genuinely spent.
	 *
	 * Runs after the 'sd/edi/after_import' hook, never before: a theme's own
	 * post-import script may still want to read these files.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private function cleanupDemoFiles(): void {
		/**
		 * Filter: 'sd/edi/cleanup_demo_files'
		 *
		 * Return false to keep the extracted demo payload after the import,
		 * e.g. while debugging a demo package.
		 *
		 * @param bool   $cleanup  Whether to delete the working directory.
		 * @param string $demoSlug Demo being imported.
		 *
		 * @since 2.0.0
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( ! apply_filters( 'sd/edi/cleanup_demo_files', true, $this->demoSlug ) ) {
			return;
		}

		// Media the user can still retry from the result screen is resolved
		// against <demo-dir>/uploads by retryMedia(). Deleting that would strip
		// the retry of its local source and force a download that may be exactly
		// what failed. Leave the payload for the run that still needs it.
		if ( FailedMedia::count( $this->sessionId ) > 0 ) {
			return;
		}

		$demoDir = (string) $this->demoDir();

		// An empty demo directory would resolve demoUploadDir() to the shared
		// staging root and take every other demo down with it.
		if ( '' === $demoDir ) {
			return;
		}

		$target = $this->demoUploadDir( $demoDir );
		$root   = $this->demoUploadDir();

		if ( empty( $target ) || empty( $root ) || ! is_dir( $target ) ) {
			return;
		}

		$targetReal = realpath( $target );
		$rootReal   = realpath( $root );

		// Only ever delete strictly inside the plugin's own staging root.
		if ( false === $targetReal || false === $rootReal
			|| $targetReal === $rootReal
			|| 0 !== strpos( $targetReal, trailingslashit( $rootReal ) ) ) {
			return;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem || ! $wp_filesystem->rmdir( $targetReal, true ) ) {
			// Not fatal: the import itself succeeded, and the leftover payload is
			// only wasted space. Surface it so a full disk has a traceable cause.
			ImportLogger::warning(
				esc_html__( 'Could not remove the temporary demo files; they can be deleted manually from uploads/easy-demo-importer.', 'easy-demo-importer' ),
				$this->sessionId,
				$this->demoSlug
			);
		}
	}
}
