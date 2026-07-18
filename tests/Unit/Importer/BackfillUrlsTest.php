<?php
/**
 * Unit tests for the batched attachment-URL backfill.
 *
 * The importer rewrites remapped attachment URLs across post_content and
 * enclosure meta. The optimized backfill folds a batch of URLs into one nested
 * REPLACE() per table (instead of one full-table scan per URL) and skips
 * identity remaps. These tests lock the query-count contract and the substring-
 * safe nesting order, with $wpdb replaced by a Mockery mock.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Importer;

use Mockery;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Importer\ChunkedImport;
use SigmaDevs\EasyDemoImporter\Common\Importer\ImportState;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SD_EDI_WP_Import::backfill_attachment_urls
 */
final class BackfillUrlsTest extends UnitTestCase {

	/**
	 * Captured $wpdb->query() SQL strings.
	 *
	 * @var array
	 */
	private $queries = [];

	/**
	 * Captured $wpdb->prepare() calls: ['sql' => string, 'args' => array].
	 *
	 * @var array
	 */
	private $prepared = [];

	/**
	 * Install a $wpdb mock that records prepare()/query() calls.
	 *
	 * @return void
	 */
	private function mockWpdb(): void {
		$this->queries  = [];
		$this->prepared = [];

		$wpdb           = Mockery::mock();
		$wpdb->posts    = 'wp_posts';
		$wpdb->postmeta = 'wp_postmeta';

		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( $sql, $args = [] ) {
				$this->prepared[] = [ 'sql' => $sql, 'args' => (array) $args ];
				return $sql;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			function ( $sql ) {
				$this->queries[] = $sql;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Build an importer with the given URL remap map.
	 *
	 * @param array $remap old => new URL pairs.
	 *
	 * @return ChunkedImport
	 */
	private function importer( array $remap ): ChunkedImport {
		$importer             = new ChunkedImport( new ImportState( sys_get_temp_dir() . '/sd-edi-backfill-' . uniqid( '', true ) ) );
		$importer->url_remap  = $remap;
		return $importer;
	}

	public function test_one_batch_issues_two_queries_and_skips_identity(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 ); // batch size = default 100.
		$this->mockWpdb();

		$this->importer(
			[
				'http://demo.test/a/longname.jpg' => 'http://site.test/a/longname.jpg',
				'http://demo.test/same.jpg'       => 'http://demo.test/same.jpg', // identity — skipped.
				'http://demo.test/a/b.jpg'        => 'http://site.test/a/b.jpg',
			]
		)->backfill_attachment_urls();

		// One batch → exactly two full-table scans (post_content + enclosure),
		// not 2 per URL.
		self::assertCount( 2, $this->queries );

		// The identity remap never reaches the prepared args.
		$args = $this->prepared[0]['args'];
		self::assertNotContains( 'http://demo.test/same.jpg', $args );
		// Two non-identity pairs → four bound values.
		self::assertCount( 4, $args );
		// Nested REPLACE over a single column.
		self::assertStringContainsString( 'REPLACE(REPLACE(post_content', $this->prepared[0]['sql'] );
	}

	public function test_large_map_is_chunked_into_fewer_queries_than_urls(): void {
		Functions\when( 'apply_filters' )->justReturn( 2 ); // batch size = 2.
		$this->mockWpdb();

		$this->importer(
			[
				'http://demo.test/1.jpg' => 'http://site.test/1.jpg',
				'http://demo.test/2.jpg' => 'http://site.test/2.jpg',
				'http://demo.test/3.jpg' => 'http://site.test/3.jpg',
				'http://demo.test/4.jpg' => 'http://site.test/4.jpg',
			]
		)->backfill_attachment_urls();

		// 4 URLs, batch size 2 → 2 batches × 2 tables = 4 queries (vs 8 one-per-URL).
		self::assertCount( 4, $this->queries );
	}

	public function test_no_queries_when_every_remap_is_identity(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$this->mockWpdb();

		$this->importer(
			[
				'http://demo.test/x.jpg' => 'http://demo.test/x.jpg',
			]
		)->backfill_attachment_urls();

		self::assertCount( 0, $this->queries );
	}
}
