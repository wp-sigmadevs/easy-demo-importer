<?php
/**
 * Backend Ajax Class: ImportLayerSlider
 *
 * Initializes the LayerSlider import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
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
 * @since 2.0.0
 */
class ImportLayerSlider extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 2.0.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 2.0.0
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
	 * @since 2.0.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		$sliderImported = $this->unzipAndImportSlider(
			'layerSliderZip',
			function ( $extractDir, $slider ) {
				return $this->importLayerSlider( $extractDir, $slider );
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
	 * @return bool True if at least one slider was imported, false otherwise.
	 * @since 2.0.0
	 */
	private function importLayerSlider( $extractDir ) {
		// LayerSlider must be active to import into it.
		if ( ! class_exists( 'LS_Sliders' ) || ! defined( 'LS_ROOT_PATH' ) ) {
			return false;
		}

		$import_util_path = LS_ROOT_PATH . '/classes/class.ls.importutil.php';
		$filesystem_path  = LS_ROOT_PATH . '/classes/class.ls.filesystem.php';

		if ( ! file_exists( $import_util_path ) || ! file_exists( $filesystem_path ) ) {
			return false;
		}

		require_once $import_util_path;
		require_once $filesystem_path;

		// Verify the import class the required file is expected to define, so a
		// future LayerSlider restructure degrades gracefully instead of fataling.
		if ( ! class_exists( 'LS_ImportUtil' ) ) {
			return false;
		}

		$sliderFiles = glob( $extractDir . '/*.zip' );

		// glob() returns false on error; guard before iterating.
		if ( empty( $sliderFiles ) ) {
			return false;
		}

		$imported = false;

		foreach ( $sliderFiles as $sliderFile ) {
			// LS_ImportUtil runs the import in its constructor; a malformed
			// export shouldn't abort the whole AJAX request.
			try {
				new \LS_ImportUtil( $sliderFile );
				$imported = true;
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $imported;
	}
}
