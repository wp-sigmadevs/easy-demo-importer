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
 * comments and options — and never touches users or other tables. Restoring
 * reverts the site to the moment the snapshot was taken: anything created after
 * the import is lost, so this is offered opt-in with a loud confirmation and is
 * meant for fresh setup sites.
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
	 * Creates a fresh restore point, replacing any previous one.
	 *
	 * @return bool True on success.
	 * @since 1.2.0
	 */
	public static function create(): bool {
		global $wpdb;

		self::drop();

		foreach ( self::tables() as $table ) {
			$shadow = self::shadowName( $table, $wpdb->prefix );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "CREATE TABLE `{$shadow}` LIKE `{$table}`" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( "INSERT INTO `{$shadow}` SELECT * FROM `{$table}`" );
		}

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

		self::drop();

		// The wholesale table swap invalidates every cache layer.
		wp_cache_flush();

		return true;
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
	}
}
