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

if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
	return;
}

/** Display verbose errors */
define( 'IMPORT_DEBUG', false );

/** WordPress Import Administration API */
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';

	if ( file_exists( $class_wp_importer ) ) {
		require $class_wp_importer;
	}
}

/** Functions missing in older WordPress versions. */
require_once dirname( __FILE__ ) . '/compat.php';

/** SD_EDI_WXR_Parser class */
require_once dirname( __FILE__ ) . '/parsers/class-wxr-parser.php';

/** SD_EDI_WXR_Parser_SimpleXML class */
require_once dirname( __FILE__ ) . '/parsers/class-wxr-parser-simplexml.php';

/** SD_EDI_WXR_Parser_XML class */
require_once dirname( __FILE__ ) . '/parsers/class-wxr-parser-xml.php';

/** SD_EDI_WXR_Parser_Regex class */
require_once dirname( __FILE__ ) . '/parsers/class-wxr-parser-regex.php';

/** SD_EDI_WP_Import class */
require_once dirname( __FILE__ ) . '/class-wp-import.php';
