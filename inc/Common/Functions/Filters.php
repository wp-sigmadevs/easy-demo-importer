<?php
/**
 * Functions Class: Filters.
 *
 * List of all functions hooked in filter hooks.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use enshrined\svgSanitize\Sanitizer;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Functions Class: Filters.
 *
 * @since 1.0.0
 */
class Filters {
	/**
	 * Update the list of file types support.
	 *
	 * @param array $file_types List of supported file types.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function supportedFileTypes( $file_types ) {
		// Allow SVG uploads only for users who can manage_options. This filter
		// is global (upload_mimes fires for every media upload site-wide, not
		// just this plugin's own import flow), and manage_options is the same
		// boundary the rest of the plugin's admin functionality is gated on
		// (see Helpers::verifyUserRole()) -- kept consistent with
		// fixSVGDetection() below, which must use the same capability.
		if ( current_user_can( 'manage_options' ) ) {
			$file_types['svg'] = 'image/svg+xml';
		}

		return $file_types;
	}

	/**
	 * Sanitize an uploaded SVG file.
	 *
	 * @param array $file Uploaded file information.
	 *
	 * @return array
	 * @since 1.1.3
	 */
	public static function sanitizeSVG( $file ) {
		// Only proceed if the file is an SVG. Detected by filename/content,
		// NOT $file['type'] -- that value is the client-supplied multipart
		// Content-Type, so gating on it let an attacker declare a genuine SVG
		// as e.g. image/png to skip sanitization entirely, while
		// fixSVGDetection() below (run later, on wp_check_filetype_and_ext)
		// still stored the file as a real, unsanitized SVG.
		if ( ! self::looksLikeSVG( $file ) ) {
			return $file;
		}

		// Set the maximum file size (500KB max).
		$max_file_size = apply_filters( 'sd/edi/import/max_svg_file_size', 500 * 1024 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$size_in_kb    = $max_file_size / 1024;
		$size_in_mb    = $size_in_kb / 1024;
		$size_message  = ( $size_in_kb < 1024 ) ? $size_in_kb . 'KB' : number_format( $size_in_mb, 2 ) . 'MB';

		// Validate file size. $file['size'] isn't always present -- the REST
		// media endpoint's raw-body upload path (wp_handle_sideload_prefilter)
		// builds a $file array without one, so fall back to the actual file
		// size on disk.
		$file_size = isset( $file['size'] )
			? (int) $file['size']
			: ( ! empty( $file['tmp_name'] ) && is_readable( $file['tmp_name'] ) ? (int) filesize( $file['tmp_name'] ) : 0 );

		if ( $file_size > $max_file_size ) {
			$file['error'] = sprintf(
				/* translators: file size */
				esc_html__( 'The uploaded SVG exceeds the maximum allowed file size of %s.', 'easy-demo-importer' ),
				esc_html( $size_message )
			);

			return $file;
		}

		// Sanitize the SVG file.
		$sanitizer = new Sanitizer();
		$sanitizer->removeRemoteReferences( true );
		$sanitizer->removeXMLTag( true );
		$sanitizer->minify( true );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$svg_content = file_get_contents( $file['tmp_name'] );
		$clean_svg   = $sanitizer->sanitize( $svg_content );

		// If the file is not safe, return an error.
		if ( false === $clean_svg ) {
			$file['error'] = esc_html__( 'This SVG file contains unsafe content and cannot be uploaded.', 'easy-demo-importer' );

			return $file;
		}

		// Write sanitized SVG content back to the file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file['tmp_name'], $clean_svg );

		return $file;
	}

	/**
	 * Whether an uploaded file is an SVG, independent of the client-supplied
	 * $file['type']. Checked by filename extension first (cheap), falling
	 * back to sniffing the file's own head bytes for an <svg tag so a
	 * misleading extension can't hide an SVG payload from sanitization either.
	 *
	 * @param array $file Uploaded file information.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private static function looksLikeSVG( $file ) {
		$filename = isset( $file['name'] ) && is_string( $file['name'] ) ? strtolower( $file['name'] ) : '';

		if ( '.svg' === substr( $filename, -4 ) ) {
			return true;
		}

		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$head = file_get_contents( $file['tmp_name'], false, null, 0, 1024 );

		return is_string( $head ) && false !== stripos( $head, '<svg' );
	}

	/**
	 * Fix SVG file detection in WordPress.
	 *
	 * @param array  $data     Array containing file data.
	 * @param string $file     Path to the file.
	 * @param string $filename The name of the file.
	 *
	 * @return array
	 * @since 1.1.3
	 */
	public static function fixSVGDetection( $data, $file, $filename ) {
		// Only resurrect the SVG type/extension for users actually allowed to
		// upload SVGs -- same capability supportedFileTypes() gates the
		// upload_mimes entry on (manage_options, not import: this must stay
		// identical to that check or the same class of bypass reappears).
		// Without this, a filename ending in .svg got reassigned
		// type=image/svg+xml regardless of capability, letting any Author
		// bypass the allowed-MIME gate entirely (CVE-2024-9071 was not fully
		// fixed by the 1.1.3 sanitizer alone -- this filter had no capability
		// check of its own).
		if ( ! current_user_can( 'manage_options' ) ) {
			return $data;
		}

		// If the file is an SVG, manually set the MIME type and extension.
		$ext = isset( $data['ext'] ) && is_string( $data['ext'] ) ? $data['ext'] : '';

		if ( strlen( $ext ) < 1 ) {
			$exploded = explode( '.', $filename );
			$ext      = strtolower( end( $exploded ) );
		}

		if ( 'svg' === $ext ) {
			$data['type'] = 'image/svg+xml';
			$data['ext']  = 'svg';
		}

		return $data;
	}
}
