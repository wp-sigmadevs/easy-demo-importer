<?php
/**
 * Unit tests for BundledMedia.
 *
 * Exercises the pure attachment-URL → uploads-relative-path mapping that drives
 * bundled-media resolution, independent of WordPress and the filesystem.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Importer;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Importer\BundledMedia;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Importer\BundledMedia
 */
final class BundledMediaTest extends UnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		// The util's only WP dependency; native parse_url has the same contract.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);
	}

	/**
	 * Primary case: standard wp-content/uploads URL → uploads-relative path.
	 */
	public function test_standard_uploads_url_maps_to_relative_path() {
		$candidates = BundledMedia::candidates(
			'https://source.test/wp-content/uploads/2024/01/hero.jpg'
		);

		$this->assertContains( '2024/01/hero.jpg', $candidates );
		$this->assertSame( '2024/01/hero.jpg', $candidates[0] );
	}

	/**
	 * A URL without the wp-content prefix still resolves after /uploads/.
	 */
	public function test_bare_uploads_url_maps_to_relative_path() {
		$candidates = BundledMedia::candidates(
			'https://source.test/uploads/2023/12/photo.png'
		);

		$this->assertSame( '2023/12/photo.png', $candidates[0] );
	}

	/**
	 * CDN URL that dropped the uploads segment falls back to the YYYY/MM tail.
	 */
	public function test_cdn_url_without_uploads_segment_uses_year_month_tail() {
		$candidates = BundledMedia::candidates(
			'https://cdn.example.com/2024/05/banner.webp'
		);

		$this->assertContains( '2024/05/banner.webp', $candidates );
	}

	/**
	 * A URL with no uploads segment and no date tail yields no candidates.
	 */
	public function test_unmappable_url_returns_empty() {
		$this->assertSame(
			[],
			BundledMedia::candidates( 'https://source.test/some/logo.svg' )
		);
	}

	/**
	 * Traversal sequences are rejected outright.
	 */
	public function test_traversal_is_rejected() {
		$this->assertSame(
			[],
			BundledMedia::candidates( 'https://source.test/uploads/../../etc/passwd' )
		);
	}

	/**
	 * A non-URL string produces no candidates rather than an error.
	 */
	public function test_empty_path_returns_empty() {
		$this->assertSame( [], BundledMedia::candidates( '' ) );
	}
}
