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

		$slider           = basename(
			$this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'layerSliderZip' ) :
			Helpers::getDemoData( $this->config, 'layerSliderZip' )
		);
		$sliderFileExists = file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip' );

		if ( $slider && $sliderFileExists ) {
			$this->importLayerSlider( $slider );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_finalize_demo',
			esc_html__( 'Finalizing demo data import.', 'easy-demo-importer' ),
			$slider ? esc_html__( 'LayerSlider slides imported.', 'easy-demo-importer' ) : esc_html__( 'Skipping LayerSlider import.', 'easy-demo-importer' )
		);
	}

	/**
	 * Import LayerSlider slides.
	 *
	 * @param string $slider Slider ZIP file name.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function importLayerSlider( $slider ) {
		$slider     = basename( $slider );
		$sliderFile = $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip';
		$extractDir = $this->demoUploadDir( $this->demoDir() ) . '/' . $slider;
		$zip        = new \ZipArchive();

		if ( $zip->open( $sliderFile ) === true ) {
			// Create the dedicated extraction subdirectory first so realpath() resolves.
			wp_mkdir_p( $extractDir );
			$real_extract = realpath( $extractDir );

			// Validate every ZIP entry before extracting to prevent ZipSlip.
			if ( false !== $real_extract ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$entry_name = $zip->getNameIndex( $i );
					$dest       = $real_extract . DIRECTORY_SEPARATOR . $entry_name;

					// Reject the entire archive if any entry escapes the target dir.
					if ( false === $dest || 0 !== strpos( realpath( dirname( $dest ) ) . DIRECTORY_SEPARATOR, $real_extract . DIRECTORY_SEPARATOR ) ) {
						$zip->close();
						return;
					}
				}
			}

			// Extract into demo-content/layer-slider/ so inner ZIPs are at a known path.
			$zip->extractTo( $extractDir );
			$zip->close();

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
}
