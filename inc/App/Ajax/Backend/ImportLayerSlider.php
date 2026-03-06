<?php
/**
 * Backend Ajax Class: ImportLayerSlider
 *
 * Initializes the LayerSlider import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
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
 * Backend Ajax Class: ImportLayerSlider
 *
 * @since 1.2.0
 */
class ImportLayerSlider extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.2.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.2.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		parent::register();

		add_action( 'wp_ajax_sd_edi_import_layer_slider', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		$sliderImported = $this->unzipAndImportSlider(
			'layerSliderZip',
			function ( $extractDir, $slider ) {
				$this->importLayerSlider( $extractDir, $slider );
			}
		);

		// Response.
		$this->prepareResponse(
			'sd_edi_finalize_demo',
			esc_html__( 'Finalizing demo data import.', 'easy-demo-importer' ),
			$sliderImported ? esc_html__( 'LayerSlider slides imported.', 'easy-demo-importer' ) : esc_html__( 'Skipping LayerSlider import.', 'easy-demo-importer' )
		);
	}

	/**
	 * Import LayerSlider slides.
	 *
	 * @param string $extractDir Directory where slider files were extracted.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function importLayerSlider( $extractDir ) {
		if ( class_exists( 'LS_Sliders' ) && defined( 'LS_ROOT_PATH' ) ) {
			$import_util_path = LS_ROOT_PATH . '/classes/class.ls.importutil.php';
			$filesystem_path  = LS_ROOT_PATH . '/classes/class.ls.filesystem.php';

			if ( file_exists( $import_util_path ) && file_exists( $filesystem_path ) ) {
				require_once $import_util_path;
				require_once $filesystem_path;

				$sliderFiles = glob( $extractDir . '/*.zip' );

				foreach ( $sliderFiles as $sliderFile ) {
					new \LS_ImportUtil( $sliderFile );
				}
			}
		}
	}
}
