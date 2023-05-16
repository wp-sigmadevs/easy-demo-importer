<?php
/**
 * Backend Class: Callbacks
 *
 * The list of all callback functions.
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
 * Backend Class: Callbacks
 *
 * @since 1.0.0
 */
class Callbacks {
	/**
	 * Callback: Admin Section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function renderDemoImportPage() {
		$themeConfig     = sd_edi()->getDemoConfig();
		$activeTheme     = sd_edi()->activeTheme();
		$supportedThemes = sd_edi()->supportedThemes();

		if ( ! in_array( $activeTheme, $supportedThemes, true ) ) {
			$themeConfig = [];
		}

		Helpers::renderView( 'demo-import', [ 'themeConfig' => $themeConfig ] );
	}
}
