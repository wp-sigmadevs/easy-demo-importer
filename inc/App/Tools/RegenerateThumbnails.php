<?php
/**
 * Tools Class: RegenerateThumbnails
 *
 * A standalone "Easy Thumbnails" utility under the Appearance menu.
 * Reuses the resumable, time-boxed ThumbnailRegenerator engine (the same one
 * that powers the import's regeneration phase) to rebuild intermediate image
 * sizes across the whole media library without timing out on large libraries.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Tools;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Functions\ImportLogger,
	Importer\ThumbnailRegenerator
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Tools Class: RegenerateThumbnails
 *
 * @since 2.0.0
 */
class RegenerateThumbnails extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 2.0.0
	 */
	use Singleton;

	/**
	 * Required capability.
	 */
	const CAP = 'manage_options';

	/**
	 * AJAX action + nonce name.
	 */
	const ACTION = 'sd_edi_regen_thumbnails';

	/**
	 * Image IDs fetched per request before the time-box is checked.
	 */
	const PAGE_LIMIT = 50;

	/**
	 * Slug the activity log groups regeneration runs under (rendered as
	 * "Thumbnail Regeneration" in the Activity tab).
	 */
	const LOG_SLUG = 'thumbnail-regeneration';

	/**
	 * Registers the AJAX handler.
	 *
	 * The page itself is a React route on the Easy Demo Importer screen
	 * (`#/regenerate_thumbnails`), registered in App\Backend\Pages — so this
	 * class only wires the batch-processing endpoint that route talks to.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * AJAX: process one time-boxed page of image attachments.
	 *
	 * Stateless across requests: the cursor is the last processed attachment ID,
	 * round-tripped via the request. Idempotent — re-running metadata generation
	 * for an already-processed image is safe, so a re-issue simply continues.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function handle() {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Refresh the page and try again.', 'easy-demo-importer' ) ], 403 );
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You do not have permission to do this.', 'easy-demo-importer' ) ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$probe       = isset( $_POST['probe'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['probe'] ) );
		$single      = isset( $_POST['single'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['single'] ) );
		$after       = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;
		$force       = isset( $_POST['force'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		$session     = isset( $_POST['session'] ) ? sanitize_text_field( wp_unslash( $_POST['session'] ) ) : '';
		$regenerated = isset( $_POST['regenerated'] ) ? absint( wp_unslash( $_POST['regenerated'] ) ) : 0;
		$skipped     = isset( $_POST['skipped'] ) ? absint( wp_unslash( $_POST['skipped'] ) ) : 0;
		$failed      = isset( $_POST['failed'] ) ? absint( wp_unslash( $_POST['failed'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$total = $this->totalImages();

		// The React page probes once on mount to show the library size before
		// the user starts — return just the count, do no work.
		if ( $probe ) {
			wp_send_json_success(
				[
					'done'        => false,
					'probe'       => true,
					'session'     => '',
					'after'       => 0,
					'total'       => $total,
					'items'       => [],
					'regenerated' => 0,
					'skipped'     => 0,
					'failed'      => 0,
				]
			);
		}

		// An empty session marks the first request: mint an id and open the run
		// in the activity log. The client round-trips it on every later request
		// so start/finish entries all group under the one run.
		if ( '' === $session ) {
			$session = 'regen_' . substr( md5( uniqid( '', true ) ), 0, 16 );
			$this->logStart( $session, $total, $force, $single );
		}

		// One-at-a-time mode does exactly one image per request; the default
		// batches a time-boxed page so large libraries finish in fewer round-trips.
		$limit = $single ? 1 : self::PAGE_LIMIT;
		$ids   = $this->imageIdsAfter( $after, $limit );

		if ( empty( $ids ) ) {
			$this->logFinish( $session, $regenerated, $skipped, $failed );

			wp_send_json_success(
				[
					'done'        => true,
					'session'     => $session,
					'after'       => $after,
					'total'       => $total,
					'items'       => [],
					'regenerated' => $regenerated,
					'skipped'     => $skipped,
					'failed'      => $failed,
				]
			);
		}

		$budget = (float) apply_filters( 'sd/edi/regen_tool_seconds', 15 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );
		$last   = $after;
		$items  = [];

		foreach ( $ids as $id ) {
			$id          = (int) $id;
			$regenerator = ThumbnailRegenerator::forAttachment( $id );

			if ( null === $regenerator ) {
				++$skipped;
				$status = 'skipped';
			} elseif ( $regenerator->regenerate( ! $force ) ) {
				++$regenerated;
				$status = 'regenerated';
			} else {
				++$failed;
				$status = 'failed';
			}

			$item    = $this->itemDetail( $id, $status );
			$last    = $id;
			$items[] = $item;

			// Only failures are logged per-image — the full run stays a short
			// summary in the activity log, while the live page list carries the
			// complete image-by-image detail.
			if ( 'failed' === $status ) {
				ImportLogger::warning(
					/* translators: %s: image title or filename. */
					sprintf( esc_html__( 'Could not regenerate: %s', 'easy-demo-importer' ), $item['title'] ),
					$session,
					self::LOG_SLUG
				);
			}

			// Finish the current image, then stop once over budget (skipped in
			// single mode, which already stops after one).
			if ( ! $single && ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		wp_send_json_success(
			[
				'done'        => false,
				'session'     => $session,
				'after'       => $last,
				'total'       => $total,
				'items'       => $items,
				'regenerated' => $regenerated,
				'skipped'     => $skipped,
				'failed'      => $failed,
			]
		);
	}

	/**
	 * Opens a regeneration run in the activity log.
	 *
	 * @param string $session Run session id.
	 * @param int    $total   Images to process.
	 * @param bool   $force   Whether every size is being re-created.
	 * @param bool   $single  Whether running one image per request.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private function logStart( string $session, int $total, bool $force, bool $single ): void {
		ImportLogger::maybeInstall();

		$modes = [];

		if ( $force ) {
			$modes[] = esc_html__( 'force every size', 'easy-demo-importer' );
		}

		if ( $single ) {
			$modes[] = esc_html__( 'one at a time', 'easy-demo-importer' );
		}

		$message = sprintf(
			/* translators: %s: number of images. */
			esc_html__( 'Regenerating thumbnails for %s images.', 'easy-demo-importer' ),
			number_format_i18n( $total )
		);

		if ( $modes ) {
			$message .= ' (' . implode( ', ', $modes ) . ')';
		}

		ImportLogger::info( $message, $session, self::LOG_SLUG );
	}

	/**
	 * Closes a regeneration run with a summary. Runs that never reach here (the
	 * user left mid-way) are surfaced as "Interrupted" by the log's own
	 * unfinished-run detection.
	 *
	 * @param string $session     Run session id.
	 * @param int    $regenerated Count regenerated.
	 * @param int    $skipped     Count skipped.
	 * @param int    $failed      Count failed.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private function logFinish( string $session, int $regenerated, int $skipped, int $failed ): void {
		$message = sprintf(
			/* translators: 1: regenerated count, 2: skipped count, 3: failed count. */
			esc_html__( 'Regeneration complete — %1$s regenerated, %2$s skipped, %3$s failed.', 'easy-demo-importer' ),
			number_format_i18n( $regenerated ),
			number_format_i18n( $skipped ),
			number_format_i18n( $failed )
		);

		ImportLogger::success( $message, $session, self::LOG_SLUG );
	}

	/**
	 * Builds the per-image detail row surfaced in the live regeneration list.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $status regenerated | skipped | failed.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	private function itemDetail( int $id, string $status ): array {
		$title = get_the_title( $id );

		if ( '' === $title ) {
			$file  = get_attached_file( $id );
			$title = $file ? wp_basename( $file ) : (string) $id;
		}

		return [
			'id'     => $id,
			'title'  => $title,
			'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			'status' => $status,
		];
	}

	/**
	 * Total number of image attachments in the library.
	 *
	 * @return int
	 * @since 2.0.0
	 */
	private function totalImages(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);
	}

	/**
	 * The next page of image attachment IDs after a cursor, ascending.
	 *
	 * @param int $after Last processed attachment ID (0 to start).
	 * @param int $limit Page size.
	 *
	 * @return int[]
	 * @since 2.0.0
	 */
	private function imageIdsAfter( int $after, int $limit ): array {
		global $wpdb;

		$like = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				$like,
				$after,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
