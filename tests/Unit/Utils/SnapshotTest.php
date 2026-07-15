<?php
/**
 * Unit tests for Snapshot.
 *
 * Covers the pure shadow-table name mapping (the DB operations themselves need a
 * real database and are exercised by manual/integration testing).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use SigmaDevs\EasyDemoImporter\Common\Utils\Snapshot;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\Snapshot
 */
final class SnapshotTest extends UnitTestCase {

	public function test_shadow_name_inserts_infix_after_prefix(): void {
		self::assertSame( 'wp_sd_edi_snap_posts', Snapshot::shadowName( 'wp_posts', 'wp_' ) );
		self::assertSame( 'wp_sd_edi_snap_options', Snapshot::shadowName( 'wp_options', 'wp_' ) );
	}

	public function test_shadow_name_honors_custom_prefix(): void {
		self::assertSame( 'xyz_sd_edi_snap_termmeta', Snapshot::shadowName( 'xyz_termmeta', 'xyz_' ) );
	}

	public function test_shadow_name_when_table_lacks_prefix(): void {
		// If the table does not start with the prefix, the whole name is treated
		// as the bare table.
		self::assertSame( 'wp_sd_edi_snap_posts', Snapshot::shadowName( 'posts', 'wp_' ) );
	}
}
