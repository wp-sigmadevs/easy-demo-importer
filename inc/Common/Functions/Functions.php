<?php
/**
 * Functions Class: Functions.
 *
 * Main function class for external uses
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\Base;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Functions Class: Functions.
 *
 * @since 1.0.0
 */
class Functions extends Base {
	/**
	 * Class Constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Init Demo Configuration.
		$this->initDemoConfig();
	}

	/**
	 * Get plugin data by using sd_edi()->getData()
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getData() {
		return $this->plugin->data();
	}

	/**
	 * Init Demo Config.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function initDemoConfig() {
		add_action( 'after_setup_theme', [ $this, 'getDemoConfig' ] );
	}

	/**
	 * Get Theme demo config.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getDemoConfig() {
		return apply_filters( 'sd/edi/importer/config', [] );
	}

	/**
	 * Get active theme.
	 *
	 * @return false|mixed|null
	 * @since 1.0.0
	 */
	public function activeTheme() {
		return get_option( 'stylesheet' );
	}

	/**
	 * Supported Themes.
	 *
	 * @return mixed|null
	 * @since 1.0.0
	 */
	public function supportedThemes() {
		return apply_filters(
			'sd/edi/importer/themes',
			[
				! empty( $this->getDemoConfig()['themeSlug'] ) ? esc_html( $this->getDemoConfig()['themeSlug'] ) : '',
			]
		);
	}
}
