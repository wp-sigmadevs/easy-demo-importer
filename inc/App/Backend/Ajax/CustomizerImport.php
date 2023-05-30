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

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Functions\Helpers,
	Models\Customizer,
	Traits\Singleton
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
		Helpers::verifyAjaxCall();

		$customizerFilePath = $this->demoUploadDir( $this->demoDir() ) . '/customizer.dat';
		$fileExists         = file_exists( $customizerFilePath );

		if ( $fileExists ) {
			// Import customizer data.
			ob_start();
			( new Customizer() )->import( $customizerFilePath, $this->excludeImages );
			ob_end_clean();
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_import_menus',
			$fileExists ? esc_html__( 'Setting menus', 'easy-demo-importer' ) : '',
			$fileExists ? esc_html__( 'Customizer settings imported', 'easy-demo-importer' ) : esc_html__( 'No customizer settings found', 'easy-demo-importer' )
		);
	}
}
