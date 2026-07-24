<?php
/**
 * Unit tests for Requirements.
 *
 * Exercises the pure `requires`-block evaluation (PHP version, extensions,
 * active-plugin checks) that decides whether a demo card is offered or greyed
 * out. Plugin checks resolve through Helpers::pluginActivationStatus(), which
 * returns 'install' for a path that does not exist under WP_PLUGIN_DIR, so the
 * not-active branch is covered without stubbing WordPress internals.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\DemoRequirements;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\DemoRequirements
 */
final class DemoRequirementsTest extends UnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		// The checks wrap labels in esc_html__, and pluginActivationStatus runs
		// the path through esc_attr; both are pass-throughs here.
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );

		// A path under a nonexistent plugin dir makes file_exists() false, so
		// pluginActivationStatus() returns 'install' (not active) for real.
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', '/sd-edi-nonexistent-plugins' );
		}
	}

	public function test_absent_or_empty_requires_is_met(): void {
		self::assertSame( [ 'met' => true, 'missing' => [] ], DemoRequirements::evaluate( [] ) );
		self::assertSame( [ 'met' => true, 'missing' => [] ], DemoRequirements::evaluate( null ) );
		self::assertSame( [ 'met' => true, 'missing' => [] ], DemoRequirements::evaluate( 'nope' ) );
	}

	public function test_php_below_required_is_missing(): void {
		$result = DemoRequirements::evaluate( [ 'php' => '99.0' ] );

		self::assertFalse( $result['met'] );
		self::assertCount( 1, $result['missing'] );
		self::assertStringContainsString( '99.0', $result['missing'][0] );
	}

	public function test_php_satisfied_is_met(): void {
		self::assertTrue( DemoRequirements::evaluate( [ 'php' => '5.6' ] )['met'] );
	}

	public function test_missing_extension_is_reported(): void {
		$result = DemoRequirements::evaluate( [ 'extensions' => [ 'sd_edi_fake_ext' ] ] );

		self::assertFalse( $result['met'] );
		self::assertStringContainsString( 'sd_edi_fake_ext', $result['missing'][0] );
	}

	public function test_loaded_extension_is_met(): void {
		// json is compiled into every supported PHP build and cannot be disabled.
		self::assertTrue( DemoRequirements::evaluate( [ 'extensions' => [ 'json' ] ] )['met'] );
	}

	public function test_inactive_plugin_uses_its_label(): void {
		$result = DemoRequirements::evaluate(
			[ 'plugins' => [ 'acf-pro/acf.php' => 'ACF Pro' ] ]
		);

		self::assertFalse( $result['met'] );
		self::assertSame( [ 'ACF Pro' ], $result['missing'] );
	}

	public function test_plain_list_plugin_falls_back_to_path_label(): void {
		$result = DemoRequirements::evaluate( [ 'plugins' => [ 'acf-pro/acf.php' ] ] );

		self::assertSame( [ 'acf-pro/acf.php' ], $result['missing'] );
	}

	public function test_multiple_failures_aggregate(): void {
		$result = DemoRequirements::evaluate(
			[
				'php'        => '99.0',
				'extensions' => [ 'sd_edi_fake_ext' ],
				'plugins'    => [ 'acf-pro/acf.php' => 'ACF Pro' ],
			]
		);

		self::assertFalse( $result['met'] );
		self::assertCount( 3, $result['missing'] );
	}
}
