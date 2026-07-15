<?php
/**
 * Bootstrap for the Brain Monkey unit test suite.
 *
 * These tests run WITHOUT WordPress core or a database — WP functions are mocked
 * per-test with Brain Monkey. This bootstrap only needs the Composer autoloader
 * and a couple of parent-class stubs.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

// Plugin source files guard on ABSPATH before defining anything; define it so
// the autoloader can load them under test.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Parent-class stubs so ChunkedImport (extends SD_EDI_WP_Import extends
// WP_Importer) can be instantiated without loading WordPress core.
require_once __DIR__ . '/stubs/wp-importer-stub.php';
