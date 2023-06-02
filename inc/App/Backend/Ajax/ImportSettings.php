<?php
/**
 * Backend Ajax Class: ImportSettings
 *
 * Initializes the settings import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\ImporterAjax;
use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Ajax Class: ImportSettings
 *
 * @since 1.0.0
 */
class ImportSettings extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_settings', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		$settings = $this->multiple ? Helpers::keyExists( $this->config['demoData'][ $this->demoSlug ]['settingsJson'] ) : Helpers::keyExists( $this->config['settingsJson'] );

		$settingsExists = isset( $settings ) && is_array( $settings );

		if ( $settingsExists ) {
			foreach ( $settings as $option ) {
				$optionFile = $this->demoUploadDir( $this->demoDir() ) . '/' . $option . '.json';
				$fileExists = file_exists( $optionFile );

				if ( $fileExists ) {
					$data = file_get_contents( $optionFile );

					if ( $data ) {
						update_option( $option, json_decode( $data, true ) );
					}
				}
			}
		}

		// TODO: Need to check form file before giving response.
		$forms = $this->multiple ? Helpers::keyExists( $this->config['demoData'][ $this->demoSlug ]['fluentFormsJson'] ) : Helpers::keyExists( $this->config['fluentFormsJson'] );

		$formsExists = isset( $forms ) || is_plugin_active( 'fluentform/fluentform.php' );

		// Response.
		$this->prepareResponse(
			$formsExists ? 'sd_edi_import_fluent_forms' : 'sd_edi_import_widgets',
			$formsExists ? esc_html__( 'Importing Fluent forms', 'easy-demo-importer' ) : esc_html__( 'Importing widgets', 'easy-demo-importer' ),
			$settingsExists ? esc_html__( 'Theme settings imported', 'easy-demo-importer' ) : esc_html__( 'Settings import not needed', 'easy-demo-importer' )
		);
	}
}
