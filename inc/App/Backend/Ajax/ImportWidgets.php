<?php
/**
 * Backend Ajax Class: ImportWidgets
 *
 * Initializes the widgets import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: ImportWidgets
 *
 * @since 1.0.0
 */
class ImportWidgets extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_widgets', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		$widgetsFilePath = $this->demoUploadDir( $this->demoSlug ) . '/widget.wie';
		$fileExists      = file_exists( $widgetsFilePath );

		if ( $fileExists ) {
			// Import widgets data.
			ob_start();
			( new Widgets() )->import( $widgetsFilePath );
			ob_end_clean();
		}

		$sliderFileExists = file_exists( $this->demoUploadDir( $this->demoSlug ) . '/revslider.zip' );

		// Response.
		$this->prepareResponse(
			$sliderFileExists ? 'sd_edi_import_revslider' : 'sd_edi_finalize_demo',
			$sliderFileExists ? esc_html__( 'Importing Revolution slider', 'easy-demo-importer' ) : esc_html__( 'Finalizing demo data', 'easy-demo-importer' ),
			$fileExists ? esc_html__( 'Widgets imported', 'easy-demo-importer' ) : esc_html__( 'No widgets found', 'easy-demo-importer' )
		);
	}
}
