<?php
/**
 * Integration tests for the resumable chunked importer.
 *
 * Runs the real prepare → processBatch → finalize cycle against a real
 * WordPress + database, so it exercises the bundled WXR parser, wp_insert_post,
 * term assignment and the cross-request state store end to end.
 *
 * Requires the WordPress integration suite (see tests/README.md); it is NOT run
 * by the default `unit` suite.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Integration\Importer;

use WP_UnitTestCase;
use SigmaDevs\EasyDemoImporter\Common\Importer\ChunkedImport;
use SigmaDevs\EasyDemoImporter\Common\Importer\ImportState;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Importer\ChunkedImport
 */
final class ChunkedImportIntegrationTest extends WP_UnitTestCase {

	/**
	 * Working directory holding the WXR under test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * @inheritDoc
	 */
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		require_once dirname( __DIR__, 3 ) . '/lib/wordpress-importer/wordpress-importer.php';

		$this->dir = get_temp_dir() . 'sd-edi-itest-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
		copy( dirname( __DIR__, 2 ) . '/fixtures/sample.wxr', $this->dir . '/content.xml' );
	}

	/**
	 * @inheritDoc
	 */
	public function tear_down() {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		@rmdir( $this->dir );
		parent::tear_down();
	}

	/**
	 * Drives the full chunked cycle the way InstallDemo's AJAX phases do, but
	 * synchronously in one process.
	 *
	 * @param ImportState $state Shared state store.
	 *
	 * @return void
	 */
	private function runFullImport( ImportState $state ): void {
		$prepare                    = new ChunkedImport( $state );
		$prepare->fetch_attachments = false;
		$prepare->prepare( $this->dir . '/content.xml' );

		do {
			$result = ( new ChunkedImport( $state ) )->processBatch();
		} while ( empty( $result['done'] ) );

		( new ChunkedImport( $state ) )->finalize();
	}

	public function test_full_cycle_imports_every_post_and_deletes_state(): void {
		$state = ImportState::forSession( $this->dir, 'itest-full' );

		$this->runFullImport( $state );

		$one = get_page_by_path( 'edi-sample-post-one', OBJECT, 'post' );
		$two = get_page_by_path( 'edi-sample-post-two', OBJECT, 'post' );

		$this->assertInstanceOf( \WP_Post::class, $one );
		$this->assertInstanceOf( \WP_Post::class, $two );
		$this->assertSame( 'publish', $one->post_status );

		// State file is cleaned up once the import finalizes.
		$this->assertFalse( $state->exists() );

		// Term counting is deferred across batches and recomputed authoritatively
		// in finalize(); the shared 'EDI Sample' category must reflect both posts.
		$category = get_term_by( 'slug', 'edi-sample', 'category' );
		$this->assertInstanceOf( \WP_Term::class, $category );
		$this->assertSame( 2, (int) $category->count, 'Deferred term counts must be reconciled in finalize().' );
	}

	public function test_resuming_a_batch_does_not_duplicate_posts(): void {
		$state = ImportState::forSession( $this->dir, 'itest-resume' );

		$prepare                    = new ChunkedImport( $state );
		$prepare->fetch_attachments = false;
		$prepare->prepare( $this->dir . '/content.xml' );

		// Run the batch stage twice from the same persisted cursor position to
		// simulate a client re-issuing after a 524; the cursor + post_exists()
		// must prevent duplicate inserts.
		( new ChunkedImport( $state ) )->processBatch();
		( new ChunkedImport( $state ) )->processBatch();
		( new ChunkedImport( $state ) )->finalize();

		$posts = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'name'        => 'edi-sample-post-one',
				'numberposts' => -1,
			]
		);

		$this->assertCount( 1, $posts, 'The same WXR post must not be imported twice.' );
	}

	public function test_parse_output_written_once_and_resume_reads_both_files(): void {
		$state = ImportState::forSession( $this->dir, 'itest-split' );

		$prepare                    = new ChunkedImport( $state );
		$prepare->fetch_attachments = false;
		$prepare->prepare( $this->dir . '/content.xml' );

		// prepare() writes the parsed-WXR output to the immutable store; the
		// mutable store carries only the cursor + maps, never the heavy posts blob.
		$immutable = $state->loadImmutable();
		$mutable   = $state->load();
		$this->assertIsArray( $immutable );
		$this->assertArrayHasKey( 'posts', $immutable );
		$this->assertNotEmpty( $immutable['posts'] );
		$this->assertArrayNotHasKey( 'posts', $mutable );

		// The immutable file must be written exactly once — batches never rewrite it.
		$immutable_path = $state->path() . '.imm';
		$digest_before  = md5_file( $immutable_path );

		do {
			$result = ( new ChunkedImport( $state ) )->processBatch();
		} while ( empty( $result['done'] ) );

		$this->assertSame(
			$digest_before,
			md5_file( $immutable_path ),
			'The parsed-WXR blob must be written once at prepare(), not on every batch.'
		);

		( new ChunkedImport( $state ) )->finalize();

		// A full resume across many fresh instances still imports every post,
		// reconstructing state from the two files each request…
		$this->assertInstanceOf(
			\WP_Post::class,
			get_page_by_path( 'edi-sample-post-one', OBJECT, 'post' )
		);

		// …and finalize() removes BOTH the mutable and immutable state files.
		$this->assertFalse( $state->exists() );
		$this->assertNull( $state->loadImmutable() );
	}

	public function test_recount_touches_only_terms_attached_to_imported_posts(): void {
		// A pre-existing category unrelated to the import, with a deliberately
		// wrong count. The old "recount every term in every taxonomy" would have
		// reset it to 0; the narrowed recount must leave it exactly as-is.
		$unrelated = self::factory()->term->create(
			[
				'taxonomy' => 'category',
				'name'     => 'Untouched',
			]
		);

		global $wpdb;
		$wpdb->update( $wpdb->term_taxonomy, [ 'count' => 99 ], [ 'term_id' => $unrelated ] );
		clean_term_cache( $unrelated, 'category' );

		$this->runFullImport( ImportState::forSession( $this->dir, 'itest-narrow' ) );

		// The imported category IS reconciled from the deferred counts…
		$category = get_term_by( 'slug', 'edi-sample', 'category' );
		$this->assertSame( 2, (int) $category->count );

		// …while a term with no imported posts is never recounted.
		$this->assertSame( 99, (int) get_term( $unrelated, 'category' )->count );
	}
}
