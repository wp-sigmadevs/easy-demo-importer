<?php
/**
 * Rest Class: RestAPI Custom Endpoint.
 *
 * This class initializes REST API and creates a custom endpoint.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use WP_Error;
use WP_REST_Response;
use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Functions\Helpers
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Rest Class: RestAPI Custom Endpoint.
 *
 * @since 1.0.0
 */
class RestEndpoint extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * The prefix for the API.
	 *
	 * @var string API_PREFIX
	 * @since 1.0.0
	 **/
	const API_PREFIX = 'sd/edi';

	/**
	 * API Version.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $version = 'v1';

	/**
	 * This class is only being instantiated if REST_REQUEST is defined
	 * in the requester as requested in the Bootstrap class
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isRest()
	 */
	public function register() {
		if ( class_exists( 'WP_REST_Server' ) ) {
			add_action( 'rest_api_init', [ $this, 'addPluginApiEndpoint' ] );
		}

		$this->pluginList();
	}

	/**
	 * Returns the namespace for the API request.
	 *
	 * @return string
	 * @since 1.0.0
	 **/
	public function getNamespace() {
		$version = empty( $this->version ) ? 'v1' : $this->version;

		return self::API_PREFIX . '/' . $version;
	}

	/**
	 * Sends a REST response with an error status.
	 *
	 * @param string $error The error message to be included in the response.
	 * @param array  $errorData An optional array of additional error data.
	 * @param int    $code The HTTP status code to be returned in the response.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 **/
	protected function sendError( $error, $errorData = [], $code = 200 ) {
		$resData = [
			'success' => false,
			'message' => $error,
		];

		if ( ! empty( $errorData ) ) {
			$resData['data'] = $errorData;
		}

		$response = new WP_REST_Response( $resData );
		$response->set_status( $code );

		return rest_ensure_response( $response );
	}

	/**
	 * Sends a REST response with success status, data, and message.
	 *
	 * @param array  $data The data to be included in the response.
	 * @param string $message The message to be included in the response.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 **/
	protected function sendResponse( $data = [], $message = '' ) {
		$resData = [
			'success' => true,
			'data'    => $data,
			'message' => $message,
		];

		$response = new WP_REST_Response( $resData );

		return rest_ensure_response( $response );
	}

	/**
	 * Add Endpoint for Demo Data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addPluginApiEndpoint() {
		$this->addDemoDataEndpoint();
		$this->addPluginStatusEndpoint();
		$this->addServerStatusEndpoint();
	}

	/**
	 * Add Demo Data Route.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addDemoDataEndpoint() {
		register_rest_route(
			$this->getNamespace(),
			'/import/list',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'buildList' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);
	}

	/**
	 * Add Plugin Status Route.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addPluginStatusEndpoint() {
		register_rest_route(
			$this->getNamespace(),
			'/plugin/list',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'pluginList' ],
				'permission_callback' => [ $this, 'permission' ],
			]
		);
	}

	/**
	 * Add Server Status Route.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addServerStatusEndpoint() {
		register_rest_route(
			$this->getNamespace(),
			'/server/status',
			[
				'methods'  => 'GET',
				'callback' => [ $this, 'serverStatus' ],
			// 'permission_callback' => [ $this, 'permission' ],
			]
		);
	}

	/**
	 * Checks whether user has permission to manage options.
	 *
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	public function permission() {
		$hasPermission = current_user_can( 'manage_options' );

		if ( ! $hasPermission ) {
			return new WP_Error(
				'authentication_error',
				esc_html__( 'Sorry, you are not allowed to do that!', 'easy-demo-importer' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Builds a list of config files for the demo importer.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function buildList() {
		$themeConfig     = sd_edi()->getDemoConfig();
		$activeTheme     = sd_edi()->activeTheme();
		$supportedThemes = sd_edi()->supportedThemes();

		if ( ! in_array( $activeTheme, $supportedThemes, true ) ) {
			$themeConfig = [];
		}

		if ( empty( $themeConfig ) ) {
			return $this->sendError( esc_html__( 'We apologize for any inconvenience, but it appears that the configuration file for the demo importer is either missing or you are using an unsupported theme. As a result, the installation of the demo content cannot proceed any further at this time. Thank you for your understanding.', 'easy-demo-importer' ) );
		}

		return $this->sendResponse( $themeConfig, esc_html__( 'Data is ready to fetch', 'easy-demo-importer' ) );
	}

	/**
	 * Builds a list of plugin with status.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function pluginList() {
		$themeConfig     = sd_edi()->getDemoConfig();
		$requiredPlugins = [];

		if ( ! isset( $themeConfig['multipleZip'] ) || ! isset( $themeConfig['demoData'] ) ) {
			return $this->sendError( esc_html__( 'Demo data Configuration not found.', 'easy-demo-importer' ) );
		}

		if ( ! $themeConfig['multipleZip'] ) {
			$requiredPlugins = ! empty( $themeConfig['plugins'] ) ? $themeConfig['plugins'] : [];
		} else {
			foreach ( $themeConfig['demoData'] as $demo ) {
				if ( isset( $demo['plugins'] ) && is_array( $demo['plugins'] ) ) {
					foreach ( $demo['plugins'] as $key => $plugin ) {
						$requiredPlugins[ $key ] = $plugin;
					}
				}
			}
		}

		foreach ( $requiredPlugins as $plugin => $pluginData ) {
			$requiredPlugins[ $plugin ]['status'] = Helpers::pluginActivationStatus( $pluginData['filePath'] );

			if ( ! empty( $pluginData['location'] ) ) {
				unset( $requiredPlugins[ $plugin ]['location'] );
			}
		}

		return $this->sendResponse( $requiredPlugins, esc_html__( 'Data is ready to fetch', 'easy-demo-importer' ) );
	}

	/**
	 * Builds server status.
	 *
	 * @return WP_REST_Response
	 * @since 1.0.0
	 */
	public function serverStatus() {
		$info = $this->serverStatusTabs();

		return $this->sendResponse( $info, esc_html__( 'Data is ready to fetch', 'easy-demo-importer' ) );
	}

	/**
	 * System Status Tabs.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function serverStatusTabs() {
		$tabs = [];

		$tabs['system_info'] = [
			'label'  => esc_html__( 'System Info', 'easy-demo-importer' ),
			'fields' => $this->systemInfoFields(),
		];

		$tabs['wp_info'] = [
			'label'  => esc_html__( 'WordPress Info', 'easy-demo-importer' ),
			'fields' => $this->wpInfoFields(),
		];

		return $tabs;
	}

	/**
	 * System Info Fields.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function systemInfoFields() {
		global $wpdb;

		$fields = [];

		$fields['operating_system'] = [
			'label' => esc_html__( 'Operating System', 'easy-demo-importer' ),
			'value' => esc_html( PHP_OS ),
		];

		$fields['server'] = [
			'label' => esc_html__( 'Server', 'easy-demo-importer' ),
			'value' => ! empty( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : esc_html__( 'Detection Error', 'easy-demo-importer' ),
		];

		$fields['mysql'] = [
			'label' => esc_html__( 'MySQL Version', 'easy-demo-importer' ),
			'value' => esc_html( $wpdb->get_var( 'SELECT VERSION()' ) ),
		];

		$fields['php'] = [
			'label' => esc_html__( 'PHP Version', 'easy-demo-importer' ),
			'value' => esc_html( PHP_VERSION ),
		];

		$fields['max_exec_time'] = [
			'label' => esc_html__( 'PHP Max Execution Time', 'easy-demo-importer' ),
			'value' => esc_html( ini_get( 'max_execution_time' ) ),
			'error' => ini_get( 'max_execution_time' ) < 300 ? esc_html__( 'Recommended PHP Max Execution Time is 300', 'easy-demo-importer' ) : '',
		];

		$fields['max_upload_size'] = [
			'label' => esc_html__( 'PHP Max Upload Size', 'easy-demo-importer' ),
			'value' => esc_html( ini_get( 'upload_max_filesize' ) ),
			'error' => ini_get( 'upload_max_filesize' ) < 256 ? esc_html__( 'Recommended PHP Max Execution Time is 256M', 'easy-demo-importer' ) : '',
		];

		$fields['post_max_size'] = [
			'label' => esc_html__( 'PHP Post Max Size', 'easy-demo-importer' ),
			'value' => esc_html( ini_get( 'post_max_size' ) ),
			'error' => ini_get( 'post_max_size' ) < 5000 ? esc_html__( 'Recommended PHP Post Max Size is 512M', 'easy-demo-importer' ) : '',
		];

		$fields['max_input_vars'] = [
			'label' => esc_html__( 'PHP Max Input Vars', 'easy-demo-importer' ),
			'value' => esc_html( ini_get( 'max_input_vars' ) ),
		];

		$fields['memory_limit'] = [
			'label' => esc_html__( 'PHP Memory Limit', 'easy-demo-importer' ),
			'value' => esc_html( ini_get( 'memory_limit' ) ),
			'error' => ini_get( 'memory_limit' ) < 256 ? esc_html__( 'Recommended PHP Memory Limit is 256M', 'easy-demo-importer' ) : '',
		];

		$fields['curl'] = [
			'label' => esc_html__( 'cURL Installed', 'easy-demo-importer' ),
			'value' => extension_loaded( 'curl' ) ? esc_html__( 'Yes', 'easy-demo-importer' ) : esc_html__( 'No', 'easy-demo-importer' ),
		];

		$curl_data = function_exists( 'curl_version' ) ? curl_version() : false;

		if ( $curl_data ) {
			$fields['curl_version'] = [
				'label' => esc_html__( 'cURL version', 'easy-demo-importer' ),
				'value' => esc_html( $curl_data['version'] ),
			];
		}

		$fields['gd'] = [
			'label' => esc_html__( 'GD Installed', 'easy-demo-importer' ),
			'value' => extension_loaded( 'gd' ) ? esc_html__( 'Yes', 'easy-demo-importer' ) : esc_html__( 'No', 'easy-demo-importer' ),
		];

		$gd_data = function_exists( 'gd_info' ) ? gd_info() : false;

		if ( $gd_data ) {
			$fields['gd_version'] = [
				'label' => esc_html__( 'GD version', 'easy-demo-importer' ),
				'value' => esc_html( $gd_data['GD Version'] ),
			];
		}

		$fields['write_permission'] = [
			'label' => esc_html__( 'Write Permission', 'easy-demo-importer' ),
			'value' => esc_html( $this->checkWritePermission() ),
		];

		$demo           = sd_edi()->getDemoConfig();
		$downloadServer = ! empty( $demo['demoZip'] ) ? esc_url( $demo['demoZip'] ) : null;

		$fields['demo_connection'] = [
			'label' => esc_html__( 'Download Server Connection', 'easy-demo-importer' ),
			'value' => ! empty( $downloadServer ) && wp_remote_get( $downloadServer, [ 'timeout' => 10 ] ) ? esc_html__( 'Connected', 'easy-demo-importer' ) : esc_html__( 'Connection failure', 'easy-demo-importer' ),
		];

		return $fields;
	}

	/**
	 * WordPress Info Fields.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function wpInfoFields() {
		global $wp_rewrite;

		$fields = [];

		$fields['wp_version'] = [
			'label' => esc_html__( 'WordPress Version', 'easy-demo-importer' ),
			'value' => esc_html( get_bloginfo( 'version' ) ),
		];

		$fields['site_url'] = [
			'label' => esc_html__( 'Site URL', 'easy-demo-importer' ),
			'value' => esc_html( get_site_url() ),
		];

		$fields['home_url'] = [
			'label' => esc_html__( 'Home URL', 'easy-demo-importer' ),
			'value' => esc_html( get_home_url() ),
		];

		$fields['multisite'] = [
			'label' => esc_html__( 'Multisite', 'easy-demo-importer' ),
			'value' => is_multisite() ? esc_html__( 'Yes', 'easy-demo-importer' ) : esc_html__( 'No', 'easy-demo-importer' ),
		];

		$fields['max_upload_size'] = [
			'label' => esc_html__( 'Max Upload Size', 'easy-demo-importer' ),
			'value' => esc_html( size_format( wp_max_upload_size() ) ),
		];

		$fields['memory_limit'] = [
			'label' => esc_html__( 'Memory Limit', 'easy-demo-importer' ),
			'value' => esc_html( WP_MEMORY_LIMIT ),
		];

		$fields['max_memory_limit'] = [
			'label' => esc_html__( 'Max Memory Limit', 'easy-demo-importer' ),
			'value' => esc_html( WP_MAX_MEMORY_LIMIT ),
		];

		$fields['permalink_structure'] = [
			'label' => esc_html__( 'Permalink Structure', 'easy-demo-importer' ),
			'value' => '' !== $wp_rewrite->permalink_structure ? esc_html( $wp_rewrite->permalink_structure ) : esc_html__( 'Plain', 'easy-demo-importer' ),
		];

		$fields['language'] = [
			'label' => esc_html__( 'Language', 'easy-demo-importer' ),
			'value' => esc_html( get_bloginfo( 'language' ) ),
		];

		$fields['wp_debug'] = [
			'label' => esc_html__( 'Debug Mode Enabled', 'easy-demo-importer' ),
			'value' => WP_DEBUG ? esc_html__( 'Yes', 'easy-demo-importer' ) : esc_html__( 'No', 'easy-demo-importer' ),
		];

		$fields['script_debug'] = [
			'label' => esc_html__( 'Script Debug Mode Enabled', 'easy-demo-importer' ),
			'value' => SCRIPT_DEBUG ? esc_html__( 'Yes', 'easy-demo-importer' ) : esc_html__( 'No', 'easy-demo-importer' ),
		];

		return $fields;
	}

	/**
	 * Check write permission.
	 *
	 * @return string
	 */
	private function checkWritePermission() {
		$output          = __( 'Write permission error', 'easy-demo-importer' );
		$wpUploadDir     = wp_upload_dir( null, false );
		$error           = $wpUploadDir['error'];
		$ediDownloadPath = $wpUploadDir['basedir'] . '/easy-demo-importer/';

		if ( ! $error && is_writable( $ediDownloadPath ) ) {
			$output = __( 'Writable', 'easy-demo-importer' );
		}

		return $output;
	}
}
