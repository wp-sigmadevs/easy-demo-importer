<?php
/**
 * General Class: Hooks.
 *
 * This class initializes all the Action & Filter Hooks.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

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
 * General Class: Actions Hooks.
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
		add_action( 'init', [ Actions::class, 'testAction' ] );

		return $this;
	}

	/**
	 * List of filter hooks
	 *
	 * @return Hooks
	 */
	public function filters() {
		add_filter( 'my_plugin_boilerplate_post_type_title', [ Filters::class, 'testFilter' ], 99 );

		return $this;
	}
}
