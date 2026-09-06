<?php
/**
 * Integration test: plugin-deletion cleanup against a real database.
 *
 * uninstall.php was missing from the packaged plugin in every release from 2.0.0
 * to 2.0.2, so none of this code had ever executed on a real site. When 2.0.3
 * finally shipped it, a review found three separate defects in it: the media
 * restore point was stranded, the cron cleanup named a hook that does not exist,
 * and the imported-attachment postmeta was never swept.
 *
 * This test runs the real file against a real database so the next change to it
 * has a safety net. It asserts what deletion must remove, and - just as
 * importantly - that it does not touch anything belonging to the rest of the
 * site.
 *
 * Requires the WordPress integration suite (see tests/README.md).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Integration;

use WP_UnitTestCase;

/**
 * @coversNothing uninstall.php is a script, not a class.
 */
final class UninstallIntegrationTest extends WP_UnitTestCase {

	/**
	 * Cron hook ManualImport schedules on the first manual-import screen load.
	 */
	private const CRON_HOOK = 'sd_edi_manual_cleanup';

	/**
	 * Seeds every artifact the plugin is supposed to clean up, plus a control
	 * set that must survive.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;

		// Options + transients the sweep should match.
		update_option( 'sd_edi_imported_demo', 'demo-one' );
		update_option( 'sd_edi_mediasnap', [ 'mode' => 'manifest', 'manifest' => '' ] );
		set_transient( 'sd_edi_download_progress', 42, HOUR_IN_SECONDS );

		// Control rows: nothing here may be removed.
		update_option( 'blogname', 'Control Site' );
		update_option( 'some_other_plugin_setting', 'keep-me' );
		set_transient( 'unrelated_transient', 'keep-me', HOUR_IN_SECONDS );

		foreach ( $this->pluginTables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) )" );
		}

		// An attachment carrying the importer's chunk-replay dedup key.
		$attachment_id = self::factory()->post->create( [ 'post_type' => 'attachment' ] );
		update_post_meta( $attachment_id, '_sd_edi_source_url', 'https://example.test/image.jpg' );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'keep-me' );

		// The recurring event that survived deletion before 2.0.3.
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
	}

	/**
	 * Tables uninstall.php is expected to drop: the two fixed ones plus a live
	 * restore-point shadow, which is discovered dynamically rather than named.
	 *
	 * @return array<int,string>
	 */
	private function pluginTables(): array {
		global $wpdb;

		return [
			$wpdb->prefix . 'sd_edi_taxonomy_import',
			$wpdb->prefix . 'sd_edi_import_log',
			$wpdb->prefix . 'sd_edi_snap_posts',
		];
	}

	/**
	 * Runs the real uninstall script the way WordPress does.
	 *
	 * @return void
	 */
	private function runUninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'easy-demo-importer/easy-demo-importer.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		wp_cache_flush();
	}

	/**
	 * @return void
	 */
	public function test_uninstall_removes_options_transients_and_postmeta(): void {
		global $wpdb;

		$this->runUninstall();

		self::assertFalse( get_option( 'sd_edi_imported_demo' ), 'sd_edi_* options must be deleted.' );
		self::assertFalse( get_option( 'sd_edi_mediasnap' ), 'The media snapshot pointer must be deleted.' );
		self::assertFalse( get_transient( 'sd_edi_download_progress' ), 'sd_edi_* transients must be deleted.' );

		// Regression guard for the sweep that did not exist before 2.0.3: one row
		// per imported attachment stayed in postmeta forever.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_sd_edi_source_url' )
		);

		self::assertSame( 0, $orphans, 'Imported-attachment tracking meta must not survive deletion.' );
	}

	/**
	 * @return void
	 */
	public function test_uninstall_drops_every_plugin_table_including_snapshot_shadows(): void {
		global $wpdb;

		$this->runUninstall();

		foreach ( $this->pluginTables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

			// Before 2.0.3 the identifier was passed to prepare() as %1$s, which
			// quoted it as a string literal and made the statement a syntax error,
			// so every table survived deletion silently.
			self::assertNull( $found, "Table {$table} must be dropped on uninstall." );
		}
	}

	/**
	 * @return void
	 */
	public function test_uninstall_clears_the_recurring_cleanup_event(): void {
		self::assertNotFalse( wp_next_scheduled( self::CRON_HOOK ), 'Fixture should have scheduled the event.' );

		$this->runUninstall();

		// The cron list previously named 'sd_edi_import_cron', which exists nowhere
		// in the codebase, so the real daily event was left firing forever against
		// a callback that had been deleted.
		self::assertFalse( wp_next_scheduled( self::CRON_HOOK ), 'The recurring cleanup event must be unscheduled.' );
	}

	/**
	 * @return void
	 */
	public function test_uninstall_leaves_unrelated_site_data_alone(): void {
		$this->runUninstall();

		self::assertSame( 'Control Site', get_option( 'blogname' ) );
		self::assertSame( 'keep-me', get_option( 'some_other_plugin_setting' ) );
		self::assertSame( 'keep-me', get_transient( 'unrelated_transient' ) );

		// The LIKE patterns escape their underscores; a missing escape would turn
		// `sd\_edi\_%` into a wildcard match far wider than intended.
		self::assertNotEmpty( get_option( 'siteurl' ) );
	}
}
