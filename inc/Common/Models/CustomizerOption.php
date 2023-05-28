<?php
/**
 * Model Class: CustomizerOption
 *
 * This class extends WP_Customize_Setting, so we can access
 * the protected updated method when importing options.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Models;

use WP_Customize_Setting;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Model Class: CustomizerOption
 *
 * @since 1.0.0
 */
class CustomizerOption extends WP_Customize_Setting {
	/**
	 * Import an option value for this setting.
	 *
	 * @param mixed $value The option value.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function import( $value ) {
		$this->update( $value );
	}
}
