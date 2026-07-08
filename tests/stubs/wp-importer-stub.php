<?php
/**
 * Minimal parent-class stubs for unit tests.
 *
 * ChunkedImport extends the vendored SD_EDI_WP_Import, which extends WordPress
 * core's WP_Importer. Under Brain Monkey there is no WordPress, so we define a
 * hollow WP_Importer and load the real vendored importer class. The vendored
 * class only calls WP functions inside method bodies — the unit tests exercise
 * only ChunkedImport's own additions (the import_start override + state
 * persistence), which never reach the parent's WP-dependent methods.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

if ( ! class_exists( 'WP_Importer' ) ) {
	// phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
	class WP_Importer {}
}

require_once dirname( __DIR__, 2 ) . '/lib/wordpress-importer/class-wp-import.php';
