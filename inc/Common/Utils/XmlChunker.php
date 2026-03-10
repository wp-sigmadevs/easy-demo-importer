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

		$memory_limit_bytes = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$memory_limit_mb    = $memory_limit_bytes > 0 ? (int) ( $memory_limit_bytes / ( 1024 * 1024 ) ) : -1;

		if ( $memory_limit_mb > 0 && $memory_limit_mb < 256 ) {
			$size = min( $size, 5 );
		}

		return max( 1, $size );
	}

	/**
	 * Extract N items from a WXR file starting at $offset.
	 *
	 * Strategy:
	 *  1. First pass: capture the WXR header (everything before the first <item>)
	 *     — this includes authors, categories, tags, terms, and WXR metadata.
	 *     Terms are in every chunk so the importer can resolve parents.
	 *  2. Second pass: stream items with XMLReader, skip $offset items,
	 *     capture the next $limit items as raw XML strings.
	 *  3. Combine: header XML + item XML strings + closing tags.
	 *  4. Write to a temp file and return its path.
	 *     Caller is responsible for deleting the temp file.
	 *
	 * @param string $file_path   Absolute path to the source WXR file.
	 * @param int    $offset      Zero-based index of the first item to include.
	 * @param int    $limit       Number of items to include. Default: chunkSize().
	 * @param int[]  $allowed_ids If non-empty, only items whose wp:post_id is in
	 *                            this list are counted toward $offset and $limit.
	 *                            Pass [] to include all items (Phase 4 hook-in).
	 * @return string|null Absolute path to the temp WXR file, or null on failure.
	 * @since 1.3.0
	 */
	public static function extractChunk(
		string $file_path,
		int $offset,
		int $limit = 0,
		array $allowed_ids = []
	): ?string {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return null;
		}

		if ( $limit <= 0 ) {
			$limit = self::chunkSize();
		}

		// ── Pass 1: capture WXR header ────────────────────────────────────────────
		$header = self::extractHeader( $file_path );

		if ( null === $header ) {
			return null;
		}

		// ── Pass 2: collect item XML strings ─────────────────────────────────────
		$reader = new \XMLReader();

		if ( ! $reader->open( $file_path ) ) {
			return null;
		}

		$item_xmls = [];
		$seen      = 0;
		$collected = 0;

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT !== $reader->nodeType || 'item' !== $reader->localName ) {
				continue;
			}

			// Read the full outer XML of this item once.
			$item_xml = $reader->readOuterXml();

			// Check allowed_ids filter (Phase 4 hook-in).
			if ( ! empty( $allowed_ids ) ) {
				$doc = new \DOMDocument();
				@$doc->loadXML( $item_xml ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$nodes = $doc->getElementsByTagNameNS( '*', 'post_id' );
				$id    = $nodes->length > 0 ? (int) $nodes->item( 0 )->textContent : 0;

				if ( ! in_array( $id, $allowed_ids, true ) ) {
					continue;
				}
			}

			++$seen;

			if ( $seen <= $offset ) {
				continue;
			}

			$item_xmls[] = $item_xml;
			++$collected;

			if ( $collected >= $limit ) {
				break;
			}
		}

		$reader->close();

		if ( empty( $item_xmls ) ) {
			return null;
		}

		// ── Build temp WXR file ───────────────────────────────────────────────────
		$chunk_xml  = $header;
		$chunk_xml .= "\n" . implode( "\n", $item_xmls ) . "\n";
		$chunk_xml .= "  </channel>\n</rss>";

		$tmp_path = wp_tempnam( 'sd-edi-chunk-', get_temp_dir() );

		if ( false === file_put_contents( $tmp_path, $chunk_xml ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return null;
		}

		return $tmp_path;
	}

	/**
	 * Extract the WXR header — everything before the first <item>.
	 *
	 * Includes: rss open tag, channel metadata, wp:author, wp:category,
	 * wp:tag, wp:term elements. Excludes the closing </channel></rss>.
	 *
	 * @param string $file_path Absolute path to the WXR file.
	 * @return string|null Header XML string, or null on failure.
	 * @since 1.3.0
	 */
	private static function extractHeader( string $file_path ): ?string {
		$handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return null;
		}

		$header = '';
		$found  = false;

		while ( ! feof( $handle ) ) {
			$line = fgets( $handle );

			if ( false === $line ) {
				break;
			}

			// Stop accumulating once we hit the first <item> tag.
			if ( false !== strpos( $line, '<item>' ) || preg_match( '/<item\s/', $line ) ) {
				$found = true;
				break;
			}

			// Strip closing tags — we'll add them back after the items.
			if ( trim( $line ) === '</channel>' || trim( $line ) === '</rss>' ) {
				continue;
			}

			$header .= $line;
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $found ? $header : null;
	}
}
