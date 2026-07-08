<?php
/**
 * Unit tests for ChunkedImport.
 *
 * These exercise ChunkedImport's own additions in isolation — the import_start
 * override and the cross-request state persistence contract — without touching
 * the WP-dependent parent methods.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Importer;

use RuntimeException;
use SigmaDevs\EasyDemoImporter\Common\Importer\ChunkedImport;
use SigmaDevs\EasyDemoImporter\Common\Importer\ImportState;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Importer\ChunkedImport
 */
final class ChunkedImportTest extends UnitTestCase {

	/**
	 * Temp state file.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();
		$this->path = sys_get_temp_dir() . '/sd-edi-chunk-' . uniqid( '', true ) . '.dat';
	}

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
		parent::tear_down();
	}

	public function test_import_start_throws_instead_of_dying_on_missing_file(): void {
		$importer = new ChunkedImport( new ImportState( $this->path ) );

		$this->expectException( RuntimeException::class );

		// Parent import_start() would echo + die() here; the override throws so
		// InstallDemo can catch it and fall back to the single-shot importer.
		$importer->import_start( '/no/such/file/does-not-exist.xml' );
	}

	public function test_state_persists_and_hydrates_across_instances(): void {
		$importer = new ChunkedImport( new ImportState( $this->path ) );

		// Seed the exact cross-request maps that resumability depends on.
		$importer->posts                = [ [ 'post_id' => 1 ], [ 'post_id' => 2 ] ];
		$importer->processed_posts      = [ 1 => 101, 2 => 102 ];
		$importer->processed_menu_items = [ 9 => 909 ];
		$importer->post_orphans         = [ 2 => 1 ];
		$importer->url_remap            = [ 'http://old.test' => 'http://new.test' ];
		$importer->featured_images      = [ 101 => 55 ];
		$importer->fetch_attachments    = true;
		$this->setPrivate( $importer, 'offset', 5 );

		$this->invoke( $importer, 'persist' );

		// A fresh instance (a new AJAX request) must recover identical state.
		$resumed = new ChunkedImport( new ImportState( $this->path ) );
		self::assertTrue( $this->invoke( $resumed, 'hydrate' ) );

		self::assertSame( [ 1 => 101, 2 => 102 ], $resumed->processed_posts );
		self::assertSame( [ 9 => 909 ], $resumed->processed_menu_items );
		self::assertSame( [ 2 => 1 ], $resumed->post_orphans );
		self::assertSame( [ 'http://old.test' => 'http://new.test' ], $resumed->url_remap );
		self::assertSame( [ 101 => 55 ], $resumed->featured_images );
		self::assertTrue( $resumed->fetch_attachments );
		self::assertSame( 5, $this->getPrivate( $resumed, 'offset' ) );
	}

	public function test_hydrate_returns_false_when_no_state_exists(): void {
		$importer = new ChunkedImport( new ImportState( $this->path ) );

		self::assertFalse( $this->invoke( $importer, 'hydrate' ) );
	}

	public function test_process_batch_reports_done_when_state_missing(): void {
		$importer = new ChunkedImport( new ImportState( $this->path ) );

		// No state on disk → nothing to do → done, so the pipeline advances
		// rather than looping forever.
		$result = $importer->processBatch();

		self::assertSame(
			[
				'processed' => 0,
				'total'     => 0,
				'done'      => true,
			],
			$result
		);
	}
}
