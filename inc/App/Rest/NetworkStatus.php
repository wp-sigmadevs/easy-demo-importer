<?php
/**
 * REST: NetworkStatus.
 *
 * Provides Network Admin endpoints for status, network config,
 * and network-wide plugin install.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * REST: NetworkStatus.
 *
 * @since 1.2.0
 */
final class NetworkStatus {
	/**
	 * REST namespace. Deliberately hyphenated (`sd-edi/v1`) and distinct
	 * from the legacy class's slashed namespace (`sd/edi/v1`) so Network
	 * Admin endpoints are visibly partitioned in URLs and routing tables.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const NS = 'sd-edi/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::NS,
			'/network/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getStatus' ],
				'permission_callback' => [ $this, 'permSuperAdmin' ],
			]
		);

		register_rest_route(
			self::NS,
			'/network/config',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'getConfig' ],
					'permission_callback' => [ $this, 'permSuperAdmin' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'saveConfig' ],
					'permission_callback' => [ $this, 'permSuperAdmin' ],
					'args'                => [
						'enabled' => [ 'type' => 'boolean', 'required' => true ],
						'config'  => [ 'type' => 'object', 'required' => true ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/network/install-plugin',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'installPlugin' ],
				'permission_callback' => [ $this, 'permSuperAdmin' ],
				'args'                => [
					'slug' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	/**
	 * Permission callback: must be Super Admin on multisite.
	 *
	 * @return bool|WP_Error
	 * @since 1.2.0
	 */
	public function permSuperAdmin() {
		if ( ! ContextResolver::isMultisite() ) {
			return new WP_Error( 'sd_edi_not_multisite', __( 'Endpoint only available on multisite.', 'easy-demo-importer' ), [ 'status' => 404 ] );
		}
		if ( ! is_super_admin() ) {
			return new WP_Error( 'sd_edi_forbidden', __( 'Super Admin only.', 'easy-demo-importer' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * GET /network/status — returns blog list with last-import metadata.
	 *
	 * @return WP_REST_Response
	 * @since 1.2.0
	 * TODO(perf): cache the result in a 60s site-transient when networks grow large.
	 */
	public function getStatus(): WP_REST_Response {
		$ids = get_sites(
			[ 'fields' => 'ids', 'number' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0 ]
		);

		$rows = [];
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			try {
				$details = get_blog_details( (int) $id );
				$rows[]  = [
					'blog_id'     => (int) $id,
					'domain'      => $details ? $details->domain . $details->path : '',
					'site_url'    => get_site_url(),
					'last_import' => get_option( 'sd_edi_last_import', null ),
					'demo'        => get_option( 'sd_edi_last_imported_demo', '' ),
					'has_table'   => $this->blogHasTable(),
				];
			} finally {
				restore_current_blog();
			}
		}

		return new WP_REST_Response( [ 'sites' => $rows ], 200 );
	}

	/**
	 * GET /network/config — returns the current network override config.
	 *
	 * @return WP_REST_Response
	 * @since 1.2.0
	 */
	public function getConfig(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'enabled' => (bool) get_site_option( 'sd_edi_network_override_enabled', false ),
				'config'  => get_site_option( 'sd_edi_network_config', [] ),
				'updated' => (int) get_site_option( 'sd_edi_network_config_updated', 0 ),
			],
			200
		);
	}

	/**
	 * POST /network/config — replaces the network override config.
	 *
	 * @param WP_REST_Request $req Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.2.0
	 */
	public function saveConfig( WP_REST_Request $req ) {
		$enabled = (bool) $req->get_param( 'enabled' );
		$config  = (array) $req->get_param( 'config' );

		if ( $enabled ) {
			if ( ! $this->validateConfigShape( $config ) ) {
				return new WP_Error(
					'sd_edi_invalid_config',
					__( 'Network config must include themeName, themeSlug, and demoData.', 'easy-demo-importer' ),
					[ 'status' => 400 ]
				);
			}
			$config = $this->sanitizeConfigDeep( $config );
		} else {
			// Disabled override — store an empty array, ignore submitted config.
			$config = [];
		}

		update_site_option( 'sd_edi_network_override_enabled', $enabled );
		update_site_option( 'sd_edi_network_config', $config );
		update_site_option( 'sd_edi_network_config_updated', time() );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * POST /network/install-plugin — downloads a wordpress.org plugin and
	 * activates it network-wide. Super Admin only.
	 *
	 * @param WP_REST_Request $req Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.2.0
	 */
	public function installPlugin( WP_REST_Request $req ) {
		$slug = sanitize_key( (string) $req->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'sd_edi_bad_slug', __( 'Plugin slug is required.', 'easy-demo-importer' ), [ 'status' => 400 ] );
		}

		$lockKey = 'sd_edi_install_lock_' . $slug;
		if ( get_site_transient( $lockKey ) ) {
			return new WP_Error( 'sd_edi_install_locked', __( 'Another installation of this plugin is in progress. Please wait.', 'easy-demo-importer' ), [ 'status' => 429 ] );
		}
		set_site_transient( $lockKey, 1, MINUTE_IN_SECONDS * 5 );

		try {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/misc.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

			if ( ! WP_Filesystem() ) {
				return new WP_Error( 'sd_edi_filesystem', __( 'Filesystem credentials are required to install plugins.', 'easy-demo-importer' ), [ 'status' => 500 ] );
			}

			$api = plugins_api(
				'plugin_information',
				[ 'slug' => $slug, 'fields' => [ 'sections' => false ] ]
			);
			if ( is_wp_error( $api ) ) {
				return new WP_Error( 'sd_edi_api', $api->get_error_message(), [ 'status' => 500 ] );
			}

			$skin     = new \WP_Ajax_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );

			$downloadLink = is_object( $api ) ? ( $api->download_link ?? '' ) : ( $api['download_link'] ?? '' );
			if ( '' === $downloadLink ) {
				return new WP_Error( 'sd_edi_api', __( 'Could not resolve plugin download URL.', 'easy-demo-importer' ), [ 'status' => 500 ] );
			}

			$result = $upgrader->install( $downloadLink );
			if ( is_wp_error( $result ) ) {
				return new WP_Error( 'sd_edi_install', $result->get_error_message(), [ 'status' => 500 ] );
			}

			$pluginFile = $upgrader->plugin_info();
			if ( ! $pluginFile ) {
				return new WP_Error( 'sd_edi_install_no_file', __( 'Plugin installed but file path could not be resolved.', 'easy-demo-importer' ), [ 'status' => 500 ] );
			}

			$activate = activate_plugin( $pluginFile, '', true, true ); // network=true, silent=true
			if ( is_wp_error( $activate ) ) {
				return new WP_Error( 'sd_edi_activate', $activate->get_error_message(), [ 'status' => 500 ] );
			}

			wp_clean_plugins_cache( true );

			return new WP_REST_Response( [ 'ok' => true, 'plugin_file' => $pluginFile ], 200 );
		} finally {
			delete_site_transient( $lockKey );
		}
	}

	/**
	 * Whether the current blog has the plugin's per-blog table.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function blogHasTable(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Recursively sanitize string leaves of a config array.
	 *
	 * @param array $config Raw config.
	 *
	 * @return array Sanitized config.
	 * @since 1.2.0
	 */
	private function sanitizeConfigDeep( array $config ): array {
		array_walk_recursive(
			$config,
			static function ( &$value ) {
				if ( is_string( $value ) ) {
					$value = sanitize_text_field( $value );
				}
			}
		);
		return $config;
	}

	/**
	 * Cheap shape validation for a network config.
	 *
	 * @param array $config Config.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function validateConfigShape( array $config ): bool {
		return ! empty( $config['themeName'] )
			&& ! empty( $config['themeSlug'] )
			&& isset( $config['demoData'] ) && is_array( $config['demoData'] );
	}
}
