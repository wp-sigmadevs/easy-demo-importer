<?php
/**
 * Utility Class: UploadSkipCounter.
 *
 * Observes WordPress's MIME/extension verification during a WXR import and
 * counts attachments that would be rejected because the current user lacks
 * the `unfiltered_upload` capability. Subsite admins on multisite never have
 * this capability, so the bundled WordPress importer silently skips affected
 * media. Surfacing the count lets the wizard report "N attachments skipped".
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: UploadSkipCounter.
 *
 * @since 1.2.0
 */
final class UploadSkipCounter {
	/**
	 * Transient key holding the per-blog skip counter.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	public const KEY = 'sd_edi_skipped_uploads';

	/**
	 * Register the observer filter.
	 *
	 * Hooked at priority 99 so it runs after WordPress's own
	 * `wp_check_filetype_and_ext` adjustments (and after the plugin's SVG
	 * detection fix at priority 10).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function register(): void {
		add_filter( 'wp_check_filetype_and_ext', [ __CLASS__, 'count' ], 99, 5 );
	}

	/**
	 * Count rejections without altering the filter payload.
	 *
	 * @param array       $data      Result of {ext, type, proper_filename}.
	 * @param string      $file      Full path to the file (unused).
	 * @param string      $filename  Original filename (unused).
	 * @param array|null  $mimes     Allowed MIMEs (unused).
	 * @param string      $real_mime Real MIME type detected (unused).
	 *
	 * @return array Unmodified $data.
	 * @since 1.2.0
	 */
	public static function count( $data, $file, $filename, $mimes, $real_mime = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( current_user_can( 'unfiltered_upload' ) ) {
			return $data;
		}

		if ( empty( $data['type'] ) || empty( $data['ext'] ) ) {
			$current = (int) get_transient( self::KEY );
			set_transient( self::KEY, $current + 1, HOUR_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Read the current skip count for this blog.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function get(): int {
		return (int) get_transient( self::KEY );
	}

	/**
	 * Clear the counter (called at the start of every import).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function reset(): void {
		delete_transient( self::KEY );
	}
}
