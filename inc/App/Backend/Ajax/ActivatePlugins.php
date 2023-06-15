<?php
/**
 * Backend Ajax Class: ActivatePlugins
 *
 * Initializes required plugin activation Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Functions\Helpers,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: ActivatePlugins
 *
 * @since 1.0.0
 */
class ActivatePlugins extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Active count.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public $activeCount;

	/**
	 * Plugins.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $plugins = [];

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		parent::register();

		$this->activeCount = 0;

		if ( empty( $this->config['plugins'] ) && empty( $this->config['demoData'][ $this->demoSlug ]['plugins'] ) ) {
			return;
		}

		$this->plugins = $this->multiple ? $this->config['demoData'][ $this->demoSlug ]['plugins'] : $this->config['plugins'];

		add_action( 'wp_ajax_sd_edi_activate_plugins', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		// Activate Required Plugins.
		$this->activatePlugins();

		// Response.
		$this->prepareResponse(
			'sd_edi_download_demo_files',
			esc_html__( 'Demo files download in progress.', 'easy-demo-importer' ),
			$this->activeCount > 0 ? esc_html__( 'All the required plugins activated.', 'easy-demo-importer' ) : esc_html__( 'All the required plugins already activated.', 'easy-demo-importer' )
		);
	}

	/**
	 * Activating required plugins.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function activatePlugins() {
		foreach ( $this->plugins as $pluginSlug => $plugin ) {
			$name         = ! empty( $plugin['name'] ) ? $plugin['name'] : '';
			$filePath     = ! empty( $plugin['filePath'] ) ? $plugin['filePath'] : '';
			$pluginStatus = $this->pluginStatus( $filePath );

			if ( 'inactive' === $pluginStatus ) {
				$this->activatePlugin( $filePath );
				$this->activeCount ++;
			}
		}
	}

	/**
	 * Activating plugin.
	 *
	 * @param string $path Plugin path.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function activatePlugin( $path ) {
		if ( $path ) {
			$activate = activate_plugin( $path, '', false, true );
		}
	}
}
