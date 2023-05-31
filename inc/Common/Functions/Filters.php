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
		$new_filetypes        = [];
		$new_filetypes['svg'] = 'image/svg+xml';

		return array_merge( $file_types, $new_filetypes );
	}
}
