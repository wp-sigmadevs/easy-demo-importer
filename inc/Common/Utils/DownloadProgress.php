<?php
/**
 * Utility Class: DownloadProgress
 *
 * Byte-level progress for the demo archive download.
 *
 * Unlike the content import and image regeneration — which run as a series of
 * AJAX round-trips, so each response can carry its own progress — the download
 * is a single blocking request. The browser fires it and hears nothing until
 * the file has landed, which on a slow host or a large archive is a long,
 * silent wait that reads as a hang.
 *
 * This class opens a side channel: a cURL transfer callback writes the running
 * byte count to a transient, and a REST route reads it back so the modal can
 * poll while the download request is still in flight.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.1
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: DownloadProgress
 *
 * @since 2.0.1
 */
class DownloadProgress {

	/**
	 * Transient key prefix; the import session id is appended.
	 *
	 * @since 2.0.1
	 */
	const TRANSIENT_PREFIX = 'sd_edi_dl_progress_';

	/**
	 * How long a progress record survives, in seconds.
	 *
	 * Comfortably longer than the download timeout ceiling so a record is
	 * never expired out from under a still-running transfer, but short enough
	 * that an abandoned import does not leave rows behind.
	 *
	 * @since 2.0.1
	 */
	const TTL = 900;

	/**
	 * Minimum seconds between transient writes.
	 *
	 * The transfer callback fires very frequently — often several times per
	 * second per chunk. Writing on every call would hammer the options table
	 * for no visible benefit, since the UI polls far slower than that.
	 *
	 * @since 2.0.1
	 */
	const THROTTLE = 1.0;

	/**
	 * Session currently being tracked, or '' when tracking is off.
	 *
	 * @var string
	 * @since 2.0.1
	 */
	private static $sessionId = '';

	/**
	 * Unix timestamp (float) of the last transient write.
	 *
	 * @var float
	 * @since 2.0.1
	 */
	private static $lastWrite = 0.0;

	/**
	 * Percentage complete, or null when it cannot be known.
	 *
	 * Returns null when the server sent no Content-Length (a chunked or
	 * gzip-streamed response), so callers can fall back to an indeterminate
	 * bar rather than showing a fabricated number.
	 *
	 * Pure — no state, no side effects.
	 *
	 * @param int $downloaded Bytes received so far.
	 * @param int $total      Total bytes expected, or 0 when unknown.
	 *
	 * @return int|null Percentage 0-100, or null when indeterminate.
	 * @since 2.0.1
	 */
	public static function percent( int $downloaded, int $total ): ?int {
		if ( $total <= 0 || $downloaded < 0 ) {
			return null;
		}

		return (int) min( 100, (int) floor( ( $downloaded / $total ) * 100 ) );
	}

	/**
	 * Whether a write is due, given the last write time.
	 *
	 * The first sample of a transfer always writes so the bar appears
	 * immediately rather than after the throttle window.
	 *
	 * Pure — takes both times as arguments.
	 *
	 * @param float $lastWrite Unix timestamp of the previous write, 0.0 for none.
	 * @param float $now       Current Unix timestamp.
	 *
	 * @return bool
	 * @since 2.0.1
	 */
	public static function isWriteDue( float $lastWrite, float $now ): bool {
		if ( $lastWrite <= 0.0 ) {
			return true;
		}

		return ( $now - $lastWrite ) >= self::THROTTLE;
	}

	/**
	 * Starts tracking the download for a session.
	 *
	 * Registers the cURL hook and seeds a zeroed record, so a poll landing
	 * before the first byte arrives gets "starting" rather than nothing.
	 *
	 * @param string $sessionId Import session id.
	 *
	 * @return void
	 * @since 2.0.1
	 */
	public static function start( string $sessionId ): void {
		if ( '' === $sessionId ) {
			return;
		}

		self::$sessionId = $sessionId;
		self::$lastWrite = 0.0;

		self::write( 0, 0 );

		add_action( 'http_api_curl', [ self::class, 'attach' ], 10, 3 );
	}

