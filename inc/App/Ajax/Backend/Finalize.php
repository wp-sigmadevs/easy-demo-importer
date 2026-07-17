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
	Functions\Helpers,
	Functions\ImportLogger,
	Functions\SessionManager,
	Abstracts\ImporterAjax
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
		// memory-heavy and are outside our control, so give them headroom.
		//
		// Deliberately not restored afterwards. PHP enforces memory_limit at
		// allocation time, so lowering it back while the hooks' memory is still
		// held would fatal on the next allocation (flush_rewrite_rules() below)
		// — the very failure this raise prevents. The limit is per-request and
		// this is the wizard's last request, so there is nothing to restore for.
		wp_raise_memory_limit( 'admin' );

		// A host that forbids changing memory_limit is the one case worth
		// surfacing: an import that dies in the hooks below leaves no other
		// clue. The other falsy returns of wp_raise_memory_limit() mean the
		// limit is already unlimited or high enough, which is not a problem.
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
}
