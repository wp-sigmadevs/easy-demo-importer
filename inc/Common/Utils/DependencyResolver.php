<?php
/**
 * Utility: DependencyResolver
 *
 * Scans selected WXR item IDs and classifies dependencies as hard (auto-add)
 * or soft (advisory/optional).
 *
 * Hard deps: parent pages — auto-included silently.
 * Soft deps: nav menu items — shown as optional advisory checkboxes.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class DependencyResolver
 *
 * @since 1.5.0
 */
class DependencyResolver {

	/**
	 * Analyse selected post IDs and return hard + soft dependency arrays.
	 *
	 * @param string  $file_path      Absolute path to WXR file.
	 * @param int[]   $selected_ids   Post IDs the user has selected.
	 * @param array   $import_options User-selected import options.
	 * @return array{hard: int[], soft: list<array{id: int, label: string, type: string}>}
	 * @since 1.5.0
	 */
	public static function resolve( string $file_path, array $selected_ids, array $import_options = [] ): array {
		if ( ! file_exists( $file_path ) || empty( $selected_ids ) ) {
			return [ 'hard' => [], 'soft' => [] ];
		}

		$all_items = self::indexItems( $file_path );
		$hard      = [];
		$soft      = [];
		$added     = array_flip( $selected_ids );

		// If Media option is ON, all attachments are hard dependencies (required for import).
		if ( ! isset( $import_options['media'] ) || $import_options['media'] ) {
			foreach ( $all_items as $id => $item ) {
				if ( 'attachment' === $item['post_type'] && ! isset( $added[ $id ] ) ) {
					$hard[]           = $id;
					$added[ $id ]     = true;
				}
			}
		}

		// If Menus option is ON, all nav_menu_item are hard dependencies.
		if ( ! isset( $import_options['menus'] ) || $import_options['menus'] ) {
			foreach ( $all_items as $id => $item ) {
				if ( 'nav_menu_item' === $item['post_type'] && ! isset( $added[ $id ] ) ) {
					$hard[]           = $id;
					$added[ $id ]     = true;
				}
			}
		}

		// Hard deps: all ancestor pages not in selection (iterative — resolves grandparents too).
		$queue = $selected_ids;
		while ( ! empty( $queue ) ) {
			$next_queue = [];
			foreach ( $queue as $id ) {
				$item   = $all_items[ $id ] ?? null;
				$parent = $item ? (int) $item['post_parent'] : 0;
				if ( $parent > 0 && ! isset( $added[ $parent ] ) && isset( $all_items[ $parent ] ) ) {
					$hard[]           = $parent;
					$added[ $parent ] = true;
					$next_queue[]     = $parent;
				}
			}
			$queue = $next_queue;
		}

		return [
			'hard' => array_values( array_unique( $hard ) ),
			'soft' => [],
		];
	}

	/**
	 * Build an in-memory index: post_id => [post_type, post_parent, post_title].
	 *
	 * @param string $file_path Absolute path to WXR file.
	 * @return array<int, array{post_type: string, post_parent: int, post_title: string}>
	 * @since 1.5.0
	 */
	private static function indexItems( string $file_path ): array {
		$index   = [];
		$reader  = new \XMLReader();

		if ( ! $reader->open( $file_path ) ) {
			return $index;
		}

		$in_item     = false;
		$post_id     = 0;
		$post_type   = '';
		$post_parent = 0;
		$post_title  = '';

		while ( $reader->read() ) {
			if ( \XMLReader::ELEMENT === $reader->nodeType ) {
				$local = $reader->localName;
				$ns    = $reader->namespaceURI;

				if ( 'item' === $local && '' === $ns ) {
					$in_item     = true;
					$post_id     = 0;
					$post_type   = '';
					$post_parent = 0;
					$post_title  = '';
					continue;
				}

				if ( $in_item ) {
					if ( 'title' === $local && '' === $ns ) {
						$reader->read();
						$post_title = trim( $reader->value );
					} elseif ( false !== strpos( $ns, 'wordpress.org/export' ) ) {
						if ( 'post_id' === $local ) {
							$reader->read();
							$post_id = (int) $reader->value;
						} elseif ( 'post_type' === $local ) {
							$reader->read();
							$post_type = trim( $reader->value );
						} elseif ( 'post_parent' === $local ) {
							$reader->read();
							$post_parent = (int) $reader->value;
						}
					}
				}
			}

			if (
				\XMLReader::END_ELEMENT === $reader->nodeType
				&& 'item' === $reader->localName
				&& $in_item
			) {
				$index[ $post_id ] = [
					'post_type'   => $post_type ?: 'post',
					'post_parent' => $post_parent,
					'post_title'  => $post_title,
				];
				$in_item = false;
			}
		}

		$reader->close();

		return $index;
	}
}
