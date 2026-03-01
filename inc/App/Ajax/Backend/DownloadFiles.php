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

		$demoZip  = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'demoZip' ) :
			Helpers::getDemoData( $this->config, 'demoZip' );
		$result   = $this->downloadDemoFiles( $demoZip );
		$success  = $result['success'];

		// Response.
		$this->prepareResponse(
			$success ? 'sd_edi_import_xml' : '',
			$success ? esc_html__( 'Importing content, sit back, relax! This might take a while.', 'easy-demo-importer' ) : '',
			$success ? esc_html__( 'Demo files have landed, we are good to go!', 'easy-demo-importer' ) : '',
			! $success,
			! $success ? $result['message'] : '',
			! $success ? $result['hint'] : '',
		);
	}

	/**
	 * Download demo files.
	 *
	 * @param string $external_url External demo URL.
	 *
	 * @return array{success: bool, message: string, hint: string}
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

		if ( ! $wp_filesystem->exists( $this->demoUploadDir() ) && ! $wp_filesystem->mkdir( $this->demoUploadDir() ) ) {
			return [
				'success' => false,
				'message' => esc_html__( 'Could not create the demo upload directory.', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'Check that your uploads directory is writable. Go to Tools > Site Health for file permission details.', 'easy-demo-importer' ),
			];
		}

		$timeout  = (int) apply_filters( 'sd/edi/download_timeout', 120 );
		$sslverify = (bool) apply_filters( 'sd/edi/download_sslverify', true );
		$demoData  = $this->demoUploadDir() . 'imported-demo-data.zip';

		$response = wp_remote_get(
			$external_url,
			[
				'timeout'   => $timeout,
				'sslverify' => $sslverify,
			]
		);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			$is_ssl_error  = (bool) preg_match( '/ssl|certificate|curl error 60|curl error 35/i', $error_message );

			if ( $is_ssl_error ) {
				return [
					'success' => false,
					'message' => esc_html__( 'Could not verify the SSL certificate of the demo file server.', 'easy-demo-importer' ),
					/* translators: %s: WordPress filter name */
					'hint'    => sprintf(
						esc_html__( "Your server's SSL/cURL configuration may be outdated. Ask your host to update their CA certificate bundle. As a temporary workaround, add %s to your theme's functions.php — note: this disables SSL certificate verification.", 'easy-demo-importer' ),
						'add_filter(\'sd/edi/download_sslverify\', \'__return_false\');'
					),
				];
			}

			return [
				'success' => false,
				'message' => esc_html__( 'Lost connection to the demo file server.', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'Check that your server has outbound internet access. If you are on a local environment, ensure it can reach external URLs.', 'easy-demo-importer' ),
			];
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $http_code ) {
			return $this->httpCodeToError( (int) $http_code );
		}

		$file = wp_remote_retrieve_body( $response );

		$wp_filesystem->put_contents( $demoData, $file );

		// Unzip file.
		$unzip_result = unzip_file( $demoData, $this->demoUploadDir() );

		// Delete zip regardless of unzip result.
		$wp_filesystem->delete( $demoData );

		if ( is_wp_error( $unzip_result ) ) {
			return [
				'success' => false,
				'message' => esc_html__( 'Demo files were downloaded but could not be extracted.', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'Ensure the ZipArchive PHP extension is enabled. Contact your host if you are unsure.', 'easy-demo-importer' ),
			];
		}

		return [ 'success' => true, 'message' => '', 'hint' => '' ];
	}

	/**
	 * Map an HTTP status code to a user-friendly error array.
	 *
	 * @param int $code HTTP status code.
	 *
	 * @return array{success: bool, message: string, hint: string}
	 * @since 1.2.0
	 */
	private function httpCodeToError( int $code ): array {
		$messages = [
			401 => [
				'message' => esc_html__( 'Access to the demo file was denied (401 Unauthorized).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The demo file URL may require authentication. Contact the theme author to verify the demo file URL is publicly accessible.', 'easy-demo-importer' ),
			],
			403 => [
				'message' => esc_html__( 'Access to the demo file was forbidden (403 Forbidden).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The demo file server is blocking the request. This can happen if your server IP is restricted. Contact the theme author.', 'easy-demo-importer' ),
			],
			404 => [
				'message' => esc_html__( 'The demo file was not found on the server (404 Not Found).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The demo ZIP URL in the theme configuration may be incorrect or the file has been moved. Contact the theme author for an updated demo package.', 'easy-demo-importer' ),
			],
			408 => [
				'message' => esc_html__( 'The demo file server took too long to respond (408 Request Timeout).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The file server may be temporarily overloaded. Try again in a few minutes. You can also increase the timeout by adding add_filter(\'sd/edi/download_timeout\', fn() => 300); to your theme\'s functions.php.', 'easy-demo-importer' ),
			],
			429 => [
				'message' => esc_html__( 'Too many download requests were made (429 Too Many Requests).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'Wait a few minutes before trying again. The demo file server may be rate-limiting requests.', 'easy-demo-importer' ),
			],
			500 => [
				'message' => esc_html__( 'The demo file server encountered an internal error (500 Internal Server Error).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'This is a problem on the demo file server, not your site. Try again later or contact the theme author.', 'easy-demo-importer' ),
			],
			503 => [
				'message' => esc_html__( 'The demo file server is temporarily unavailable (503 Service Unavailable).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The demo server may be undergoing maintenance. Wait a few minutes and try again.', 'easy-demo-importer' ),
			],
			504 => [
				'message' => esc_html__( 'The demo file server gateway timed out (504 Gateway Timeout).', 'easy-demo-importer' ),
				'hint'    => esc_html__( 'The demo server is temporarily overloaded or unreachable. Try again in a few minutes. If the problem persists, contact the theme author.', 'easy-demo-importer' ),
			],
		];

		if ( isset( $messages[ $code ] ) ) {
			return array_merge( [ 'success' => false ], $messages[ $code ] );
		}

		return [
			'success' => false,
			/* translators: %d: HTTP status code */
			'message' => sprintf( esc_html__( 'The demo file server returned an unexpected response (HTTP %d).', 'easy-demo-importer' ), $code ),
			'hint'    => esc_html__( 'Try again later. If the problem persists, note the error code and contact the theme author.', 'easy-demo-importer' ),
		];
	}
}
