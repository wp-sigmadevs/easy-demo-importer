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

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SD_EDI_WP_Import;
use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Abstracts\ImporterAjax,
	Importer\ChunkedImport,
	Importer\ImportState
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
		add_action( 'wp_ajax_sd_edi_import_xml_batch', [ $this, 'importBatch' ] );
		add_action( 'wp_ajax_sd_edi_import_xml_finalize', [ $this, 'finalizeImport' ] );
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

		if ( ! $this->acquireMutex() ) {
			$this->respondWaiting( 'sd_edi_import_xml' );
			return;
		}

		$xmlFile = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';

		if ( ! file_exists( $xmlFile ) ) {
			$this->prepareResponse(
				'',
				'',
				'',
				true,
				esc_html__( 'Demo import process failed. No content file found.', 'easy-demo-importer' )
			);
			return;
		}

		/**
		 * Action Hook: 'sd/edi/before_import'
		 *
		 * Performs special actions before demo import. Fired at the start of
		 * every chunk request (prepare/batch/finalize) so the resource boost in
		 * beforeImportActions() applies to each request, not just the first.
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/before_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Clear any nav menus left by a previous (possibly partial) run so the
		// importer always starts from a clean slate.
		$this->clearNavMenus();

		// ── Legacy single-shot fallback ─────────────────────────────────────────────
		// Disabled by returning false from the filter, or used automatically if the
		// chunked prepare stage cannot start. Preserves the pre-1.2.0 behavior for
		// small demos / non-proxied hosts.
		if ( ! $this->chunkedEnabled() ) {
			$this->importDemoContent( $xmlFile, $this->excludeImages, $this->skipImageRegeneration );

			/** This action is documented in finalizeImport(). */
			do_action( 'sd/edi/after_content_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			$this->prepareResponse(
				'sd_edi_import_customizer',
				esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
				esc_html__( 'Everything has been imported smoothly.', 'easy-demo-importer' )
			);
			return;
		}

		// ── Chunked import — Stage 1: prepare ───────────────────────────────────────
		try {
			$importer                    = $this->chunkedImporter();
			$importer->fetch_attachments = ( 'true' !== $this->excludeImages );

			ob_start();
			$total = $importer->prepare( $xmlFile );
			ob_end_clean();
		} catch ( \Throwable $e ) {
			// Parsing/preparation failed — fall back to the single-shot importer so
			// the import still runs (just without resumability).
			if ( isset( $importer ) ) {
				$importer->state()->delete();
			}

			$this->importDemoContent( $xmlFile, $this->excludeImages, $this->skipImageRegeneration );

			/** This action is documented in finalizeImport(). */
			do_action( 'sd/edi/after_content_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			$this->prepareResponse(
				'sd_edi_import_customizer',
				esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
				esc_html__( 'Everything has been imported smoothly.', 'easy-demo-importer' )
			);
			return;
		}

		wp_send_json(
			$this->chunkPayload(
				[
					'nextPhase'        => 'sd_edi_import_xml_batch',
					'nextPhaseMessage' => esc_html__( 'Importing content…', 'easy-demo-importer' ),
					'progress'         => [
						'processed' => 0,
						'total'     => $total,
					],
				]
			)
		);
	}

	/**
	 * Ajax response: Stage 2 — process one time-boxed batch of posts.
	 *
	 * Re-fires itself (via the retry protocol) until every post is processed,
	 * then advances to the finalize stage. Idempotent and safe to re-issue.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function importBatch() {
		Helpers::verifyAjaxCall();

		if ( ! $this->acquireMutex() ) {
			$this->respondWaiting( 'sd_edi_import_xml_batch' );
			return;
		}

		$xmlFile = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';

		/** This action is documented in response(); re-applies the per-request resource boost. */
		do_action( 'sd/edi/before_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Attachment downloads happen in this stage, so the skip-regeneration
		// filters must be active here (not in prepare).
		$this->maybeSkipImageRegeneration();

		ob_start();
		$result = $this->chunkedImporter()->processBatch();
		ob_end_clean();

		$progress = [
			'processed' => (int) $result['processed'],
			'total'     => (int) $result['total'],
		];

		if ( empty( $result['done'] ) ) {
			// Not finished: re-fire this same phase immediately. retryAfter:0 keeps
			// the loop tight (the client sends no delay when retryAfter is 0).
			wp_send_json(
				$this->chunkPayload(
					[
						'nextPhase'  => 'sd_edi_import_xml_batch',
						'retry'      => true,
						'retryAfter' => 0,
						'progress'   => $progress,
					]
				)
			);
			return;
		}

		wp_send_json(
			$this->chunkPayload(
				[
					'nextPhase'        => 'sd_edi_import_xml_finalize',
					'nextPhaseMessage' => esc_html__( 'Finalizing content…', 'easy-demo-importer' ),
					'progress'         => $progress,
				]
			)
		);
	}

	/**
	 * Ajax response: Stage 3 — resolve cross-post references and end the import.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function finalizeImport() {
		Helpers::verifyAjaxCall();

		if ( ! $this->acquireMutex() ) {
			$this->respondWaiting( 'sd_edi_import_xml_finalize' );
			return;
		}

		$xmlFile = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';

		/** This action is documented in response(); re-applies the per-request resource boost. */
		do_action( 'sd/edi/before_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		ob_start();
		$this->chunkedImporter()->finalize();
		ob_end_clean();

		// When images were excluded, strip the now-dangling featured-image links.
		if ( 'true' === $this->excludeImages ) {
			$this->unsetThumbnails();
		}

		/**
		 * Action Hook: 'sd/edi/after_content_import'
		 *
		 * Performs special actions after content import.
		 *
		 * @since 1.1.5
		 */
		do_action( 'sd/edi/after_content_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$this->prepareResponse(
			'sd_edi_import_customizer',
			esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
			esc_html__( 'Everything has been imported smoothly.', 'easy-demo-importer' )
		);
	}

	/**
	 * Whether the resumable chunked importer is enabled.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function chunkedEnabled(): bool {
		return (bool) apply_filters( 'sd/edi/enable_chunked_import', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Builds a ChunkedImport bound to this session's state store.
	 *
	 * The state path is derived from the demo working directory + session id, so
	 * it is stable across the prepare/batch/finalize requests of one import.
	 *
	 * @return ChunkedImport
	 * @since 1.2.0
	 */
	private function chunkedImporter(): ChunkedImport {
		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			$wpImporter = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

			if ( file_exists( $wpImporter ) ) {
				require_once $wpImporter;
			}
		}

		$state = ImportState::forSession( $this->demoUploadDir( $this->demoDir() ), $this->sessionId );

		return new ChunkedImport( $state );
	}

	/**
	 * Adds the filters that skip intermediate image generation, when requested.
	 *
	 * Request-scoped: filters vanish at request end, so this runs on every batch
	 * request that may download attachments.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function maybeSkipImageRegeneration(): void {
		if ( $this->skipImageRegeneration ) {
			add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
			add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
		}
	}

	/**
	 * Acquires the cross-request import mutex.
	 *
	 * INSERT IGNORE on wp_options is atomic: MySQL's unique key on option_name
	 * guarantees exactly one process holds the row. A stale lock (held > 30 min,
	 * i.e. the holder crashed) is force-released so the site is never stuck — the
	 * session state file is left intact so the import can resume. On success a
	 * shutdown handler releases the lock, covering die()/timeout/fatal exits that
	 * try/finally cannot.
	 *
	 * @return bool True if the lock was acquired by this request.
	 * @since 1.2.0
	 */
	private function acquireMutex(): bool {
		global $wpdb;

		$mutex_option = 'sd_edi_xml_import_mutex';
		$mutex_ts     = (int) get_option( $mutex_option, 0 );

		if ( $mutex_ts && ( time() - $mutex_ts ) > 30 * MINUTE_IN_SECONDS ) {
			delete_option( $mutex_option );
			$mutex_ts = 0;
		}

		if ( $mutex_ts ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$mutex_option,
				(string) time()
			)
		);

		if ( $acquired ) {
			register_shutdown_function(
				static function ( string $opt ) {
					delete_option( $opt );
				},
				$mutex_option
			);
		}

		return $acquired;
	}

	/**
	 * Emits a "waiting for the previous request to finish" retry response.
	 *
	 * The client re-sends the same request after retryAfter seconds without
	 * advancing the pipeline.
	 *
	 * @param string $phase The AJAX action to retry.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function respondWaiting( string $phase ): void {
		wp_send_json(
			$this->chunkPayload(
				[
					'nextPhase'        => $phase,
					'nextPhaseMessage' => esc_html__( 'Waiting for previous import to finish…', 'easy-demo-importer' ),
					'retry'            => true,
					'retryAfter'       => 5,
				]
			)
		);
	}

	/**
	 * Builds a chunk-phase response payload, merged over shared defaults.
	 *
	 * @param array $extra Fields overriding the defaults.
	 *
	 * @return array
	 * @since 1.2.0
	 */
	private function chunkPayload( array $extra ): array {
		return array_merge(
			[
				'demo'                  => $this->demoSlug,
				'excludeImages'         => $this->excludeImages,
				'skipImageRegeneration' => $this->skipImageRegeneration,
				'reset'                 => $this->reset,
				'sessionId'             => $this->sessionId,
				'nextPhase'             => '',
				'nextPhaseMessage'      => '',
				'completedMessage'      => '',
				'error'                 => false,
				'retry'                 => false,
			],
			$extra
		);
	}

	/**
	 * Import demo content.
	 *
	 * @param string $xmlFilePath XML file path.
	 * @param string $excludeImages Exclude images.
	 * @param bool   $skipImageRegeneration Skip image regeneration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function importDemoContent( $xmlFilePath, $excludeImages, $skipImageRegeneration ) {
		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
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

				if ( $skipImageRegeneration ) {
					add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
					add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
				}

				// Import XML.
				ob_start();
				$wp_import->import( $xmlFilePath );
				ob_end_clean();

				if ( ! $excludeImages ) {
					$this->unsetThumbnails();
				}

				if ( $skipImageRegeneration ) {
					remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
					remove_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
				}
			}
		}
	}

	/**
	 * Hard-delete all nav menu taxonomy terms and every nav_menu_item post.
	 *
	 * Uses direct SQL instead of the WordPress API so that no WordPress filter,
	 * object cache, or post-status guard can leave rows behind.  Called before
	 * every XML import so that a resume run always starts from a clean slate.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function clearNavMenus() {
		global $wpdb;

		// ── Step 1: collect every nav_menu term ID straight from the DB ──────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_ids = $wpdb->get_col(
			"SELECT tt.term_id
			 FROM {$wpdb->term_taxonomy} tt
			 WHERE tt.taxonomy = 'nav_menu'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item'"
		);

		if ( ! empty( $term_ids ) ) {
			$term_ids_in = implode( ',', array_map( 'intval', $term_ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$term_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id IN ({$term_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$term_ids_in})" );
		}

		if ( ! empty( $post_ids ) ) {
			$post_ids_in = implode( ',', array_map( 'intval', $post_ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$post_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$post_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$post_ids_in})" );
		}

		// Flush the nav-menu object cache so the importer sees a clean slate.
		wp_cache_delete( 'last_changed', 'nav_menu' );
		clean_term_cache( $term_ids ?: [], 'nav_menu' );
	}

	/**
	 * Unset featured images from posts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function unsetThumbnails() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key = %s", '_thumbnail_id' )
		);
	}
}
