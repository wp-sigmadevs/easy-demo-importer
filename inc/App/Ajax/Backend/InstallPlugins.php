<?php
/**
 * Backend Ajax Class: InstallPlugins
 *
 * Initializes required plugin installation Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use WP_Error;
use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;
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
 * Backend Ajax Class: InstallPlugins
 *
 * @since 1.0.0
 */
class InstallPlugins extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Install count.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	public $installCount;

	/**
	 * Plugins.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	private $plugins = [];

	/**
	 * Plugin install errors.
	 *
	 * @var array
	 * @since 1.2.0
	 */
	private $installErrors = [];

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

		$this->installCount = 0;

		if ( ! Helpers::pluginConfigExists( $this->demoSlug, $this->config ) ) {
			return;
		}

		$this->plugins = Helpers::getPluginsList( $this->demoSlug, $this->config, $this->multiple );

		add_action( 'wp_ajax_sd_edi_install_plugins', [ $this, 'response' ] );
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

		// Install Required Plugins.
		$this->installPlugins();

		if ( ! empty( $this->installErrors ) ) {
			$failedPlugins = implode( ', ', $this->installErrors );
			$this->prepareResponse(
				'',
				'',
				'',
				true,
				sprintf(
					/* translators: %s: comma-separated list of plugin slugs */
					esc_html__( 'Failed to install required plugin(s): %s.', 'easy-demo-importer' ),
					$failedPlugins
				),
				esc_html__( 'Check your server\'s outbound internet access and ensure WordPress.org is reachable. You can also try installing the plugins manually from Plugins > Add New, then run the import again.', 'easy-demo-importer' )
			);
			return;
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_activate_plugins',
			esc_html__( 'Activating those plugins.', 'easy-demo-importer' ),
			$this->installCount > 0 ? esc_html__( 'Plugins installed, time to get started!', 'easy-demo-importer' ) : esc_html__( 'Plugins already installed, all set to go!', 'easy-demo-importer' )
		);
	}

	/**
	 * Installing required plugins.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function installPlugins() {
		foreach ( $this->plugins as $pluginSlug => $plugin ) {
			$source   = ! empty( $plugin['source'] ) ? $plugin['source'] : '';
			$filePath = ! empty( $plugin['filePath'] ) ? $plugin['filePath'] : '';
			$location = ! empty( $plugin['location'] ) ? $plugin['location'] : '';

			if ( strtolower( 'WordPress' ) === strtolower( $source ) ) {
				$this->installOrgPlugin( $filePath, $pluginSlug );
			} else {
				$this->installCustomPlugin( $filePath, $location );
			}
		}
	}

	/**
	 * Installing wordpress.org Plugin.
	 *
	 * @param string $path Plugin path.
	 * @param string $slug Plugin slug.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function installOrgPlugin( $path, $slug ) {
		$pluginStatus = $this->pluginStatus( $path );

		if ( 'install' === $pluginStatus || 'update' === $pluginStatus ) {
			// Include required libs for installation.
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

			// Get Plugin Info.
			$api = $this->callPluginApi( $slug );

			if ( is_wp_error( $api ) ) {
				$this->installErrors[] = $slug;
				return;
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );

			if ( 'install' === $pluginStatus ) {
				$result = $upgrader->install( $api->download_link );
			} else {
				$result = $upgrader->upgrade( $path, [ 'clear_update_cache' => false ] );
			}

			if ( is_wp_error( $result ) || false === $result ) {
				$this->installErrors[] = $slug;
				return;
			}

			++$this->installCount;
		}
	}

	/**
	 * Plugin API.
	 *
	 * @param string $slug Plugin slug.
	 *
	 * @return array|object|WP_Error
	 * @since 1.0.0
	 */
	public function callPluginApi( $slug ) {
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		return plugins_api(
			'plugin_information',
			[
				'slug'   => $slug,
				'fields' => [
					'downloaded'        => false,
					'rating'            => false,
					'description'       => false,
					'short_description' => false,
					'donate_link'       => false,
					'tags'              => false,
					'sections'          => false,
					'homepage'          => false,
					'added'             => false,
					'last_updated'      => false,
					'compatibility'     => false,
					'tested'            => false,
					'requires'          => false,
					'downloadlink'      => true,
					'icons'             => false,
				],
			]
		);
	}

	/**
	 * Installing custom Plugin.
	 *
	 * @param string $path Plugin path.
	 * @param string $externalUrl Plugin external URL.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function installCustomPlugin( $path, $externalUrl ) {
		$plugin_status = $this->pluginStatus( $path );

		if ( 'install' === $plugin_status ) {
			// Make sure we have the dependency.
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			/*
			 * Initialize WordPress' file system handler.
			 *
			 * @var WP_Filesystem_Base $wp_filesystem
			 */
			WP_Filesystem();

			global $wp_filesystem;

			$plugin   = $this->demoUploadDir() . 'plugin.zip';
			$timeout  = (int) apply_filters( 'sd/edi/download_timeout', 120 );
			$sslverify = (bool) apply_filters( 'sd/edi/download_sslverify', true );

			$response = wp_remote_get(
				$externalUrl,
				[
					'timeout'   => $timeout,
					'sslverify' => $sslverify,
				]
			);

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				$this->installErrors[] = basename( $path );
				return;
			}

			$wp_filesystem->mkdir( $this->demoUploadDir() );
			$wp_filesystem->put_contents( $plugin, wp_remote_retrieve_body( $response ) );

			$unzip_result = unzip_file( $plugin, WP_PLUGIN_DIR );

			// Delete zip regardless of unzip result.
			$wp_filesystem->delete( $plugin );

			if ( is_wp_error( $unzip_result ) ) {
				$this->installErrors[] = basename( $path );
				return;
			}

			++$this->installCount;
		}
	}
}
