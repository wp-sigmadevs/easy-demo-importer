<?php
/**
 * Unit tests for ManualContext.
 *
 * The key sanitiser becomes part of a filesystem path, so its traversal-safety
 * is security-relevant and covered here.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use SigmaDevs\EasyDemoImporter\Common\Utils\ManualContext;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\ManualContext
 */
final class ManualContextTest extends UnitTestCase {

	public function test_demo_dir_prefixes_the_key(): void {
		self::assertSame( 'manual-abc123', ManualContext::demoDir( 'abc123' ) );
	}

	public function test_sanitize_key_keeps_only_hex(): void {
		self::assertSame( 'ab12', ManualContext::sanitizeKey( 'AB-12/xyz!' ) );
	}

	public function test_sanitize_key_defuses_path_traversal(): void {
		$key = ManualContext::sanitizeKey( '../../etc/passwd' );

		self::assertStringNotContainsString( '/', $key );
		self::assertStringNotContainsString( '.', $key );
		self::assertSame( 1, preg_match( '/^[a-f0-9]*$/', $key ) );
	}

	public function test_config_stub_points_at_the_manual_dir(): void {
		$stub = ManualContext::configStub( 'deadbeef' );

		self::assertFalse( $stub['multipleZip'] );
		self::assertSame( 'manual-deadbeef.zip', $stub['demoZip'] );
	}
}
