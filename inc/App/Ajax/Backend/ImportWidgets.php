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

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Models\Widgets,
	Traits\Singleton,
	Functions\Helpers,
	Abstracts\ImporterAjax
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
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		$widgetsFilePath = $this->demoUploadDir( $this->demoDir() ) . '/widget.wie';
		$fileExists      = file_exists( $widgetsFilePath );

		if ( $fileExists ) {
			/**
			 * Action Hook: 'sd/edi/before_widgets_import'
			 *
			 * Performs special actions before widgets import.
			 *
			 * @since 1.1.5
			 */
			do_action( 'sd/edi/before_widgets_import', $widgetsFilePath ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			// Import widgets data.
			ob_start();
			( new Widgets() )->import( $widgetsFilePath );
			ob_end_clean();

			/**
			 * Action Hook: 'sd/edi/after_widgets_import'
			 *
			 * Performs special actions after widgets import.
			 *
			 * @since 1.1.5
			 */
			do_action( 'sd/edi/after_widgets_import', $widgetsFilePath ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		}

		$slider           = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'revSliderZip' ) :
			Helpers::getDemoData( $this->config, 'revSliderZip' );
		$sliderFileExists = file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip' );
		$hasRevSlider     = $slider && $sliderFileExists;

		$layerSlider    = basename(
			$this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'layerSliderZip' ) :
			Helpers::getDemoData( $this->config, 'layerSliderZip' )
		);
		$hasLayerSlider = $layerSlider && file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $layerSlider . '.zip' );

		if ( $hasRevSlider ) {
			$nextPhase   = 'sd_edi_import_rev_slider';
			$nextMessage = esc_html__( 'Importing Slider Revolution slides.', 'easy-demo-importer' );
		} elseif ( $hasLayerSlider ) {
			$nextPhase   = 'sd_edi_import_layer_slider';
			$nextMessage = esc_html__( 'Importing LayerSlider layouts.', 'easy-demo-importer' );
		} else {
			$nextPhase   = 'sd_edi_finalize_demo';
			$nextMessage = esc_html__( 'Finalizing demo data import.', 'easy-demo-importer' );
		}

		// Response.
		$this->prepareResponse(
			$nextPhase,
			$nextMessage,
			$fileExists ? esc_html__( 'Widgets successfully imported.', 'easy-demo-importer' ) : esc_html__( 'No widgets import needed.', 'easy-demo-importer' )
		);
	}
}
