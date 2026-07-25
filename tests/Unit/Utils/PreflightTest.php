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

	public function test_execution_time_check_pass_warn_and_unlimited(): void {
		self::assertSame( Preflight::PASS, Preflight::executionTimeCheck( 60 )['status'] );
		self::assertSame( Preflight::PASS, Preflight::executionTimeCheck( 0 )['status'] );

		$warn = Preflight::executionTimeCheck( 10 );
		self::assertSame( Preflight::WARN, $warn['status'] );
		// A low execution time warns but never blocks — the importer chunks around it.
		self::assertFalse( $warn['blocking'] );
	}

	public function test_memory_tune_outcome_raised_and_reached(): void {
		$out = Preflight::memoryTuneOutcome( '128M', '256M', '256M' );
		self::assertTrue( $out['raised'] );
		self::assertTrue( $out['reached'] );
		self::assertFalse( $out['refused'] );
	}

	public function test_memory_tune_outcome_host_refused(): void {
		// Requested more, host didn't move it.
		$out = Preflight::memoryTuneOutcome( '128M', '256M', '128M' );
		self::assertFalse( $out['raised'] );
		self::assertFalse( $out['reached'] );
		self::assertTrue( $out['refused'] );
	}

	public function test_memory_tune_outcome_raised_but_capped_short(): void {
		$out = Preflight::memoryTuneOutcome( '128M', '256M', '192M' );
		self::assertTrue( $out['raised'] );
		self::assertFalse( $out['reached'] );
		self::assertFalse( $out['refused'] );
	}

	public function test_memory_tune_outcome_unlimited_is_reached(): void {
		$out = Preflight::memoryTuneOutcome( '128M', '256M', '-1' );
		self::assertFalse( $out['raised'] );
		self::assertTrue( $out['reached'] );
	}

	public function test_memory_check_reports_raised(): void {
		$tune  = Preflight::memoryTuneOutcome( '128M', '256M', '256M' );
		$check = Preflight::memoryCheck( '256M', '256M', $tune );
		self::assertSame( Preflight::PASS, $check['status'] );
		self::assertStringContainsString( '128M', $check['message'] );
	}

	public function test_checks_flag_adjusted_only_when_the_plugin_raised_them(): void {
		$raised = Preflight::memoryTuneOutcome( '128M', '256M', '256M' );
		self::assertTrue( Preflight::memoryCheck( '256M', '256M', $raised )['adjusted'] );

		// Host refused: nothing was changed, so nothing to flag.
		$refused = Preflight::memoryTuneOutcome( '128M', '256M', '128M' );
		self::assertFalse( Preflight::memoryCheck( '128M', '256M', $refused )['adjusted'] );

		// Already high enough: no raise was attempted.
		$untouched = Preflight::memoryTuneOutcome( '512M', '512M', '512M' );
		self::assertFalse( Preflight::memoryCheck( '512M', '256M', $untouched )['adjusted'] );

		// No tune data at all (System Status renders checks without it).
		self::assertFalse( Preflight::memoryCheck( '512M', '256M' )['adjusted'] );
	}

	public function test_execution_time_check_flags_adjusted_when_raised(): void {
		$raised = Preflight::execTuneOutcome( 15, 30, 30 );
		self::assertTrue( Preflight::executionTimeCheck( 30, $raised )['adjusted'] );

		$refused = Preflight::execTuneOutcome( 15, 30, 15 );
		self::assertFalse( Preflight::executionTimeCheck( 15, $refused )['adjusted'] );

		self::assertFalse( Preflight::executionTimeCheck( 60 )['adjusted'] );
	}

	public function test_untuned_checks_are_never_flagged_adjusted(): void {
		// Every other check builder must default the flag off.
		self::assertFalse( Preflight::phpVersionCheck( '8.1', '7.4' )['adjusted'] );
		self::assertFalse( Preflight::extensionCheck( 'ZipArchive', true, true )['adjusted'] );
		self::assertFalse( Preflight::imageLibraryCheck( true, false )['adjusted'] );
		self::assertFalse( Preflight::requiredPluginsCheck( [] )['adjusted'] );
	}

	public function test_memory_check_reports_host_refusal_as_warn(): void {
		$tune  = Preflight::memoryTuneOutcome( '128M', '256M', '128M' );
		$check = Preflight::memoryCheck( '128M', '256M', $tune );
		self::assertSame( Preflight::WARN, $check['status'] );
		self::assertFalse( $check['blocking'] );
	}

	public function test_exec_tune_outcome_raised_and_reached(): void {
		$out = Preflight::execTuneOutcome( 15, 30, 30 );
		self::assertTrue( $out['raised'] );
		self::assertTrue( $out['reached'] );
		self::assertFalse( $out['refused'] );
	}

	public function test_exec_tune_outcome_host_refused(): void {
		$out = Preflight::execTuneOutcome( 15, 30, 15 );
		self::assertFalse( $out['raised'] );
		self::assertTrue( $out['refused'] );
	}

	public function test_exec_tune_outcome_unlimited_before_is_untouched(): void {
		// 0 = unlimited; never counted as a raise or refusal.
		$out = Preflight::execTuneOutcome( 0, 0, 0 );
		self::assertFalse( $out['raised'] );
		self::assertFalse( $out['refused'] );
		self::assertTrue( $out['reached'] );
	}

	public function test_limits_log_entry_both_ok_is_info(): void {
		$entry = Preflight::limitsLogEntry( '256M', 60 );
		self::assertSame( 'info', $entry['level'] );

		// Unlimited memory (-1) and unlimited exec (0) also read as ready.
		self::assertSame( 'info', Preflight::limitsLogEntry( '-1', 0 )['level'] );
	}

	public function test_limits_log_entry_memory_short_is_warning(): void {
		$entry = Preflight::limitsLogEntry( '128M', 60 );
		self::assertSame( 'warning', $entry['level'] );
		self::assertStringContainsString( '128M', $entry['message'] );
	}

	public function test_limits_log_entry_exec_short_is_warning(): void {
		self::assertSame( 'warning', Preflight::limitsLogEntry( '256M', 10 )['level'] );
	}

	public function test_limits_log_entry_both_short_is_warning(): void {
		$entry = Preflight::limitsLogEntry( '128M', 10 );
		self::assertSame( 'warning', $entry['level'] );
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
