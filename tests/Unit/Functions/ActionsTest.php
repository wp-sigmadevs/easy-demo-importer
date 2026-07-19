<?php
/**
 * Unit tests for the Elementor taxonomy-ID remapping in Actions.
 *
 * Covers the pure search/replace helpers (replaceCategory / repeater / WP
 * widget variants) and the batched _elementor_data loader that bounds memory
 * during the finalize pass.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Mockery;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\Actions;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\Actions
 */
final class ActionsTest extends UnitTestCase {

	/**
	 * Stub sd_edi()->getNewID() to a deterministic +100 remap.
	 *
	 * @return void
	 */
	private function stubIdRemap(): void {
		$remapper = new class() {
			/**
			 * @param mixed $id Original ID.
			 *
			 * @return int
			 */
			public function getNewID( $id ): int {
				return (int) $id + 100;
			}
		};

		Functions\when( 'sd_edi' )->justReturn( $remapper );
	}

	public function test_replace_category_remaps_single_value(): void {
		$this->stubIdRemap();

		$element = [
			'widgetType' => 'posts',
			'settings'   => [ 'cat' => 5 ],
		];

		Actions::performSearchReplace( $element, [ 'posts' => 'cat' ] );

		self::assertSame( 105, $element['settings']['cat'] );
	}

	public function test_replace_category_remaps_array_of_values(): void {
		$this->stubIdRemap();

		$element = [
			'widgetType' => 'posts',
			'settings'   => [ 'cat' => [ 5, 6 ] ],
		];

		Actions::performSearchReplace( $element, [ 'posts' => 'cat' ] );

		self::assertSame( [ 105, 106 ], $element['settings']['cat'] );
	}

	public function test_replace_category_for_wp_widget_uses_nested_wp_key(): void {
		$this->stubIdRemap();

		$element = [
			'widgetType' => 'wp-widget-recent-posts',
			'settings'   => [ 'wp' => [ 'cat' => 5 ] ],
		];

		Actions::performSearchReplace( $element, [ 'wp-widget-recent-posts' => 'cat' ] );

		self::assertSame( 105, $element['settings']['wp']['cat'] );
	}

	public function test_replace_category_in_repeater_rows(): void {
		$this->stubIdRemap();

		$element = [
			'widgetType' => 'posts',
			'settings'   => [
				'items' => [
					[ 'catField' => 5 ],
					[ 'catField' => 6 ],
				],
			],
		];

		Actions::performSearchReplace( $element, [ 'posts' => [ 'repeater_items' => 'catField' ] ] );

		self::assertSame( 105, $element['settings']['items'][0]['catField'] );
		self::assertSame( 106, $element['settings']['items'][1]['catField'] );
	}

	public function test_non_matching_widget_type_is_left_untouched(): void {
		$this->stubIdRemap();

		$element  = [
			'widgetType' => 'heading',
			'settings'   => [ 'cat' => 5 ],
		];
		$original = $element;

		Actions::performSearchReplace( $element, [ 'posts' => 'cat' ] );

		self::assertSame( $original, $element );
	}

	public function test_search_replace_id_recurses_into_nested_elements(): void {
		$this->stubIdRemap();

		$data = [
			[
				'widgetType' => 'heading',
				'elements'   => [
					[
						'widgetType' => 'posts',
						'settings'   => [ 'cat' => 5 ],
					],
				],
			],
		];

		Actions::searchReplaceID( $data, [ 'posts' => 'cat' ] );

		self::assertSame( 105, $data[0]['elements'][0]['settings']['cat'] );
	}

	public function test_elementor_taxonomy_fix_returns_early_without_config(): void {
		$obj         = new \stdClass();
		$obj->config = [ 'elementor_data_fix' => [] ];

		self::assertInstanceOf( Actions::class, Actions::elementorTaxonomyFix( $obj ) );
	}

	public function test_elementor_taxonomy_fix_loads_blobs_in_batches(): void {
		global $wpdb;
		$wpdb           = Mockery::mock();
		$wpdb->postmeta = 'wp_postmeta';

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		// 45 matching posts -> array_chunk(20) -> 3 batches (20 + 20 + 5).
		$ids = range( 1, 45 );

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( $ids );

		// The key assertion: blobs are pulled in 3 batched queries, never one
		// unbounded SELECT of every _elementor_data row.
		$wpdb->shouldReceive( 'get_results' )
			->times( 3 )
			->andReturn( [ (object) [ 'post_id' => 1, 'meta_value' => '[]' ] ] );

		$wpdb->shouldReceive( 'update' )->andReturn( 1 );

		$obj         = new \stdClass();
		$obj->config = [ 'elementor_data_fix' => [ 'posts' => 'cat' ] ];

		self::assertInstanceOf( Actions::class, Actions::elementorTaxonomyFix( $obj ) );
	}

	public function test_elementor_taxonomy_fix_stops_when_no_posts_match(): void {
		global $wpdb;
		$wpdb           = Mockery::mock();
		$wpdb->postmeta = 'wp_postmeta';

		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_col' )->once()->andReturn( [] );

		// No IDs -> no blob queries at all.
		$wpdb->shouldNotReceive( 'get_results' );

		$obj         = new \stdClass();
		$obj->config = [ 'elementor_data_fix' => [ 'posts' => 'cat' ] ];

		self::assertInstanceOf( Actions::class, Actions::elementorTaxonomyFix( $obj ) );
	}
}
