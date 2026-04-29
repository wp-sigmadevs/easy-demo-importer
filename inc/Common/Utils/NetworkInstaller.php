<?php
/**
 * Utility Class: NetworkInstaller.
 *
 * Owns per-blog schema lifecycle for multisite installs.
 * Wraps switch_to_blog + dbDelta + restore_current_blog with safety asserts.
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
 * Utility Class: NetworkInstaller.
 *
 * @since 1.2.0
 */
final class NetworkInstaller {
	/**
	 * Cron hook used to process remaining blogs in chunks during
	 * network-wide table creation.
	 *
	 * @since 1.2.0
	 */
	const CRON_HOOK = 'sd_edi_network_install_chunk';

	/**
	 * Chunk size for cross-blog operations.
	 *
	 * @since 1.2.0
	 */
	const CHUNK_SIZE = 50;

	/**
	 * Create the plugin's per-blog table on a given blog.
	 *
	 * @param int $blogId Target blog ID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function createTableForBlog( int $blogId ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		switch_to_blog( $blogId );
		try {
			self::runCreateTable();
			self::runCreateDir();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Drop the plugin's per-blog table on a given blog and clean per-blog options.
	 *
	 * @param int $blogId Target blog ID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function dropTableForBlog( int $blogId ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		switch_to_blog( $blogId );
		try {
			global $wpdb;
			$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sd\\_edi\\_%'"
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Create tables on all current blogs in a network. Chunked.
	 * The first chunk runs synchronously; remaining blog IDs are processed
	 * by a single-shot WP-Cron event to avoid activation timeouts.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function createTablesForAllBlogs() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		$ids = get_sites(
			[
				'fields'   => 'ids',
				'number'   => 0,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			]
		);

		if ( empty( $ids ) ) {
			return;
		}

		$first = array_slice( $ids, 0, self::CHUNK_SIZE );
		$rest  = array_slice( $ids, self::CHUNK_SIZE );

		foreach ( $first as $id ) {
			self::createTableForBlog( (int) $id );
		}

		if ( ! empty( $rest ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, [ array_map( 'intval', $rest ) ] );
		}
	}

	/**
	 * Cron handler for the remaining-chunks pass.
	 *
	 * @param array $blogIds Blog IDs left to process.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function processChunk( array $blogIds ) {
		if ( empty( $blogIds ) ) {
			return;
		}

		$first = array_slice( $blogIds, 0, self::CHUNK_SIZE );
		$rest  = array_slice( $blogIds, self::CHUNK_SIZE );

		foreach ( $first as $id ) {
			self::createTableForBlog( (int) $id );
		}

		if ( ! empty( $rest ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, [ array_map( 'intval', $rest ) ] );
		}
	}

	/**
	 * Run the existing single-blog createTable routine in the current context.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function runCreateTable() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );
		$collate   = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) );

		if ( $exists !== $tableName ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			dbDelta(
				"CREATE TABLE {$tableName} (
                  original_id BIGINT UNSIGNED NOT NULL,
                  new_id BIGINT UNSIGNED NOT NULL,
                  slug varchar(200) NOT NULL,
                  PRIMARY KEY (original_id)
                  ) {$collate};"
			);
		}
	}

	/**
	 * Run the existing demo-dir creation in the current context.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function runCreateDir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'easy-demo-importer';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}
}
