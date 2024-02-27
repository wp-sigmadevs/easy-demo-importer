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

		/**
		 * Action Hook: 'sd/edi/after_import'
		 *
		 * Performs special actions after demo import.
		 *
		 * @hooked SigmaDevs\EasyDemoImporter\Common\Functions\Actions::afterImportActions 10
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/after_import', $this );

		// Resetting permalink.
		flush_rewrite_rules();

		// Response.
		$this->prepareResponse(
			'',
			'',
			esc_html__( 'Hooray! You are all set! Now go out and have a blast!', 'easy-demo-importer' )
		);
	}
}
