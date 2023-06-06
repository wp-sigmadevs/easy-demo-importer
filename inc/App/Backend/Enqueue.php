<?php
/**
 * Backend Class: Enqueue
 *
 * This class enqueues required styles & scripts in the admin pages.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend;

use SigmaDevs\EasyDemoImporter\Common\{Functions\Helpers, Traits\Singleton, Abstracts\Enqueue as EnqueueBase};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Class: Enqueue
 *
 * @package ThePluginName\App\Backend
 * @since 1.0.0
 */
class Enqueue extends EnqueueBase {
	/**
	 * Singleton Trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

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
		$this->assets();

		if ( empty( $this->assets() ) ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Method to accumulate styles list.
	 *
	 * @return Enqueue
	 * @since 1.0.0
	 */
	protected function getStyles() {
		$styles = [];

		$styles[] = [
			'handle' => 'thickbox',
		];

		$styles[] = [
			'handle'    => 'sd-edi-admin-styles',
			'asset_uri' => $this->plugin->assetsUri() . '/css/backend' . $this->plugin->suffix . '.css',
			'version'   => $this->plugin->version(),
		];

		$this->enqueues['style'] = apply_filters( 'sd_edi_registered_admin_styles', $styles, 10, 1 );

		return $this;
	}

	/**
	 * Method to accumulate scripts list.
	 *
	 * @return Enqueue
	 * @since 1.0.0
	 */
	protected function getScripts() {
		$scripts = [];

		$scripts[] = [
			'handle'     => 'sd-edi-admin-script',
			'asset_uri'  => $this->plugin->assetsUri() . '/js/backend' . $this->plugin->suffix . '.js',
			'dependency' => [ 'jquery' ],
			'in_footer'  => true,
			'version'    => $this->plugin->version(),
		];

		$this->enqueues['script'] = apply_filters( 'sd_edi_registered_admin_scripts', $scripts, 10, 1 );

		return $this;
	}

	/**
	 * Method to enqueue scripts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue() {
		$this
			->registerScripts()
			->enqueueScripts()
			->localize( $this->localizeData() );
	}

	/**
	 * Localized data.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function localizeData() {
		return [
			'handle' => 'sd-edi-admin-script',
			'object' => 'sdEdiAdminParams',
			'data'   => [
				'ajaxUrl'          => esc_url( Helpers::ajaxUrl() ),
				'restApiUrl'       => esc_url_raw( rest_url() ),
				'restNonce'        => wp_create_nonce( 'wp_rest' ),
				'ediLogo'          => $this->plugin->assetsUri() . '/images/sd-edi-logo.svg',
				'numberOfDemos'    => ! empty( sd_edi()->getDemoConfig()['demoData'] ) ? count( sd_edi()->getDemoConfig()['demoData'] ) : 0,
				Helpers::nonceId() => wp_create_nonce( Helpers::nonceText() ),
				'prepareImporting' => esc_html__( 'Preparing to install demo data', 'easy-demo-importer' ),
				'resetDatabase'    => esc_html__( 'Doing cleanups', 'easy-demo-importer' ),
				'importError'      => esc_html__( 'There was an error in importing demo. Please reload the page and try again.', 'easy-demo-importer' ),
				'importSuccess'    => '<h2>' . esc_html__( 'All done. Have fun!', 'easy-demo-importer' ) . '</h2><p>' . esc_html__( 'Demo data has been successfully installed.', 'easy-demo-importer' ) . '</p><a class="button" target="_blank" href="' . esc_url( home_url( '/' ) ) . '">View your Website</a>',
			],
		];
	}
}
