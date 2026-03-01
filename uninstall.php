<?php
/**
 * Plugin Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * WordPress calls this file automatically if it exists in the plugin root.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
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

// Drop the taxonomy import tracking table.
$table_name = $wpdb->prefix . 'sd_edi_taxonomy_import';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %1$s', $table_name ) );

// Clear any scheduled cron events registered by the plugin.
$cron_hooks = [
	'sd_edi_import_cron',
];

foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
	}
}

// Delete the uploads/easy-demo-importer/ staging directory.
$upload_dir   = wp_upload_dir();
$edi_dir      = trailingslashit( $upload_dir['basedir'] ) . 'easy-demo-importer';

if ( is_dir( $edi_dir ) ) {
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();

	global $wp_filesystem;

	$wp_filesystem->rmdir( $edi_dir, true );
}
