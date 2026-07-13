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
 * Scope is deliberately limited to the import's blast radius — content, terms,
 * comments and options — and never touches users or other tables. The media
 * library (wp-content/uploads) is captured in lockstep via {@see MediaSnapshot}
 * so a rollback restores the actual image files, not just their attachment rows.
 * Restoring reverts the site to the moment the snapshot was taken: anything
 * created after the import is lost, so this is offered opt-in with a loud
 * confirmation and is meant for fresh setup sites.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

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
	 * Live tables included in a snapshot.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	private static function tables(): array {
		global $wpdb;

		return [
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->terms,
			$wpdb->term_taxonomy,
			$wpdb->term_relationships,
			$wpdb->termmeta,
			$wpdb->comments,
			$wpdb->commentmeta,
			$wpdb->options,
		];
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
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . self::INFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		return ! empty( $found );
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

		if ( ! self::exists() ) {
			return false;
		}

		foreach ( self::tables() as $table ) {
			$shadow = self::shadowName( $table, $wpdb->prefix );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $shadow ) ) );

			if ( ! $exists ) {
				continue;
			}

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

		foreach ( self::tables() as $table ) {
			$shadow = self::shadowName( $table, $wpdb->prefix );

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
