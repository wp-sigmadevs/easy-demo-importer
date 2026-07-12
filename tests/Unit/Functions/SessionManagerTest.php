<?php
/**
 * Unit tests for SessionManager.
 *
 * Drives the import lock/session lifecycle against an in-memory option +
 * transient store so the concurrency rules (validation, ownership, stale-lock
 * cleanup, force release) are verified without WordPress or a database.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\SessionManager;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\SessionManager
 */
final class SessionManagerTest extends UnitTestCase {

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = [];

	/**
	 * In-memory transient store.
	 *
	 * @var array
	 */
	private $transients = [];

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		$this->options    = [];
		$this->transients = [];

		$options    = &$this->options;
		$transients = &$this->transients;

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$options ) {
				return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$options ) {
				$options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $key ) use ( &$options ) {
				unset( $options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				return array_key_exists( $key, $transients ) ? $transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) use ( &$transients ) {
				$transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			static function ( $key ) use ( &$transients ) {
				unset( $transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'uuid-1' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Seeds an active session (lock + transient).
	 *
	 * @param string $id      Session id.
	 * @param int    $user_id Owning user id.
	 *
	 * @return void
	 */
	private function seed( string $id = 's1', int $user_id = 1 ): void {
		$this->options[ SessionManager::LOCK_KEY ]                        = $id;
		$this->transients[ SessionManager::SESSION_PREFIX . $id ] = [
			'session_id' => $id,
			'user_id'    => $user_id,
			'started_at' => 1,
		];
	}

	/**
	 * start() stores a session transient and acquires the lock.
	 */
	public function test_start_stores_session_and_acquires_lock() {
		$data = SessionManager::start();

		$this->assertSame( 'uuid-1', $data['session_id'] );
		$this->assertSame( 'uuid-1', $this->options[ SessionManager::LOCK_KEY ] );
		$this->assertArrayHasKey( SessionManager::SESSION_PREFIX . 'uuid-1', $this->transients );
	}

	/**
	 * get() returns null when nothing is locked.
	 */
	public function test_get_null_when_unlocked() {
		$this->assertNull( SessionManager::get() );
	}

	/**
	 * get() clears the lock and returns null when the transient expired.
	 */
	public function test_get_clears_stale_lock() {
		$this->options[ SessionManager::LOCK_KEY ] = 's1'; // lock without a transient.

		$this->assertNull( SessionManager::get() );
		$this->assertArrayNotHasKey( SessionManager::LOCK_KEY, $this->options );
	}

	/**
	 * isValid() is true only for the active id owned by the current user.
	 */
	public function test_is_valid_matches_id_and_owner() {
		$this->seed( 's1', 1 );

		$this->assertTrue( SessionManager::isValid( 's1' ) );
		$this->assertFalse( SessionManager::isValid( 'other' ) );
	}

	/**
	 * isValid() rejects a session owned by a different user.
	 */
	public function test_is_valid_rejects_foreign_owner() {
		$this->seed( 's1', 2 ); // current user stub is 1.

		$this->assertFalse( SessionManager::isValid( 's1' ) );
	}

	/**
	 * isLocked() reflects an active session.
	 */
	public function test_is_locked() {
		$this->assertFalse( SessionManager::isLocked() );
		$this->seed();
		$this->assertTrue( SessionManager::isLocked() );
	}

	/**
	 * release() clears the lock and transient for the matching session.
	 */
	public function test_release_clears_matching_session() {
		$this->seed( 's1' );

		SessionManager::release( 's1' );

		$this->assertArrayNotHasKey( SessionManager::LOCK_KEY, $this->options );
		$this->assertArrayNotHasKey( SessionManager::SESSION_PREFIX . 's1', $this->transients );
	}

	/**
	 * release() must NOT clear a lock that a newer import already took over.
	 */
	public function test_release_preserves_lock_taken_over() {
		$this->seed( 's1' );
		$this->options[ SessionManager::LOCK_KEY ] = 's2'; // a newer import owns the lock now.

		SessionManager::release( 's1' );

		$this->assertSame( 's2', $this->options[ SessionManager::LOCK_KEY ] );
	}

	/**
	 * forceRelease() clears whatever lock is set, unconditionally.
	 */
	public function test_force_release_clears_any_lock() {
		$this->seed( 's1' );

		SessionManager::forceRelease();

		$this->assertArrayNotHasKey( SessionManager::LOCK_KEY, $this->options );
		$this->assertArrayNotHasKey( SessionManager::SESSION_PREFIX . 's1', $this->transients );
	}

	/**
	 * cleanup() removes an orphaned lock (transient gone) but keeps a live one.
	 */
	public function test_cleanup_removes_orphan_keeps_active() {
		// Orphan: lock without transient.
		$this->options[ SessionManager::LOCK_KEY ] = 's1';
		SessionManager::cleanup();
		$this->assertArrayNotHasKey( SessionManager::LOCK_KEY, $this->options );

		// Active: lock with transient — untouched.
		$this->seed( 's2' );
		SessionManager::cleanup();
		$this->assertSame( 's2', $this->options[ SessionManager::LOCK_KEY ] );
	}
}
