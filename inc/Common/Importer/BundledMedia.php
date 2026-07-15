<?php
/**
 * Importer Utility: BundledMedia
 *
 * Pure URL→path mapping for the bundled-media import mode. Given an attachment
 * URL from a WXR file, produces the ordered list of uploads-relative paths to
 * look for inside a demo package's bundled `uploads/` folder. Kept free of any
 * WordPress or filesystem calls so the mapping is unit-testable in isolation;
 * the importer layers existence checks and the filter on top.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Importer;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Importer Utility: BundledMedia
 *
 * @since 2.0.0
 */
final class BundledMedia {
	/**
	 * Ordered, uploads-relative candidate paths for a given attachment URL.
	 *
	 * The bundled folder mirrors the standard WP uploads layout, so the relative
	 * path after `…/uploads/` is what we look up. Two candidates are produced,
	 * most-specific first:
	 *
	 *   1. Everything after the last `/uploads/` segment (the normal case, host
	 *      agnostic — works whether the URL kept the `wp-content/uploads` prefix
	 *      or not).
	 *   2. A `YYYY/MM/…` tail anywhere in the path, for CDN-rewritten URLs that
	 *      dropped the `uploads/` segment entirely.
	 *
	 * Any candidate containing a `..` traversal segment is rejected.
	 *
	 * @param string $url Attachment URL from the WXR file.
	 *
	 * @return string[] Uploads-relative paths to try, in priority order.
	 * @since 2.0.0
	 */
	public static function candidates( string $url ): array {
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return [];
		}

		$path  = rawurldecode( $path );
		$found = [];

		// Primary: uploads-relative path after the last `/uploads/` segment.
		if ( preg_match( '#/uploads/(.+)$#', $path, $matches ) ) {
			$found[] = ltrim( $matches[1], '/' );
		}

		// Fallback: a YYYY/MM/… tail for CDN URLs without an `uploads/` segment.
		if ( preg_match( '#(\d{4}/\d{2}/[^/].*)$#', $path, $matches ) ) {
			$found[] = ltrim( $matches[1], '/' );
		}

		$found = array_values(
			array_unique(
				array_filter(
					$found,
					static function ( $relative ) {
						return '' !== $relative && false === strpos( $relative, '..' );
					}
				)
			)
		);

		return $found;
	}
}
