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
use SigmaDevs\EasyDemoImporter\Common\Utils\OutputGuard;

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
	 * @since 2.0.0
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
		// Drop anything other code printed before this handler got control, so
		// the JSON this request answers with stays parseable. Every phase routes
		// through here, including the error responses below.
		OutputGuard::reset();

		// Verifies the Ajax request.
		if ( ! check_ajax_referer( self::nonceText(), self::nonceId(), false ) ) {
			self::logDenial( 'nonce' );

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
			self::logDenial( 'capability' );

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
	 * Records a rejected request so a failed nonce or capability check leaves a trace.
	 *
	 * Both denial paths previously returned 403 and wrote nothing anywhere, so a
	 * probe against the plugin's Ajax actions or REST routes was invisible after
	 * the fact.
	 *
	 * Routing is deliberate. Entries are only written to the activity log when an
	 * import session is actually running, because ImportLogger::getRuns() groups
	 * by `session_id` and caps the view at ten runs - a stream of session-less
	 * denial rows would collapse into one phantom "run" and push real imports out
	 * of the Activity tab. A denial *during* an import is exactly what belongs in
	 * that run's timeline (an expired nonce mid-import is the "page left open too
	 * long" case the error message itself describes); a denial outside one is a
	 * probe, and goes to the PHP error log instead.
	 *
	 * The `sd/edi/security_denial` action fires in both cases so a site can route
	 * these into its own audit sink without depending on either default.
	 *
	 * @param string $reason Which gate rejected the request: 'nonce' or 'capability'.
	 *
	 * @return void
	 * @since 2.0.3
	 */
	private static function logDenial( string $reason ) {
		// Read only to name the rejected action; the request is being refused, and
		// this value is never trusted or acted on beyond being logged.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'unknown';
		$user_id = get_current_user_id();

		/**
		 * Fires when the plugin refuses a request on a nonce or capability check.
		 *
		 * @param string $reason  'nonce' or 'capability'.
		 * @param string $action  The rejected Ajax action name.
		 * @param int    $user_id Current user ID, 0 when logged out.
		 *
		 * @since 2.0.3
		 */
		do_action( 'sd/edi/security_denial', $reason, $action, $user_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$message = sprintf(
			/* translators: 1: denial reason, 2: ajax action name, 3: user ID. */
			esc_html__( 'Request denied on %1$s check (action: %2$s, user: %3$d).', 'easy-demo-importer' ),
			$reason,
			$action,
			$user_id
		);

		$active     = SessionManager::get();
		$session_id = is_array( $active ) && ! empty( $active['session_id'] ) ? (string) $active['session_id'] : '';

		if ( '' !== $session_id ) {
			ImportLogger::warning( $message, $session_id );

			return;
		}

		// No run to attach to. error_log() is the correct sink for a security
		// event: it is host-rotated, reaches log aggregation, and cannot bloat a
		// database table that an authenticated low-privilege user could otherwise
		// drive writes into by spamming a rejected action.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[easy-demo-importer] ' . $message );
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
}
