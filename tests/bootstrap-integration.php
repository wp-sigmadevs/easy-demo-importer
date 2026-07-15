<?php
/**
 * Bootstrap for the WordPress integration test suite (WP_UnitTestCase).
 *
 * Requires a WordPress test-suite install and a test database. Point WP_TESTS_DIR
 * at a wordpress-develop/tests/phpunit checkout (or set WP_PHPUNIT__DIR when using
 * the wp-phpunit package). See tests/README.md for setup.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir && getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WordPress test suite not found at {$_tests_dir}.\n" .
		"Set WP_TESTS_DIR or run the install script — see tests/README.md.\n"
	);
	exit( 1 );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

require_once $_tests_dir . '/includes/functions.php';

// Load this plugin (and the bundled WXR importer) into the test WordPress.
tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/easy-demo-importer.php';
	}
);

require $_tests_dir . '/includes/bootstrap.php';
