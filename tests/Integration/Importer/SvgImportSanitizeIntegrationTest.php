<?php
/**
 * Integration test: imported SVGs are sanitized on disk.
 *
 * Drives the real SD_EDI_WP_Import::import_local_file() bundled-media path
 * against a real WordPress + filesystem, proving that a demo-supplied SVG is
 * neutralised (or rejected) as it lands in the uploads directory — the S1
 * stored-XSS fix, end to end rather than through a mocked sanitizer.
 *
 * Requires the WordPress integration suite (see tests/README.md).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Integration\Importer;

use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \SD_EDI_WP_Import::import_local_file
 */
final class SvgImportSanitizeIntegrationTest extends WP_UnitTestCase {

	/**
	 * Working directory holding the source SVGs under test.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Files copied into uploads during a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $created = [];

	/**
	 * @inheritDoc
	 */
	public function set_up() {
		parent::set_up();

		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		require_once dirname( __DIR__, 3 ) . '/lib/wordpress-importer/wordpress-importer.php';

		// SVG is not a core-allowed type; permit it for this test the way the
		// plugin's own filter does for admins.
		add_filter(
			'upload_mimes',
			static function ( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';

				return $mimes;
			}
		);

		$this->dir = get_temp_dir() . 'sd-edi-svg-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->dir );
	}

	/**
	 * @inheritDoc
	 */
	public function tear_down() {
		foreach ( $this->created as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->created = [];

		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		@rmdir( $this->dir );

		parent::tear_down();
	}

	/**
	 * Writes a source SVG and returns its path.
	 *
	 * @param string $name     File name.
	 * @param string $contents File contents.
	 *
	 * @return string
	 */
	private function sourceSvg( string $name, string $contents ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, $contents );

		return $path;
	}

	public function test_malicious_svg_is_stored_without_scripts(): void {
		$src = $this->sourceSvg(
			'evil.svg',
			'<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect onload="evil()"/></svg>'
		);

		$importer = new \SD_EDI_WP_Import();
		$result   = $importer->import_local_file( $src, 'https://old.test/wp-content/uploads/evil.svg', [ 'upload_date' => null ] );

		$this->assertIsArray( $result, 'A parseable SVG must import, not error out.' );
		$this->assertArrayHasKey( 'file', $result );
		$this->created[] = $result['file'];

		$stored = file_get_contents( $result['file'] );
		$this->assertStringNotContainsStringIgnoringCase( 'script', $stored );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $stored );
		$this->assertStringContainsString( '<svg', $stored, 'The sanitized SVG should remain valid SVG markup.' );
	}

	public function test_unparseable_svg_is_rejected_and_not_left_on_disk(): void {
		$src = $this->sourceSvg( 'broken.svg', 'this is not xml at all <<<' );

		$importer = new \SD_EDI_WP_Import();
		$result   = $importer->import_local_file( $src, 'https://old.test/wp-content/uploads/broken.svg', [ 'upload_date' => null ] );

		$this->assertInstanceOf( WP_Error::class, $result, 'Unsanitizable SVG content must be rejected.' );

		// Nothing unsafe should be left behind in the uploads directory.
		$uploads = wp_upload_dir();
		$this->assertFileDoesNotExist( trailingslashit( $uploads['path'] ) . 'broken.svg' );
	}

	public function test_non_svg_media_imports_untouched(): void {
		// A 1x1 GIF — a normal media file must import normally (no SVG path).
		$gif = $this->dir . '/pixel.gif';
		file_put_contents( $gif, base64_decode( 'R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==' ) );

		$importer = new \SD_EDI_WP_Import();
		$result   = $importer->import_local_file( $gif, 'https://old.test/wp-content/uploads/pixel.gif', [ 'upload_date' => null ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'file', $result );
		$this->created[] = $result['file'];
		$this->assertFileExists( $result['file'] );
	}
}
