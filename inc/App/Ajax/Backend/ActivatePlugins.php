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

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Abstracts\ImporterAjax
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

		if ( ! Helpers::pluginConfigExists( $this->demoSlug, $this->config ) ) {
			return;
		}

		$this->plugins = Helpers::getPluginsList( $this->demoSlug, $this->config, $this->multiple );

		add_action( 'wp_ajax_sd_edi_activate_plugins', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		// Activate Required Plugins.
		$this->activatePlugins();

		/**
		 * Action Hook: 'sd/edi/after_plugins_activation'
		 *
		 * Performs special actions after plugins activation.
		 *
		 * @since 1.1.5
		 */
		do_action( 'sd/edi/after_plugins_activation', $this );

		// Response.
		$this->prepareResponse(
			'sd_edi_download_demo_files',
			esc_html__( 'Fetching demo files from the server.', 'easy-demo-importer' ),
			$this->activeCount > 0 ? esc_html__( 'Required plugins are activated. Awesome!', 'easy-demo-importer' ) : esc_html__( 'Plugins are active, ready to rock!', 'easy-demo-importer' )
		);
	}

	/**
	 * Activating required plugins.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function activatePlugins() {
		foreach ( $this->plugins as $plugin ) {
			$filePath     = ! empty( $plugin['filePath'] ) ? $plugin['filePath'] : '';
			$pluginStatus = $this->pluginStatus( $filePath );

			if ( 'inactive' === $pluginStatus ) {
				$this->activatePlugin( $filePath );
				++$this->activeCount;
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
			/**
			 * Action Hook: 'sd/edi/before_plugin_activation'
			 *
			 * Performs special actions before plugins activation if needed.
			 *
			 * @since 1.1.5
			 */
			do_action( 'sd/edi/before_plugin_activation', $path );

			activate_plugin( $path, '', false, true );

			/**
			 * Action Hook: 'sd/edi/after_plugin_activation'
			 *
			 * Performs special actions after plugins activation if needed.
			 *
			 * @hooked SigmaDevs\EasyDemoImporter\Common\Functions\Actions::pluginActivationActions 10
			 *
			 * @since 1.0.0
			 */
			do_action( 'sd/edi/after_plugin_activation', $path );
		}
	}
}
