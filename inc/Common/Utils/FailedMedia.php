<?php
/**
 * Utility Class: FailedMedia
 *
 * Persists the list of attachments that could not be downloaded during an
 * import, keyed by import session, so the user can retry just those later. A
 * fully-failed download never creates an attachment (the importer skips it), so
 * a retry needs the original WXR post data + source URL — which is what each
 * stored record carries.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: FailedMedia
 *
 * @since 2.0.0
 */
final class FailedMedia {

	/**
	 * Option name prefix; the session id is hashed onto the end.
	 */
	const PREFIX = 'sd_edi_failed_media_';

	/**
	 * Option key for a session's failed-media list.
	 *
	 * @param string $session_id Import session id.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private static function key( string $session_id ): string {
		return self::PREFIX . md5( $session_id );
	}

	/**
	 * Stores the failed-media list for a session (or clears it when empty).
	 *
	 * @param string $session_id Import session id.
	 * @param array  $items      Failed-media records ({url, data}).
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function save( string $session_id, array $items ): void {
		if ( empty( $items ) ) {
			self::clear( $session_id );
			return;
		}

		update_option( self::key( $session_id ), $items, false );
	}

	/**
	 * Loads a session's failed-media list.
	 *
	 * @param string $session_id Import session id.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function get( string $session_id ): array {
		$items = get_option( self::key( $session_id ), [] );

		return is_array( $items ) ? $items : [];
	}

	/**
	 * Number of failed-media records stored for a session.
	 *
	 * @param string $session_id Import session id.
	 *
	 * @return int
	 * @since 2.0.0
	 */
	public static function count( string $session_id ): int {
		return count( self::get( $session_id ) );
	}

	/**
	 * Deletes a session's failed-media list.
	 *
	 * @param string $session_id Import session id.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function clear( string $session_id ): void {
		delete_option( self::key( $session_id ) );
	}
}
