<?php
/**
 * Unit tests for ImportLogger.
 *
 * Exercises the pure logic (level normalization, message sanitization, empty
 * skipping) and the DB insert contract, with $wpdb replaced by a Mockery mock.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Mockery;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\ImportLogger;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\ImportLogger
 */
final class ImportLoggerTest extends UnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		// wp_strip_all_tags: good-enough stand-in — strip tags like the real one.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( $text ) {
				return trim( wp_strip_tags_shim( $text ) );
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-07-09 12:00:00' );
	}

	public function test_levels_are_the_four_known_levels(): void {
		self::assertSame(
			[ 'info', 'success', 'warning', 'error' ],
			ImportLogger::levels()
		);
	}

	public function test_normalize_level_passes_known_levels_through(): void {
		self::assertSame( 'error', ImportLogger::normalizeLevel( 'error' ) );
		self::assertSame( 'warning', ImportLogger::normalizeLevel( 'WARNING' ) );
		self::assertSame( 'success', ImportLogger::normalizeLevel( '  success ' ) );
	}

	public function test_normalize_level_defaults_unknown_to_info(): void {
		self::assertSame( 'info', ImportLogger::normalizeLevel( 'critical' ) );
		self::assertSame( 'info', ImportLogger::normalizeLevel( '' ) );
	}

	public function test_log_ignores_empty_and_markup_only_messages(): void {
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )->never();
		$GLOBALS['wpdb'] = $wpdb;

		ImportLogger::log( '   ' );
		ImportLogger::log( '<br />' );

		// The never() expectation is the assertion (counted in tear_down).
		self::assertTrue( true );
	}

	public function test_log_inserts_sanitized_row_with_normalized_level(): void {
		$captured     = [];
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				static function ( $table, $data, $format ) use ( &$captured ) {
					$captured = compact( 'table', 'data', 'format' );
					return 1;
				}
			);
		$GLOBALS['wpdb'] = $wpdb;

		ImportLogger::log( '<strong>Failed to import</strong>', 'nonsense-level', 'sess-123' );

		self::assertSame( 'wp_sd_edi_import_log', $captured['table'] );
		self::assertSame( 'Failed to import', $captured['data']['message'] );
		self::assertSame( 'info', $captured['data']['level'], 'Unknown level must fall back to info.' );
		self::assertSame( 'sess-123', $captured['data']['session_id'] );
		self::assertSame( '2026-07-09 12:00:00', $captured['data']['logged_at'] );
	}
}

/**
 * Minimal strip_tags shim so the unit test does not depend on WordPress.
 *
 * @param string $text Input.
 *
 * @return string
 */
function wp_strip_tags_shim( $text ): string {
	return strip_tags( (string) $text );
}
