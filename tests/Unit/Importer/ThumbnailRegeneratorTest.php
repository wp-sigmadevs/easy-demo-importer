<?php
/**
 * Unit tests for ThumbnailRegenerator.
 *
 * Exercises the forAttachment() factory's skip decisions — the guard logic that
 * decides whether an attachment is worth regenerating — without touching a real
 * image editor or the filesystem beyond a mocked file_exists.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Importer;

use Mockery;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Importer\ThumbnailRegenerator;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Importer\ThumbnailRegenerator
 */
final class ThumbnailRegeneratorTest extends UnitTestCase {

	/**
	 * A missing attachment yields no regenerator.
	 */
	public function test_missing_post_is_skipped() {
		Functions\when( 'get_post' )->justReturn( null );

		$this->assertNull( ThumbnailRegenerator::forAttachment( 123 ) );
	}

	/**
	 * A post that isn't an attachment is skipped.
	 */
	public function test_non_attachment_is_skipped() {
		Functions\when( 'get_post' )->justReturn( (object) [ 'post_type' => 'post' ] );

		$this->assertNull( ThumbnailRegenerator::forAttachment( 123 ) );
	}

	/**
	 * A site-icon attachment is left untouched (custom-cropped).
	 */
	public function test_site_icon_is_skipped() {
		Functions\when( 'get_post' )->justReturn( (object) [ 'post_type' => 'attachment' ] );
		Functions\when( 'get_post_meta' )->justReturn( 'site-icon' );

		$this->assertNull( ThumbnailRegenerator::forAttachment( 123 ) );
	}

	/**
	 * A non-image attachment (e.g. a PDF) is skipped by this phase.
	 */
	public function test_non_image_is_skipped() {
		Functions\when( 'get_post' )->justReturn( (object) [ 'post_type' => 'attachment' ] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( false );

		$this->assertNull( ThumbnailRegenerator::forAttachment( 123 ) );
	}

	/**
	 * An image with no resolvable original path is skipped rather than erroring.
	 */
	public function test_missing_original_path_is_skipped() {
		Functions\when( 'get_post' )->justReturn( (object) [ 'post_type' => 'attachment' ] );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'wp_attachment_is_image' )->justReturn( true );
		Functions\when( 'wp_get_original_image_path' )->justReturn( false );

		$this->assertNull( ThumbnailRegenerator::forAttachment( 123 ) );
	}

	/**
	 * Missing-only mode (default) registers the intermediate-size filter so only
	 * absent thumbnails are (re)built.
	 */
	public function test_only_missing_mode_registers_the_missing_sizes_filter() {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [ 'file' => 'x.jpg', 'sizes' => [] ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		Functions\expect( 'add_filter' )
			->once()
			->with( 'intermediate_image_sizes_advanced', Mockery::type( 'array' ), 10, 2 );
		Functions\expect( 'remove_filter' )->once();

		$this->assertTrue( $this->makeRegenerator()->regenerate( true ) );
	}

	/**
	 * Force mode leaves the filter off entirely, so every registered size is
	 * rebuilt from the original.
	 */
	public function test_force_mode_skips_the_missing_sizes_filter() {
		Functions\when( 'wp_get_attachment_metadata' )->justReturn( [ 'sizes' => [] ] );
		Functions\when( 'wp_generate_attachment_metadata' )->justReturn( [ 'file' => 'x.jpg', 'sizes' => [] ] );
		Functions\when( 'wp_update_attachment_metadata' )->justReturn( true );

		Functions\expect( 'add_filter' )->never();
		Functions\expect( 'remove_filter' )->never();

		$this->assertTrue( $this->makeRegenerator()->regenerate( false ) );
	}

	/**
	 * Builds a regenerator without the private forAttachment() guard chain, so a
	 * test can exercise regenerate() directly.
	 *
	 * @return ThumbnailRegenerator
	 */
	private function makeRegenerator(): ThumbnailRegenerator {
		$instance = ( new \ReflectionClass( ThumbnailRegenerator::class ) )->newInstanceWithoutConstructor();

		$this->setPrivate( $instance, 'attachmentId', 123 );
		$this->setPrivate( $instance, 'fullSizePath', '/tmp/x.jpg' );

		return $instance;
	}
}
