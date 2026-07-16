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
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Builds one grouped run for the markInterruptedRuns() tests.
	 *
	 * @param string $sid    Session id.
	 * @param string $status Run status.
	 *
	 * @return array
	 */
	private function makeRun( string $sid, string $status ): array {
		return [
			'session_id' => $sid,
			'demo_slug'  => 'home-01',
			'started_at' => '2026-07-09 11:00:00',
			'status'     => $status,
			'count'      => 1,
			'entries'    => [
				[
					'logged_at' => '2026-07-09 11:00:05',
					'level'     => ImportLogger::INFO,
					'message'   => 'Importing…',
				],
			],
		];
	}

	public function test_mark_interrupted_flags_stale_in_progress_run(): void {
		$runs = ImportLogger::markInterruptedRuns(
			[ $this->makeRun( 'sid-A', ImportLogger::INFO ) ],
			''
		);

		self::assertSame( ImportLogger::INTERRUPTED, $runs[0]['status'] );
		// A closing entry was appended…
		self::assertSame( 2, $runs[0]['count'] );
		self::assertSame( ImportLogger::INTERRUPTED, end( $runs[0]['entries'] )['level'] );
		// …reusing the last real entry's timestamp, not "now".
		self::assertSame( '2026-07-09 11:00:05', end( $runs[0]['entries'] )['logged_at'] );
	}

	public function test_mark_interrupted_leaves_the_active_session_alone(): void {
		$runs = ImportLogger::markInterruptedRuns(
			[ $this->makeRun( 'sid-A', ImportLogger::INFO ) ],
			'sid-A'
		);

		self::assertSame( ImportLogger::INFO, $runs[0]['status'] );
		self::assertSame( 1, $runs[0]['count'] );
	}

	public function test_mark_interrupted_ignores_finished_runs(): void {
		foreach ( [ ImportLogger::SUCCESS, ImportLogger::WARNING, ImportLogger::ERROR ] as $status ) {
			$runs = ImportLogger::markInterruptedRuns(
				[ $this->makeRun( 'sid-A', $status ) ],
				''
			);

			self::assertSame( $status, $runs[0]['status'] );
			self::assertSame( 1, $runs[0]['count'] );
		}
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

	/**
	 * Builds an oldest-first log row for grouping tests.
	 *
	 * @param string $session Session id.
	 * @param string $slug    Demo slug.
	 * @param string $level   Level.
	 * @param string $at      Timestamp.
	 *
	 * @return array<string,string>
	 */
	private function row( string $session, string $slug, string $level, string $at ): array {
		return [
			'session_id' => $session,
			'demo_slug'  => $slug,
			'level'      => $level,
			'message'    => "msg-$level",
			'logged_at'  => $at,
		];
	}

	public function test_group_rows_groups_by_session_newest_run_first(): void {
		$runs = ImportLogger::groupRows(
			[
				$this->row( 'A', 'sportify', 'info', '2026-07-09 10:00:00' ),
				$this->row( 'A', 'sportify', 'info', '2026-07-09 10:01:00' ),
				$this->row( 'B', 'woo', 'info', '2026-07-09 11:00:00' ),
			]
		);

		self::assertCount( 2, $runs );
		self::assertSame( 'B', $runs[0]['session_id'], 'Newest run first.' );
		self::assertSame( 'A', $runs[1]['session_id'] );
		self::assertSame( 'sportify', $runs[1]['demo_slug'] );
		self::assertSame( '2026-07-09 10:00:00', $runs[1]['started_at'] );
		self::assertSame( 2, $runs[1]['count'] );
	}

	public function test_group_rows_any_error_marks_run_failed(): void {
		$runs = ImportLogger::groupRows(
			[
				$this->row( 'A', 'woo', 'info', '2026-07-09 10:00:00' ),
				$this->row( 'A', 'woo', 'error', '2026-07-09 10:01:00' ),
				$this->row( 'A', 'woo', 'success', '2026-07-09 10:02:00' ),
			]
		);

		self::assertSame( ImportLogger::ERROR, $runs[0]['status'], 'Error wins over a later success.' );
	}

	public function test_group_rows_success_marks_clean_run(): void {
		$runs = ImportLogger::groupRows(
			[
				$this->row( 'A', 'woo', 'info', '2026-07-09 10:00:00' ),
				$this->row( 'A', 'woo', 'success', '2026-07-09 10:02:00' ),
			]
		);

		self::assertSame( ImportLogger::SUCCESS, $runs[0]['status'] );
	}

	public function test_group_rows_success_with_warnings_becomes_warning(): void {
		$runs = ImportLogger::groupRows(
			[
				$this->row( 'A', 'woo', 'info', '2026-07-09 10:00:00' ),
				$this->row( 'A', 'woo', 'warning', '2026-07-09 10:01:00' ),
				$this->row( 'A', 'woo', 'success', '2026-07-09 10:02:00' ),
			]
		);

		self::assertSame( ImportLogger::WARNING, $runs[0]['status'] );
		// The internal tracking flag must never leak into the payload.
		self::assertArrayNotHasKey( 'has_warning', $runs[0] );
	}

	public function test_group_rows_error_wins_over_warnings(): void {
		$runs = ImportLogger::groupRows(
			[
				$this->row( 'A', 'woo', 'warning', '2026-07-09 10:00:00' ),
				$this->row( 'A', 'woo', 'error', '2026-07-09 10:01:00' ),
				$this->row( 'A', 'woo', 'success', '2026-07-09 10:02:00' ),
			]
		);

		self::assertSame( ImportLogger::ERROR, $runs[0]['status'] );
	}

	public function test_group_rows_without_terminal_entry_stays_info(): void {
		$runs = ImportLogger::groupRows(
			[ $this->row( 'A', 'woo', 'info', '2026-07-09 10:00:00' ) ]
		);

		self::assertSame( ImportLogger::INFO, $runs[0]['status'] );
	}

	public function test_group_rows_empty_returns_empty(): void {
		self::assertSame( [], ImportLogger::groupRows( [] ) );
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
