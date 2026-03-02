<?php
/**
 * Config Class: I18n.
 *
 * Internationalization and localization definitions.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Config;

use SigmaDevs\EasyDemoImporter\Common\
{
	Abstracts\Base,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Config Class: I18n.
 *
 * @since 1.0.0
 */
final class I18n extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @docs https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#loading-text-domain
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load() {
		// WordPress 4.6+ automatically loads translations for wp.org-hosted plugins.
	}
}
