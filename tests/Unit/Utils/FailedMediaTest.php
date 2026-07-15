<?php
/**
 * Unit tests for FailedMedia.
 *
 * Verifies the per-session failed-media store against an in-memory option
 * backend.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\FailedMedia;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\FailedMedia
 */
final class FailedMediaTest extends UnitTestCase {

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		$this->options = [];
		$options       = &$this->options;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$options ) {
				unset( $options[ $key ] );
				return true;
			}
		);
	}

	public function test_save_and_get_roundtrip(): void {
		$items = [ [ 'url' => 'http://x/a.jpg', 'data' => [ 'post_title' => 'A' ] ] ];

		FailedMedia::save( 's1', $items );

		self::assertSame( $items, FailedMedia::get( 's1' ) );
		self::assertSame( 1, FailedMedia::count( 's1' ) );
	}

	public function test_saving_empty_clears_the_entry(): void {
		FailedMedia::save( 's1', [ [ 'url' => 'u' ] ] );
		FailedMedia::save( 's1', [] );

		self::assertSame( [], FailedMedia::get( 's1' ) );
		self::assertSame( 0, FailedMedia::count( 's1' ) );
	}

	public function test_get_returns_empty_when_absent(): void {
		self::assertSame( [], FailedMedia::get( 'never-saved' ) );
	}

	public function test_clear_removes_the_entry(): void {
		FailedMedia::save( 's1', [ [ 'url' => 'u' ] ] );
		FailedMedia::clear( 's1' );

		self::assertSame( [], FailedMedia::get( 's1' ) );
	}

	public function test_sessions_are_isolated(): void {
		FailedMedia::save( 'a', [ [ 'url' => 'u1' ] ] );
		FailedMedia::save( 'b', [ [ 'url' => 'u2' ], [ 'url' => 'u3' ] ] );

		self::assertSame( 1, FailedMedia::count( 'a' ) );
		self::assertSame( 2, FailedMedia::count( 'b' ) );
	}
}
