<?php
/**
 * Class: XmlChunker
 *
 * Streams a WXR file with XMLReader to extract item metadata
 * and produce temporary chunk files for the existing importer.
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
 * Class XmlChunker
 *
 * @since 1.3.0
 */
class XmlChunker {

	/**
	 * Default items per chunk.
	 * Filterable via sd/edi/xml_chunk_size.
	 *
	 * @var int
	 */
	const DEFAULT_CHUNK_SIZE = 20;

	/**
	 * Stream a WXR file and return metadata for every <item> element.
	 *
	 * Returns a flat array of associative arrays, each with:
	 *   - post_id    (int)    wp:post_id value, or 0 if absent
	 *   - post_type  (string) wp:post_type value
	 *   - post_title (string) title element value
	 *
	 * Used by: dry-run stats endpoint, Phase 4 item picker.
	 *
	 * @param string $file_path Absolute path to the WXR XML file.
	 * @return array<int, array{post_id: int, post_type: string, post_title: string}>
	 * @since 1.3.0
	 */
	public static function getItems( string $file_path ): array {
		$items = [];

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return $items;
		}

		$reader = new \XMLReader();

		if ( ! $reader->open( $file_path ) ) {
			return $items;
		}

		$in_item    = false;
		$post_id    = 0;
		$post_type  = '';
		$post_title = '';

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				if ( 'item' === $reader->localName && '' === $reader->namespaceURI ) {
					$in_item    = true;
					$post_id    = 0;
					$post_type  = '';
					$post_title = '';
					continue;
				}

				if ( $in_item ) {
					$local = $reader->localName;
					$ns    = $reader->namespaceURI;

					if ( 'title' === $local && '' === $ns ) {
						$reader->read();
						$post_title = trim( $reader->value );
					} elseif ( 'post_id' === $local && false !== strpos( $ns, 'wordpress.org/export' ) ) {
						$reader->read();
						$post_id = (int) $reader->value;
					} elseif ( 'post_type' === $local && false !== strpos( $ns, 'wordpress.org/export' ) ) {
						$reader->read();
						$post_type = trim( $reader->value );
					}
				}
			}

			if ( \XMLReader::END_ELEMENT === $reader->nodeType && 'item' === $reader->localName ) {
				if ( $in_item ) {
					$items[] = [
						'post_id'    => $post_id,
						'post_type'  => $post_type ?: 'post',
						'post_title' => $post_title,
					];
					$in_item = false;
				}
			}
		}

		$reader->close();

		return $items;
	}

	/**
	 * Get the resolved chunk size (respects memory limit and filter).
	 *
	 * @return int
	 * @since 1.3.0
	 */
	public static function chunkSize(): int {
		$size = (int) apply_filters( 'sd/edi/xml_chunk_size', self::DEFAULT_CHUNK_SIZE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$memory_limit = (int) ini_get( 'memory_limit' );

		if ( $memory_limit > 0 && $memory_limit < 256 ) {
			$size = min( $size, 5 );
		}

		return max( 1, $size );
	}
}