	/**
	 * Stops tracking and clears the record.
	 *
	 * @param string $sessionId Import session id.
	 *
	 * @return void
	 * @since 2.0.1
	 */
	public static function stop( string $sessionId ): void {
		remove_action( 'http_api_curl', [ self::class, 'attach' ], 10 );

		self::$sessionId = '';
		self::$lastWrite = 0.0;

		if ( '' !== $sessionId ) {
			delete_transient( self::TRANSIENT_PREFIX . $sessionId );
		}
	}

	/**
	 * Attaches a transfer callback to the cURL handle.
	 *
	 * Only the streamed demo archive download is tracked; other requests the
	 * import makes (plugin ZIPs, the plugins API) are left alone. WordPress
	 * fires this action after its own curl_setopt calls, so these win.
	 *
	 * @param resource|\CurlHandle $handle      cURL handle.
	 * @param array<string,mixed>  $parsed_args Request arguments.
	 * @param string               $url         Request URL.
	 *
	 * @return void
	 * @since 2.0.1
	 */
	public static function attach( $handle, $parsed_args, $url ): void {
		unset( $url );

		if ( '' === self::$sessionId || empty( $parsed_args['stream'] ) ) {
			return;
		}

		if ( ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		$callback = static function ( $resource, $downloadTotal, $downloaded ) {
			unset( $resource );

			self::maybeWrite( (int) $downloaded, (int) $downloadTotal );

			// Any non-zero return aborts the transfer.
			return 0;
		};

		curl_setopt( $handle, CURLOPT_NOPROGRESS, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt

		// XFERINFO is the modern callback; PROGRESSFUNCTION is its predecessor
		// and is kept as a fallback for older libcurl builds.
		if ( defined( 'CURLOPT_XFERINFOFUNCTION' ) ) {
			curl_setopt( $handle, CURLOPT_XFERINFOFUNCTION, $callback ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		} else {
			curl_setopt( $handle, CURLOPT_PROGRESSFUNCTION, $callback ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
		}
	}

	/**
	 * Writes a progress sample if the throttle window has elapsed.
	 *
	 * @param int $downloaded Bytes received so far.
	 * @param int $total      Total bytes expected, or 0 when unknown.
	 *
	 * @return void
	 * @since 2.0.1
	 */
	private static function maybeWrite( int $downloaded, int $total ): void {
		$now = microtime( true );

		if ( ! self::isWriteDue( self::$lastWrite, $now ) ) {
			return;
		}

		self::$lastWrite = $now;

		self::write( $downloaded, $total );
	}

	/**
	 * Persists a progress sample.
	 *
	 * @param int $downloaded Bytes received so far.
	 * @param int $total      Total bytes expected, or 0 when unknown.
	 *
	 * @return void
	 * @since 2.0.1
	 */
	private static function write( int $downloaded, int $total ): void {
		if ( '' === self::$sessionId ) {
			return;
		}

		set_transient(
			self::TRANSIENT_PREFIX . self::$sessionId,
			[
				'downloaded' => $downloaded,
				'total'      => $total,
			],
			self::TTL
		);
	}

	/**
	 * Reads the current progress for a session.
	 *
	 * @param string $sessionId Import session id.
	 *
	 * @return array{tracking: bool, downloaded: int, total: int, percent: int|null}
	 * @since 2.0.1
	 */
	public static function read( string $sessionId ): array {
		$empty = [
			'tracking'   => false,
			'downloaded' => 0,
			'total'      => 0,
			'percent'    => null,
		];

		if ( '' === $sessionId ) {
			return $empty;
		}

		$record = get_transient( self::TRANSIENT_PREFIX . $sessionId );

		if ( ! is_array( $record ) ) {
			return $empty;
		}

		$downloaded = isset( $record['downloaded'] ) ? (int) $record['downloaded'] : 0;
		$total      = isset( $record['total'] ) ? (int) $record['total'] : 0;

		return [
			'tracking'   => true,
			'downloaded' => $downloaded,
			'total'      => $total,
			'percent'    => self::percent( $downloaded, $total ),
		];
	}
}
