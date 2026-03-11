<?php
/**
 * Utility: UrlReplacer
 *
 * Reads <wp:base_site_url> from a WXR file and runs a DB search-replace
 * to swap the demo domain for the current site URL. Called once, after
 * all XML chunks have been imported.
 *
 * Opt-out: return false from `sd/edi/auto_url_fix` filter.
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
 * Class UrlReplacer
 *
 * @since 1.5.0
 */
class UrlReplacer {

	/**
	 * Run URL replacement after import.
	 *
	 * @param string $xml_path   Absolute path to WXR file.
	 * @param string $session_id Import session ID for logging.
	 * @return int Number of DB values updated, or 0 if skipped.
	 * @since 1.5.0
	 */
	public static function run( string $xml_path, string $session_id ): int {
		if ( ! apply_filters( 'sd/edi/auto_url_fix', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return 0;
		}

		$demo_url = self::readBaseUrl( $xml_path );

		if ( ! $demo_url ) {
			return 0;
		}

		$site_url = untrailingslashit( get_site_url() );
		$demo_url = untrailingslashit( $demo_url );

		if ( $demo_url === $site_url ) {
			return 0;
		}

		$replaced = self::searchReplace( $demo_url, $site_url );

		// Flush Elementor cache if active.
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		ImportLogger::log(
			sprintf(
				/* translators: 1: demo URL, 2: site URL, 3: replacement count */
				__( 'Auto URL fix: replaced "%1$s" → "%2$s" in %3$d DB values.', 'easy-demo-importer' ),
				$demo_url,
				$site_url,
				$replaced
			),
			$replaced > 0 ? 'success' : 'info',
			$session_id
		);

		return $replaced;
	}

	/**
	 * Read <wp:base_site_url> from the WXR header (line-by-line, stops early).
	 *
	 * @param string $file_path Absolute path to WXR file.
	 * @return string Demo base site URL, or empty string if not found.
	 * @since 1.5.0
	 */
	private static function readBaseUrl( string $file_path ): string {
		if ( ! file_exists( $file_path ) ) {
			return '';
		}

		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return '';
		}

		$url  = '';
		$read = 0;

		while ( ! feof( $handle ) && $read < 500 ) {
			$line = fgets( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			++$read;

			if ( false === $line ) {
				break;
			}

			if ( preg_match( '#<wp:base_site_url>(.*?)</wp:base_site_url>#i', $line, $m ) ) {
				$url = trim( $m[1] );
				break;
			}

			// Stop reading once we reach the first <item> — URL tag is in the header.
			if ( false !== strpos( $line, '<item' ) ) {
				break;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $url;
	}

	/**
	 * Run search-replace across serialization-safe DB columns.
	 *
	 * @param string $from Demo URL to replace.
	 * @param string $to   Site URL to replace with.
	 * @return int Total number of DB values updated.
	 * @since 1.5.0
	 */
	private static function searchReplace( string $from, string $to ): int {
		global $wpdb;

		$count = 0;

		// Build http/https/www variants for the demo URL.
		$pairs = [
			$from => $to,
		];

		$http_from = str_replace( 'https://', 'http://', $from );
		$http_to   = str_replace( 'https://', 'http://', $to );

		if ( $http_from !== $from ) {
			$pairs[ $http_from ] = $http_to;
		}

		// Tables, columns, and their known primary key columns.
		$targets = [
			$wpdb->posts    => [ 'columns' => [ 'post_content', 'post_excerpt', 'guid' ], 'pk' => 'ID' ],
			$wpdb->postmeta => [ 'columns' => [ 'meta_value' ], 'pk' => 'meta_id' ],
			$wpdb->options  => [ 'columns' => [ 'option_value' ], 'pk' => 'option_id' ],
		];

		foreach ( $targets as $table => $config ) {
			$pk_col = $config['pk'];
			foreach ( $config['columns'] as $col ) {
				foreach ( $pairs as $search => $replace ) {
					if ( $search === $replace ) {
						continue;
					}

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$wpdb->prepare(
							"SELECT * FROM `$table` WHERE `$col` LIKE %s",
							'%' . $wpdb->esc_like( $search ) . '%'
						),
						ARRAY_A
					);

					if ( ! $rows ) {
						continue;
					}

					foreach ( $rows as $row ) {
						$old = $row[ $col ];
						$new = self::replaceInValue( $old, $search, $replace );

						if ( $new !== $old ) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
							$wpdb->update( $table, [ $col => $new ], [ $pk_col => $row[ $pk_col ] ] );
							++$count;
						}
					}
				}
			}
		}

		return $count;
	}

	/**
	 * Replace within a potentially serialized string safely.
	 *
	 * @param string $value   Original value.
	 * @param string $search  String to find.
	 * @param string $replace Replacement string.
	 * @return string Updated value.
	 * @since 1.5.0
	 */
	private static function replaceInValue( string $value, string $search, string $replace ): string {
		if ( is_serialized( $value ) ) {
			$unserialized = maybe_unserialize( $value );
			$json         = wp_json_encode( $unserialized );

			if ( $json ) {
				$replaced = str_replace( $search, $replace, $json );

				if ( $replaced !== $json ) {
					$decoded = json_decode( $replaced, true );

					if ( null !== $decoded ) {
						return maybe_serialize( $decoded );
					}
				}
			}

			// Leave malformed serialized data unchanged.
			return $value;
		}

		return str_replace( $search, $replace, $value );
	}
}
