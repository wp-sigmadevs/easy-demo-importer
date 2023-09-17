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
	Traits\Singleton
};
use SigmaDevs\EasyDemoImporter\Common\Functions\
{
	Actions,
	Filters
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
		add_action( 'init', [ Actions::class, 'rewriteFlushCheck' ] );
		add_action( 'sd/edi/importer_init', [ Actions::class, 'beforeImportActions' ] );
		add_action( 'sd/edi/after_plugin_activation', [ Actions::class, 'pluginActivationActions' ] );
		add_action( 'sd/edi/after_import', [ Actions::class, 'afterImportActions' ] );

		return $this;
	}

	/**
	 * List of filter hooks
	 *
	 * @return Hooks
	 */
	public function filters() {
		add_filter( 'upload_mimes', [ Filters::class, 'supportedFileTypes' ] );

		return $this;
	}
}
