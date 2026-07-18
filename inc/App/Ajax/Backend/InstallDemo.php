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
	Functions\ImportLogger,
	Abstracts\ImporterAjax,
	Importer\ChunkedImport,
	Importer\ImportState,
	Importer\ThumbnailRegenerator,
	Utils\FailedMedia,
	Utils\Snapshot
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
		add_action( 'wp_ajax_sd_edi_regenerate_images', [ $this, 'regenerateImages' ] );
		add_action( 'wp_ajax_sd_edi_retry_media', [ $this, 'retryMedia' ] );
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

		// Activity log: ensure the table exists (covers existing installs that
		// pre-date the log), prune old entries, and record the start of this run.
		ImportLogger::maybeInstall();
		ImportLogger::prune();
		ImportLogger::info(
			sprintf(
				/* translators: %s: demo name. */
				esc_html__( 'Content import started for “%s”.', 'easy-demo-importer' ),
				$this->demoSlug
			),
			$this->sessionId,
			$this->demoSlug
		);

		// Opt-in restore point for the MANUAL import path, which enters the
		// pipeline here (nextPhase 'sd_edi_import_xml') and skips Initialize
		// entirely, so Initialize's pre-reset snapshot never runs for it.
		// Manual import uses reset=false, so the tables still hold the
		// pre-import state at this point. The regular (Initialize) path already
		// created the snapshot before its database reset; the per-session guard
		// makes this a no-op there (and on the repeated per-chunk calls) so a
		// snapshot is taken once per import and never twice.
		if ( $this->snapshot && ! Snapshot::isForSession( $this->sessionId ) ) {
			if ( Snapshot::create( $this->sessionId, $this->reset, $this->demoSlug ) ) {
				ImportLogger::info(
					esc_html__( 'Restore point created — this import can be rolled back.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);
			} else {
				ImportLogger::warning(
					esc_html__( 'Restore point skipped — this site is too large to snapshot safely; the import will continue without rollback.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);
			}
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
				esc_html__( 'Content imported — smooth sailing!', 'easy-demo-importer' ),
				false,
				'',
				'',
				esc_html__( 'Content imported.', 'easy-demo-importer' )
			);
			return;
		}

		// ── Chunked import — Stage 1: prepare ───────────────────────────────────────
		try {
			$importer                    = $this->chunkedImporter();
			$importer->fetch_attachments = ( 'true' !== $this->excludeImages );
			$importer->bundled_media_dir = $this->bundledMediaDir();

			if ( $importer->bundled_media_dir && 'true' !== $this->excludeImages ) {
				ImportLogger::info(
					esc_html__( 'Bundled media detected — images will be imported from the demo package instead of downloaded.', 'easy-demo-importer' ),
					$this->sessionId,
					$this->demoSlug
				);
			}

			// The importer reports through logImporterEntry (see chunkedImporter());
			// the buffer only discards any stray output a third-party import hook
			// might print, keeping the AJAX JSON response parseable.
			ob_start();
			$total = $importer->prepare( $xmlFile );
			ob_end_clean();
		} catch ( \Throwable $e ) {
			// Parsing/preparation failed — fall back to the single-shot importer so
			// the import still runs (just without resumability).
			if ( isset( $importer ) ) {
				$importer->state()->delete();
			}

			ImportLogger::error(
				sprintf(
					/* translators: %s: error message. */
					esc_html__( 'Chunked import could not start (%s). Falling back to single-shot import.', 'easy-demo-importer' ),
					$e->getMessage()
				),
				$this->sessionId,
				$this->demoSlug
			);

			$this->importDemoContent( $xmlFile, $this->excludeImages, $this->skipImageRegeneration );

			/** This action is documented in finalizeImport(). */
			do_action( 'sd/edi/after_content_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

			$this->prepareResponse(
				'sd_edi_import_customizer',
				esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
				esc_html__( 'Content imported — smooth sailing!', 'easy-demo-importer' ),
				false,
				'',
				'',
				esc_html__( 'Content imported.', 'easy-demo-importer' )
			);
			return;
		}

		ImportLogger::info(
			sprintf(
				/* translators: %d: number of items queued. */
				esc_html__( 'WXR parsed — %d items queued for import.', 'easy-demo-importer' ),
				(int) $total
			),
			$this->sessionId,
			$this->demoSlug
		);

		// Parse done — free the lock so the first batch request acquires cleanly.
		$this->releaseMutex();

		wp_send_json(
			$this->chunkPayload(
				[
					'nextPhase'        => 'sd_edi_import_xml_batch',
					// Internal sub-phase of content import — no user-facing card.
					// The single "Importing content…" card from the download step
					// persists across prepare → batch → finalize. No progress is
					// reported here on purpose: the bar shimmers (indeterminate)
					// until the first batch reports a real percentage, so it never
					// jumps in at a high value after a silent parse.
					'nextPhaseMessage' => '',
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
	 * @since 2.0.0
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

		// Attachment downloads happen in this stage. Chunked imports always land
		// originals only and defer intermediate sizes to the dedicated
		// regeneration phase, keeping each batch well under the gateway limit.
		$this->deferThumbnailGeneration();

		// Structured notices are logged via logImporterEntry (see chunkedImporter());
		// the buffer only discards any stray third-party import-hook output so the
		// JSON response stays parseable.
		ob_start();
		$result = $this->chunkedImporter()->processBatch();
		ob_end_clean();

		// Work done — free the lock before responding so this import's next
		// request (fired immediately when retryAfter is 0) re-acquires cleanly.
		$this->releaseMutex();

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
					// Internal sub-phase — no user-facing card. Progress is kept so
					// the single content card's bar holds near 100% through the
					// reference-fixup step instead of resetting.
					'nextPhaseMessage' => '',
					'progress'         => $progress,
				]
			)
		);
	}

	/**
	 * Ajax response: Stage 3 — resolve cross-post references and end the import.
	 *
	 * @return void
	 * @since 2.0.0
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

		$importer = $this->chunkedImporter();

		// finalize() -> import_end() suppresses its "All done. Have fun!" notice
		// while a log sink is attached (chunkedImporter() sets one), and the
		// backfill/remap/recount steps are silent, so this call emits nothing. The
		// buffer stays as a safety net that discards any stray output a third-party
		// import_end hook might print, keeping the AJAX JSON response parseable.
		ob_start();
		$importer->finalize();
		ob_end_clean();

		// When images were excluded, strip the now-dangling featured-image links.
		if ( 'true' === $this->excludeImages ) {
			$this->unsetThumbnails();
		}

		// Content import is a mid-pipeline step, not the finish line (regeneration,
		// menus, widgets and finalize still follow), so it logs as info. Only the
		// terminal phase logs success — otherwise an import interrupted after this
		// point but before finalize would be mis-recorded as a completed run.
		ImportLogger::info(
			esc_html__( 'Content import completed.', 'easy-demo-importer' ),
			$this->sessionId,
			$this->demoSlug
		);

		if ( $importer->bundled_media_imported > 0 ) {
			ImportLogger::info(
				sprintf(
					/* translators: %d: number of media files. */
					esc_html__( '%d media files imported from the demo package.', 'easy-demo-importer' ),
					(int) $importer->bundled_media_imported
				),
				$this->sessionId,
				$this->demoSlug
			);
		}

		// Persist any attachments whose download failed so the user can retry just
		// those from the result screen, without re-running the whole import.
		FailedMedia::save( $this->sessionId, (array) $importer->failed_attachments );

		if ( ! empty( $importer->failed_attachments ) ) {
			ImportLogger::warning(
				sprintf(
					/* translators: %d: number of failed media files. */
					esc_html__( '%d media file(s) could not be downloaded — you can retry them from the result screen.', 'easy-demo-importer' ),
					count( $importer->failed_attachments )
				),
				$this->sessionId,
				$this->demoSlug
			);
		}

		/**
		 * Action Hook: 'sd/edi/after_content_import'
		 *
		 * Performs special actions after content import.
		 *
		 * @since 1.1.5
		 */
		do_action( 'sd/edi/after_content_import', $xmlFile, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// The batch loop imported originals only. If regeneration wasn't skipped
		// and this run actually imported media, hand the new attachment IDs to the
		// dedicated, resumable regeneration phase before moving on to customizer.
		// Reference fixup + after-import hooks done — free the lock before the
		// handoff so the next phase acquires cleanly.
		$this->releaseMutex();

		$attachmentIds = ( $this->skipImageRegeneration || 'true' === $this->excludeImages )
			? []
			: $importer->importedAttachmentIds();

		if ( ! empty( $attachmentIds ) ) {
			$this->regenState()->save(
				[
					'ids'    => $attachmentIds,
					'cursor' => 0,
				]
			);

			$this->prepareResponse(
				'sd_edi_regenerate_images',
				esc_html__( 'Regenerating images — sit tight!', 'easy-demo-importer' ),
				esc_html__( 'Content imported — smooth sailing!', 'easy-demo-importer' ),
				false,
				'',
				'',
				esc_html__( 'Content imported.', 'easy-demo-importer' )
			);
			return;
		}

		$this->prepareResponse(
			'sd_edi_import_customizer',
			esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
			esc_html__( 'Content imported — smooth sailing!', 'easy-demo-importer' ),
			false,
			'',
			'',
			esc_html__( 'Content imported.', 'easy-demo-importer' )
		);
	}

	/**
	 * Ajax response: dedicated image-regeneration phase.
	 *
	 * Runs after content import (which imported originals only), before the
	 * customizer phase. Rebuilds the intermediate image sizes for the attachments
	 * imported by this run, in time-boxed, resumable slices driven by the same
	 * cursor + retry/progress protocol as the batch phase — so a large media
	 * library never overruns the gateway wall-clock limit. Only touches this
	 * run's attachment IDs; a site's pre-existing media is never regenerated.
	 * Idempotent: re-running metadata generation for an already-processed
	 * attachment is safe, so a re-issued request simply continues.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function regenerateImages() {
		Helpers::verifyAjaxCall();

		if ( ! $this->acquireMutex() ) {
			$this->respondWaiting( 'sd_edi_regenerate_images' );
			return;
		}

		// wp_generate_attachment_metadata() lives in wp-admin/includes/image.php,
		// which is not guaranteed to be loaded during an AJAX request.
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$state = $this->regenState();
		$data  = $state->load();

		// No IDs to process (missing/stale state) — nothing to do, move on.
		if ( null === $data || empty( $data['ids'] ) ) {
			$state->delete();
			$this->prepareResponse(
				'sd_edi_import_customizer',
				esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
				esc_html__( 'No images to regenerate — skipping.', 'easy-demo-importer' ),
				false,
				'',
				'',
				esc_html__( 'No images to regenerate. Skipping.', 'easy-demo-importer' )
			);
			return;
		}

		$ids    = array_values( array_map( 'intval', (array) $data['ids'] ) );
		$total  = count( $ids );
		$cursor = isset( $data['cursor'] ) ? (int) $data['cursor'] : 0;

		// Kept short (matching the content batch budget) so regeneration returns
		// progress frequently and the bar advances in small steps rather than
		// sitting on the indeterminate shimmer until a single long window
		// finishes. Smaller is also further under the gateway wall-clock limit;
		// the trade-off is more (cheap, immediately re-fired) round-trips.
		$budget = (float) apply_filters( 'sd/edi/regen_chunk_seconds', 10 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );

		while ( $cursor < $total ) {
			$regenerator = ThumbnailRegenerator::forAttachment( $ids[ $cursor ] );

			if ( null !== $regenerator ) {
				$regenerator->regenerate();
			}

			++$cursor;

			// Finish the current attachment, then stop once over budget.
			if ( ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		// Chunk done — free the lock before the immediate (retryAfter:0) re-fire
		// so the next regen request re-acquires cleanly.
		$this->releaseMutex();

		$progress = [
			'processed' => $cursor,
			'total'     => $total,
		];

		if ( $cursor < $total ) {
			$state->save(
				[
					'ids'    => $ids,
					'cursor' => $cursor,
				]
			);

			// Not finished: re-fire this same phase immediately.
			wp_send_json(
				$this->chunkPayload(
					[
						'nextPhase'  => 'sd_edi_regenerate_images',
						'retry'      => true,
						'retryAfter' => 0,
						'progress'   => $progress,
					]
				)
			);
			return;
		}

		$state->delete();

		ImportLogger::info(
			sprintf(
				/* translators: %d: number of attachments. */
				esc_html__( 'Regenerated images for %d attachments.', 'easy-demo-importer' ),
				$total
			),
			$this->sessionId,
			$this->demoSlug
		);

		$this->prepareResponse(
			'sd_edi_import_customizer',
			esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ),
			esc_html__( 'Images regenerated — looking sharp!', 'easy-demo-importer' ),
			false,
			'',
			'',
			esc_html__( 'Images regenerated.', 'easy-demo-importer' )
		);
	}

	/**
	 * State store for the image-regeneration phase of this session.
	 *
	 * Separate from the content-import state so both can coexist for one session
	 * without colliding.
	 *
	 * @return ImportState
	 * @since 2.0.0
	 */
	private function regenState(): ImportState {
		return ImportState::forSession( $this->demoUploadDir( $this->demoDir() ), $this->sessionId, 'regen' );
	}

	/**
	 * Ajax: retry the media downloads that failed during a run.
	 *
	 * Reads the failed-attachment list saved at finalize (keyed by the original
	 * import session, passed as retrySession so it bypasses the live-import
	 * session guard), re-attempts each in time-boxed, resumable slices via a fresh
	 * importer, and rewrites the content URLs for whatever succeeds. The cursor +
	 * tallies round-trip through the request; the storage is cleared when the pass
	 * completes.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function retryMedia() {
		Helpers::verifyAjaxCall();

		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			$wpImporter = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

			if ( file_exists( $wpImporter ) ) {
				require_once $wpImporter;
			}
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verifyAjaxCall() checked the nonce.
		$retry_session = isset( $_POST['retrySession'] ) ? sanitize_text_field( wp_unslash( $_POST['retrySession'] ) ) : '';
		$cursor        = isset( $_POST['retryCursor'] ) ? absint( wp_unslash( $_POST['retryCursor'] ) ) : 0;
		$recovered     = isset( $_POST['recovered'] ) ? absint( wp_unslash( $_POST['recovered'] ) ) : 0;
		$still_failed  = isset( $_POST['stillFailed'] ) ? absint( wp_unslash( $_POST['stillFailed'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A slice can recover records and then lose its response to a gateway
		// timeout; the client would retry from its stale cursor and re-import the
		// already-recovered attachments (process_attachment does not dedup
		// attachments). Persist the server-side position and clamp the client's
		// cursor up to it so recovered records are never processed twice.
		$state_key = 'sd_edi_retry_state_' . md5( $retry_session );
		$saved     = get_option( $state_key, [] );

		if ( is_array( $saved ) && isset( $saved['cursor'] ) && (int) $saved['cursor'] > $cursor ) {
			$cursor       = (int) $saved['cursor'];
			$recovered    = isset( $saved['recovered'] ) ? (int) $saved['recovered'] : $recovered;
			$still_failed = isset( $saved['stillFailed'] ) ? (int) $saved['stillFailed'] : $still_failed;
		}

		// The importer failing to load is a real error, not a completed retry —
		// report it rather than a silent "finished".
		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			wp_send_json_error(
				[ 'message' => esc_html__( 'The importer could not be loaded. Please try again.', 'easy-demo-importer' ) ],
				500
			);
		}

		$items = FailedMedia::get( $retry_session );
		$total = count( $items );

		if ( empty( $items ) ) {
			FailedMedia::clear( $retry_session );
			delete_option( $state_key );
			wp_send_json_success(
				[
					'done'        => true,
					'cursor'      => $cursor,
					'total'       => $total,
					'recovered'   => $recovered,
					'stillFailed' => $still_failed,
				]
			);
		}

		/** This action is documented in response(); re-applies the per-request resource boost. */
		do_action( 'sd/edi/before_import', '', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$importer                    = new SD_EDI_WP_Import();
		$importer->fetch_attachments = true;
		$importer->bundled_media_dir = $this->bundledMediaDir();
		$importer->log_callback      = [ $this, 'logImporterEntry' ];

		$budget = (float) apply_filters( 'sd/edi/retry_chunk_seconds', 20 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );

		ob_start();

		while ( $cursor < $total ) {
			$record = isset( $items[ $cursor ] ) ? $items[ $cursor ] : [];
			$data   = isset( $record['data'] ) ? (array) $record['data'] : [];
			$url    = isset( $record['url'] ) ? (string) $record['url'] : '';

			$result = ( '' !== $url && ! empty( $data ) )
				? $importer->process_attachment( $data, $url )
				: new \WP_Error( 'sd_edi_retry_invalid', 'Invalid record.' );

			if ( is_wp_error( $result ) ) {
				++$still_failed;
			} else {
				++$recovered;
			}

			++$cursor;

			// Persist the position after every record so a killed response can
			// never cause a re-import on the client's retry.
			update_option(
				$state_key,
				[
					'cursor'      => $cursor,
					'recovered'   => $recovered,
					'stillFailed' => $still_failed,
				],
				false
			);

			if ( ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		// Accumulate this slice's URL remaps rather than back-filling now: a
		// full-table REPLACE per slice repeats costly posts/postmeta scans and can
		// itself exceed the gateway timeout. All remaps are applied once, below,
		// on the final slice.
		$remap_key = 'sd_edi_retry_remap_' . md5( $retry_session );

		if ( ! empty( $importer->url_remap ) ) {
			$accumulated = get_option( $remap_key, [] );
			$accumulated = is_array( $accumulated ) ? $accumulated : [];
			update_option( $remap_key, array_merge( $accumulated, $importer->url_remap ), false );
		}

		ob_end_clean();

		if ( $cursor < $total ) {
			wp_send_json_success(
				[
					'done'        => false,
					'cursor'      => $cursor,
					'total'       => $total,
					'recovered'   => $recovered,
					'stillFailed' => $still_failed,
				]
			);
		}

		// Final slice: apply every accumulated remap in a single backfill pass.
		$accumulated = get_option( $remap_key, [] );

		if ( is_array( $accumulated ) && ! empty( $accumulated ) ) {
			$importer->url_remap = $accumulated;
			$importer->backfill_attachment_urls();
		}

		delete_option( $remap_key );

		// One pass complete — clear the stored list and the resume state.
		FailedMedia::clear( $retry_session );
		delete_option( $state_key );

		ImportLogger::info(
			sprintf(
				/* translators: 1: recovered count, 2: still-failed count. */
				esc_html__( 'Media retry finished — %1$d recovered, %2$d still failed.', 'easy-demo-importer' ),
				$recovered,
				$still_failed
			),
			$retry_session,
			$this->demoSlug
		);

		wp_send_json_success(
			[
				'done'        => true,
				'cursor'      => $cursor,
				'total'       => $total,
				'recovered'   => $recovered,
				'stillFailed' => $still_failed,
			]
		);
	}

	/**
	 * Whether the resumable chunked importer is enabled.
	 *
	 * @return bool
	 * @since 2.0.0
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
	 * @since 2.0.0
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

		$importer               = new ChunkedImport( $state );
		$importer->log_callback = [ $this, 'logImporterEntry' ];

		return $importer;
	}

	/**
	 * Absolute path to this demo's bundled `uploads/` folder, or '' if none.
	 *
	 * Bundled-media mode turns on automatically when the extracted demo package
	 * contains an `uploads/` folder. Filterable so a theme author can force it on
	 * or off per demo. When empty, every attachment is fetched over HTTP as before.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private function bundledMediaDir(): string {
		$dir = $this->demoUploadDir( $this->demoDir() ) . '/uploads';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$enabled = (bool) apply_filters( 'sd/edi/importer/bundled_media_enabled', is_dir( $dir ), $this->demoSlug, $this->config );

		return ( $enabled && is_dir( $dir ) ) ? $dir : '';
	}

	/**
	 * Suppresses inline intermediate-size generation during the batch loop.
	 *
	 * Chunked imports download originals only and (re)build the resized sub-sizes
	 * in the dedicated regeneration phase, so each attachment stays cheap and no
	 * batch overruns the gateway wall-clock limit. Only the sub-sizes are
	 * deferred — base attachment metadata (dimensions, file) is still written, so
	 * every image works at full size between phases. Request-scoped: filters
	 * vanish at request end, so this runs on every batch that downloads media.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private function deferThumbnailGeneration(): void {
		add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
	}

	/**
	 * Records a structured notice from the bundled importer.
	 *
	 * Wired to SD_EDI_WP_Import::$log_callback so the importer reports each
	 * per-item outcome as an explicit ( message, level ) pair — rather than
	 * printing HTML this handler would have to capture and re-parse, guessing the
	 * severity back out of the wording under a locale-dependent match. The
	 * importer already declares the level (an "already exists" or "image import
	 * off" skip is info, a real failure is a warning), so it is stored verbatim;
	 * ImportLogger mirrors warnings and errors to wp-content/debug.log.
	 *
	 * @param string $message Human-readable notice from the importer.
	 * @param string $level   One of info|success|warning|error.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function logImporterEntry( string $message, string $level ): void {
		ImportLogger::log(
			$message,
			ImportLogger::normalizeLevel( $level ),
			$this->sessionId,
			$this->demoSlug
		);
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
	 * @since 2.0.0
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
	 * Releases the import mutex immediately.
	 *
	 * A phase holds the lock only for its own work. Releasing it right before the
	 * response is sent — rather than waiting for the shutdown handler that runs
	 * after the response — lets this same import's very next request re-acquire
	 * without the spurious "another import is running" wait the shutdown-timing
	 * race would otherwise cause (worst with the retryAfter:0 batch/regen loops).
	 * The shutdown handler registered in acquireMutex() stays as a crash-safety net.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private function releaseMutex(): void {
		delete_option( 'sd_edi_xml_import_mutex' );
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
	 * @since 2.0.0
	 */
	private function respondWaiting( string $phase ): void {
		wp_send_json(
			$this->chunkPayload(
				[
					'nextPhase'        => $phase,
					'nextPhaseMessage' => esc_html__( 'Waiting for previous import to finish…', 'easy-demo-importer' ),
					'retry'            => true,
					// Distinguishes a genuine lock conflict (this) from the
					// batch/regen tight-loop continuations, which also set
					// retry:true. Only this drives the client's visible
					// "waiting for another import" counter and its cap.
					'mutexHeld'        => true,
					// Kept short: the common case here is a benign timing overlap in
					// the tight batch loop (the previous request's shutdown handler
					// has not released the lock yet), not real contention — so a brief
					// re-check avoids a jarring multi-second stall. Genuine concurrent
					// imports simply poll every 2s until the other finishes.
					'retryAfter'       => 2,
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
	 * @since 2.0.0
	 */
	private function chunkPayload( array $extra ): array {
		return array_merge(
			[
				'demo'                  => $this->demoSlug,
				'excludeImages'         => $this->excludeImages,
				'skipImageRegeneration' => $this->skipImageRegeneration,
				'reset'                 => $this->reset,
				'snapshot'              => $this->snapshot,
				'manual'                => $this->manual ? 'true' : 'false',
				'manualKey'             => $this->manualKey,
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
				$wp_import->bundled_media_dir = $this->bundledMediaDir();
				$wp_import->log_callback      = [ $this, 'logImporterEntry' ];

				if ( $skipImageRegeneration ) {
					add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
					add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
				}

				// Import XML. Notices are logged through logImporterEntry (log_callback
				// set above); the buffer only discards stray third-party hook output.
				ob_start();
				$wp_import->import( $xmlFilePath );
				ob_end_clean();

				// Persist failed attachment downloads for the retry flow.
				FailedMedia::save( $this->sessionId, (array) $wp_import->failed_attachments );

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
	 * @since 2.0.0
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
