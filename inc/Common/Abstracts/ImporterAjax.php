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

		// Theme config.
		$this->config = sd_edi()->getDemoConfig();

		// Uploads Directory.
		$this->uploadsDir = wp_get_upload_dir();

		// Check if multiple demo is configured.
		$this->multiple = ! empty( $this->config['multipleZip'] ) ? $this->config['multipleZip'] : false;

		// First demo slug.
		$firstDemoSlug = array_key_first( $this->config['demoData'] );

		// Demo slug.
		$this->demoSlug = ! empty( $_POST['demo'] ) ? $this->multiple ? sanitize_text_field( wp_unslash( $_POST['demo'] ) ) : $firstDemoSlug : $firstDemoSlug;

		// Check if images import is needed.
		$this->excludeImages = ! empty( $_POST['excludeImages'] ) ? sanitize_text_field( wp_unslash( $_POST['excludeImages'] ) ) : '';

		// Check if database reset needed.
		$this->reset = isset( $_POST['reset'] ) && 'true' === $_POST['reset'];
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
			'demo'             => $this->demoSlug,
			'excludeImages'    => $this->excludeImages,
			'nextPhase'        => $nextPhase,
			'nextPhaseMessage' => $nextPhaseMessage,
			'completedMessage' => $complete,
			'error'            => $error,
			'errorMessage'     => $errorMessage,
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
		$json = wp_json_encode( $this->response );

		wp_send_json( $json );
	}

	/**
	 * Demo upload path.
	 *
	 * @param string $path Path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function demoUploadDir( $path = '' ) {
		return $this->uploadsDir['basedir'] . '/easy-demo-importer/' . $path;
	}

	/**
	 * Before import action.
	 *
	 * @param string $xml Demo XML file.
	 * @param string $excludeImages $exclude images.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function beforeImportActions( $xml, $excludeImages ) {
		do_action( 'sd/edi/before/import', $xml, $excludeImages );
	}

	/**
	 * After import action.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function afterImportActions() {
		do_action( 'sd/edi/after/import' );
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
