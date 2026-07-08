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

		Functions\expect( 'wp_send_json_error' )
			->once()
			->with( \Mockery::type( 'array' ), 403 );

		Helpers::verifyUserRole();
	}
}
