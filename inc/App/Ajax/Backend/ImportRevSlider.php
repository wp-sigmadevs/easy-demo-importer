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

		$slider           = basename(
			$this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'revSliderZip' ) :
			Helpers::getDemoData( $this->config, 'revSliderZip' )
		);
		$sliderFileExists = file_exists( $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip' );

		if ( $slider && $sliderFileExists ) {
			$this->importSlider( $slider );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_finalize_demo',
			esc_html__( 'Finalizing demo data import.', 'easy-demo-importer' ),
			$slider ? esc_html__( 'Slider Revolution slides imported.', 'easy-demo-importer' ) : esc_html__( 'Skipping Slider Revolution import!.', 'easy-demo-importer' )
		);
	}

	/**
	 * Setting nav menus.
	 *
	 * @param string $slider Slider zip File name.
	 *
	 * @return void
	 * @since 1.1.0
	 */
	private function importSlider( $slider ) {
		// Strip path separators from theme-config value.
		$slider      = basename( $slider );
		$sliderFiles = $this->demoUploadDir( $this->demoDir() ) . '/' . $slider . '.zip';
		$unzipDir    = $this->demoUploadDir( $this->demoDir() );
		$zip         = new \ZipArchive();

		if ( $zip->open( $sliderFiles ) === true ) {
			// Validate every ZIP entry before extracting to prevent ZipSlip.
			$real_unzip = realpath( $unzipDir );

			if ( false !== $real_unzip ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$entry_name = $zip->getNameIndex( $i );
					$dest       = $real_unzip . DIRECTORY_SEPARATOR . $entry_name;

					// Reject the entire archive if any entry escapes the target dir.
					if ( false === $dest || 0 !== strpos( realpath( dirname( $dest ) ) . DIRECTORY_SEPARATOR, $real_unzip . DIRECTORY_SEPARATOR ) ) {
						$zip->close();
						return;
					}
				}
			}

			$zip->extractTo( $unzipDir );
			$zip->close();

			if ( class_exists( 'RevSlider' ) ) {
				$revSlider   = new \RevSlider();
				$sliderFiles = glob( $unzipDir . '/' . $slider . '/*.zip' );

				foreach ( $sliderFiles as $sliderFile ) {
					$revSlider->importSliderFromPost( true, true, $sliderFile );
				}
			}
		}
	}
}
