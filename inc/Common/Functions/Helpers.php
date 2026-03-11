<?php
/**
 * Functions Class: Helpers.
 *
 * List of all helper functions.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use WP_Post;
use WP_Error;
use WP_Query;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Functions Class: Helpers.
 *
 * @since 1.0.0
 */
class Helpers {
	/**
	 * Whitelist of allowed view names for renderView().
	 *
	 * @var array
	 * @since 1.2.0
	 */
	private static $allowedViews = [
		'demo-import',
		'server-status',
	];

	/**
	 * Gets Ajax URL.
	 *
	 * @static
	 *
	 * @return string	 * @since  1.0.0
	 */
	public static function ajaxUrl() {
		return admin_url( 'admin-ajax.php' );
	}

	/**
	 * Nonce Text.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function nonceText() {
		return 'sd_edi_nonce_secret';
	}

	/**
	 * Nonce ID.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function nonceId() {
		return 'sd_edi_nonce';
	}

	/**
	 * Check if the AJAX call is valid and
	 * the user has sufficient permission.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function verifyAjaxCall() {
		// Verifies the Ajax request.
		if ( ! check_ajax_referer( self::nonceText(), self::nonceId(), false ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Security check failed. Access denied.', 'easy-demo-importer' ),
				],
				403
			);
			// @phpstan-ignore deadCode.unreachable
			wp_die();
		}

		// Verifies the user role.
		self::verifyUserRole();
	}

	/**
	 * Verify if the current user has the 'manage_options' capability.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function verifyUserRole() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'You don\'t have permission to perform this action.', 'easy-demo-importer' ),
				],
				403
			);
			// @phpstan-ignore deadCode.unreachable
			wp_die();
		}
	}

	/**
	 * Allowed Tags for wp_kses: Basic.
	 *
	 * @static
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public static function allowedTags() {
		return [
			'a'          => [
				'class' => [],
				'href'  => [],
				'rel'   => [],
				'title' => [],
			],
			'b'          => [],
			'blockquote' => [
				'cite' => [],
			],
			'cite'       => [
				'title' => [],
			],
			'code'       => [],
			'div'        => [
				'class' => [],
				'title' => [],
				'style' => [],
			],
			'em'         => [],
			'h1'         => [
				'class' => [],
			],
			'h2'         => [
				'class' => [],
			],
			'h3'         => [
				'class' => [],
			],
			'h4'         => [
				'class' => [],
				'style' => [],
			],
			'h5'         => [
				'class' => [],
			],
			'h6'         => [
				'class' => [],
			],
			'i'          => [
				'class' => [],
			],
			'img'        => [
				'alt'    => [],
				'class'  => [],
				'height' => [],
				'src'    => [],
				'width'  => [],
			],
			'li'         => [
				'class' => [],
			],
			'ol'         => [
				'class' => [],
			],
			'p'          => [
				'class' => [],
				'style' => [],
			],
			'span'       => [
				'class' => [],
				'title' => [],
				'style' => [],
			],
			'strong'     => [],
			'small'      => [],
			'hr'         => [],
			'br'         => [],
			'ul'         => [
				'class' => [],
			],
		];
	}

	/**
	 * Renders Admin View.
	 *
	 * @param string $viewName View name.
	 * @param array  $args View args.
	 *
	 * @return WP_Error|void
	 * @since  1.0.0
	 */
	public static function renderView( $viewName, $args = [] ) {
		// Enforce whitelist for security.
		if ( ! in_array( $viewName, self::$allowedViews, true ) ) {
			return new WP_Error(
				'invalid_view_name',
				/* translators: View file name. */
				sprintf( esc_html__( 'Invalid view name: %s', 'easy-demo-importer' ), esc_html( $viewName ) )
			);
		}

		$file       = str_replace( '.', '/', $viewName );
		$file       = ltrim( $file, '/' );
		$pluginPath = sd_edi()->getData()['plugin_path'];
		$viewsPath  = sd_edi()->getData()['views_folder'];
		$viewFile   = trailingslashit( $pluginPath . '/' . $viewsPath ) . $file . '.php';

		if ( ! file_exists( $viewFile ) ) {
			return new WP_Error(
				'view_file_not_found',
				/* translators: View file name. */
				sprintf( esc_html__( '%s file not found', 'easy-demo-importer' ), esc_html( $viewFile ) )
			);
		}

		load_template( $viewFile, true, $args );
	}


