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
 * @since   1.2.0
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
 * @since 1.2.0
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
	 * @since 1.2.0
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
	 * @since 1.2.0
	 */
	private $state;

	/**
	 * Index of the next post to process within $this->posts.
	 *
	 * @var int
	 * @since 1.2.0
	 */
	private $offset = 0;

	/**
	 * Constructor.
	 *
	 * @param ImportState $state State store for this import session.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
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

		// import_start() deferred term & comment counting for the taxonomy pass
		// above; flush it now (counts are trivial here — no posts are assigned
		// yet). Batches re-defer term counting per request and finalize()
		// recomputes every taxonomy authoritatively, so the expensive per-post
		// term counting never runs live during the post loop.
		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );

		$this->offset = 0;
		$this->persist();

		return count( $this->posts );
	}

	/**
	 * Parses the WXR and seeds the importer state.
	 *
	 * Overrides the parent, which echoes an error and calls die() on a missing or
	 * unparseable file — that would kill the AJAX request and bypass the caller's
	 * single-shot fallback. Throwing instead lets InstallDemo catch the failure
	 * and fall back gracefully.
	 *
	 * @param string $file Absolute path to the WXR file.
	 *
	 * @return void
	 * @throws \RuntimeException When the file is missing or cannot be parsed.
	 * @since 1.2.0
	 */
	public function import_start( $file ) {
		if ( ! is_file( $file ) ) {
			throw new \RuntimeException( 'WXR file not found.' );
		}

		$import_data = $this->parse( $file );

		if ( is_wp_error( $import_data ) ) {
			throw new \RuntimeException( 'WXR could not be parsed: ' . esc_html( $import_data->get_error_message() ) );
		}

		$this->version = $import_data['version'];
		$this->get_authors_from_import( $import_data );
		$this->posts      = $import_data['posts'];
		$this->terms      = $import_data['terms'];
		$this->categories = $import_data['categories'];
		$this->tags       = $import_data['tags'];
		$this->base_url   = esc_url( $import_data['base_url'] );

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );

		do_action( 'import_start' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
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
	 * @since 1.2.0
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

		// Defer term counting for this request. Each post assignment would
		// otherwise trigger a live COUNT+UPDATE per taxonomy — the dominant cost
		// on WooCommerce demos (product_cat, product_tag, pa_* attributes). The
		// deferred queue is a per-process static that dies with this request; it
		// is never flushed here on purpose. finalize() recounts authoritatively
		// from DB state, so a batch that times out mid-loop still ends correct.
		wp_defer_term_counting( true );

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
	 * @since 1.2.0
	 */
	public function finalize(): bool {
		if ( ! $this->hydrate() ) {
			return false;
		}

		$this->addImportFilters();

		$this->backfill_parents();
		$this->backfill_attachment_urls();
		$this->remap_featured_images();

		$this->recountTerms();

		$this->import_end();

		$this->state->delete();

		return true;
	}

	/**
	 * Authoritatively recomputes term counts for every taxonomy.
	 *
	 * Batches defer term counting (see processBatch), and that deferred queue
	 * cannot survive between the separate batch requests — so rather than relying
	 * on it flushing, this recounts from actual DB state once, at the end. It is
	 * correct regardless of what any batch flushed, so a batch that died and was
	 * re-issued still leaves accurate counts. Each taxonomy's registered
	 * update_count_callback is honored (e.g. WooCommerce product attributes).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function recountTerms(): void {
		foreach ( get_taxonomies() as $taxonomy ) {
			$term_ids = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			);

			if ( ! empty( $term_ids ) && ! is_wp_error( $term_ids ) ) {
				wp_update_term_count_now( $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * The state store backing this import.
	 *
	 * @return ImportState
	 * @since 1.2.0
	 */
	public function state(): ImportState {
		return $this->state;
	}

	/**
	 * Attachment post IDs created by this import run.
	 *
	 * Filters the importer's old-to-new post map down to the rows that are
	 * actually attachments, in a single query. Used to drive the dedicated
	 * image-regeneration phase so it only touches media imported by this run and
	 * never a site's pre-existing attachments. Read from in-memory state, so it
	 * remains valid after finalize() has deleted the state file.
	 *
	 * @return int[] New attachment post IDs.
	 * @since 1.2.0
	 */
	public function importedAttachmentIds(): array {
		if ( empty( $this->processed_posts ) ) {
			return [];
		}

		$ids = array_values( array_filter( array_map( 'intval', (array) $this->processed_posts ) ) );

		if ( empty( $ids ) ) {
			return [];
		}

		global $wpdb;

		$in = implode( ',', $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ({$in})" );

		return array_map( 'intval', (array) $found );
	}

	/**
	 * Registers the import filters that import() normally adds up-front.
	 *
	 * Must run at the start of every request that touches importer logic, since
	 * filters do not survive between AJAX calls.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function addImportFilters(): void {
		add_filter( 'import_post_meta_key', [ $this, 'is_valid_meta_key' ] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		add_filter( 'http_request_timeout', [ &$this, 'bump_request_timeout' ] );
	}

	/**
	 * Serializes the current cross-request state to the store.
	 *
	 * @return void
	 * @since 1.2.0
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
	 * @since 1.2.0
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
