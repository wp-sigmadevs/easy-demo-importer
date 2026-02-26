<?php
/**
 * Abstract Class: Importer Ajax
 *
 * This abstract class is responsible for ajax import functionalities.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Abstracts;

use SigmaDevs\EasyDemoImporter\Common\Functions\Helpers;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Abstract Class: Importer Ajax
 *
 * @since 1.0.0
 */
abstract class ImporterAjax {
	/**
	 * Theme demo config.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $config = [];

	/**
	 * Ajax response.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $response = [];

	/**
	 * Demo slug.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $demoSlug = '';

	/**
	 * Exclude images.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $excludeImages = '';

	/**
	 * Skip image regeneration.
	 *
	 * @var bool
	 * @since 1.2.0
	 */
	public $skipImageRegeneration = false;

	/**
	 * Multiple Zip check.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $multiple = false;

	/**
	 * Uploads directory.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $uploadsDir;

	/**
	 * Database reset.
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	public $reset;

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
		// Verify if the user has the correct role.
		Helpers::verifyUserRole();

		// Handle the Post submission.
		$this->handlePostSubmission();
	}

	/**
	 * Handles post submission.
	 *
	 * @return void
	 */
	protected function handlePostSubmission() {
		// Theme config.
		$this->config = sd_edi()->getDemoConfig();

		if ( empty( $this->config ) ) {
			return;
		}

		// Uploads Directory.
		$this->uploadsDir = wp_get_upload_dir();

		// Check if multiple demo is configured.
		$this->multiple = ! empty( $this->config['multipleZip'] ) ? $this->config['multipleZip'] : false;

		// Demo slug.
		$this->demoSlug = $this->getDemoSlug();

		if ( check_ajax_referer( Helpers::nonceText(), Helpers::nonceId() ) ) {
			// Check if images import is needed.
			$this->excludeImages = ! empty( $_POST['excludeImages'] ) ? sanitize_text_field( wp_unslash( $_POST['excludeImages'] ) ) : '';

			// Check if image regeneration should be skipped.
			$this->skipImageRegeneration = isset( $_POST['skipImageRegeneration'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['skipImageRegeneration'] ) );

			// Check if database reset needed.
			$this->reset = isset( $_POST['reset'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['reset'] ) );
		}
	}

	/**
	 * Get demo slug.
	 *
	 * @return int|string|null
	 *
	 * @since 1.0.0
	 */
	private function getDemoSlug() {
		if ( ! check_ajax_referer( Helpers::nonceText(), Helpers::nonceId() ) ) {
			return '';
		}

		$firstDemoSlug = ! empty( $this->config['demoData'] ) ? array_key_first( $this->config['demoData'] ) : '';

		if ( empty( $_POST['demo'] ) ) {
			return $firstDemoSlug;
		}

		return sanitize_text_field( wp_unslash( $_POST['demo'] ) );
	}

	/**
	 * Prepare ajax response.
	 *
	 * @param string $nextPhase Next phase.
	 * @param string $nextPhaseMessage Next phase message.
	 * @param string $complete Completed message.
	 * @param bool   $error Error.
	 * @param string $errorMessage Error message.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function prepareResponse( $nextPhase, $nextPhaseMessage, $complete = '', $error = false, $errorMessage = '' ) {
		$this->response = [
			'demo'                  => $this->demoSlug,
			'excludeImages'         => $this->excludeImages,
			'skipImageRegeneration' => $this->skipImageRegeneration,
			'reset'                 => $this->reset,
			'nextPhase'             => $nextPhase,
			'nextPhaseMessage'      => $nextPhaseMessage,
			'completedMessage'      => $complete,
			'error'                 => $error,
			'errorMessage'          => $errorMessage,
		];

		$this->sendResponse();
	}

	/**
	 * Send ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function sendResponse() {
		wp_send_json( $this->response );
	}

	/**
	 * Demo upload path.
	 *
	 * @param string $path Path.
	 *
	 * @return string|void
	 * @since 1.0.0
	 */
	public function demoUploadDir( $path = '' ) {
		if ( is_array( $this->uploadsDir ) && isset( $this->uploadsDir['basedir'] ) ) {
			return $this->uploadsDir['basedir'] . '/easy-demo-importer/' . $path;
		}
	}

	/**
	 * Demo directory.
	 *
	 * @return array|string
	 * @since 1.0.0
	 */
	public function demoDir() {
		$demoZip = $this->multiple ? Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'demoZip' ) : Helpers::getDemoData( $this->config, 'demoZip' );

		return pathinfo( basename( $demoZip ), PATHINFO_FILENAME );
	}

	/**
	 * Check if plugin is active or not.
	 *
	 * @param string $path Plugin path.
	 *
	 * @return string
	 */
	protected function pluginStatus( $path ) {
		$status = 'install';

		$pluginPath = WP_PLUGIN_DIR . '/' . $path;

		if ( file_exists( $pluginPath ) ) {
			$status = is_plugin_active( $path ) ? 'active' : 'inactive';

			require_once ABSPATH . 'wp-admin/includes/update.php';

			$update_list = get_site_transient( 'update_plugins' );

			if ( isset( $update_list->response[ $path ] ) ) {
				$status = 'update';
			}
		}

		return $status;
	}

	/**
	 * Ajax response.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	abstract public function response();
}
