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
}
