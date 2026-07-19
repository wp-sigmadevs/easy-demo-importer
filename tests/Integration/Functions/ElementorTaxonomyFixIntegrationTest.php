<?php
/**
 * Integration test: Elementor taxonomy-ID remapping against a real database.
 *
 * Exercises Actions::elementorTaxonomyFix() end to end — the getNewID() lookup
 * cache (P1) and the batched _elementor_data loader (P3) — against real
 * postmeta rows and the real import mapping table, proving the finalize pass
 * rewrites every widget's category IDs across more rows than one batch holds.
 *
 * Requires the WordPress integration suite (see tests/README.md).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Integration\Functions;

use WP_UnitTestCase;
use SigmaDevs\EasyDemoImporter\Common\Functions\Actions;
use SigmaDevs\EasyDemoImporter\Common\Functions\Functions as EdiFunctions;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\Actions::elementorTaxonomyFix
 */
final class ElementorTaxonomyFixIntegrationTest extends WP_UnitTestCase {

	/**
	 * @inheritDoc
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		$table = $wpdb->prefix . 'sd_edi_taxonomy_import';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				original_id BIGINT UNSIGNED NOT NULL,
				new_id BIGINT UNSIGNED NOT NULL,
				slug varchar(200) NOT NULL,
				PRIMARY KEY (original_id)
			)"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		// Reset the request-scoped getNewID() memo so a prior test's mappings
		// can't leak into this one.
		$prop = new \ReflectionProperty( EdiFunctions::class, 'newIdCache' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * Seeds an original_id => new_id mapping through the real writer.
	 *
	 * @param int $original Original term ID.
	 * @param int $new      New term ID.
	 *
	 * @return void
	 */
	private function mapId( int $original, int $new ): void {
		sd_edi()->createEntry( $original, $new, 'term-' . $original );
	}

	/**
	 * Writes an _elementor_data blob directly, matching the raw (unslashed)
	 * form elementorTaxonomyFix() reads and writes.
	 *
	 * @param int   $postId Post ID.
	 * @param array $data   Elementor data structure.
	 *
	 * @return void
	 */
	private function seedElementorData( int $postId, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->postmeta,
			[
				'post_id'    => $postId,
				'meta_key'   => '_elementor_data',
				'meta_value' => wp_json_encode( $data ),
			]
		);
	}

	/**
	 * Reads back the raw _elementor_data blob as an array.
	 *
	 * @param int $postId Post ID.
	 *
	 * @return array
	 */
	private function readElementorData( int $postId ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s",
				$postId,
				'_elementor_data'
			)
		);

		return json_decode( (string) $raw, true );
	}

	public function test_remaps_category_ids_in_a_single_post(): void {
		$this->mapId( 5, 105 );
		$this->mapId( 6, 106 );

		$postId = self::factory()->post->create();
		$this->seedElementorData(
			$postId,
			[
				[
					'widgetType' => 'posts',
					'settings'   => [ 'cat' => [ 5, 6 ] ],
				],
			]
		);

		$obj         = new \stdClass();
		$obj->config = [ 'elementor_data_fix' => [ 'posts' => 'cat' ] ];

		Actions::elementorTaxonomyFix( $obj );

		$out = $this->readElementorData( $postId );
		$this->assertSame( [ 105, 106 ], $out[0]['settings']['cat'] );
	}

	public function test_remaps_across_more_posts_than_a_single_batch(): void {
		$this->mapId( 5, 105 );

		// 45 posts forces the loader through 3 batches (chunks of 20).
		$postIds = [];
		for ( $i = 0; $i < 45; $i++ ) {
			$postId    = self::factory()->post->create();
			$postIds[] = $postId;
			$this->seedElementorData(
				$postId,
				[
					[
						'widgetType' => 'posts',
						'settings'   => [ 'cat' => 5 ],
					],
				]
			);
		}

		$obj         = new \stdClass();
		$obj->config = [ 'elementor_data_fix' => [ 'posts' => 'cat' ] ];

		Actions::elementorTaxonomyFix( $obj );

		foreach ( $postIds as $postId ) {
			$out = $this->readElementorData( $postId );
			$this->assertSame( 105, $out[0]['settings']['cat'], "Post {$postId} must be remapped." );
		}
	}
}
