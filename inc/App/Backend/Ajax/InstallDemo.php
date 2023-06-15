<?php
/**
 * Backend Ajax Class: InstallDemo
 *
 * Initializes the demo installation Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SD_EDI_WP_Import;
use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Functions\Helpers,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: InstallDemo
 *
 * @since 1.0.0
 */
class InstallDemo extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_xml', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		$xmlFile = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';

		$fileExists = file_exists( $xmlFile );

		// Init import actions.
		$this->beforeImportActions( $xmlFile, $this->excludeImages );

		if ( $fileExists ) {
			$this->importDemoContent( $xmlFile, $this->excludeImages );
		}

		// Response.
		$this->prepareResponse(
			$fileExists ? 'sd_edi_import_customizer' : '',
			$fileExists ? esc_html__( 'Customizer settings import in progress.', 'easy-demo-importer' ) : '',
			$fileExists ? esc_html__( 'All content successfully imported', 'easy-demo-importer' ) : '',
			! $fileExists,
			! $fileExists ? esc_html__( 'Demo import process failed. No content file found.', 'easy-demo-importer' ) : '',
		);
	}

	/**
	 * Import demo content.
	 *
	 * @param string $xmlFilePath XML file path.
	 * @param string $excludeImages Exclude images.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function importDemoContent( $xmlFilePath, $excludeImages ) {
		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			$wpImporter = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

			if ( file_exists( $wpImporter ) ) {
				require_once $wpImporter;
			}
		}

		// Import demo content from XML.
		if ( class_exists( 'SD_EDI_WP_Import' ) ) {
			$excludeImages = ! ( 'true' === $excludeImages );

			if ( file_exists( $xmlFilePath ) ) {
				$wp_import                    = new SD_EDI_WP_Import();
				$wp_import->fetch_attachments = $excludeImages;

				// Import XML.
				ob_start();
				$wp_import->import( $xmlFilePath );
				ob_end_clean();

				if ( ! $excludeImages ) {
					$this->unsetThumbnails();
				}
			}
		}
	}

	/**
	 * Unset featured images from posts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function unsetThumbnails() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key = %s", '_thumbnail_id' )
		);
	}
}
