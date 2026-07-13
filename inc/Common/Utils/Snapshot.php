<?php
/**
 * Utility Class: Snapshot
 *
 * A restore-point for the tables an import writes to, implemented as DB-native
 * shadow tables. Before an import, each content/options table is cloned into a
 * `{prefix}sd_edi_snap_*` table via `CREATE TABLE … LIKE` + `INSERT … SELECT`
 * (both run entirely inside MySQL — no PHP row loop, so no per-row timeout).
 * Rolling back truncates each live table and copies its rows back from the
 * shadow, then drops the shadows.
 *
 * Scope is every prefixed table the import could touch — discovered dynamically
 * with the same scan {@see Initialize::databaseReset()} uses, so the set that a
 * reset wipes and the set the snapshot backs up are identical (posts, terms,
 * comments, options AND every plugin's own custom tables: WooCommerce orders,
 * form entries, bookings, …). Users, usermeta, the activity log and regenerable
 * queues (Action Scheduler) are excluded; the exclude list is filterable via
 * `sd/edi/snapshot_exclude_tables`. The media library (wp-content/uploads) is
 * captured in lockstep via {@see MediaSnapshot} so a rollback restores the actual
 * image files, not just their attachment rows. Restoring reverts the site to the
 * moment the snapshot was taken: anything created after the import is lost, so
 * this is offered opt-in with a loud confirmation and is meant for fresh setup
 * sites.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Common\Functions\ImportLogger;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: Snapshot
 *
 * @since 1.2.0
 */
final class Snapshot {

	/**
	 * Shadow-table name infix.
	 */
	const INFIX = 'sd_edi_snap_';

	/**
	 * Option that records which import session the current restore point belongs
	 * to. Used to take a fresh snapshot once per import (rather than reusing a
	 * stale one left behind by a previous import) while still no-opping the
	 * repeated per-chunk calls within a single session.
	 */
	const SESSION_OPTION = 'sd_edi_snapshot_session';

	/**
	 * Live tables included in a snapshot — every prefixed table with a storage
	 * engine, discovered the same way {@see Initialize::databaseReset()} finds the
	 * tables it resets, minus the exclude list. Keeps the reset set and the backup
	 * set identical so a rollback is complete.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	private static function tables(): array {
		global $wpdb;

		$tables = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_results( 'SHOW TABLE STATUS' );

		if ( ! is_array( $status ) ) {
			return $tables;
		}

		foreach ( $status as $table ) {
			if ( 0 !== stripos( $table->Name, $wpdb->prefix ) ) {
				continue;
			}

			// Views and the like have no engine and cannot be cloned.
			if ( empty( $table->Engine ) ) {
				continue;
			}

			if ( self::isExcluded( $table->Name, $wpdb->prefix ) ) {
				continue;
			}

			$tables[] = $table->Name;
		}

		return $tables;
	}

	/**
	 * Whether a table is kept out of the snapshot: the shadows themselves, user
	 * accounts (never reverted, so a rollback can't delete logins), the plugin's
	 * own persistent activity log, and regenerable queues such as Action
	 * Scheduler. Filterable via `sd/edi/snapshot_exclude_tables` (bare, unprefixed
	 * table names).
	 *
	 * @param string $table  Full table name (includes prefix).
	 * @param string $prefix Table prefix.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private static function isExcluded( string $table, string $prefix ): bool {
		$bare = ( '' !== $prefix && 0 === strpos( $table, $prefix ) )
			? substr( $table, strlen( $prefix ) )
			: $table;

		// Never snapshot the shadow tables (would recurse the restore point).
		if ( 0 === strpos( $bare, self::INFIX ) ) {
			return true;
		}

		// Regenerable / transient job queues — large and pointless to revert.
		if ( 0 === strpos( $bare, 'actionscheduler' ) ) {
			return true;
		}

		$exclude = (array) apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'sd/edi/snapshot_exclude_tables',
			[ 'users', 'usermeta', ImportLogger::TABLE ]
		);

		return in_array( $bare, $exclude, true );
	}

	/**
	 * Reverse of {@see shadowName()}: `{prefix}sd_edi_snap_posts` → `{prefix}posts`.
	 *
	 * @param string $shadow Shadow table name (includes prefix).
	 * @param string $prefix Table prefix.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private static function liveFromShadow( string $shadow, string $prefix ): string {
		$needle = $prefix . self::INFIX;

		return ( 0 === strpos( $shadow, $needle ) )
			? $prefix . substr( $shadow, strlen( $needle ) )
			: $shadow;
	}

	/**
	 * Every shadow table that currently exists (the tables actually saved by the
	 * live restore point). Restore and drop key off this rather than a fresh scan
	 * of live tables, since an import can add or drop tables mid-run.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	private static function shadowTables(): array {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . self::INFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return is_array( $found ) ? $found : [];
	}

	/**
	 * Shadow-table name for a live table.
	 *
	 * Pure: `{prefix}posts` → `{prefix}sd_edi_snap_posts`.
	 *
	 * @param string $live_table Live table name (includes prefix).
	 * @param string $prefix     Table prefix.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	public static function shadowName( string $live_table, string $prefix ): string {
		$bare = ( '' !== $prefix && 0 === strpos( $live_table, $prefix ) )
			? substr( $live_table, strlen( $prefix ) )
			: $live_table;

		return $prefix . self::INFIX . $bare;
	}

	/**
	 * Whether a restore point currently exists.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function exists(): bool {
		return ! empty( self::shadowTables() );
	}

	/**
	 * Whether a restore point has already been taken for the given import
	 * session. Lets the once-per-import creation no-op on the repeated per-chunk
	 * calls, while still taking a fresh snapshot for each new import session.
	 *
	 * @param string $sessionId Current import session ID.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isForSession( string $sessionId ): bool {
		return '' !== $sessionId && get_option( self::SESSION_OPTION ) === $sessionId;
	}

	/**
	 * Creates a fresh restore point, replacing any previous one.
	 *
	 * Always drops any stale shadow tables left by a previous import first, so a
	 * new import never inherits an earlier import's (possibly empty) restore
	 * point. The session marker is recorded even when the snapshot is skipped for
	 * size, so the repeated per-chunk callers stop retrying for this session.
	 *
	 * @param string $sessionId Import session this restore point belongs to.
	 * @param bool   $reset     Whether the import resets the database and uploads
	 *                          (selects the media capture strategy).
	 * @param string $demoSlug  Demo slug, for the activity log.
	 *
	 * @return bool True on success.
	 * @since 1.2.0
	 */
	public static function create( string $sessionId = '', bool $reset = false, string $demoSlug = '' ): bool {
		global $wpdb;

		// Clear any stale shadows from a previous import before anything else, so
		// a skipped (too-large) or half-done snapshot can never leave an older
		// import's restore point in place.
		self::drop();

		// Mark this session handled up front — even the too-large skip below must
		// record it, or the per-chunk callers would recount the whole DB on every
		// request. Autoload off: read only during an active import.
		update_option( self::SESSION_OPTION, $sessionId, false );

		// A single unchunked INSERT..SELECT per table can exceed the gateway
		// timeout on a very large existing site. Skip (so the import proceeds
		// without a restore point) rather than risk a half-done snapshot. Filter
		// `sd/edi/snapshot_max_rows` to 0 to disable the guard.
		if ( self::tooLargeToSnapshot() ) {
			return false;
		}

		foreach ( self::tables() as $table ) {
			$shadow = self::shadowName( $table, $wpdb->prefix );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "CREATE TABLE `{$shadow}` LIKE `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO `{$shadow}` SELECT * FROM `{$table}`" );
		}

