<?php
/**
 * Unit tests for Helpers.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\Helpers;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\Helpers
 */
final class HelpersTest extends UnitTestCase {

	public function test_nonce_identifiers_are_stable(): void {
		// These are the POST field / action pair the whole AJAX layer relies on;
		// changing them silently would break every request.
		self::assertSame( 'sd_edi_nonce', Helpers::nonceId() );
		self::assertSame( 'sd_edi_nonce_secret', Helpers::nonceText() );
	}

	public function test_verify_user_role_allows_capable_user(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'wp_send_json_error' )->never();

		Helpers::verifyUserRole();

		// Reaching here without a JSON error response is the assertion.
		self::assertTrue( true );
	}

	public function test_verify_user_role_blocks_incapable_user_with_403(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_die' )->justReturn( null );
		Functions\when( 'error_log' )->justReturn( true );
		$this->stubDenialLogging();

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		Helpers::verifyUserRole();
	}

	public function test_denied_request_is_recorded(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_die' )->justReturn( null );
		Functions\when( 'wp_send_json_error' )->justReturn( null );
		Functions\when( 'error_log' )->justReturn( true );
		$this->stubDenialLogging();

		// The audit hook is the contract a site can rely on, and it must fire for
		// every denial regardless of which sink the entry lands in.
		Functions\expect( 'do_action' )
			->once()
			->with( 'sd/edi/security_denial', 'capability', 'sd_edi_install_demo', 7 );

		Helpers::verifyUserRole();
	}

	public function test_denial_outside_an_import_does_not_write_a_phantom_run(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_die' )->justReturn( null );
		Functions\when( 'wp_send_json_error' )->justReturn( null );
		Functions\when( 'do_action' )->justReturn( null );
		$this->stubDenialLogging();

		// No session lock, so SessionManager::get() returns null. Nothing may be
		// written to the activity log: ImportLogger::getRuns() groups by
		// session_id and caps the view, so session-less rows would collapse into a
		// phantom "run" and push real imports out of the Activity tab.
		Functions\expect( 'get_transient' )->never();

		// The entry still has to land somewhere - it goes to the PHP error log.
		Functions\expect( 'error_log' )
			->once()
			->with( \Mockery::pattern( '/^\[easy-demo-importer\] Request denied on capability check/' ) );

		Helpers::verifyUserRole();
	}

	/**
	 * Stubs the WordPress functions the denial-logging path touches.
	 *
	 * `get_option` returning '' makes SessionManager::get() report no active
	 * import, which is the common case for a denial.
	 */
	private function stubDenialLogging(): void {
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'get_option' )->justReturn( '' );

		$_REQUEST['action'] = 'sd_edi_install_demo';
	}
}
