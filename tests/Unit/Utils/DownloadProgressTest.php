<?php
/**
 * Unit tests for DownloadProgress.
 *
 * Covers the two pure pieces the feature turns on: the percentage grader, which
 * must refuse to invent a number when the server sent no Content-Length, and the
 * write throttle that keeps a very chatty cURL callback off the options table.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\DownloadProgress;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\DownloadProgress
 */
final class DownloadProgressTest extends UnitTestCase {

	public function test_percent_is_null_without_a_known_total(): void {
		// A chunked or gzip-streamed response reports no Content-Length, so
		// cURL hands over a total of 0. The bar must stay indeterminate.
		self::assertNull( DownloadProgress::percent( 5000, 0 ) );
		self::assertNull( DownloadProgress::percent( 0, 0 ) );
		self::assertNull( DownloadProgress::percent( 5000, -1 ) );
	}

	public function test_percent_is_null_for_a_negative_byte_count(): void {
		self::assertNull( DownloadProgress::percent( -1, 100 ) );
	}

	public function test_percent_grades_a_known_transfer(): void {
		self::assertSame( 0, DownloadProgress::percent( 0, 1000 ) );
		self::assertSame( 25, DownloadProgress::percent( 250, 1000 ) );
		self::assertSame( 100, DownloadProgress::percent( 1000, 1000 ) );
	}

	public function test_percent_floors_rather_than_rounds(): void {
		// Rounding up would show 100% while bytes are still arriving.
		self::assertSame( 99, DownloadProgress::percent( 999, 1000 ) );
		self::assertSame( 33, DownloadProgress::percent( 1, 3 ) );
	}

	public function test_percent_is_capped_when_more_arrives_than_promised(): void {
		self::assertSame( 100, DownloadProgress::percent( 1200, 1000 ) );
	}

	public function test_first_sample_always_writes(): void {
		// So the bar appears immediately instead of after the throttle window.
		self::assertTrue( DownloadProgress::isWriteDue( 0.0, 1000.0 ) );
	}

	public function test_write_is_suppressed_inside_the_throttle_window(): void {
		self::assertFalse( DownloadProgress::isWriteDue( 1000.0, 1000.5 ) );
		self::assertFalse( DownloadProgress::isWriteDue( 1000.0, 1000.99 ) );
	}

	public function test_write_is_due_once_the_window_has_elapsed(): void {
		self::assertTrue( DownloadProgress::isWriteDue( 1000.0, 1001.0 ) );
		self::assertTrue( DownloadProgress::isWriteDue( 1000.0, 1005.0 ) );
	}

	public function test_read_reports_not_tracking_without_a_session(): void {
		self::assertSame(
			[
				'tracking'   => false,
				'downloaded' => 0,
				'total'      => 0,
				'percent'    => null,
			],
			DownloadProgress::read( '' )
		);
	}

	public function test_read_reports_not_tracking_when_no_record_exists(): void {
		Functions\when( 'get_transient' )->justReturn( false );

		$result = DownloadProgress::read( 'session-1' );

		self::assertFalse( $result['tracking'] );
		self::assertNull( $result['percent'] );
	}

	public function test_read_grades_a_stored_record(): void {
		Functions\when( 'get_transient' )->justReturn(
			[
				'downloaded' => 512,
				'total'      => 2048,
			]
		);

		self::assertSame(
			[
				'tracking'   => true,
				'downloaded' => 512,
				'total'      => 2048,
				'percent'    => 25,
			],
			DownloadProgress::read( 'session-1' )
		);
	}

	public function test_read_reports_tracking_with_a_null_percent_when_total_is_unknown(): void {
		// Tracking is live and bytes are arriving, but no total was advertised.
		Functions\when( 'get_transient' )->justReturn(
			[
				'downloaded' => 4096,
				'total'      => 0,
			]
		);

		$result = DownloadProgress::read( 'session-1' );

		self::assertTrue( $result['tracking'] );
		self::assertSame( 4096, $result['downloaded'] );
		self::assertNull( $result['percent'] );
	}

	public function test_read_survives_a_malformed_record(): void {
		Functions\when( 'get_transient' )->justReturn( [ 'garbage' => true ] );

		$result = DownloadProgress::read( 'session-1' );

		self::assertTrue( $result['tracking'] );
		self::assertSame( 0, $result['downloaded'] );
		self::assertNull( $result['percent'] );
	}
}
