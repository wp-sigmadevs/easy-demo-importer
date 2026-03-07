<?php
/**
 * Class: SessionManager
 *
 * Manages import sessions and concurrent-import mutex lock.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class: SessionManager
 *
 * @since 1.2.0
 */
class SessionManager {
	/**
	 * Option key for the active import lock.
	 *
	 * @var string
	 */
	const LOCK_KEY = 'sd_edi_import_lock';

	/**
	 * Transient prefix for session data.
	 *
	 * @var string
	 */
	const SESSION_PREFIX = 'sd_edi_session_';

	/**
	 * Start a new import session.
	 *
	 * Generates a UUID v4 session ID, stores session data in a transient,
	 * and acquires the mutex lock. Cleans up any orphaned previous session first.
	 *
	 * @return array{session_id: string, user_id: int, started_at: int} The new session data.
	 * @since 1.2.0
	 */
	public static function start(): array {
		// Clean up any orphaned session from a previous crashed import.
		static::cleanup();

		$session_id = wp_generate_uuid4();
		$ttl        = (int) apply_filters( 'sd/edi/lock_ttl', 30 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$session_data = [
			'session_id' => $session_id,
			'user_id'    => get_current_user_id(),
			'started_at' => time(),
		];

		// Store session data in a transient that auto-expires.
		set_transient( static::SESSION_PREFIX . $session_id, $session_data, $ttl );

		// Acquire the mutex lock.
		update_option( static::LOCK_KEY, $session_id, false );

		return $session_data;
	}

	/**
	 * Get the currently active session.
	 *
	 * @return array{session_id: string, user_id: int, started_at: int}|null Session data or null if no active session.
	 * @since 1.2.0
	 */
	public static function get(): ?array {
		$session_id = get_option( static::LOCK_KEY, '' );

		if ( empty( $session_id ) ) {
			return null;
		}

		$session_data = get_transient( static::SESSION_PREFIX . $session_id );

		if ( false === $session_data ) {
			// Transient expired — lock is stale. Clear it.
			delete_option( static::LOCK_KEY );
			return null;
		}

		return $session_data;
	}

	/**
	 * Check whether an import is currently locked (running).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isLocked(): bool {
		return null !== static::get();
	}

	/**
	 * Validate that a session ID matches the active lock.
	 *
	 * @param string $session_id Session ID to validate.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isValid( string $session_id ): bool {
		$active = static::get();

		if ( null === $active || $active['session_id'] !== $session_id ) {
			return false;
		}

		// Tie sessions to user IDs: only the user who started the import can proceed with it.
		return (int) $active['user_id'] === get_current_user_id();
	}

	/**
	 * Release the active import lock and delete session data.
	 *
	 * @param string $session_id Session ID to release.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function release( string $session_id ): void {
		delete_transient( static::SESSION_PREFIX . $session_id );

		// Only clear the lock if it still holds this session's ID
		// (prevents a race condition where a new import started).
		if ( get_option( static::LOCK_KEY, '' ) === $session_id ) {
			delete_option( static::LOCK_KEY );
		}
	}

	/**
	 * Force-release the import lock unconditionally.
	 *
	 * Unlike release() (which requires the correct session ID) and cleanup()
	 * (which only removes expired locks), this method always clears whatever
	 * lock is currently set — including a live, in-TTL lock. Use this when
	 * the user explicitly requests a reset via "Start Over".
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function forceRelease(): void {
		$session_id = get_option( static::LOCK_KEY, '' );

		if ( ! empty( $session_id ) ) {
			delete_transient( static::SESSION_PREFIX . $session_id );
		}

		delete_option( static::LOCK_KEY );
	}

	/**
	 * Clean up any orphaned session data from a previous crashed import.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function cleanup(): void {
		$session_id = get_option( static::LOCK_KEY, '' );

		if ( empty( $session_id ) ) {
			return;
		}

		// If the transient is still alive, the lock is active — don't touch it.
		if ( false !== get_transient( static::SESSION_PREFIX . $session_id ) ) {
			return;
		}

		// Transient is gone, but the lock option remains — orphaned lock. Remove it.
		delete_option( static::LOCK_KEY );
	}
}
