<?php
/**
 * Backend Ajax Class: Initialize
 *
 * Initializes Demo Import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Functions\Helpers,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: Initialize
 *
 * @since 1.0.0
 */
class Initialize extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_install_demo', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		// Resetting database.
		if ( $this->reset ) {
			$this->databaseReset();
		}

		// Start Importer Hook.
		do_action( 'sd/edi/importer_init', $this );

		// Response.
		$this->prepareResponse(
			'sd_edi_install_plugins',
			esc_html__( 'Plugins installation in progress.', 'easy-demo-importer' ),
			( $this->reset ) ? esc_html__( 'Database reset completed.', 'easy-demo-importer' ) : esc_html__( 'Minor cleanups done.', 'easy-demo-importer' )
		);
	}

	/**
	 * Database reset.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function databaseReset() {
		global $wpdb;

		$coreTables    = [
			'commentmeta',
			'comments',
			'links',
			'postmeta',
			'posts',
			'term_relationships',
			'term_taxonomy',
			'termmeta',
			'terms',
		];
		$excludeTables = [ 'options', 'usermeta', 'users' ];

		$coreTables = array_map(
			function ( $tbl ) {
				global $wpdb;

				return $wpdb->prefix . $tbl;
			},
			$coreTables
		);

		$excludeTables = array_map(
			function ( $tbl ) {
				global $wpdb;

				return $wpdb->prefix . $tbl;
			},
			$excludeTables
		);

		$customTables = [];
		$tableStatus  = $wpdb->get_results( 'SHOW TABLE STATUS' );

		if ( is_array( $tableStatus ) ) {
			foreach ( $tableStatus as $index => $table ) {
				if ( 0 !== stripos( $table->Name, $wpdb->prefix ) ) {
					continue;
				}

				if ( empty( $table->Engine ) ) {
					continue;
				}

				if ( false === in_array( $table->Name, $coreTables ) && false === in_array( $table->Name, $excludeTables ) ) {
					$customTables[] = $table->Name;
				}
			}
		}

		$customTables = array_merge( $coreTables, $customTables );

		foreach ( $customTables as $tbl ) {
			$wpdb->query( 'SET foreign_key_checks = 0' );
			$wpdb->query( 'TRUNCATE TABLE ' . $tbl );
		}

		// Delete Widgets.
		Helpers::deleteWidgets();

		// Delete ThemeMods.
		Helpers::deleteThemeMods();

		// Clear "uploads" folder.
		$this->clearUploads( $this->uploadsDir['basedir'] );
	}

	/**
	 * Clear folder
	 *
	 * @param string $dir Directory to clean.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function clearUploads( $dir ) {
		$files = array_diff( scandir( $dir ), [ '.', '..' ] );

		foreach ( $files as $file ) {
			( is_dir( "$dir/$file" ) ) ? $this->clearUploads( "$dir/$file" ) : unlink( "$dir/$file" );
		}

		return ! ( $dir !== $this->uploadsDir['basedir'] ) || rmdir( $dir );
	}
}
