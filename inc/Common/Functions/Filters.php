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
 * Class: Filters.
 *
 * @since 1.0.0
 */
class Filters {
	/**
	 * Testing hooked function.
	 *
	 * @static
	 *
	 * @param string $title Title.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function testFilter( $title ) {
		return $title;
	}
}
