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
		// Allow SVG uploads only for users with the 'import' capability.
		if ( current_user_can( 'import' ) ) {
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
		// Only proceed if the file is an SVG.
		if ( 'image/svg+xml' !== $file['type'] ) {
			return $file;
		}

		// Set maximum file size (500KB max).
		$max_file_size = apply_filters( 'sd/edi/import/max_svg_file_size', 500 * 1024 );
		$size_in_kb    = $max_file_size / 1024;
		$size_in_mb    = $size_in_kb / 1024;
		$size_message  = ( $size_in_kb < 1024 ) ? $size_in_kb . 'KB' : number_format( $size_in_mb, 2 ) . 'MB';

		// Validate file size.
		if ( $file['size'] > $max_file_size ) {
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