		// Capture the media library too, so a rollback restores the image files
		// and not just their attachment rows.
		MediaSnapshot::create( $sessionId, $reset, $demoSlug );

		return true;
	}

	/**
	 * Restores every live table from its shadow, then drops the shadows.
	 *
	 * @return bool True if a restore point existed and was applied.
	 * @since 1.2.0
	 */
	public static function restore(): bool {
		global $wpdb;

		$shadows = self::shadowTables();

		if ( empty( $shadows ) ) {
			return false;
		}

		foreach ( $shadows as $shadow ) {
			$table = self::liveFromShadow( $shadow, $wpdb->prefix );

			// Recreate the live table if the import dropped it, so the shadow's
			// rows always have somewhere to go back to.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` LIKE `{$shadow}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "TRUNCATE `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO `{$table}` SELECT * FROM `{$shadow}`" );
		}

		// Restore the media library alongside the database so the two stay in
		// lockstep (no-op when only a DB snapshot was taken).
		MediaSnapshot::restore();

		self::drop();

		// The wholesale table swap invalidates every cache layer.
		wp_cache_flush();

		return true;
	}

	/**
	 * Whether the snapshotted tables hold more rows than is safe to clone in one
	 * unchunked pass. Counts per table, short-circuiting once the cap is passed.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private static function tooLargeToSnapshot(): bool {
		global $wpdb;

		$cap = (int) apply_filters( 'sd/edi/snapshot_max_rows', 500000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( $cap <= 0 ) {
			return false;
		}

		$total = 0;

		foreach ( self::tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

			if ( $total > $cap ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Drops every shadow table.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function drop(): void {
		global $wpdb;

		foreach ( self::shadowTables() as $shadow ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$shadow}`" );
		}

		// Discard the media half in lockstep with the database half.
		MediaSnapshot::discard();

		// The restore point is gone; forget which session owned it so the next
		// import takes a fresh snapshot.
		delete_option( self::SESSION_OPTION );
	}
}
