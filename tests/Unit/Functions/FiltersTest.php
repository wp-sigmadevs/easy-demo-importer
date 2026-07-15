<?php
/**
 * Unit tests for the SVG upload-hardening filters.
 *
 * Covers the two pieces that close CVE-2024-9071: content-sniffing SVG
 * detection (so a misleading MIME/extension can't skip sanitization) and the
 * capability gate on MIME resurrection (so only manage_options users can have a
 * .svg reassigned to image/svg+xml).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\Filters;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\Filters
 */
final class FiltersTest extends UnitTestCase {

	/**
	 * Temp files created during a test, cleaned up after.
	 *
	 * @var string[]
	 */
	private $tmpFiles = [];

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		foreach ( $this->tmpFiles as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmpFiles = [];
		parent::tear_down();
	}

	/**
	 * Writes a temp file and returns its path.
	 *
	 * @param string $contents File contents.
	 *
	 * @return string
	 */
	private function tempFile( string $contents ): string {
		$path = tempnam( sys_get_temp_dir(), 'edi-svg-' );
		file_put_contents( $path, $contents );
		$this->tmpFiles[] = $path;

		return $path;
	}

	/**
	 * Invokes the private static looksLikeSVG().
	 *
	 * @param array $file Upload array.
	 *
	 * @return bool
	 */
	private function looksLikeSVG( array $file ): bool {
		$method = new \ReflectionMethod( Filters::class, 'looksLikeSVG' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $file );
	}

	/**
	 * A .svg extension is detected regardless of content.
	 */
	public function test_svg_extension_is_detected() {
		$this->assertTrue( $this->looksLikeSVG( [ 'name' => 'logo.SVG' ] ) );
	}

	/**
	 * A disguised extension is still caught by sniffing the head bytes.
	 */
	public function test_svg_content_is_detected_under_wrong_extension() {
		$path = $this->tempFile( '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"></svg>' );

		$this->assertTrue(
			$this->looksLikeSVG( [ 'name' => 'image.png', 'tmp_name' => $path ] )
		);
	}

	/**
	 * A genuine non-SVG is not flagged.
	 */
	public function test_non_svg_is_not_detected() {
		$path = $this->tempFile( 'just some plain text, no markup' );

		$this->assertFalse(
			$this->looksLikeSVG( [ 'name' => 'notes.txt', 'tmp_name' => $path ] )
		);
	}

	/**
	 * With neither an .svg name nor a readable file, detection is false.
	 */
	public function test_unreadable_non_svg_returns_false() {
		$this->assertFalse( $this->looksLikeSVG( [ 'name' => 'x.png' ] ) );
	}

	/**
	 * The capability gate: a non-admin never gets the SVG type resurrected.
	 */
	public function test_fix_svg_detection_blocks_non_admin() {
		Functions\when( 'current_user_can' )->justReturn( false );

		$in  = [ 'ext' => '', 'type' => '' ];
		$out = Filters::fixSVGDetection( $in, '/tmp/x.svg', 'x.svg' );

		$this->assertSame( $in, $out, 'Non-admins must not have the SVG MIME reassigned.' );
	}

	/**
	 * An admin uploading an .svg gets the correct MIME/extension restored.
	 */
	public function test_fix_svg_detection_restores_type_for_admin() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$out = Filters::fixSVGDetection( [ 'ext' => '', 'type' => '' ], '/tmp/x.svg', 'x.svg' );

		$this->assertSame( 'image/svg+xml', $out['type'] );
		$this->assertSame( 'svg', $out['ext'] );
	}

	/**
	 * A non-SVG upload by an admin is left unchanged.
	 */
	public function test_fix_svg_detection_ignores_non_svg() {
		Functions\when( 'current_user_can' )->justReturn( true );

		$in  = [ 'ext' => 'png', 'type' => 'image/png' ];
		$out = Filters::fixSVGDetection( $in, '/tmp/x.png', 'x.png' );

		$this->assertSame( $in, $out );
	}
}
