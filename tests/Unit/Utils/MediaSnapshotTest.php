<?php
/**
 * Unit tests for MediaSnapshot.
 *
 * Focuses on the private files() walker: it must be a lazy generator (so the
 * manifest builder's file-count cap can abandon an oversized walk instead of
 * enumerating the whole uploads tree first) and must skip symlinks so a restore
 * can never reach outside the uploads tree.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Generator;
use ReflectionMethod;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\MediaSnapshot;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\MediaSnapshot
 */
final class MediaSnapshotTest extends UnitTestCase {

	/**
	 * Temp uploads-like directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		Functions\when( 'trailingslashit' )->alias(
			static function ( $path ) {
				return rtrim( (string) $path, '/\\' ) . '/';
			}
		);

		$this->dir = sys_get_temp_dir() . '/sd-edi-mediasnap-' . uniqid( '', true );
		mkdir( $this->dir . '/sub', 0777, true );
		file_put_contents( $this->dir . '/a.txt', 'a' );
		file_put_contents( $this->dir . '/sub/b.txt', 'b' );
		// A symlink that must be excluded so a restore cannot follow it out of
		// the uploads tree.
		@symlink( $this->dir . '/a.txt', $this->dir . '/link' );
	}

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		foreach ( [ $this->dir . '/link', $this->dir . '/sub/b.txt', $this->dir . '/a.txt' ] as $file ) {
			if ( is_link( $file ) || is_file( $file ) ) {
				@unlink( $file );
			}
		}
		@rmdir( $this->dir . '/sub' );
		@rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Invoke the private static files() walker.
	 *
	 * @param string $base Directory to walk.
	 *
	 * @return mixed
	 */
	private function walk( string $base ) {
		$method = new ReflectionMethod( MediaSnapshot::class, 'files' );
		$method->setAccessible( true );

		return $method->invoke( null, $base );
	}

	public function test_files_is_a_lazy_generator_not_a_materialized_array(): void {
		// The cap short-circuit in the manifest builder relies on this being a
		// generator; regressing to a pre-built array would re-enable walking the
		// whole tree before the cap can fire.
		self::assertInstanceOf( Generator::class, $this->walk( $this->dir ) );
	}

	public function test_files_yields_relative_paths_and_skips_symlinks(): void {
		$paths = iterator_to_array( $this->walk( $this->dir ), false );
		sort( $paths );

		self::assertSame( [ 'a.txt', 'sub/b.txt' ], $paths );
	}

	public function test_files_yields_nothing_for_a_missing_directory(): void {
		self::assertSame( [], iterator_to_array( $this->walk( $this->dir . '/nope' ), false ) );
	}
}
