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
use SigmaDevs\EasyDemoImporter\Common\Utils\NetworkInstaller;
use SigmaDevs\EasyDemoImporter\Common\Utils\UploadSkipCounter;

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

		// Multisite lifecycle.
		add_action( 'wp_initialize_site', [ $this, 'onSiteCreate' ], 10, 2 );
		add_action( 'wp_uninitialize_site', [ $this, 'onSiteDelete' ], 10, 1 );
		add_action( NetworkInstaller::CRON_HOOK, [ NetworkInstaller::class, 'processChunk' ], 10, 1 );

		// Observe attachments skipped by WordPress's MIME guard during WXR import.
		add_action( 'init', [ UploadSkipCounter::class, 'register' ] );

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

	/**
	 * Create the plugin's per-blog table when a new subsite is created.
	 *
	 * @param \WP_Site $newSite The new site object.
	 * @param array    $args    Initialization arguments (unused).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function onSiteCreate( $newSite, $args = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		NetworkInstaller::createTableForBlog( (int) $newSite->blog_id );
	}

	/**
	 * Drop the plugin's per-blog table when a subsite is removed.
	 *
	 * @param \WP_Site $oldSite The site being removed.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function onSiteDelete( $oldSite ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		NetworkInstaller::dropTableForBlog( (int) $oldSite->blog_id );
	}
}