	/**
	 * Determines the active status of a plugin given its file path.
	 *
	 * @param string $filePath The file path of the plugin.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function pluginActivationStatus( $filePath ) {
		$status     = 'install';
		$pluginPath = WP_PLUGIN_DIR . '/' . esc_attr( $filePath );

		if ( file_exists( $pluginPath ) ) {
			include_once ABSPATH . 'wp-admin/includes/plugin.php';
			include_once ABSPATH . 'wp-admin/includes/update.php';

			if ( function_exists( 'is_plugin_active' ) ) {
				$status = is_plugin_active( $filePath ) ? 'active' : 'inactive';
			}

			$update_list = get_site_transient( 'update_plugins' );

			if ( isset( $update_list->response[ $filePath ] ) ) {
				$status = 'active' === $status ? 'update' : 'inactive-update';
			}
		}

		return $status;
	}

	/**
	 * Delete widgets.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function deleteWidgets() {
		global $wp_registered_widget_controls;

		$widgetControls = $wp_registered_widget_controls;

		$availableWidgets = [];

		foreach ( $widgetControls as $widget ) {
			if ( ! empty( $widget['id_base'] ) && ! isset( $availableWidgets[ $widget['id_base'] ] ) ) {
				$availableWidgets[] = $widget['id_base'];
			}
		}

		update_option( 'sidebars_widgets', [ 'wp_inactive_widgets' => [] ] );

		foreach ( $availableWidgets as $widgetData ) {
			update_option( 'widget_' . $widgetData, [] );
		}
	}

	/**
	 * Delete ThemeMods.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function deleteThemeMods() {
		$themeSlug = get_option( 'stylesheet' );
		$mods      = get_option( "theme_mods_$themeSlug" );

		if ( false !== $mods ) {
			delete_option( "theme_mods_$themeSlug" );
		}
	}

	/**
	 * Deletes any registered navigation menus
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function deleteNavMenus() {
		$nav_menus = wp_get_nav_menus();

		// Delete navigation menus.
		if ( ! empty( $nav_menus ) ) {
			foreach ( $nav_menus as $nav_menu ) {
				wp_delete_nav_menu( $nav_menu->slug );
			}
		}
	}

	/**
	 * Check if array key exists;
	 *
	 * @param array  $haystack The array or string to search within.
	 * @param string $needle The key to search for within the haystack.
	 * @param string $dataType The expected data type of the value.
	 *
	 * @return array|string
	 * @since  1.0.0
	 */
	public static function getDemoData( $haystack, $needle, $dataType = 'string' ) {
		if ( is_array( $haystack ) && array_key_exists( $needle, $haystack ) ) {
			$key = $haystack[ $needle ];

			if ( 'array' === $dataType ) {
				return is_array( $key ) ? $key : [];
			} elseif ( 'string' === $dataType ) {
				return is_string( $key ) ? $key : '';
			}
		}

		return '';
	}

	/**
	 * Get page by title.
	 *
	 * @param string $title Page name.
	 * @param string $post_type Post type.
	 *
	 * @return WP_Post|null
	 * @since  1.0.0
	 */
	public static function getPageByTitle( $title, $post_type = 'page' ) {
		$query = new WP_Query(
			[
				'post_type'              => esc_html( $post_type ),
				'title'                  => esc_html( $title ),
				'post_status'            => 'all',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'orderby'                => 'post_date ID',
				'order'                  => 'ASC',
			]
		);

		if ( ! empty( $query->post ) ) {
			$pageByTitle = $query->post;
		} else {
			$pageByTitle = null;
		}

		return $pageByTitle;
	}

	/**
	 * Check if plugin config exists.
	 *
	 * @param string $demo Demo slug.
	 * @param array  $config Theme config.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public static function pluginConfigExists( $demo, $config ) {
		$demoData = ! empty( $config['demoData'][ $demo ] ) ? $config['demoData'][ $demo ] : [];

		return ! ( empty( $config['plugins'] ) && empty( $demoData['plugins'] ) );
	}

	/**
	 * Get plugins list.
	 *
	 * @param string $demo Demo slug.
	 * @param array  $config Theme config.
	 * @param bool   $multiple Is multiple?.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public static function getPluginsList( $demo, $config, $multiple ) {
		$demoData = ! empty( $config['demoData'][ $demo ] ) ? $config['demoData'][ $demo ] : [];

		return $multiple ? $demoData['plugins'] : $config['plugins'];
	}

	/**
	 * Get lists of active plugins.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function getActivePlugins() {
		// Ensure get_plugins function is loaded.
		if ( ! function_exists( 'get_plugins' ) ) {
			include ABSPATH . '/wp-admin/includes/plugin.php';
		}

		$activePlugins = get_option( 'active_plugins' );

		return array_intersect_key( get_plugins(), array_flip( $activePlugins ) );
	}

	/**
	 * Get lists of inactive plugins.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function getInactivePlugins() {
		return array_diff_key( get_plugins(), self::getActivePlugins() );
	}

	/**
	 * Recursively checks if a multidimensional array has a certain key.
	 *
	 * @param array  $array The array to search.
	 * @param string $key The key to search for.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	/**
	 * Recursively checks if a multidimensional array has a certain key.
	 *
	 * @param array  $array The array to search.
	 * @param string $key The key to search for.
	 *
	 * @return bool
	 * @since 1.1.0
	 */
	public static function searchArrayKey( $array, $key ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		if ( array_key_exists( $key, $array ) ) {
			return true;
		}

		foreach ( $array as $item ) {
			if ( is_array( $item ) && self::searchArrayKey( $item, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if the current request is a demo import AJAX request.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	public static function isImportRequest(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		if ( empty( $action ) ) {
			return false;
		}

		return 0 === strpos( $action, 'sd_edi_' );
	}

	/**
	 * Resolve the local XML file path for a demo slug.
	 *
	 * @param array  $config    Demo config array.
	 * @param string $demo_slug Demo slug.
	 * @return string|null Absolute path, or null if unresolvable.
	 * @since 1.5.0
	 */
	public static function resolveXmlPath( array $config, string $demo_slug ): ?string {
		$upload_dir = wp_get_upload_dir();
		$base       = $upload_dir['basedir'] . '/easy-demo-importer/';

		$multiple = ! empty( $config['multipleZip'] );
		$demoZip  = '';

		if ( $multiple && isset( $config['demoData'][ $demo_slug ] ) ) {
			$demoZip = self::getDemoData( $config['demoData'][ $demo_slug ], 'demoZip' );
		} elseif ( ! $multiple ) {
			$demoZip = self::getDemoData( $config, 'demoZip' );
		}

		if ( ! $demoZip ) {
			return null;
		}

		$demoDir = pathinfo( basename( $demoZip ), PATHINFO_FILENAME );

		return $base . $demoDir . '/content.xml';
	}
}
