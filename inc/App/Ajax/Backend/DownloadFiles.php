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

		// Response. Friendlier text in the modal; the log keeps the neutral
		// equivalent.
		$this->prepareResponse(
			$success ? 'sd_edi_import_xml' : '',
			$success ? __( 'Importing content…', 'easy-demo-importer' ) : '',
			$success ? __( 'Demo files downloaded — good to go!', 'easy-demo-importer' ) : '',
			! $success,
			! $success ? $result['message'] : '',
			! $success ? $result['hint'] : '',
			$success ? __( 'Demo files downloaded.', 'easy-demo-importer' ) : null
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

		// Allow theme authors to restrict which domains may serve demo files.
		// Return a non-empty array of hostnames to enable the allowlist.
		$allowed_domains = (array) apply_filters( 'sd/edi/allowed_download_domains', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( ! empty( $allowed_domains ) ) {
			$host = (string) wp_parse_url( $external_url, PHP_URL_HOST );

			if ( ! in_array( $host, $allowed_domains, true ) ) {
				return [
					'success' => false,
					'message' => __( 'The demo ZIP URL is not on an allowed domain.', 'easy-demo-importer' ),
					'hint'    => __( 'The demo file host is not in the sd/edi/allowed_download_domains allowlist. Contact the theme author.', 'easy-demo-importer' ),
				];
			}
		}

		$timeout   = (int) apply_filters( 'sd/edi/download_timeout', 120 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$sslverify = (bool) apply_filters( 'sd/edi/download_sslverify', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$demoData  = $this->demoUploadDir() . 'imported-demo-data.zip';
		$partFile  = $demoData . '.part';
		$chunkFile = $demoData . '.chunk';

		// Resume an interrupted download. If a partial file from a previous
		// attempt is on disk, request only the remaining bytes with a Range
		// header so a large demo does not restart from zero on every retry.
		$offset = $wp_filesystem->exists( $partFile ) ? (int) $wp_filesystem->size( $partFile ) : 0;

		// A fresh download streams straight into the part file; a resume streams
		// the ranged response into a side chunk that is then appended, because
		// wp_remote_get()'s stream target is opened truncating, not appending.
		$streamTarget = $offset > 0 ? $chunkFile : $partFile;

		$args = [
			'timeout'   => $timeout,
			'sslverify' => $sslverify,
			// Stream the archive straight to disk instead of buffering it in a
			// PHP string. A large (WooCommerce) demo can exceed the host
			// memory_limit and fatal before a single post is imported;
			// streaming keeps peak memory flat regardless of archive size.
			'stream'    => true,
			'filename'  => $streamTarget,
		];

		if ( $offset > 0 ) {
			$args['headers'] = [ 'Range' => 'bytes=' . $offset . '-' ];
		}

		$response = wp_remote_get( $external_url, $args );

		if ( is_wp_error( $response ) ) {
			// Keep whatever streamed to disk so the next attempt can resume:
			// fold the side chunk (if any) onto the part file rather than
			// discarding the progress made before the connection dropped.
			$this->appendChunk( $chunkFile, $partFile );

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

		if ( 416 === $http_code ) {
			// Range Not Satisfiable: the part file already holds the whole
			// archive, so the empty side chunk is simply discarded.
			$wp_filesystem->delete( $chunkFile );
		} elseif ( 206 === $http_code ) {
			// Partial Content: fold the freshly streamed range onto the part file.
			$this->appendChunk( $chunkFile, $partFile );
		} elseif ( 200 === $http_code ) {
			if ( $offset > 0 ) {
				// The server ignored the Range and re-sent the whole file, so
				// the side chunk is authoritative and replaces the stale part.
				$wp_filesystem->delete( $partFile );
				$wp_filesystem->move( $chunkFile, $partFile, true );
			}
			// A fresh 200 already streamed into the part file directly.
		} else {
			// The streamed file holds the error body, not the zip. Discard both
			// the failed transfer and any earlier partial so a retry restarts clean.
			$wp_filesystem->delete( $chunkFile );
			$wp_filesystem->delete( $partFile );

			return $this->httpCodeToError( (int) $http_code );
		}

		// Promote the completed part file to the final archive name. With
		// 'stream' => true the body was written to disk directly and
		// wp_remote_retrieve_body() is empty, so no put_contents() is needed.
		if ( $wp_filesystem->exists( $partFile ) ) {
			$wp_filesystem->move( $partFile, $demoData, true );
		}

		if ( ! $wp_filesystem->exists( $demoData ) ) {
			return [
				'success' => false,
				'message' => __( 'Demo files were downloaded but could not be saved to the server.', 'easy-demo-importer' ),
				'hint'    => __( 'Check your server disk space and file permissions.', 'easy-demo-importer' ),
			];
		}

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
	 * Appends a streamed chunk file onto an accumulator file and removes the chunk.
	 *
	 * Copies stream-to-stream so a resumed download's peak memory stays flat
	 * regardless of chunk size — get_contents() would re-buffer the whole chunk,
	 * defeating the streaming download. No-op when the chunk does not exist.
	 *
	 * @param string $from Chunk file to append and delete.
	 * @param string $to   Accumulator file appended to (created if absent).
	 *
	 * @return void
	 * @since 2.1.0
	 */
	private function appendChunk( string $from, string $to ): void {
		global $wp_filesystem;

		if ( ! $wp_filesystem->exists( $from ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$src = fopen( $from, 'rb' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$dst = fopen( $to, 'ab' );

		if ( false !== $src && false !== $dst ) {
			stream_copy_to_stream( $src, $dst );
		}

		if ( false !== $src ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $src );
		}

		if ( false !== $dst ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $dst );
		}

		$wp_filesystem->delete( $from );
	}

	/**
	 * Map an HTTP status code to a user-friendly error array.
	 *
	 * @param int $code HTTP status code.
	 *
	 * @return array{success: bool, message: string, hint: string}
	 * @since 2.0.0
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
