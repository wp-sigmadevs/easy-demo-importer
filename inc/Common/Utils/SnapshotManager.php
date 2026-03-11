<?php
/**
 * Utility: SnapshotManager
 *
 * Creates and restores pre-import snapshots for rollback.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class SnapshotManager
 *
 * @since 1.5.0
 */
class SnapshotManager {

	/**
	 * Create a snapshot watermark before import starts.
	 * Returns the snapshot row ID (used as rollback token).
	 *
	 * @param string $session_id Import session UUID.
	 * @param string $demo_slug  Demo slug being imported.
	 * @return int Snapshot row ID, or 0 on failure.
	 * @since 1.5.0
	 */
	public static function create( string $session_id, string $demo_slug ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_post_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_term_id = (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );

		$options = [];

		foreach ( [ 'sidebars_widgets', 'nav_menu_locations' ] as $key ) {
			$options[ $key ] = get_option( $key );
		}

		$theme_mods_key          = 'theme_mods_' . get_stylesheet();
		$options[ $theme_mods_key ] = get_option( $theme_mods_key );

		$snapshot = [
			'max_post_id'   => $max_post_id,
			'max_term_id'   => $max_term_id,
			'options'       => $options,
			'snapshot_time' => current_time( 'mysql' ),
		];

		$table = $wpdb->prefix . 'sd_edi_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			[
				'session_id'    => $session_id,
				'demo_slug'     => $demo_slug,
				'snapshot_data' => wp_json_encode( $snapshot ),
				'created_at'    => current_time( 'mysql' ),
				'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get a non-expired snapshot by ID.
	 * Returns null if not found or expired.
	 *
	 * @param int $snapshot_id Snapshot row ID.
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public static function get( int $snapshot_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'sd_edi_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				"SELECT * FROM %1\$s WHERE id = %d AND expires_at > %s",
				$table,
				$snapshot_id,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get the latest non-expired snapshot (for CompleteStep Undo button).
	 *
	 * @return array<string, mixed>|null
	 * @since 1.5.0
	 */
	public static function getLatest(): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'sd_edi_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				"SELECT * FROM %1\$s WHERE expires_at > %s ORDER BY created_at DESC LIMIT 1",
				$table,
				current_time( 'mysql' )
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Restore from snapshot: delete posts/terms above watermarks, restore options.
	 *
	 * @param int    $snapshot_id Snapshot row ID.
	 * @param string $session_id  Session ID for logging.
	 * @return array{posts_deleted: int, terms_deleted: int}|array{error: string}
	 * @since 1.5.0
	 */
	public static function restore( int $snapshot_id, string $session_id ): array {
		global $wpdb;

		$row = self::get( $snapshot_id );

		if ( ! $row ) {
			return [ 'error' => 'Snapshot not found or expired.' ];
		}

		$data        = json_decode( $row['snapshot_data'], true );
		$max_post_id = (int) ( $data['max_post_id'] ?? 0 );
		$max_term_id = (int) ( $data['max_term_id'] ?? 0 );
		$snap_time   = $data['snapshot_time'] ?? '';
		$options     = $data['options'] ?? [];

		// Delete posts created after snapshot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_date >= %s",
				$max_post_id,
				$snap_time
			)
		);

		$posts_deleted = 0;

		foreach ( $posts as $post_id ) {
			wp_delete_post( (int) $post_id, true );
			++$posts_deleted;
		}

		// Delete terms created after snapshot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$terms = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT term_id FROM {$wpdb->terms} WHERE term_id > %d",
				$max_term_id
			)
		);

		$terms_deleted = 0;

		foreach ( $terms as $term_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$tt = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
					(int) $term_id
				)
			);

			if ( $tt ) {
				wp_delete_term( (int) $term_id, $tt );
				++$terms_deleted;
			}
		}

		// Restore options.
		foreach ( $options as $key => $value ) {
			update_option( $key, $value );
		}

		// Flush caches and rewrite rules.
		wp_cache_flush();
		flush_rewrite_rules( false );

		// Delete snapshot row.
		$table = $wpdb->prefix . 'sd_edi_snapshots';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table, [ 'id' => $snapshot_id ], [ '%d' ] );

		ImportLogger::log(
			sprintf(
				/* translators: 1: posts deleted, 2: terms deleted */
				__( 'Rollback complete: %1$d posts and %2$d terms deleted; options restored.', 'easy-demo-importer' ),
				$posts_deleted,
				$terms_deleted
			),
			'success',
			$session_id
		);

		return [
			'posts_deleted' => $posts_deleted,
			'terms_deleted' => $terms_deleted,
		];
	}

	/**
	 * Delete all expired snapshots.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function purgeExpired(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'sd_edi_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				"DELETE FROM %1\$s WHERE expires_at < %s",
				$table,
				current_time( 'mysql' )
			)
		);
	}
}
