<?php
/**
 * Utility Class: ContextResolver.
 *
 * Single source of truth for multisite/network context decisions:
 * where the request is running, what the current user can do, and
 * which blog ID the operation should target.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: ContextResolver.
 *
 * @since 1.2.0
 */
final class ContextResolver {
	/**
	 * Whether the current request is on a multisite install.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isMultisite() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether the current request is in the Network Admin area.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isNetworkContext() {
		return self::isMultisite() && function_exists( 'is_network_admin' ) && is_network_admin();
	}

	/**
	 * Current blog ID, or 1 on single-site.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function currentBlogId() {
		return self::isMultisite() ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Whether the current user can run an import on the current blog.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canRunImport() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( self::isNetworkContext() && ! is_super_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the current user can install plugins on this install.
	 * On multisite, only Super Admin (matches WP core gating).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canInstallPlugins() {
		if ( self::isMultisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'install_plugins' );
	}

	/**
	 * Whether the current user can upload arbitrary mime types.
	 * On multisite, only Super Admin (core gating).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canUnfilteredUpload() {
		if ( self::isMultisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'unfiltered_upload' );
	}

	/**
	 * Target blog ID. Reads ?blog= from the request when a Super Admin
	 * is operating against a specific subsite, otherwise the current blog.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function targetBlogId() {
		if ( self::isMultisite() && self::canInstallPlugins() && isset( $_GET['blog'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = absint( wp_unslash( $_GET['blog'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $candidate > 0 ) {
				return $candidate;
			}
		}

		return self::currentBlogId();
	}

	/**
	 * Convenience: human-readable identifier for the current blog
	 * (used in banners and confirmations). Falls back to '1' on single-site.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	public static function currentBlogLabel() {
		$id = self::currentBlogId();

		if ( ! self::isMultisite() ) {
			return (string) $id;
		}

		$details = get_blog_details( $id );
		if ( ! $details ) {
			return (string) $id;
		}

		return sprintf( 'subsite-%d (%s)', $id, untrailingslashit( $details->siteurl ) );
	}
}
