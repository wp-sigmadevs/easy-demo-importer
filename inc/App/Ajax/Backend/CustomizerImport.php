<?php
/**
 * Backend Ajax Class: CustomizerImport
 *
 * Initializes the Customizer import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Models\Customizer,
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
class CustomizerImport extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_customizer', [ $this, 'response' ] );
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

		$customizerFilePath = $this->demoUploadDir( $this->demoDir() ) . '/customizer.dat';
		$fileExists         = file_exists( $customizerFilePath );

		if ( $fileExists ) {
			/**
			 * Action Hook: 'sd/edi/before_customizer_import'
			 *
			 * Performs special actions before customizer import if needed.
			 *
			 * @since 1.1.5
			 */
			do_action( 'sd/edi/before_customizer_import', $customizerFilePath );

			// Import customizer data.
			ob_start();
			( new Customizer() )->import( $customizerFilePath, $this->excludeImages );
			ob_end_clean();

			/**
			 * Action Hook: 'sd/edi/after_customizer_import'
			 *
			 * Performs special actions after customizer import if needed.
			 *
			 * @since 1.1.5
			 */
			do_action( 'sd/edi/after_customizer_import', $customizerFilePath );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_import_menus',
			$fileExists ? esc_html__( 'Working on the menus, just a sec!', 'easy-demo-importer' ) : '',
			$fileExists ? esc_html__( 'Customizer settings in place – all set!', 'easy-demo-importer' ) : esc_html__( 'Skipping the fancy customizer settings import!.', 'easy-demo-importer' )
		);
	}
}
