<?php
/**
 * Plugin Uninstall.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * On multisite, cleans up every blog and removes network options.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

global $wpdb;

/**
 * Per-blog cleanup. Runs in whichever blog context is current.
 *
 * @return void
 */
function sd_edi_uninstall_current_blog() {
	global $wpdb;

	// Delete sd_edi_* options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sd\\_edi\\_%'" );

	// Delete sd_edi_* transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sd\\_edi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sd\\_edi\\_%'"
	);

	// Drop per-blog table.
	$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	// Remove staging dir.
	$upload  = wp_upload_dir();
	$edi_dir = trailingslashit( $upload['basedir'] ) . 'easy-demo-importer';
	if ( is_dir( $edi_dir ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			if ( ! empty( $wp_filesystem ) && is_object( $wp_filesystem ) ) {
				$wp_filesystem->rmdir( $edi_dir, true );
			}
		}
	}

	// Per-blog cron.
	foreach ( [ 'sd_edi_import_cron' ] as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}

// phpcs:ignore -- Intentional: uninstall.php loads in a minimal WP context where is_multisite() may not be reliable.
if ( defined( 'MULTISITE' ) && MULTISITE ) { // @phpstan-ignore-line phpstanWP.wpConstant.fetch
	$ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);
	foreach ( $ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		try {
			sd_edi_uninstall_current_blog();
		} finally {
			restore_current_blog();
		}
	}

	// Network-wide cron.
	foreach ( [ 'sd_edi_network_install_chunk' ] as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}

	// Network options.
	delete_site_option( 'sd_edi_network_config' );
	delete_site_option( 'sd_edi_network_override_enabled' );
	delete_site_option( 'sd_edi_network_settings' );
	delete_site_option( 'sd_edi_network_status' );
} else {
	sd_edi_uninstall_current_blog();
}
