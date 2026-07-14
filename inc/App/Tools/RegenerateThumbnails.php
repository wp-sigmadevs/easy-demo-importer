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
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Tools;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Importer\ThumbnailRegenerator
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Tools Class: RegenerateThumbnails
 *
 * @since 1.2.0
 */
class RegenerateThumbnails extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.2.0
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
	 * Registers the AJAX handler.
	 *
	 * The page itself is a React route on the Easy Demo Importer screen
	 * (`#/regenerate_thumbnails`), registered in App\Backend\Pages — so this
	 * class only wires the batch-processing endpoint that route talks to.
	 *
	 * @return void
	 * @since 1.2.0
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
	 * @since 1.2.0
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
		$after       = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;
		$force       = isset( $_POST['force'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
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
					'after'       => 0,
					'total'       => $total,
					'regenerated' => 0,
					'skipped'     => 0,
					'failed'      => 0,
				]
			);
		}

		$ids = $this->imageIdsAfter( $after, self::PAGE_LIMIT );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				[
					'done'        => true,
					'after'       => $after,
					'total'       => $total,
					'regenerated' => $regenerated,
					'skipped'     => $skipped,
					'failed'      => $failed,
				]
			);
		}

		$budget = (float) apply_filters( 'sd/edi/regen_tool_seconds', 15 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );
		$last   = $after;

		foreach ( $ids as $id ) {
			$regenerator = ThumbnailRegenerator::forAttachment( (int) $id );

			if ( null === $regenerator ) {
				++$skipped;
			} elseif ( $regenerator->regenerate( ! $force ) ) {
				++$regenerated;
			} else {
				++$failed;
			}

			$last = (int) $id;

			// Finish the current image, then stop once over budget.
			if ( ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		wp_send_json_success(
			[
				'done'        => false,
				'after'       => $last,
				'total'       => $total,
				'regenerated' => $regenerated,
				'skipped'     => $skipped,
				'failed'      => $failed,
			]
		);
	}

	/**
	 * Total number of image attachments in the library.
	 *
	 * @return int
	 * @since 1.2.0
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
	 * @since 1.2.0
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
