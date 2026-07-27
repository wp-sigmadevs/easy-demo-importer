<?php
/**
 * Plugin Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * WordPress calls this file automatically if it exists in the plugin root.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

// Do not allow directly accessing this file.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

global $wpdb;

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

// Discover any live restore-point shadow tables ({prefix}sd_edi_snap_*). A
// snapshot kept for roll-back at delete time is only dropped on Roll Back or
// Discard, so without this it would orphan a full clone of every table the
// import touched. Names are dynamic (one shadow per snapshotted table), so they
// must be found rather than hard-coded.
$edi_snap_like = $wpdb->esc_like( $wpdb->prefix . 'sd_edi_snap_' ) . '%'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$edi_snap_tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $edi_snap_like ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %1$s', $table_name ) );
}

// Clear any scheduled cron events registered by the plugin.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$cron_hooks = [
	'sd_edi_import_cron',
];

foreach ( $cron_hooks as $hook ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	$timestamp = wp_next_scheduled( $hook ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}

// Delete the uploads/easy-demo-importer/ staging directory.
$upload_dir = wp_upload_dir(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$edi_dir    = trailingslashit( $upload_dir['basedir'] ) . 'easy-demo-importer'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( is_dir( $edi_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();

	global $wp_filesystem;

	$wp_filesystem->rmdir( $edi_dir, true );
}
