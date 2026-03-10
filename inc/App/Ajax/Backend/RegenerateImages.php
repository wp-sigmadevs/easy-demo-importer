<?php
/**
 * Backend Ajax Class: RegenerateImages
 *
 * Handles image regeneration AJAX actions after demo import.
 * Does NOT extend ImporterAjax because the import session lock is
 * already released by the time regen runs — validation is nonce + capability.
 *
 * Registration: NOT auto-registered via Classes.php (requires demo POST field).
 * Instead, Hooks::actions() calls RegenerateImages::instance()->register()
 * on every admin backend request.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.4.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Utils\ImageRegenEngine,
	Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class RegenerateImages
 *
 * @since 1.4.0
 */
class RegenerateImages {
	use Singleton;

	/**
	 * Registers AJAX actions.
	 * Called by Hooks::actions() on every admin backend request.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register(): void {
		add_action( 'wp_ajax_sd_edi_regen_check', [ $this, 'regenCheck' ] );
		add_action( 'wp_ajax_sd_edi_regenerate_images', [ $this, 'regenerateImages' ] );
		add_action( 'wp_ajax_sd_edi_background_regen', [ $this, 'scheduleBackground' ] );
	}

	/**
	 * Return the total attachment count and first filename for the regen step UI.
	 *
	 * POST params: sd_edi_nonce, sessionId
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function regenCheck(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		$first_filename = '';

		if ( $total > 0 ) {
			$file = get_attached_file( $ids[0] );
			if ( $file ) {
				$first_filename = basename( $file );
			}
		}

		wp_send_json_success(
			[
				'total'          => $total,
				'first_filename' => $first_filename,
			]
		);
	}

	/**
	 * Regenerate one batch of attachments.
	 *
	 * POST params: sd_edi_nonce, sessionId, offset
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function regenerateImages(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		if ( 0 === $total ) {
			wp_send_json_success(
				[
					'done'      => 0,
					'total'     => 0,
					'completed' => true,
				]
			);
			// @phpstan-ignore deadCode.unreachable
			return;
		}

		$batch      = ImageRegenEngine::batchSize();
		$slice      = array_slice( $ids, $offset, $batch );
		$failed     = [];
		$last_sizes = [];
		$last_file  = '';

		foreach ( $slice as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file ) {
				$last_file = basename( $file );
			}

			$result = ImageRegenEngine::regen( $attachment_id );

			if ( ! empty( $result['error'] ) ) {
				$failed[] = [
					'id'       => $attachment_id,
					'filename' => $last_file,
					'error'    => $result['error'],
				];
			} else {
				$last_sizes = $result['sizes'];
			}
		}

		$done      = min( $offset + $batch, $total );
		$completed = $done >= $total;

		// Accumulate failures across all batches via a transient.
		$prior_failures = (int) get_transient( 'sd_edi_regen_progress_' . $session_id );
		$total_failures = $prior_failures + count( $failed );

		if ( ! $completed ) {
			set_transient( 'sd_edi_regen_progress_' . $session_id, $total_failures, HOUR_IN_SECONDS );
		}

		if ( $completed ) {
			ImportLogger::log(
				sprintf(
					/* translators: 1: total images, 2: failure count */
					_n(
						'Image regeneration complete: %1$d image processed, %2$d failure.',
						'Image regeneration complete: %1$d images processed, %2$d failures.',
						$total,
						'easy-demo-importer'
					),
					$total,
					$total_failures
				),
				0 === $total_failures ? 'success' : 'warning',
				$session_id
			);

			ImageRegenEngine::clearSessionAttachments( $session_id );

			// Store completion record: date, total, and failure count for System Status display.
			update_option(
				'sd_edi_last_regen',
				[
					'date'     => current_time( 'mysql' ),
					'count'    => $total,
					'failures' => $total_failures,
				],
				false
			);
			delete_option( 'sd_edi_background_regen_session' );
			delete_transient( 'sd_edi_regen_progress_' . $session_id );
		}

		wp_send_json_success(
			[
				'done'             => $done,
				'total'            => $total,
				'current_filename' => $last_file,
				'sizes_generated'  => $last_sizes,
				'failed'           => $failed,
				'completed'        => $completed,
			]
		);
	}

	/**
	 * Schedule a WP-Cron event to regenerate images in the background.
	 *
	 * POST params: sd_edi_nonce, sessionId
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function scheduleBackground(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		$ids = ImageRegenEngine::getSessionAttachments( $session_id );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				[
					'scheduled' => false,
					'total'     => 0,
				]
			);
			// @phpstan-ignore deadCode.unreachable
			return;
		}

		// Store session ID so admin_notices can show progress.
		update_option( 'sd_edi_background_regen_session', $session_id, false );

		// Schedule one cron event firing as soon as possible.
		wp_schedule_single_event( time(), 'sd_edi_background_regen', [ $session_id, 0 ] );

		wp_send_json_success(
			[
				'scheduled' => true,
				'total'     => count( $ids ),
			]
		);
	}

	/**
	 * WP-Cron callback: process one batch of background image regeneration.
	 * Registered in Hooks::actions() via add_action('sd_edi_background_regen', ...).
	 *
	 * Schedules itself again until all attachments are processed.
	 *
	 * @param string $session_id Import session UUID.
	 * @param int    $offset     Current offset into the attachment list.
	 * @return void
	 * @since 1.4.0
	 */
	public static function cronRegen( string $session_id, int $offset ): void {
		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		if ( 0 === $total ) {
			delete_option( 'sd_edi_background_regen_session' );
			return;
		}

		$batch_size  = 10;
		$slice       = array_slice( $ids, $offset, $batch_size );
		$batch_fails = 0;

		foreach ( $slice as $attachment_id ) {
			$result = ImageRegenEngine::regen( $attachment_id );
			if ( ! empty( $result['error'] ) ) {
				++$batch_fails;
			}
		}

		$done = min( $offset + $batch_size, $total );

		// Read prior progress (which also carries accumulated failure count across batches).
		$prior    = get_transient( 'sd_edi_background_regen_progress_' . $session_id );
		$failures = ( is_array( $prior ) ? (int) ( $prior['failures'] ?? 0 ) : 0 ) + $batch_fails;

		// Store progress + accumulated failures for admin notice and final record.
		set_transient(
			'sd_edi_background_regen_progress_' . $session_id,
			[
				'done'     => $done,
				'total'    => $total,
				'failures' => $failures,
			],
			HOUR_IN_SECONDS
		);

		if ( $done >= $total ) {
			ImageRegenEngine::clearSessionAttachments( $session_id );
			delete_option( 'sd_edi_background_regen_session' );
			delete_transient( 'sd_edi_background_regen_progress_' . $session_id );

			// Store completion record: date, total, and accumulated failure count for System Status display.
			update_option(
				'sd_edi_last_regen',
				[
					'date'     => current_time( 'mysql' ),
					'count'    => $done,
					'failures' => $failures,
				],
				false
			);
		} else {
			wp_schedule_single_event( time(), 'sd_edi_background_regen', [ $session_id, $done ] );
		}
	}

	/**
	 * Verify AJAX nonce and user capability. Sends JSON error and dies on failure.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function verifyRequest(): void {
		if ( ! check_ajax_referer( Helpers::nonceText(), Helpers::nonceId(), false ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Security check failed. Please refresh the page.', 'easy-demo-importer' ),
				],
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Insufficient permissions.', 'easy-demo-importer' ),
				],
				403
			);
		}
	}
}
