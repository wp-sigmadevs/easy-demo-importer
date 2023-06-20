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
	 * Gets Ajax URL.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
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
	 * Check if the AJAX call is valid.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function verifyAjaxCall() {
		check_ajax_referer( self::nonceText(), self::nonceId() );

		if ( ! current_user_can( 'import' ) ) {
			wp_die(
				sprintf(
				/* translators: %1$s - opening div and paragraph HTML tags, %2$s - closing div and paragraph HTML tags. */
					__( '%1$sYour user role isn\'t high enough. You don\'t have permission to import demo data.%2$s', 'easy-demo-importer' ),
					'<div class="notice notice-error"><p>',
					'</p></div>'
				)
			);
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
		$file       = str_replace( '.', '/', $viewName );
		$file       = ltrim( $file, '/' );
		$pluginPath = sd_edi()->getData()['plugin_path'];
		$viewsPath  = sd_edi()->getData()['views_folder'];
		$viewFile   = trailingslashit( $pluginPath . '/' . $viewsPath ) . $file . '.php';

		if ( ! file_exists( $viewFile ) ) {
			return new WP_Error(
				'brock',
				/* translators: View file name. */
				sprintf( __( '%s file not found', 'easy-demo-importer' ), $viewFile )
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

			if ( function_exists( 'is_plugin_active' ) ) {
				$status = is_plugin_active( $filePath ) ? 'active' : 'inactive';
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
	 * @param string $key Key to check.
	 * @param string $dataType Data type.
	 *
	 * @return array|string
	 * @since  1.0.0
	 */
	public static function keyExists( $key, $dataType = 'string' ) {
		if ( 'array' === $dataType ) {
			$data = [];
		} else {
			$data = '';
		}

		return ! empty( $key ) ? $key : $data;
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
		$query = new \WP_Query(
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
	 * @return mixed
	 */
	public static function getPluginsList( $demo, $config, $multiple ) {
		$demoData = ! empty( $config['demoData'][ $demo ] ) ? $config['demoData'][ $demo ] : [];

		return $multiple ? $demoData['plugins'] : $config['plugins'];
	}
}
