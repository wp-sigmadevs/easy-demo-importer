<?php
/**
 * Backend Ajax Class: InstallDemo
 *
 * Initializes the demo installation Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SD_EDI_WP_Import;
use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Abstracts\ImporterAjax
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: InstallDemo
 *
 * @since 1.0.0
 */
class InstallDemo extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_xml', [ $this, 'response' ] );
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

		global $wpdb;

		// ── Step-level mutex ────────────────────────────────────────────────────────
		// When the user reloads mid-import, PHP keeps running in the background (output
		// is buffered so the disconnect is never detected).  If the user then resumes,
		// a second request reaches this method while the first is still executing,
		// which causes two parallel XML imports and duplicated content.
		//
		// INSERT IGNORE on wp_options is atomic: MySQL's unique key on option_name
		// guarantees exactly one process gets the row.  The other gets 0 rows affected
		// and must wait before retrying.
		//
		// Stale-lock guard: if the mutex has been held for more than 30 minutes, the
		// original process likely crashed — force-release so the site isn't stuck.
		$mutex_option = 'sd_edi_xml_import_mutex';
		$mutex_ts     = (int) get_option( $mutex_option, 0 );

		if ( $mutex_ts && ( time() - $mutex_ts ) > 30 * MINUTE_IN_SECONDS ) {
			delete_option( $mutex_option );
			$mutex_ts = 0;
		}

		if ( ! $mutex_ts ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$mutex_acquired = (bool) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
					$mutex_option,
					(string) time()
				)
			);
		} else {
			$mutex_acquired = false;
		}

		if ( ! $mutex_acquired ) {
			// Background XML import is still running.  Ask the client to wait 5 s and retry.
			wp_send_json(
				[
					'demo'                  => $this->demoSlug,
					'excludeImages'         => $this->excludeImages,
					'skipImageRegeneration' => $this->skipImageRegeneration,
					'reset'                 => $this->reset,
					'sessionId'             => $this->sessionId,
					'nextPhase'             => 'sd_edi_import_xml',
					'nextPhaseMessage'      => esc_html__( 'Waiting for previous import to finish…', 'easy-demo-importer' ),
					'completedMessage'      => '',
					'error'                 => false,
					'retry'                 => true,
					'retryAfter'            => 5,
				]
			);
			return;
		}

		// ── Register shutdown-based mutex release ───────────────────────────────────
		// IMPORTANT: wp_send_json() calls wp_die() which calls die() / exit().
		// PHP's try/finally blocks do NOT run after die() / exit(), so we cannot use
		// try/finally to release the mutex.  register_shutdown_function() is the
		// correct mechanism — it fires after die(), after PHP timeouts, and after
		// fatal errors, covering every exit path.
		register_shutdown_function(
			static function ( string $opt ) {
				delete_option( $opt );
			},
			$mutex_option
		);

		// ── Run the import ──────────────────────────────────────────────────────────
		$xmlFile    = $this->demoUploadDir( $this->demoDir() ) . '/content.xml';
		$fileExists = file_exists( $xmlFile );

		/**
		 * Action Hook: 'sd/edi/before_import'
		 *
		 * Performs special actions before demo import.
		 *
		 * @since 1.0.0
		 */
		do_action( 'sd/edi/before_import', $xmlFile, $this );

		if ( $fileExists ) {
			// Clear any nav menus left by a previous (possibly partial) run so the
			// importer always starts from a clean slate.
			$this->clearNavMenus();

			$this->importDemoContent( $xmlFile, $this->excludeImages, $this->skipImageRegeneration );
		}

		/**
		 * Action Hook: 'sd/edi/after_content_import'
		 *
		 * Performs special actions after content import.
		 *
		 * @since 1.1.5
		 */
		do_action( 'sd/edi/after_content_import', $xmlFile, $this );

		// Response.
		$this->prepareResponse(
			$fileExists ? 'sd_edi_import_customizer' : '',
			$fileExists ? esc_html__( 'Importing Customizer settings.', 'easy-demo-importer' ) : '',
			$fileExists ? esc_html__( 'Everything has been imported smoothly.', 'easy-demo-importer' ) : '',
			! $fileExists,
			! $fileExists ? esc_html__( 'Demo import process failed. No content file found.', 'easy-demo-importer' ) : '',
		);
	}

	/**
	 * Import demo content.
	 *
	 * @param string $xmlFilePath XML file path.
	 * @param string $excludeImages Exclude images.
	 * @param bool   $skipImageRegeneration Skip image regeneration.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function importDemoContent( $xmlFilePath, $excludeImages, $skipImageRegeneration ) {
		if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
			define( 'SD_EDI_LOAD_IMPORTERS', true );
		}

		if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
			$wpImporter = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

			if ( file_exists( $wpImporter ) ) {
				require_once $wpImporter;
			}
		}

		// Import demo content from XML.
		if ( class_exists( 'SD_EDI_WP_Import' ) ) {
			$excludeImages = ! ( 'true' === $excludeImages );

			if ( file_exists( $xmlFilePath ) ) {
				$wp_import                    = new SD_EDI_WP_Import();
				$wp_import->fetch_attachments = $excludeImages;

				if ( $skipImageRegeneration ) {
					add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
					add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
				}

				// Import XML.
				ob_start();
				$wp_import->import( $xmlFilePath );
				ob_end_clean();

				if ( ! $excludeImages ) {
					$this->unsetThumbnails();
				}

				if ( $skipImageRegeneration ) {
					remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
					remove_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
				}
			}
		}
	}

	/**
	 * Hard-delete all nav menu taxonomy terms and every nav_menu_item post.
	 *
	 * Uses direct SQL instead of the WordPress API so that no WordPress filter,
	 * object cache, or post-status guard can leave rows behind.  Called before
	 * every XML import so that a resume run always starts from a clean slate.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function clearNavMenus() {
		global $wpdb;

		// ── Step 1: collect every nav_menu term ID straight from the DB ──────────
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$term_ids = $wpdb->get_col(
			"SELECT tt.term_id
			 FROM {$wpdb->term_taxonomy} tt
			 WHERE tt.taxonomy = 'nav_menu'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item'"
		);

		if ( ! empty( $term_ids ) ) {
			$term_ids_in = implode( ',', array_map( 'intval', $term_ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ({$term_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id IN ({$term_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE term_id IN ({$term_ids_in})" );
		}

		if ( ! empty( $post_ids ) ) {
			$post_ids_in = implode( ',', array_map( 'intval', $post_ids ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ({$post_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$post_ids_in})" );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$post_ids_in})" );
		}

		// Flush the nav-menu object cache so the importer sees a clean slate.
		wp_cache_delete( 'last_changed', 'nav_menu' );
		clean_term_cache( $term_ids ?: [], 'nav_menu' );
	}

	/**
	 * Unset featured images from posts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function unsetThumbnails() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE meta_key = %s", '_thumbnail_id' )
		);
	}
}
