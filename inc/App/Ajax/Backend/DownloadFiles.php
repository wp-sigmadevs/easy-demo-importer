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

use FilesystemIterator;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
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

		$demoZip = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'demoZip' ) :
			Helpers::getDemoData( $this->config, 'demoZip' );
		$result  = $this->downloadDemoFiles( $demoZip );
		$success = $result['success'];

		// Response.
		$this->prepareResponse(
			$success ? 'sd_edi_import_xml' : '',
			$success ? __( 'Importing content, sit back, relax! This might take a while.', 'easy-demo-importer' ) : '',
			$success ? __( 'Demo files have landed, we are good to go!', 'easy-demo-importer' ) : '',
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
				'message' => __( 'Could not create the demo upload directory.', 'easy-demo-importer' ),
				'hint'    => __( 'Check that your uploads directory is writable. Go to Tools > Site Health for file permission details.', 'easy-demo-importer' ),
			];
		}

		// Validate the demo ZIP URL before making any network request.
		if ( ! wp_http_validate_url( $external_url ) ) {
			return [
				'success' => false,
				'message' => __( 'The demo ZIP URL is not a valid URL.', 'easy-demo-importer' ),
				'hint'    => __( 'Check the demoZip value in your theme configuration.', 'easy-demo-importer' ),
			];
		}

		$parsed_scheme = wp_parse_url( $external_url, PHP_URL_SCHEME );
		if ( ! in_array( $parsed_scheme, [ 'http', 'https' ], true ) ) {
			return [
				'success' => false,
				'message' => __( 'The demo ZIP URL must use http or https.', 'easy-demo-importer' ),
				'hint'    => __( 'Check the demoZip value in your theme configuration.', 'easy-demo-importer' ),
			];
		}

		$timeout   = (int) apply_filters( 'sd/edi/download_timeout', 120 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sslverify = (bool) apply_filters( 'sd/edi/download_sslverify', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
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
					'message' => __( 'Could not verify the SSL certificate of the demo file server.', 'easy-demo-importer' ),
					'hint'    => sprintf(
						/* translators: %s: WordPress filter name */
						__( "Your server's SSL/cURL configuration may be outdated. Ask your host to update their CA certificate bundle. As a temporary workaround, add %s to your theme's functions.php — note: this disables SSL certificate verification.", 'easy-demo-importer' ),
						'add_filter(\'sd/edi/download_sslverify\', \'__return_false\');'
					),
				];
			}

			return [
				'success' => false,
				'message' => __( 'Lost connection to the demo file server.', 'easy-demo-importer' ),
				'hint'    => __( 'Check that your server has outbound internet access. If you are on a local environment, ensure it can reach external URLs.', 'easy-demo-importer' ),
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
				'message' => __( 'Demo files were downloaded but could not be extracted.', 'easy-demo-importer' ),
				'hint'    => __( 'Ensure the ZipArchive PHP extension is enabled. Contact your host if you are unsure.', 'easy-demo-importer' ),
			];
		}

		// Guard against ZipSlip: ensure every extracted file is inside the upload dir.
		$upload_dir_real = realpath( $this->demoUploadDir() );

		if ( false !== $upload_dir_real ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $upload_dir_real, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);

			foreach ( $iterator as $item ) {
				$real_item = realpath( $item->getPathname() );

				if ( false !== $real_item && 0 !== strpos( $real_item, $upload_dir_real ) ) {
					// Escape hatch: wipe the dir and reject the archive.
					$wp_filesystem->delete( $this->demoUploadDir(), true );

					return [
						'success' => false,
						'message' => __( 'The demo archive contained unsafe file paths and was rejected.', 'easy-demo-importer' ),
						'hint'    => __( 'Contact the theme author — the demo ZIP may be corrupted or tampered with.', 'easy-demo-importer' ),
					];
				}
			}
		}

		return [
			'success' => true,
			'message' => '',
			'hint'    => '',
		];
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
				'message' => __( 'Access to the demo file was denied (401 Unauthorized).', 'easy-demo-importer' ),
				'hint'    => __( 'The demo file URL may require authentication. Contact the theme author to verify the demo file URL is publicly accessible.', 'easy-demo-importer' ),
			],
			403 => [
				'message' => __( 'Access to the demo file was forbidden (403 Forbidden).', 'easy-demo-importer' ),
				'hint'    => __( 'The demo file server is blocking the request. This can happen if your server IP is restricted. Contact the theme author.', 'easy-demo-importer' ),
			],
			404 => [
				'message' => __( 'The demo file was not found on the server (404 Not Found).', 'easy-demo-importer' ),
				'hint'    => __( 'The demo ZIP URL in the theme configuration may be incorrect or the file has been moved. Contact the theme author for an updated demo package.', 'easy-demo-importer' ),
			],
			408 => [
				'message' => __( 'The demo file server took too long to respond (408 Request Timeout).', 'easy-demo-importer' ),
				'hint'    => __( 'The file server may be temporarily overloaded. Try again in a few minutes. You can also increase the timeout by adding add_filter(\'sd/edi/download_timeout\', fn() => 300); to your theme\'s functions.php.', 'easy-demo-importer' ),
			],
			429 => [
				'message' => __( 'Too many download requests were made (429 Too Many Requests).', 'easy-demo-importer' ),
				'hint'    => __( 'Wait a few minutes before trying again. The demo file server may be rate-limiting requests.', 'easy-demo-importer' ),
			],
			500 => [
				'message' => __( 'The demo file server encountered an internal error (500 Internal Server Error).', 'easy-demo-importer' ),
				'hint'    => __( 'This is a problem on the demo file server, not your site. Try again later or contact the theme author.', 'easy-demo-importer' ),
			],
			503 => [
				'message' => __( 'The demo file server is temporarily unavailable (503 Service Unavailable).', 'easy-demo-importer' ),
				'hint'    => __( 'The demo server may be undergoing maintenance. Wait a few minutes and try again.', 'easy-demo-importer' ),
			],
			504 => [
				'message' => __( 'The demo file server gateway timed out (504 Gateway Timeout).', 'easy-demo-importer' ),
				'hint'    => __( 'The demo server is temporarily overloaded or unreachable. Try again in a few minutes. If the problem persists, contact the theme author.', 'easy-demo-importer' ),
			],
		];

		if ( isset( $messages[ $code ] ) ) {
			return array_merge( [ 'success' => false ], $messages[ $code ] );
		}

		return [
			'success' => false,
			/* translators: %d: HTTP status code */
			'message' => sprintf( __( 'The demo file server returned an unexpected response (HTTP %d).', 'easy-demo-importer' ), $code ),
			'hint'    => __( 'Try again later. If the problem persists, note the error code and contact the theme author.', 'easy-demo-importer' ),
		];
	}
}
