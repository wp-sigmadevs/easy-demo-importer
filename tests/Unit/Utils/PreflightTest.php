<?php
/**
 * Unit tests for Preflight.
 *
 * Exercises the pure, value-based readiness checks (version compare, byte
 * thresholds, presence flags, config flattening) without the environment.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\Preflight;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\Preflight
 */
final class PreflightTest extends UnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();
		// The check builders wrap their messages in esc_html__.
		Functions\when( 'esc_html__' )->returnArg( 1 );
	}

	public function test_to_bytes_converts_shorthand(): void {
		self::assertSame( 256 * 1024 * 1024, Preflight::toBytes( '256M' ) );
		self::assertSame( 512 * 1024, Preflight::toBytes( '512K' ) );
		self::assertSame( 1024 * 1024 * 1024, Preflight::toBytes( '1G' ) );
		self::assertSame( 1048576, Preflight::toBytes( '1048576' ) );
		self::assertSame( 0, Preflight::toBytes( '' ) );
		self::assertSame( -1, Preflight::toBytes( '-1' ) );
	}

	public function test_php_version_pass_and_fail(): void {
		$pass = Preflight::phpVersionCheck( '8.1', '7.4' );
		self::assertSame( Preflight::PASS, $pass['status'] );
		self::assertTrue( $pass['blocking'] );

		$fail = Preflight::phpVersionCheck( '7.2', '7.4' );
		self::assertSame( Preflight::FAIL, $fail['status'] );
		self::assertTrue( $fail['blocking'] );
	}

	public function test_memory_check_pass_warn_and_unlimited(): void {
		self::assertSame( Preflight::PASS, Preflight::memoryCheck( '512M', '256M' )['status'] );
		self::assertSame( Preflight::PASS, Preflight::memoryCheck( '-1', '256M' )['status'] );

		$warn = Preflight::memoryCheck( '128M', '256M' );
		self::assertSame( Preflight::WARN, $warn['status'] );
		// A low memory limit warns but never blocks.
		self::assertFalse( $warn['blocking'] );
	}

	public function test_extension_check_present_and_absent(): void {
		$present = Preflight::extensionCheck( 'ZipArchive', true, true );
		self::assertSame( Preflight::PASS, $present['status'] );

		$absent = Preflight::extensionCheck( 'ZipArchive', false, true );
		self::assertSame( Preflight::FAIL, $absent['status'] );
		self::assertTrue( $absent['blocking'] );
	}

	public function test_image_library_check(): void {
		self::assertSame( Preflight::PASS, Preflight::imageLibraryCheck( true, false )['status'] );
		self::assertSame( Preflight::PASS, Preflight::imageLibraryCheck( false, true )['status'] );

		$none = Preflight::imageLibraryCheck( false, false );
		self::assertSame( Preflight::WARN, $none['status'] );
		self::assertFalse( $none['blocking'] );
	}

	public function test_required_plugins_empty_config_passes(): void {
		$check = Preflight::requiredPluginsCheck( [] );
		self::assertSame( Preflight::PASS, $check['status'] );
	}

	public function test_collect_plugins_single_zip(): void {
		$config = [
			'multipleZip' => false,
			'plugins'     => [
				'woo' => [ 'filePath' => 'woocommerce/woocommerce.php', 'name' => 'WooCommerce' ],
			],
		];

		$plugins = Preflight::collectPlugins( $config );

		self::assertCount( 1, $plugins );
		self::assertSame( 'woocommerce/woocommerce.php', $plugins[0]['filePath'] );
	}

	public function test_collect_plugins_multi_zip_flattens_demos(): void {
		$config = [
			'multipleZip' => true,
			'demoData'    => [
				'home-01' => [ 'plugins' => [ 'a' => [ 'filePath' => 'a/a.php' ] ] ],
				'home-02' => [ 'plugins' => [ 'b' => [ 'filePath' => 'b/b.php' ] ] ],
			],
		];

		$plugins = Preflight::collectPlugins( $config );
		$paths   = array_column( $plugins, 'filePath' );

		self::assertContains( 'a/a.php', $paths );
		self::assertContains( 'b/b.php', $paths );
	}
}
