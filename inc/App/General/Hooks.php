<?php
/**
 * General Class: Hooks.
 *
 * This class initializes all the Action & Filter Hooks.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\General;

use SigmaDevs\EasyDemoImporter\Common\
{
	Abstracts\Base,
	Traits\Singleton,
	Functions\Actions,
	Functions\Filters
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * General Class: Hooks.
 *
 * @since 1.0.0
 */
class Hooks extends Base {

	/**
	 * Singleton Trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This general class is always being instantiated as requested in the
	 * Bootstrap class
	 *
	 * @return void
	 * @see Bootstrap::registerServices
	 *
	 * @since 1.0.0
	 */
	public function register() {
		$this
			->actions()
			->filters();
	}

	/**
	 * List of action hooks
	 *
	 * @return Hooks
	 */
	public function actions() {
		// Check the rewrite flush.
		add_action( 'init', [ Actions::class, 'rewriteFlushCheck' ] );

		// Actions during importer initialization.
		add_action( 'sd/edi/importer_init', [ Actions::class, 'initImportActions' ] );

		// Actions during plugins activation.
		add_action( 'sd/edi/after_plugin_activation', [ Actions::class, 'pluginActivationActions' ] );

		// Actions before import.
		add_action( 'sd/edi/before_import', [ Actions::class, 'beforeImportActions' ] );

		// Actions after import.
		add_action( 'sd/edi/after_import', [ Actions::class, 'afterImportActions' ] );

		return $this;
	}

	/**
	 * List of filter hooks
	 *
	 * @return void
	 */
	public function filters() {
		// Add SVG file support.
		add_filter( 'upload_mimes', [ Filters::class, 'supportedFileTypes' ] );

		// Sanitize the SVG file before it is uploaded to the server.
		add_filter( 'wp_handle_upload_prefilter', [ Filters::class, 'sanitizeSVG' ] );

		// Fix WordPress MIME type detection for SVG files.
		add_filter( 'wp_check_filetype_and_ext', [ Filters::class, 'fixSVGDetection' ], 10, 3 );
	}
}
