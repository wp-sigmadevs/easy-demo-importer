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

use Mockery;
use RuntimeException;
use Brain\Monkey\Functions;
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
		$files = array_merge(
			[ $this->path, $this->path . '.imm' ],
			glob( $this->path . '.posts.*' ) ?: []
		);

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
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

		// Seed the exact cross-request maps + parse metadata that resumability
		// depends on. Posts themselves live in chunk files, not this state.
		$importer->processed_posts      = [ 1 => 101, 2 => 102 ];
		$importer->processed_menu_items = [ 9 => 909 ];
		$importer->post_orphans         = [ 2 => 1 ];
		$importer->url_remap            = [ 'http://old.test' => 'http://new.test' ];
		$importer->featured_images      = [ 101 => 55 ];
		$importer->fetch_attachments    = true;
		$this->setPrivate( $importer, 'offset', 5 );
		$this->setPrivate( $importer, 'postsTotal', 250 );
		$this->setPrivate( $importer, 'chunkSize', 100 );

		// prepare() writes the immutable meta once; each batch writes the mutable
		// maps. Both must be present for a resume to reconstruct state.
		$this->invoke( $importer, 'persistImmutable' );
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
		// The parse metadata (total + frozen chunk size) rides the immutable meta.
		self::assertSame( 250, $this->getPrivate( $resumed, 'postsTotal' ) );
		self::assertSame( 100, $this->getPrivate( $resumed, 'chunkSize' ) );
	}

	public function test_hydrate_returns_false_without_the_immutable_file(): void {
		$importer = new ChunkedImport( new ImportState( $this->path ) );
		$this->invoke( $importer, 'persist' ); // mutable only, no immutable meta file.

		// Missing the write-once parse meta → cannot resume.
		$resumed = new ChunkedImport( new ImportState( $this->path ) );
		self::assertFalse( $this->invoke( $resumed, 'hydrate' ) );
	}

	public function test_prepare_splits_posts_into_chunk_files(): void {
		$importer        = new ChunkedImport( new ImportState( $this->path ) );
		$importer->posts = [ [ 'post_id' => 1 ], [ 'post_id' => 2 ], [ 'post_id' => 3 ] ];
		$this->setPrivate( $importer, 'postsTotal', 3 );
		$this->setPrivate( $importer, 'chunkSize', 2 );

		$this->invoke( $importer, 'persistPosts' );

		// 3 posts at chunk size 2 → two files, re-indexed from 0 within each.
		$state = new ImportState( $this->path );
		self::assertSame( [ [ 'post_id' => 1 ], [ 'post_id' => 2 ] ], $state->loadPostsChunk( 0 ) );
		self::assertSame( [ [ 'post_id' => 3 ] ], $state->loadPostsChunk( 1 ) );
		self::assertNull( $state->loadPostsChunk( 2 ) );
	}

	public function test_batch_persist_never_rewrites_the_post_chunks(): void {
		$importer        = new ChunkedImport( new ImportState( $this->path ) );
		$importer->posts = [ [ 'post_id' => 1 ], [ 'post_id' => 2 ] ];
		$this->setPrivate( $importer, 'postsTotal', 2 );
		$this->setPrivate( $importer, 'chunkSize', 100 );

		$this->invoke( $importer, 'persistImmutable' );
		$this->invoke( $importer, 'persistPosts' );
		$chunk_before = file_get_contents( $this->path . '.posts.0' );

		// Simulate several batches advancing the cursor + maps.
		for ( $i = 1; $i <= 3; $i++ ) {
			$importer->processed_posts[ $i ] = 100 + $i;
			$this->setPrivate( $importer, 'offset', $i );
			$this->invoke( $importer, 'persist' );
		}

		// The posts are written once at prepare — batches never touch the chunks.
		self::assertSame( $chunk_before, file_get_contents( $this->path . '.posts.0' ) );

		// And the per-batch mutable file must not carry any posts.
		$mutable = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			file_get_contents( $this->path ),
			[ 'allowed_classes' => false ]
		);
		self::assertArrayNotHasKey( 'posts', $mutable );
		self::assertArrayHasKey( 'processed_posts', $mutable );
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

	/**
	 * When downloading remote media, an attachment must be processed as its own
	 * single-post step and the cursor checkpointed straight after it — so a
	 * request killed by the gateway mid-batch resumes past the file already
	 * fetched instead of replaying (and re-timing-out on) the same slow head.
	 */
	public function test_process_batch_checkpoints_after_each_attachment_download(): void {
		$state = new ImportState( $this->path );

		// Seed on-disk state: 8 posts, one attachment at index 5, remote
		// download on (fetch_attachments && no bundled-media dir).
		$seed                    = new ChunkedImport( $state );
		$seed->fetch_attachments = true;
		$seed->bundled_media_dir = '';
		$this->setPrivate( $seed, 'postsTotal', 8 );
		$this->setPrivate( $seed, 'chunkSize', 100 );
		$this->setPrivate( $seed, 'offset', 0 );
		$this->invoke( $seed, 'persistImmutable' );
		$this->invoke( $seed, 'persist' );

		$chunk = [];
		for ( $i = 0; $i < 8; $i++ ) {
			$chunk[] = [ 'post_type' => 5 === $i ? 'attachment' : 'post' ];
		}
		$state->savePostsChunk( 0, $chunk );

		// WP hooks/counters the batch loop touches — no-op under unit tests.
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( null );
		Functions\when( 'wp_suspend_cache_invalidation' )->justReturn( null );
		// apply_filters passes the default value (2nd arg) straight through, so
		// the loop keeps its default budget (10s) and step (5).
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Partial mock so process_posts does no real importing; record the
		// (offset, step) of each call and simulate a gateway kill on the first
		// post after the attachment.
		$mock  = Mockery::mock( ChunkedImport::class, [ $state ] )->makePartial();
		$calls = [];
		$mock->shouldReceive( 'process_posts' )->andReturnUsing(
			static function ( $offset, $step ) use ( &$calls ) {
				$calls[] = [ $offset, $step ];
				if ( $offset >= 6 ) {
					throw new RuntimeException( 'gateway killed the request' );
				}
			}
		);

		try {
			$mock->processBatch();
			self::fail( 'expected the simulated kill to propagate' );
		} catch ( RuntimeException $e ) {
			self::assertSame( 'gateway killed the request', $e->getMessage() );
		}

		// Text posts before the attachment used the full step of 5...
		self::assertSame( [ 0, 5 ], $calls[0] );
		// ...the attachment was walked as its own single-post step...
		self::assertContains( [ 5, 1 ], $calls );
		// ...and the cursor was persisted right after it (offset 6), before the
		// simulated kill, so resume continues past the downloaded file.
		self::assertSame( 6, $state->load()['offset'] ?? null );
	}
}
