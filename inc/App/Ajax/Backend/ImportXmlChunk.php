<?php
/**
 * Ajax Class: ImportXmlChunk
 *
 * Processes one chunk of the WXR XML file using the existing WordPress importer.
 * Called repeatedly by the frontend polling loop until done === total.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Abstracts\ImporterAjax,
	Utils\XmlChunker,
	Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportXmlChunk
 *
 * @since 1.3.0
 */
class ImportXmlChunk extends ImporterAjax {
	use Singleton;

	/**
	 * Register AJAX action.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function register() {
		parent::register();

		add_action( 'wp_ajax_sd_edi_import_xml_chunk', array( $this, 'response' ) );
	}

	/**
	 * Handle one chunk import.
	 *
	 * POST params:
	 *   sessionId  — active session UUID
	 *   offset     — zero-based item index to start from
	 *   demo       — demo slug (for multi-demo themes)
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function response() {
		$this->handlePostSubmission();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$xml_path = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';

		if ( ! file_exists( $xml_path ) ) {
			wp_send_json_error( array( 'errorMessage' => __( 'XML file not found.', 'easy-demo-importer' ) ), 404 );
		}

		// Total item count is stored in a session transient to avoid re-scanning.
		$session_key = 'sd_edi_xml_total_' . $this->sessionId;
		$cached      = get_transient( $session_key );

		if ( false === $cached ) {
			$items  = XmlChunker::getItems( $xml_path );
			$cached = count( $items );
			set_transient( $session_key, $cached, HOUR_IN_SECONDS );
		}

		$total = (int) $cached;

		$limit     = XmlChunker::chunkSize();
		$chunk_tmp = XmlChunker::extractChunk( $xml_path, $offset, $limit );

		if ( ! $chunk_tmp ) {
			// Offset is past end — import complete.
			ImportLogger::log(
				__( 'XML content import complete.', 'easy-demo-importer' ),
				'success',
				$this->sessionId
			);

			$this->prepareResponse(
				'sd_edi_import_customizer',
				esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
				esc_html__( 'XML content fully imported.', 'easy-demo-importer' )
			);
			return;
		}

		// Run the existing importer on the chunk.
		try {
			$this->importChunkFile( $chunk_tmp );
		} finally {
			@unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$done = min( $offset + $limit, $total );

		ImportLogger::log(
			sprintf(
				/* translators: 1: first item number, 2: last item number, 3: total */
				__( 'Imported items %1$d–%2$d of %3$d.', 'easy-demo-importer' ),
				$offset + 1,
				$done,
				$total
			),
			'info',
			$this->sessionId
		);

		wp_send_json_success(
			array(
				'done'   => $done,
				'total'  => $total,
				'offset' => $done,
			)
		);
	}

	/**
	 * Pass a chunk WXR file to the existing importer.
	 *
	 * @param string $chunk_path Absolute path to the temp chunk WXR file.
	 * @return void
	 * @since 1.3.0
	 */
	private function importChunkFile( string $chunk_path ): void {
		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			$importer_path = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

			if ( file_exists( $importer_path ) ) {
				require_once $importer_path;
			}
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			return;
		}

		$exclude_images = ! ( 'true' === $this->excludeImages );

		if ( $this->skipImageRegeneration ) {
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
			add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
		}

		$wp_import                    = new \SD_EDI_WP_Import();
		$wp_import->fetch_attachments = $exclude_images;

		ob_start();
		$wp_import->import( $chunk_path );
		ob_end_clean();

		if ( $this->skipImageRegeneration ) {
			remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
			remove_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
		}
	}
}
