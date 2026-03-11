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
