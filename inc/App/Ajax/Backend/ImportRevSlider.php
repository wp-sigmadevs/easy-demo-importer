<?php
/**
 * Backend Ajax Class: ImportRevSlider
 *
 * Initializes the Revolution Slider import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.1.0
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
 * Backend Ajax Class: ImportRevSlider
 *
 * @since 1.1.0
 */
class ImportRevSlider extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.1.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.1.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		parent::register();

		add_action( 'wp_ajax_sd_edi_import_rev_slider', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		$sliderImported = $this->unzipAndImportSlider(
			'revSliderZip',
			function ( $extractedPath, $sliderName ) {
				$this->importRevSlider( $extractedPath, $sliderName );
			},
			$this->demoUploadDir( $this->demoDir() )
		);

		$layerSlider    = basename(
			$this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'layerSliderZip' ) :
			Helpers::getDemoData( $this->config, 'layerSliderZip' )
		);
		$hasLayerSlider = $layerSlider && file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $layerSlider . '.zip' );

		// Response.
		$this->prepareResponse(
			$hasLayerSlider ? 'sd_edi_import_layer_slider' : 'sd_edi_finalize_demo',
			$hasLayerSlider ? esc_html__( 'Importing LayerSlider layouts.', 'easy-demo-importer' ) : esc_html__( 'Finalizing demo data import.', 'easy-demo-importer' ),
			$sliderImported ? esc_html__( 'Slider Revolution slides imported.', 'easy-demo-importer' ) : esc_html__( 'Skipping Slider Revolution import.', 'easy-demo-importer' )
		);
	}

	/**
	 * Import Slider Revolution slides.
	 *
	 * @param string $extractedPath Directory where slider files were extracted.
	 * @param string $sliderName     Slider zip File name.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function importRevSlider( $extractedPath, $sliderName ) {
		if ( class_exists( 'RevSlider' ) ) {
			$revSlider   = new \RevSlider();
			$sliderFiles = glob( $extractedPath . '/' . $sliderName . '/*.zip' );

			foreach ( $sliderFiles as $sliderFile ) {
				$revSlider->importSliderFromPost( true, true, $sliderFile );
			}
		}
	}
}
