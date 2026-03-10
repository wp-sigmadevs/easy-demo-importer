<?php
/**
 * Class: ImportLogger
 *
 * Writes structured log entries to wp_sd_edi_import_log.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportLogger
 *
 * @since 1.3.0
 */
class ImportLogger {

	/**
	 * Valid log levels.
	 *
	 * @var string[]
	 */
	const LEVELS = [ 'info', 'success', 'warning', 'error' ];

	/**
	 * Auto-prune logs older than this many days.
	 *
	 * @var int
	 */
	const PRUNE_DAYS = 7;

	/**
	 * Write a log entry.
	 *
	 * @param string $message    Human-readable message.
	 * @param string $level      One of: info, success, warning, error.
	 * @param string $session_id UUID of the current import session.
	 * @return void
	 * @since 1.3.0
	 */
	public static function log( string $message, string $level, string $session_id ): void {
		global $wpdb;

		if ( ! in_array( $level, self::LEVELS, true ) ) {
			$level = 'info';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'sd_edi_import_log',
			[
				'session_id' => sanitize_text_field( $session_id ),
				'logged_at'  => current_time( 'mysql' ),
				'level'      => $level,
				'message'    => sanitize_text_field( $message ),
			],
			[ '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Delete log rows older than PRUNE_DAYS days.
	 *
	 * Call at the start of each new import session.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public static function prune(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::PRUNE_DAYS . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}sd_edi_import_log WHERE logged_at < %s",
				$cutoff
			)
		);
	}

	/**
	 * Fetch log entries for a session newer than a given timestamp.
	 *
	 * @param string $session_id UUID of the import session.
	 * @param string $since      MySQL datetime string; only rows after this are returned.
	 * @return array<int, array{id: int, logged_at: string, level: string, message: string}>
	 * @since 1.3.0
	 */
	public static function fetch( string $session_id, string $since = '' ): array {
		global $wpdb;

		if ( $since ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, logged_at, level, message
					   FROM {$wpdb->prefix}sd_edi_import_log
					  WHERE session_id = %s AND logged_at > %s
					  ORDER BY id ASC",
					$session_id,
					$since
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, logged_at, level, message
					   FROM {$wpdb->prefix}sd_edi_import_log
					  WHERE session_id = %s
					  ORDER BY id ASC",
					$session_id
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}
}
