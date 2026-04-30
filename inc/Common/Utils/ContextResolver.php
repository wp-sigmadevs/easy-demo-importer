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
	public static function isMultisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether the current request is in the Network Admin area.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isNetworkContext(): bool {
		return self::isMultisite() && function_exists( 'is_network_admin' ) && is_network_admin();
	}

	/**
	 * Current blog ID, or 1 on single-site.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function currentBlogId(): int {
		return self::isMultisite() ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Whether the current user can run an import on the current blog.
	 *
	 * NOTE: this is a screen-context check. `is_network_admin()` returns false
	 * during REST/AJAX/cron requests even if the originating screen was Network
	 * Admin. Callers in REST/AJAX endpoints that need the network-admin gate
	 * should additionally check `is_super_admin()` directly, OR resolve the
	 * target via `targetBlogId()` and gate on that. Do not rely on this method
	 * alone for cross-blog operations.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canRunImport(): bool {
		// Super Admin can always run an import (any blog, any context).
		if ( function_exists( 'is_super_admin' ) && is_super_admin() ) {
			return true;
		}

		// Outside Network Admin, manage_options is the per-blog gate.
		if ( current_user_can( 'manage_options' ) && ! self::isNetworkContext() ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether the current user can install plugins on this install.
	 * On multisite, only Super Admin (matches WP core gating).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canInstallPlugins(): bool {
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
	public static function canUnfilteredUpload(): bool {
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
	public static function targetBlogId(): int {
		if ( self::isMultisite() && self::canInstallPlugins() && isset( $_GET['blog'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = absint( wp_unslash( $_GET['blog'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $candidate > 0 ) {
				$details = get_blog_details( $candidate );
				if ( $details && empty( $details->archived ) && empty( $details->spam ) && empty( $details->deleted ) ) {
					return $candidate;
				}
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
	public static function currentBlogLabel(): string {
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
