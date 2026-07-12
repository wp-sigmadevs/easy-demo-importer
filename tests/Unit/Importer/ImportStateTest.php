<?php
/**
 * Unit tests for ImportState.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Importer;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Importer\ImportState;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Importer\ImportState
 */
final class ImportStateTest extends UnitTestCase {

	/**
	 * Temp file path used by each test.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();
		$this->path = sys_get_temp_dir() . '/sd-edi-test-' . uniqid( '', true ) . '.dat';
	}

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		foreach ( [ $this->path, $this->path . '.imm' ] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	public function test_save_then_load_roundtrips_complex_state(): void {
		$state = new ImportState( $this->path );

		$data = [
			'offset'          => 42,
			'processed_posts' => [ 1 => 101, 2 => 102 ],
			'url_remap'       => [ 'http://old.test/x' => 'http://new.test/x' ],
			'featured_images' => [ 101 => 55 ],
			// Non-UTF-8 byte + serialized-looking string: JSON would mangle these,
			// which is exactly why ImportState uses PHP serialization.
			'weird'           => "a:1:{i:0;s:3:\"x\xffy\";}",
		];

		self::assertTrue( $state->save( $data ) );
		self::assertTrue( $state->exists() );
		self::assertSame( $data, $state->load() );
	}

	public function test_load_returns_null_when_missing(): void {
		$state = new ImportState( $this->path );

		self::assertFalse( $state->exists() );
		self::assertNull( $state->load() );
	}

	public function test_load_returns_null_on_corrupt_payload(): void {
		file_put_contents( $this->path, 'this-is-not-serialized-data{{{' );

		$state = new ImportState( $this->path );

		self::assertNull( $state->load() );
	}

	public function test_immutable_save_and_load_roundtrips_independently(): void {
		$state = new ImportState( $this->path );

		$immutable = [ 'posts' => [ [ 'post_id' => 1 ] ], 'base_url' => 'http://old.test' ];
		$mutable   = [ 'offset' => 3, 'processed_posts' => [ 1 => 101 ] ];

		self::assertTrue( $state->saveImmutable( $immutable ) );
		self::assertTrue( $state->save( $mutable ) );

		// The two stores are separate files and never overwrite each other.
		self::assertSame( $immutable, $state->loadImmutable() );
		self::assertSame( $mutable, $state->load() );
	}

	public function test_load_immutable_returns_null_when_missing(): void {
		$state = new ImportState( $this->path );

		self::assertNull( $state->loadImmutable() );
	}

	public function test_delete_removes_both_state_files(): void {
		$state = new ImportState( $this->path );
		$state->save( [ 'a' => 1 ] );
		$state->saveImmutable( [ 'posts' => [ 1 ] ] );

		self::assertTrue( $state->exists() );
		self::assertNotNull( $state->loadImmutable() );

		$state->delete();

		self::assertFalse( $state->exists() );
		self::assertNull( $state->loadImmutable() );
		// Deleting again is a no-op, never an error.
		$state->delete();
		self::assertFalse( $state->exists() );
	}

	public function test_for_session_builds_hashed_isolated_path(): void {
		Functions\when( 'trailingslashit' )->alias(
			static fn( $value ) => rtrim( (string) $value, '/\\' ) . '/'
		);

		$a = ImportState::forSession( '/var/uploads/edi', 'session-A' );
		$b = ImportState::forSession( '/var/uploads/edi', 'session-B' );

		self::assertStringStartsWith( '/var/uploads/edi/.sd-edi-import-state-', $a->path() );
		self::assertStringContainsString( md5( 'session-A' ), $a->path() );
		// Different sessions never collide on the same file.
		self::assertNotSame( $a->path(), $b->path() );
	}

	public function test_for_session_falls_back_to_default_slug_when_empty(): void {
		Functions\when( 'trailingslashit' )->alias(
			static fn( $value ) => rtrim( (string) $value, '/\\' ) . '/'
		);

		$state = ImportState::forSession( '/tmp/edi', '' );

		self::assertStringEndsWith( '.sd-edi-import-state-default.dat', $state->path() );
	}
}
