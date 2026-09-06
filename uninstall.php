<?php
/**
 * Plugin Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * WordPress calls this file automatically if it exists in the plugin root.
 *
 * KNOWN LIMITATION - multisite. WordPress runs this file once, in the context of
 * the blog the deletion was triggered from, so `$wpdb->options`, `$wpdb->prefix`
 * and wp_upload_dir() all resolve to that blog only. On a network-activated
 * install every other blog keeps its options, tables and staging directory. The
 * plugin is not multisite-aware generally (see the `multi-site` branch), so this
 * is recorded rather than half-fixed; a get_sites() + switch_to_blog() loop is
 * the standard shape when that work lands.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

// Do not allow directly accessing this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

global $wpdb;

/*
 * Discard the media restore point FIRST. The `sd_edi_mediasnap` option is the
 * only record of where the shadow copy lives, and the option sweep below matches
 * it. In `move` mode that shadow is the site's entire pre-import uploads tree,
 * parked at wp-content/sd-edi-restore/ - so deleting the pointer before the
 * directory strands a full copy of the media library with nothing referencing it
 * and no way to find it again.
 *
 * MediaSnapshot::discard() already removes the shadow (or manifest) and deletes
 * the option, so it is reused rather than reimplemented here; that keeps this in
 * step with the snapshot layout if it ever changes.
 */
$edi_autoload = __DIR__ . '/vendor/autoload.php'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( is_readable( $edi_autoload ) ) {
	require_once $edi_autoload;
}

if ( class_exists( '\SigmaDevs\EasyDemoImporter\Common\Utils\MediaSnapshot' ) ) {
	\SigmaDevs\EasyDemoImporter\Common\Utils\MediaSnapshot::discard();
}

// Delete all sd_edi_* options.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sd\_edi\_%'"
);

// Delete all sd_edi_* transients (stored as _transient_sd_edi_* and _transient_timeout_sd_edi_*).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sd\_edi\_%' OR option_name LIKE '\_transient\_timeout\_sd\_edi\_%'"
);

/*
 * Delete the importer's per-attachment postmeta. `_sd_edi_source_url` is written
 * for every imported attachment by lib/wordpress-importer/class-wp-import.php as
 * the chunk-replay dedup key. It lives in postmeta, not options, so neither sweep
 * above reaches it - a 500-image demo otherwise leaves 500 dead rows behind.
 */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_sd_edi_source_url' )
);

// Discover any live restore-point shadow tables ({prefix}sd_edi_snap_*). A
// snapshot kept for roll-back at delete time is only dropped on Roll Back or
// Discard, so without this it would orphan a full clone of every table the
// import touched. Names are dynamic (one shadow per snapshotted table), so they
// must be found rather than hard-coded.
$edi_snap_like = $wpdb->esc_like( $wpdb->prefix . 'sd_edi_snap_' ) . '%'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$edi_snap_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $edi_snap_like ) );

// Drop the plugin's tables (taxonomy import tracking + activity log + any live
// restore-point shadow tables).
$edi_tables = array_merge( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	[
		$wpdb->prefix . 'sd_edi_taxonomy_import',
		$wpdb->prefix . 'sd_edi_import_log',
	],
	is_array( $edi_snap_tables ) ? $edi_snap_tables : []
);

foreach ( $edi_tables as $table_name ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	// The table name is never passed through prepare(): a %s placeholder quotes
	// it as a string literal, which makes the statement a syntax error, so the
	// table was never dropped. %i would fix that but needs WP 6.2+, and this
	// plugin supports 5.5. Every name here is built from $wpdb->prefix or comes
	// back from SHOW TABLES, so it is server-side and safe to interpolate.
	// Matches the existing convention in inc/Common/Utils/Snapshot.php:402.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
}

/*
 * Clear the plugin's scheduled cron events.
 *
 * `sd_edi_manual_cleanup` is ManualImport::CLEANUP_HOOK, scheduled daily the
 * first time the manual-import screen loads. Recurring events never self-expire,
 * so one left behind re-fires every day against a callback that no longer exists.
 *
 * wp_clear_scheduled_hook() removes every occurrence regardless of args;
 * wp_next_scheduled() + wp_unschedule_event() only clears the next one.
 */
$edi_cron_hooks = [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	'sd_edi_manual_cleanup',
];

foreach ( $edi_cron_hooks as $edi_hook ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	wp_clear_scheduled_hook( $edi_hook );
}

// Delete the uploads/easy-demo-importer/ staging directory.
$upload_dir = wp_upload_dir(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$edi_dir    = trailingslashit( $upload_dir['basedir'] ) . 'easy-demo-importer'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( is_dir( $edi_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	global $wp_filesystem;

	/*
	 * WP_Filesystem() returns false without populating $wp_filesystem when no
	 * credentials can be resolved - `wp plugin uninstall` via WP-CLI, which does
	 * not pre-boot the filesystem the way delete_plugins() does, or a host with
	 * FS_METHOD set to ftpext/ssh2 and nothing stored. Calling rmdir() on null is
	 * fatal on PHP 8, and it would abort the deletion after every database change
	 * above has already committed. Matches the guard at Finalize.php:207.
	 */
	if ( WP_Filesystem() && $wp_filesystem ) {
		$wp_filesystem->rmdir( $edi_dir, true );
	}
}
