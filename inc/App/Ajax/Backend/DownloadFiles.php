<?php
/**
 * Backend Ajax Class: DownloadFiles
 *
 * Initializes the demo files download Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
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
 * Backend Ajax Class: DownloadFiles
 *
 * @since 1.0.0
 */
class DownloadFiles extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_download_demo_files', [ $this, 'response' ] );
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

		$demoZip   = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'demoZip' ) :
			Helpers::getDemoData( $this->config, 'demoZip' );
		$downloads = $this->downloadDemoFiles( $demoZip );

		// Response.
		$this->prepareResponse(
			$downloads ? 'sd_edi_import_xml' : '',
			$downloads ? esc_html__( 'Importing content, sit back, relax! This might take a while.', 'easy-demo-importer' ) : '',
			$downloads ? esc_html__( 'Demo files have landed, we are good to go!', 'easy-demo-importer' ) : '',
			! $downloads,
			! $downloads ? esc_html__( 'Import attempt failed. Demo files can not be downloaded.', 'easy-demo-importer' ) : '',
		);
	}

	/**
	 * Download demo files.
	 *
	 * @param string $external_url External demo URL.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function downloadDemoFiles( $external_url ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		/*
		 * Initialize WordPress' file system handler.
		 *
		 * @var WP_Filesystem_Base $wp_filesystem
		 */
		WP_Filesystem();

		global $wp_filesystem;

		$result = true;

		if ( ! ( $wp_filesystem->exists( $this->demoUploadDir() ) ) ) {
			$result = $wp_filesystem->mkdir( $this->demoUploadDir() );
		}

		// Abort the request if the local uploads directory couldn't be created.
		if ( ! $result ) {
			return false;
		} else {
			$demoData = $this->demoUploadDir() . 'imported-demo-data.zip';

			$response = wp_remote_get(
				$external_url,
				[
					'timeout' => 60,
				]
			);

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				return false;
			}

			$file = wp_remote_retrieve_body( $response );

			$wp_filesystem->put_contents( $demoData, $file );

			// Unzip file.
			unzip_file( $demoData, $this->demoUploadDir() );

			// Delete zip.
			$wp_filesystem->delete( $demoData );

			return true;
		}
	}
}
