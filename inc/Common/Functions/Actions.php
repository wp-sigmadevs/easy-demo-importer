<?php
/**
 * Functions Class: Actions.
 *
 * List of all functions hooked in action hooks.
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
 * Class: Actions.
 *
 * @since 1.0.0
 */
class Actions {
	/**
	 * Testing hooked function.
	 *
	 * @static
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function testAction() {
		// echo 'action hook works';
	}
}
