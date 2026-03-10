<?php
/**
 * Class: ImageRegenEngine
 *
 * Shared utility for image regeneration: batch size tuning,
 * session attachment list helpers, and per-attachment regen.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.4.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImageRegenEngine
 *
 * @since 1.4.0
 */
class ImageRegenEngine {
	/**
	 * Transient key suffix for session attachment lists.
	 *
	 * @var string
	 */
	const ATTACHMENTS_SUFFIX = '_attachments';

	/**
	 * How long to keep the attachments transient after import.
	 * Two hours is more than enough for any regen step.
	 *
	 * @var int
	 */
	const ATTACHMENTS_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Return the number of attachments to process per AJAX call.
	 * Defaults to 5, auto-reduced to 1 when PHP memory limit < 256 MB.
	 * Filterable via sd/edi/regen_batch_size.
	 *
	 * @return int
	 * @since 1.4.0
	 */
	public static function batchSize(): int {
		$limit   = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$default = $limit > 0 && $limit < 256 * MB_IN_BYTES ? 1 : 5;

		return (int) apply_filters( 'sd/edi/regen_batch_size', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Append one attachment ID to the session attachment list transient.
	 * Safe to call from an add_attachment hook during chunked XML import.
	 *
	 * @param string $session_id Active import session UUID.
	 * @param int    $post_id    Attachment post ID.
	 * @return void
	 * @since 1.4.0
	 */
	public static function appendAttachment( string $session_id, int $post_id ): void {
		if ( empty( $session_id ) || $post_id <= 0 ) {
			return;
		}

		$key  = 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX;
		$list = get_transient( $key );

		if ( ! is_array( $list ) ) {
			$list = [];
		}

		$list[] = $post_id;

		// array_unique avoids double-counting if the hook fires more than once per attachment.
		set_transient( $key, array_unique( $list ), self::ATTACHMENTS_TTL );
	}

	/**
	 * Return all tracked attachment IDs for a session.
	 *
	 * @param string $session_id Import session UUID.
	 * @return int[] List of attachment post IDs.
	 * @since 1.4.0
	 */
	public static function getSessionAttachments( string $session_id ): array {
		$key  = 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX;
		$list = get_transient( $key );

		return is_array( $list ) ? array_map( 'intval', $list ) : [];
	}

	/**
	 * Delete the attachments transient for a session once regen is complete.
	 *
	 * @param string $session_id Import session UUID.
	 * @return void
	 * @since 1.4.0
	 */
	public static function clearSessionAttachments( string $session_id ): void {
		delete_transient( 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX );
	}

	/**
	 * Regenerate thumbnails for a single attachment.
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 * @return array{sizes: string[], error: string} Result with generated size keys and error string.
	 * @since 1.4.0
	 */
	public static function regen( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return [
				'sizes' => [],
				'error' => sprintf(
					/* translators: %d: attachment post ID */
					__( 'File not found for attachment #%d.', 'easy-demo-importer' ),
					$attachment_id
				),
			];
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

		// @phpstan-ignore-next-line function.impossibleType (stubs type wp_generate_attachment_metadata as array; runtime can return WP_Error)
		if ( is_wp_error( $metadata ) ) {
			return [
				'sizes' => [],
				'error' => $metadata->get_error_message(),
			];
		}

		if ( empty( $metadata ) ) {
			return [
				'sizes' => [],
				'error' => sprintf(
					/* translators: %d: attachment post ID */
					__( 'No metadata generated for attachment #%d.', 'easy-demo-importer' ),
					$attachment_id
				),
			];
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return [
			'sizes' => array_keys( $metadata['sizes'] ?? [] ),
			'error' => '',
		];
	}
}
