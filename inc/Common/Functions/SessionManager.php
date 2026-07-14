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
			'session_id'  => $session_id,
			'user_id'     => get_current_user_id(),
			'started_at'  => time(),
			// Heartbeat: refreshed on every import phase (see touch()). A run whose
			// heartbeat has gone quiet is treated as interrupted in the Import Log,
			// even while the 30-minute lock is still held for resume.
			'last_active' => time(),
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
	 * Whether an import is genuinely live right now.
	 *
	 * A locked session whose heartbeat has gone quiet (interrupted, but still
	 * within its 30-minute lock so it can be resumed) is NOT live — it must not
	 * block a fresh import. Only a session with a recent heartbeat is treated as
	 * a running import. Uses the same staleness window as the Import Log.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isLive(): bool {
		$session = static::get();

		if ( null === $session ) {
			return false;
		}

		$last_active = (int) ( $session['last_active'] ?? $session['started_at'] ?? 0 );
		$threshold   = (int) apply_filters( 'sd/edi/interrupted_after_seconds', 2 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return ( time() - $last_active ) <= $threshold;
	}

	/**
	 * Refresh the session heartbeat.
	 *
	 * Called at the start of every import phase so the "last_active" timestamp
	 * tracks genuine progress. A live import keeps this fresh; an interrupted one
	 * lets it go stale, which the Import Log uses to distinguish a running import
	 * from an abandoned-but-still-locked one.
	 *
	 * @param string $session_id Session to touch.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function touch( string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}

		$data = get_transient( static::SESSION_PREFIX . $session_id );

		if ( ! is_array( $data ) ) {
			return;
		}

		$data['last_active'] = time();
		$ttl                 = (int) apply_filters( 'sd/edi/lock_ttl', 30 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		set_transient( static::SESSION_PREFIX . $session_id, $data, $ttl );
	}

	/**
	 * Marks a session's heartbeat as stale immediately.
	 *
	 * Called when the client knows the import has stopped (the page was reloaded
	 * mid-import), so the Import Log can flag the run as interrupted right away
	 * instead of waiting for the heartbeat to time out. The lock and the resumable
	 * state are left intact — a subsequent resume touches the heartbeat live again.
	 *
	 * @param string $session_id Session to mark stale.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function markStale( string $session_id ): void {
		if ( '' === $session_id ) {
			return;
		}

		$data = get_transient( static::SESSION_PREFIX . $session_id );

		if ( ! is_array( $data ) ) {
			return;
		}

		$data['last_active'] = 0;
		$ttl                 = (int) apply_filters( 'sd/edi/lock_ttl', 30 * MINUTE_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		set_transient( static::SESSION_PREFIX . $session_id, $data, $ttl );
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
