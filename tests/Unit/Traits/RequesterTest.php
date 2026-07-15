<?php
/**
 * Unit tests for the Requester trait's request gates.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Traits;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Traits\Requester;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Traits\Requester
 */
final class RequesterTest extends UnitTestCase {

	/**
	 * Fresh object exposing the trait under test.
	 *
	 * @return object
	 */
	private function subject() {
		return new class() {
			use Requester;
		};
	}

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		unset( $_POST['action'], $_POST['demo'], $_POST['sd_edi_nonce'] );
		parent::tear_down();
	}

	public function test_is_admin_backend_requires_login_and_admin(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		self::assertTrue( $this->subject()->isAdminBackend() );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		self::assertFalse( $this->subject()->isAdminBackend() );

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		self::assertFalse( $this->subject()->isAdminBackend() );
	}

	public function test_verify_post_submission_true_when_all_present_and_nonce_valid(): void {
		$_POST['action']       = 'sd_edi_install_demo';
		$_POST['demo']         = 'default';
		$_POST['sd_edi_nonce'] = 'abc';

		Functions\when( 'check_admin_referer' )->justReturn( true );

		self::assertTrue( $this->subject()->verifyPostSubmission() );
	}

	public function test_verify_post_submission_false_when_nonce_field_missing(): void {
		$_POST['action'] = 'sd_edi_install_demo';
		$_POST['demo']   = 'default';
		// No nonce field — must short-circuit to false without calling check_admin_referer.
		Functions\expect( 'check_admin_referer' )->never();

		self::assertFalse( $this->subject()->verifyPostSubmission() );
	}

	public function test_is_import_process_requires_admin_and_valid_submission(): void {
		$_POST['action']       = 'sd_edi_install_demo';
		$_POST['demo']         = 'default';
		$_POST['sd_edi_nonce'] = 'abc';

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );

		self::assertTrue( $this->subject()->isImportProcess() );
	}
}
