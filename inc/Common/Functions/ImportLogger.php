<?php
/**
 * Class: ImportLogger
 *
 * A persistent, queryable activity log for the demo import pipeline. Every
 * meaningful step (import started, posts queued, attachment failures, import
 * finished) writes a timestamped entry to a dedicated table, keyed by import
 * session, so a user can see what went wrong — or right — after the fact
 * instead of that information vanishing into a discarded output buffer.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class: ImportLogger
 *
 * @since 1.2.0
 */
final class ImportLogger {
	/**
	 * Unprefixed table name.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const TABLE = 'sd_edi_import_log';

	/**
	 * Option holding the installed schema version, so existing installs pick up
	 * the table without a full re-activation.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const DB_VERSION_OPTION = 'sd_edi_log_db_version';

	/**
	 * Current schema version. Bump to trigger a dbDelta on the next log call.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const DB_VERSION = '1';

	/**
	 * Days of history to keep; older entries are pruned on import start.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	const RETENTION_DAYS = 7;

	/**
	 * Log levels.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const INFO    = 'info';
	const SUCCESS = 'success';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Valid log levels.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	public static function levels(): array {
		return [ self::INFO, self::SUCCESS, self::WARNING, self::ERROR ];
	}

	/**
	 * Normalizes an arbitrary level string to a known level.
	 *
	 * @param string $level Candidate level.
	 *
	 * @return string A valid level, defaulting to INFO.
	 * @since 1.2.0
	 */
	public static function normalizeLevel( string $level ): string {
		$level = strtolower( trim( $level ) );

		return in_array( $level, self::levels(), true ) ? $level : self::INFO;
	}

	/**
	 * Creates the log table if the installed schema is out of date.
	 *
	 * Cheap to call repeatedly: a single option read short-circuits once the
	 * current version is installed, so it is safe to call on every import start.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function maybeInstall(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates (or upgrades) the log table via dbDelta.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function install(): void {
		global $wpdb;

		$table   = self::tableName();
		$collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id varchar(64) NOT NULL DEFAULT '',
  logged_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  level varchar(20) NOT NULL DEFAULT 'info',
  message text NOT NULL,
  PRIMARY KEY  (id),
  KEY session_id (session_id),
  KEY logged_at (logged_at)
) {$collate};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Writes a single log entry.
	 *
	 * Empty messages are ignored. Markup is stripped so the stored message is
	 * plain text (the bundled WXR importer reports failures as printed HTML).
	 * Error and warning entries are mirrored to the PHP error log so they are
	 * visible in wp-content/debug.log even before any UI reads the table.
	 *
	 * @param string $message    Human-readable message.
	 * @param string $level      One of the level constants.
	 * @param string $session_id Import session this entry belongs to.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function log( string $message, string $level = self::INFO, string $session_id = '' ): void {
		$message = trim( wp_strip_all_tags( $message ) );

		if ( '' === $message ) {
			return;
		}

		$level = self::normalizeLevel( $level );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::tableName(),
			[
				'session_id' => $session_id,
				'logged_at'  => current_time( 'mysql' ),
				'level'      => $level,
				'message'    => $message,
			],
			[ '%s', '%s', '%s', '%s' ]
		);

		if ( self::ERROR === $level || self::WARNING === $level ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( '[Easy Demo Importer] %s: %s', strtoupper( $level ), $message ) );
		}
	}

	/**
	 * Convenience: log an info entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function info( string $message, string $session_id = '' ): void {
		self::log( $message, self::INFO, $session_id );
	}

	/**
	 * Convenience: log a success entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function success( string $message, string $session_id = '' ): void {
		self::log( $message, self::SUCCESS, $session_id );
	}

	/**
	 * Convenience: log a warning entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function warning( string $message, string $session_id = '' ): void {
		self::log( $message, self::WARNING, $session_id );
	}

	/**
	 * Convenience: log an error entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function error( string $message, string $session_id = '' ): void {
		self::log( $message, self::ERROR, $session_id );
	}

	/**
	 * Fetches log entries, newest first.
	 *
	 * @param string $session_id Restrict to one session, or '' for all.
	 * @param int    $limit      Maximum rows to return.
	 *
	 * @return array<int,array{id:int,session_id:string,logged_at:string,level:string,message:string}>
	 * @since 1.2.0
	 */
	public static function get( string $session_id = '', int $limit = 500 ): array {
		global $wpdb;

		$table = self::tableName();
		$limit = max( 1, $limit );

		if ( '' !== $session_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, session_id, logged_at, level, message FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT %d",
					$session_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, session_id, logged_at, level, message FROM {$table} ORDER BY id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Deletes entries older than the retention window.
	 *
	 * @param int $days Retention window in days.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function prune( int $days = self::RETENTION_DAYS ): void {
		global $wpdb;

		$table  = self::tableName();
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $cutoff )
		);
	}
}
