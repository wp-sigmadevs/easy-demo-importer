<?php
/**
 * Importer Class: ChunkedImport
 *
 * A resumable wrapper around the bundled WXR importer (SD_EDI_WP_Import). Splits
 * the single-pass import() into three stages that survive across separate AJAX
 * requests, so no one request exceeds a reverse-proxy / FPM wall-clock limit
 * (the cause of the 524/503 failures on large WooCommerce demos):
 *
 *   1. prepare()      — parse the WXR once, import authors/categories/tags/terms,
 *                       persist the parsed posts + all remap maps to a state file.
 *   2. processBatch() — hydrate state, process posts in a time-boxed slice,
 *                       persist the advanced cursor + maps. Called repeatedly.
 *   3. finalize()     — hydrate state, run the cross-post backfills (parents,
 *                       attachment URLs, featured images), end the import, and
 *                       delete the state file.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.1.7
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Importer;

use SD_EDI_WP_Import;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Importer Class: ChunkedImport
 *
 * @since 1.1.7
 */
class ChunkedImport extends SD_EDI_WP_Import {
	/**
	 * Parent importer properties that make up the full cross-request state.
	 *
	 * Every one of these is populated during a normal import() run and must be
	 * carried between batches for parents, menus, featured images, comments and
	 * URL remapping to resolve correctly at finalize().
	 *
	 * @var string[]
	 * @since 1.1.7
	 */
	private const STATE_PROPS = [
		'id',
		'version',
		'authors',
		'posts',
		'terms',
		'categories',
		'tags',
		'base_url',
		'processed_authors',
		'author_mapping',
		'processed_terms',
		'processed_posts',
		'post_orphans',
		'processed_menu_items',
		'menu_item_orphans',
		'missing_menu_items',
		'url_remap',
		'featured_images',
		'fetch_attachments',
	];

	/**
	 * State store.
	 *
	 * @var ImportState
	 * @since 1.1.7
	 */
	private $state;

	/**
	 * Index of the next post to process within $this->posts.
	 *
	 * @var int
	 * @since 1.1.7
	 */
	private $offset = 0;

	/**
	 * Constructor.
	 *
	 * @param ImportState $state State store for this import session.
	 *
	 * @since 1.1.7
	 */
	public function __construct( ImportState $state ) {
		$this->state = $state;
	}

	/**
	 * Stage 1: parse the WXR and import everything the posts depend on.
	 *
	 * Runs once. Imports authors, categories, tags and terms (cheap, and every
	 * post references them), then persists the parsed posts and all remap maps
	 * so the batch stage can resume without re-parsing.
	 *
	 * @param string $file Absolute path to the WXR file.
	 *
	 * @return int Total number of posts queued for the batch stage.
	 * @since 1.1.7
	 */
	public function prepare( string $file ): int {
		$this->addImportFilters();

		// Parses the file into $this->posts/terms/categories/tags/authors and
		// fires 'import_start'. Also enables deferred term/comment counting.
		$this->import_start( $file );

		wp_suspend_cache_invalidation( true );
		$this->process_categories();
		$this->process_tags();
		$this->process_terms();
		wp_suspend_cache_invalidation( false );

		// Flush the counts import_start() deferred. Batches count inline (see
		// processBatch) so that per-request deferral never strands a count.
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$this->offset = 0;
		$this->persist();

		return count( $this->posts );
	}

	/**
	 * Stage 2: process a time-boxed slice of posts.
	 *
	 * Hydrates state, processes posts in small steps until the per-request time
	 * budget is reached, then persists the advanced cursor and maps. Idempotent
	 * and safe to re-issue: the cursor and the importer's own post_exists()
	 * checks prevent duplicates if a request is retried after a timeout.
	 *
	 * @return array{processed:int,total:int,done:bool} Progress snapshot.
	 * @since 1.1.7
	 */
	public function processBatch(): array {
		if ( ! $this->hydrate() ) {
			return [
				'processed' => 0,
				'total'     => 0,
				'done'      => true,
			];
		}

		$this->addImportFilters();

		$total  = count( $this->posts );
		$budget = (float) apply_filters( 'sd/edi/import_chunk_seconds', 45 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$step   = max( 1, (int) apply_filters( 'sd/edi/import_chunk_posts', 5 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );

		wp_suspend_cache_invalidation( true );

		while ( $this->offset < $total ) {
			$this->process_posts( $this->offset, $step );
			$this->offset = min( $this->offset + $step, $total );

			// Always finish the current step, then stop once over budget so the
			// request returns well under the gateway wall-clock limit.
			if ( ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		wp_suspend_cache_invalidation( false );

		$done = $this->offset >= $total;
		$this->persist();

		return [
			'processed' => $this->offset,
			'total'     => $total,
			'done'      => $done,
		];
	}

	/**
	 * Stage 3: resolve cross-post references and end the import.
	 *
	 * Runs once, after every post is processed. Backfills post parents, remaps
	 * attachment URLs and featured images, ends the import (cache flush + count
	 * recalculation), then deletes the state file.
	 *
	 * @return bool True if finalization ran; false if no state was found.
	 * @since 1.1.7
	 */
	public function finalize(): bool {
		if ( ! $this->hydrate() ) {
			return false;
		}

		$this->addImportFilters();

		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		$this->import_end();

		$this->state->delete();

		return true;
	}

	/**
	 * The state store backing this import.
	 *
	 * @return ImportState
	 * @since 1.1.7
	 */
	public function state(): ImportState {
		return $this->state;
	}

	/**
	 * Registers the import filters that import() normally adds up-front.
	 *
	 * Must run at the start of every request that touches importer logic, since
	 * filters do not survive between AJAX calls.
	 *
	 * @return void
	 * @since 1.1.7
	 */
	private function addImportFilters(): void {
		add_filter( 'import_post_meta_key', [ $this, 'is_valid_meta_key' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'http_request_timeout', [ &$this, 'bump_request_timeout' ] );
	}

	/**
	 * Serializes the current cross-request state to the store.
	 *
	 * @return void
	 * @since 1.1.7
	 */
	private function persist(): void {
		$data = [ 'offset' => $this->offset ];

		foreach ( self::STATE_PROPS as $prop ) {
			$data[ $prop ] = $this->$prop;
		}

		$this->state->save( $data );
	}

	/**
	 * Restores cross-request state from the store onto this instance.
	 *
	 * @return bool True if state was found and applied; false otherwise.
	 * @since 1.1.7
	 */
	private function hydrate(): bool {
		$data = $this->state->load();

		if ( null === $data ) {
			return false;
		}

		foreach ( self::STATE_PROPS as $prop ) {
			if ( array_key_exists( $prop, $data ) ) {
				$this->$prop = $data[ $prop ];
			}
		}

		$this->offset = isset( $data['offset'] ) ? (int) $data['offset'] : 0;

		return true;
	}
}
