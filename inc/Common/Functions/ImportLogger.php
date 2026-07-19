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
 * @since   2.0.0
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
 * @since 2.0.0
 */
final class ImportLogger {
	/**
	 * Unprefixed table name.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	const TABLE = 'sd_edi_import_log';

	/**
	 * Option holding the installed schema version, so existing installs pick up
	 * the table without a full re-activation.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	const DB_VERSION_OPTION = 'sd_edi_log_db_version';

	/**
	 * Current schema version. Bump to trigger a dbDelta on the next log call.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	const DB_VERSION = '2';

	/**
	 * Days of history to keep; older entries are pruned on import start.
	 *
	 * @var int
	 * @since 2.0.0
	 */
	const RETENTION_DAYS = 7;

	/**
	 * Log levels.
	 *
	 * @var string
	 * @since 2.0.0
	 */
	const INFO    = 'info';
	const SUCCESS = 'success';
	const WARNING = 'warning';
	const ERROR   = 'error';

	/**
	 * Derived run status for an import that never reached a terminal (success or
	 * error) entry and is no longer the active session — i.e. it was interrupted
	 * (page reload, gateway timeout, abandoned). Never stored as an entry level;
	 * computed at read time in getRuns().
	 *
	 * @var string
	 * @since 2.0.0
	 */
	const INTERRUPTED = 'interrupted';

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	public static function tableName(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Valid log levels.
	 *
	 * @return string[]
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
	 * @since 2.0.0
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
  demo_slug varchar(191) NOT NULL DEFAULT '',
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
	 * @param string $demo_slug  Demo this run is importing (for grouping).
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function log( string $message, string $level = self::INFO, string $session_id = '', string $demo_slug = '' ): void {
		$message = trim( wp_strip_all_tags( $message ) );

		if ( '' === $message ) {
			return;
		}

		$level = self::normalizeLevel( $level );

		global $wpdb;

		// Stored in GMT so the retention cutoff in prune() (also GMT) compares
		// like-for-like regardless of the site timezone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::tableName(),
			[
				'session_id' => $session_id,
				'demo_slug'  => $demo_slug,
				'logged_at'  => current_time( 'mysql', true ),
				'level'      => $level,
				'message'    => $message,
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
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
	 * @param string $demo_slug  Demo slug.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function info( string $message, string $session_id = '', string $demo_slug = '' ): void {
		self::log( $message, self::INFO, $session_id, $demo_slug );
	}

	/**
	 * Convenience: log a success entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 * @param string $demo_slug  Demo slug.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function success( string $message, string $session_id = '', string $demo_slug = '' ): void {
		self::log( $message, self::SUCCESS, $session_id, $demo_slug );
	}

	/**
	 * Convenience: log a warning entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 * @param string $demo_slug  Demo slug.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function warning( string $message, string $session_id = '', string $demo_slug = '' ): void {
		self::log( $message, self::WARNING, $session_id, $demo_slug );
	}

	/**
	 * Convenience: log an error entry.
	 *
	 * @param string $message    Message.
	 * @param string $session_id Session id.
	 * @param string $demo_slug  Demo slug.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function error( string $message, string $session_id = '', string $demo_slug = '' ): void {
		self::log( $message, self::ERROR, $session_id, $demo_slug );
	}

	/**
	 * Fetches log entries, newest first.
	 *
	 * @param string $session_id Restrict to one session, or '' for all.
	 * @param int    $limit      Maximum rows to return.
	 *
	 * @return array<int,array{id:int,session_id:string,demo_slug:string,logged_at:string,level:string,message:string}>
	 * @since 2.0.0
	 */
	public static function get( string $session_id = '', int $limit = 500 ): array {
		global $wpdb;

		// `$table` below is a prefix-derived identifier (see tableName()), not
		// user input; only the identifier is interpolated into the SQL — every
		// value (session_id, limit) is passed through $wpdb->prepare().
		$table = self::tableName();
		$limit = max( 1, $limit );

		if ( '' !== $session_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, session_id, demo_slug, logged_at, level, message FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT %d",
					$session_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, session_id, demo_slug, logged_at, level, message FROM {$table} ORDER BY id DESC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fetches log entries grouped into import runs (one per session), newest run
	 * first, each run carrying its derived demo label, start time and pass/fail
	 * status.
	 *
	 * @param int $limit Maximum entries to scan.
	 *
	 * @return array<int,array{session_id:string,demo_slug:string,started_at:string,status:string,count:int,entries:array<int,array{logged_at:string,level:string,message:string}>}>
	 * @since 2.0.0
	 */
	public static function getRuns( int $limit = 1000 ): array {
		// A live import refreshes its session heartbeat every phase; one that was
		// interrupted goes quiet while still holding the 30-minute lock. Treat a
		// session whose heartbeat has gone stale as no longer active so its run is
		// flagged interrupted instead of sitting on "In progress" until the lock
		// expires. The threshold sits comfortably above the longest single chunk.
		$active      = SessionManager::get();
		$active_sid  = $active['session_id'] ?? '';
		$last_active = (int) ( $active['last_active'] ?? $active['started_at'] ?? 0 );
		$threshold   = (int) apply_filters( 'sd/edi/interrupted_after_seconds', 2 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$stale       = '' !== $active_sid && ( time() - $last_active ) > $threshold;

		// Only a genuinely-live session shields its run from the interrupted flag.
		$live_sid = $stale ? '' : $active_sid;

		// Cache keyed on the latest row id: any new log entry changes the key and
		// misses the cache, so the runs view is never stale after a write, while
		// repeated Status-page opens between imports are served from the transient.
		// The live session id is part of the key so the run flips to "interrupted"
		// the moment its heartbeat clears, even though no new log row was written;
		// while a live import is pending, a per-minute bucket bounds how long the
		// "In progress" view can linger after the heartbeat actually goes stale.
		$bucket    = '' !== $live_sid ? (int) floor( time() / MINUTE_IN_SECONDS ) : 0;
		$cache_key = 'sd_edi_runs_' . self::latestId() . '_' . $limit . '_' . ( '' !== $live_sid ? $live_sid : 'none' ) . '_' . $bucket;
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Pull entries oldest-first within the scanned window so each run's
		// timeline reads top-to-bottom in the order things happened.
		$runs = self::markInterruptedRuns(
			self::groupRows( array_reverse( self::get( '', $limit ) ) ),
			$live_sid
		);

		set_transient( $cache_key, $runs, 5 * MINUTE_IN_SECONDS );

		return $runs;
	}

	/**
	 * Highest log row id currently stored, or 0 when the table is empty/absent.
	 *
	 * Used as a cheap cache-buster for getRuns(): a new entry always increments
	 * it, so the cached run list can never outlive a fresh log write.
	 *
	 * @return int
	 * @since 2.0.0
	 */
	private static function latestId(): int {
		global $wpdb;

		// `$table` is $wpdb->prefix + a constant (see tableName()), never user
		// input; a table identifier cannot be bound via $wpdb->prepare().
		$table = self::tableName();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
	}

	/**
	 * Groups flat, oldest-first log rows into runs. Pure — no DB access.
	 *
	 * @param array<int,array{session_id:string,demo_slug:string,logged_at:string,level:string,message:string}> $rows Oldest-first rows.
	 *
	 * @return array<int,array{session_id:string,demo_slug:string,started_at:string,status:string,count:int,entries:array<int,array{logged_at:string,level:string,message:string}>}>
	 * @since 2.0.0
	 */
	public static function groupRows( array $rows ): array {
		$runs = [];

		foreach ( $rows as $row ) {
			$sid = (string) $row['session_id'];

			if ( ! isset( $runs[ $sid ] ) ) {
				$runs[ $sid ] = [
					'session_id' => $sid,
					'demo_slug'  => (string) $row['demo_slug'],
					'started_at' => (string) $row['logged_at'],
					'status'     => self::INFO,
					'count'      => 0,
					'entries'    => [],
				];
			}

			if ( '' === $runs[ $sid ]['demo_slug'] && '' !== (string) $row['demo_slug'] ) {
				$runs[ $sid ]['demo_slug'] = (string) $row['demo_slug'];
			}

			$runs[ $sid ]['entries'][] = [
				'logged_at' => (string) $row['logged_at'],
				'level'     => (string) $row['level'],
				'message'   => (string) $row['message'],
			];
			++$runs[ $sid ]['count'];

			// Status precedence: any error → failed; otherwise a success entry
			// marks a clean finish; otherwise it stays informational/in-progress.
			if ( self::ERROR === $row['level'] ) {
				$runs[ $sid ]['status'] = self::ERROR;
			} elseif ( self::SUCCESS === $row['level'] && self::ERROR !== $runs[ $sid ]['status'] ) {
				$runs[ $sid ]['status'] = self::SUCCESS;
			}

			if ( self::WARNING === $row['level'] ) {
				$runs[ $sid ]['has_warning'] = true;
			}
		}

		// A run that finished cleanly but skipped items (per-image download
		// failures are logged as warnings) is reported as "warning" so the pill
		// reflects the partial failure rather than a misleading all-green success.
		foreach ( $runs as &$run ) {
			if ( self::SUCCESS === $run['status'] && ! empty( $run['has_warning'] ) ) {
				$run['status'] = self::WARNING;
			}

			unset( $run['has_warning'] );
		}

		unset( $run );

		// Newest run first.
		return array_values( array_reverse( $runs, true ) );
	}

	/**
	 * Flags never-finished runs as interrupted.
	 *
	 * A run whose status is still informational (no success and no error entry)
	 * either is the import running right now or was interrupted before it could
	 * finish. Any such run that is NOT the active session is, by definition, one
	 * that stopped mid-flight, so it gets a closing "interrupted" entry appended
	 * to its timeline and its status set to INTERRUPTED. The active session is
	 * left untouched so a live import is never mislabelled.
	 *
	 * @param array<int,array{session_id:string,status:string,count:int,entries:array<int,array{logged_at:string,level:string,message:string}>}> $runs       Grouped runs, newest first.
	 * @param string                                                                                                                              $active_sid Session id of the running import, or '' if none.
	 *
	 * @return array Runs with interrupted ones flagged.
	 * @since 2.0.0
	 */
	public static function markInterruptedRuns( array $runs, string $active_sid ): array {
		foreach ( $runs as &$run ) {
			$is_active = '' !== $active_sid && $run['session_id'] === $active_sid;

			if ( self::INFO !== $run['status'] || $is_active ) {
				continue;
			}

			$entries = $run['entries'];
			$last    = end( $entries );

			$run['entries'][] = [
				// Reuse the last real entry's timestamp so the closing line sits at
				// the end of the timeline rather than jumping to "now" on every view.
				'logged_at' => is_array( $last ) ? (string) $last['logged_at'] : (string) $run['started_at'],
				'level'     => self::INTERRUPTED,
				'message'   => __( 'Import was interrupted before it finished. Resume it, or start a new import.', 'easy-demo-importer' ),
			];
			++$run['count'];
			$run['status'] = self::INTERRUPTED;
		}

		unset( $run );

		return $runs;
	}

	/**
	 * Deletes entries older than the retention window.
	 *
	 * @param int $days Retention window in days.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function prune( int $days = self::RETENTION_DAYS ): void {
		global $wpdb;

		$table  = self::tableName();
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// `$table` is a prefix-derived identifier (see tableName()), not user
		// input; only the identifier is interpolated — the value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "DELETE FROM {$table} WHERE logged_at < %s", $cutoff )
		);
	}
}
