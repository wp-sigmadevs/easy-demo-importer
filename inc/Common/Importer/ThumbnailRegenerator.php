<?php
/**
 * Importer Utility: ThumbnailRegenerator
 *
 * Regenerates the intermediate image sizes for a single imported attachment.
 * Adapts the durable parts of Automattic's Regenerate Thumbnails plugin so a
 * demo import gets the same correctness guarantees without its REST/UI layer:
 *
 *   - Skips attachments that aren't images or are site icons (custom-cropped,
 *     must not be clobbered).
 *   - Regenerates from the true original via wp_get_original_image_path() so a
 *     previously big-image-scaled source doesn't lose fidelity.
 *   - Only regenerates sizes whose thumbnail file is actually missing, merging
 *     the already-present sizes back into the metadata. On a resumed or re-run
 *     regeneration this avoids re-encoding thumbnails that already exist.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Importer;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Importer Utility: ThumbnailRegenerator
 *
 * @since 1.2.0
 */
final class ThumbnailRegenerator {
	/**
	 * Attachment ID being regenerated.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	private $attachmentId;

	/**
	 * Absolute path to the fullsize original image.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private $fullSizePath;

	/**
	 * Attachment metadata as it stood before this run, used to merge back sizes
	 * that were skipped because their thumbnail already existed.
	 *
	 * @var array<string,mixed>
	 * @since 1.2.0
	 */
	private $oldMetadata = [];

	/**
	 * Size labels skipped this run because a matching thumbnail already existed.
	 *
	 * @var string[]
	 * @since 1.2.0
	 */
	private $skipped = [];

	/**
	 * Private constructor — use forAttachment().
	 *
	 * @param int    $attachmentId Attachment ID.
	 * @param string $fullSizePath Absolute path to the original image.
	 *
	 * @since 1.2.0
	 */
	private function __construct( int $attachmentId, string $fullSizePath ) {
		$this->attachmentId = $attachmentId;
		$this->fullSizePath = $fullSizePath;
	}

	/**
	 * Builds a regenerator for an attachment, or null when it should be skipped.
	 *
	 * Returns null (rather than an error) for anything not worth regenerating —
	 * a missing post, a non-attachment, a non-image, a site icon, or an original
	 * file that is gone — so the caller can simply move to the next ID.
	 *
	 * @param int $attachmentId Attachment ID.
	 *
	 * @return self|null
	 * @since 1.2.0
	 */
	public static function forAttachment( int $attachmentId ): ?self {
		$attachment = get_post( $attachmentId );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		// Site icons are usually custom-cropped; leave their thumbnails alone.
		if ( 'site-icon' === get_post_meta( $attachmentId, '_wp_attachment_context', true ) ) {
			return null;
		}

		// Only raster images have intermediate sizes worth regenerating here.
		if ( ! wp_attachment_is_image( $attachmentId ) ) {
			return null;
		}

		$path = function_exists( 'wp_get_original_image_path' )
			? wp_get_original_image_path( $attachmentId )
			: get_attached_file( $attachmentId );

		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}

		return new self( $attachmentId, $path );
	}

	/**
	 * Regenerates missing intermediate sizes and updates the attachment metadata.
	 *
	 * @return bool True when metadata was (re)generated and saved.
	 * @since 1.2.0
	 */
	public function regenerate(): bool {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$this->oldMetadata = wp_get_attachment_metadata( $this->attachmentId );

		if ( ! is_array( $this->oldMetadata ) ) {
			$this->oldMetadata = [];
		}

		add_filter( 'intermediate_image_sizes_advanced', [ $this, 'filterMissingSizes' ], 10, 2 );

		$meta = wp_generate_attachment_metadata( $this->attachmentId, $this->fullSizePath );

		remove_filter( 'intermediate_image_sizes_advanced', [ $this, 'filterMissingSizes' ], 10 );

		if ( empty( $meta ) ) {
			return false;
		}

		// Restore the sizes we intentionally skipped so metadata stays complete.
		if ( ! empty( $this->skipped ) && ! empty( $this->oldMetadata['sizes'] ) ) {
			foreach ( $this->skipped as $label ) {
				if ( ! empty( $this->oldMetadata['sizes'][ $label ] ) ) {
					$meta['sizes'][ $label ] = $this->oldMetadata['sizes'][ $label ];
				}
			}
		}

		wp_update_attachment_metadata( $this->attachmentId, $meta );

		return true;
	}

	/**
	 * Filters the registered sizes down to those whose thumbnail file is missing.
	 *
	 * Mirrors Automattic Regenerate Thumbnails' missing-only strategy: for each
	 * registered size, compute the target dimensions/filename the way core would
	 * and drop the size when a correctly-sized file already exists on disk.
	 *
	 * @param array<string,array{width?:int,height?:int,crop?:bool}> $sizes             Registered sizes.
	 * @param array{width?:int,height?:int,file?:string}             $fullSizeMetadata  Fullsize metadata.
	 *
	 * @return array<string,array{width?:int,height?:int,crop?:bool}>
	 * @since 1.2.0
	 */
	public function filterMissingSizes( $sizes, $fullSizeMetadata ) {
		if ( empty( $sizes ) || empty( $this->oldMetadata['sizes'] ) ) {
			return $sizes;
		}

		$editor = wp_get_image_editor( $this->fullSizePath );

		if ( is_wp_error( $editor ) ) {
			return $sizes;
		}

		$fullWidth  = isset( $fullSizeMetadata['width'] ) ? (int) $fullSizeMetadata['width'] : 0;
		$fullHeight = isset( $fullSizeMetadata['height'] ) ? (int) $fullSizeMetadata['height'] : 0;
		$fileExt    = strtolower( pathinfo( $this->fullSizePath, PATHINFO_EXTENSION ) );

		foreach ( $sizes as $label => $data ) {
			if ( empty( $this->oldMetadata['sizes'][ $label ] ) ) {
				continue;
			}

			$width  = isset( $data['width'] ) ? $data['width'] : null;
			$height = isset( $data['height'] ) ? $data['height'] : null;
			$crop   = isset( $data['crop'] ) ? $data['crop'] : false;

			$dims = image_resize_dimensions( $fullWidth, $fullHeight, $width, $height, $crop );

			// A size larger than the original wouldn't be generated by core either.
			if ( ! $dims ) {
				$this->skipped[] = $label;
				unset( $sizes[ $label ] );
				continue;
			}

			$dstW     = (int) $dims[4];
			$dstH     = (int) $dims[5];
			$filename = $editor->generate_filename( "{$dstW}x{$dstH}", null, $fileExt );
			$existing = $this->oldMetadata['sizes'][ $label ];

			if (
				isset( $existing['width'], $existing['height'] )
				&& (int) $existing['width'] === $dstW
				&& (int) $existing['height'] === $dstH
				&& file_exists( $filename )
			) {
				$this->skipped[] = $label;
				unset( $sizes[ $label ] );
			}
		}

		return $sizes;
	}
}
