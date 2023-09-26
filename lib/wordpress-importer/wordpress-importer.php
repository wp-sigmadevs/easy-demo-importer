<?php
/**
 * WordPress Importer
 *
 * Import posts, pages, comments, custom fields, categories, tags
 * and more from a WordPress export file.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
	return;
}

/** WordPress Import Administration API */
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';

	if ( file_exists( $class_wp_importer ) ) {
		require $class_wp_importer;
	}
}

/** SD_EDI_WXR_Parser class */
require_once __DIR__ . '/parsers/class-wxr-parser.php';

/** SD_EDI_WXR_Parser_SimpleXML class */
require_once __DIR__ . '/parsers/class-wxr-parser-simplexml.php';

/** SD_EDI_WXR_Parser_XML class */
require_once __DIR__ . '/parsers/class-wxr-parser-xml.php';

/** SD_EDI_WXR_Parser_Regex class */
require_once __DIR__ . '/parsers/class-wxr-parser-regex.php';

/** SD_EDI_WP_Import class */
require_once __DIR__ . '/class-wp-import.php';
