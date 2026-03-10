<?php
/**
 * Backend Ajax Class: Finalize
 *
 * Finalizes the demo import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Functions\SessionManager,
	Abstracts\ImporterAjax
};
use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: CustomizerImport
 *
 * @since 1.0.0
 */
class Finalize extends ImporterAjax {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		parent::register();

		add_action( 'wp_ajax_sd_edi_finalize_demo', array( $this, 'response' ) );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		/**
		 * Action Hook: 'sd/edi/after_import'
		 *
		 * Performs special actions after demo import.
		 *
		 * @hooked SigmaDevs\EasyDemoImporter\Common\Functions\Actions::afterImportActions 10
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/after_import', $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// Resetting permalink.
		flush_rewrite_rules();

		// Release the import session lock.
		if ( ! empty( $this->sessionId ) ) {
			SessionManager::release( $this->sessionId );
		}

		// Flush known caches after import.
		$this->flushCaches();

		// Response.
		$this->prepareResponse(
			'',
			'',
			esc_html__( 'Hooray! You are all set! Now go out and have a blast!', 'easy-demo-importer' )
		);
	}

	/**
	 * Flush known caching plugins and Elementor after import.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	private function flushCaches(): void {
		$flushed = array();

		$handlers = apply_filters( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'sd/edi/flush_caches',
			array(
				'wp_super_cache'  => static function () {
					if ( function_exists( 'wp_cache_clear_cache' ) ) {
						wp_cache_clear_cache();
						return true;
					}
					return false;
				},
				'w3_total_cache'  => static function () {
					if ( function_exists( 'w3tc_flush_all' ) ) {
						w3tc_flush_all();
						return true;
					}
					return false;
				},
				'litespeed_cache' => static function () {
					if ( class_exists( 'LiteSpeed\Purge' ) || function_exists( 'litespeed_purge_all' ) ) {
						do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						return true;
					}
					return false;
				},
				'wp_rocket'       => static function () {
					if ( function_exists( 'rocket_clean_domain' ) ) {
						rocket_clean_domain();
						return true;
					}
					return false;
				},
				'elementor_css'   => static function () {
					if ( class_exists( '\Elementor\Plugin' ) ) {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return true;
					}
					return false;
				},
				'woocommerce'     => static function () {
					if ( function_exists( 'wc_delete_product_transients' ) ) {
						wc_delete_product_transients();
						return true;
					}
					return false;
				},
				'wp_object_cache' => static function () {
					wp_cache_flush();
					return true;
				},
			)
		);

		foreach ( $handlers as $name => $handler ) {
			if ( is_callable( $handler ) && $handler() ) {
				$flushed[] = $name;
			}
		}

		if ( ! empty( $flushed ) && ! empty( $this->sessionId ) ) {
			ImportLogger::log(
				sprintf(
					/* translators: %s: comma-separated list of cache systems flushed */
					__( 'Caches cleared: %s', 'easy-demo-importer' ),
					implode( ', ', $flushed )
				),
				'success',
				$this->sessionId
			);
		}
	}
}
