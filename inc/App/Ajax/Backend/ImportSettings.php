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

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

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
		// Verifying AJAX call and user role.
		Helpers::verifyAjaxCall();

		$settings = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'settingsJson', 'array' ) :
			Helpers::getDemoData( $this->config, 'settingsJson', 'array' );

		$settingsExists = isset( $settings ) && is_array( $settings );

		if ( $settingsExists ) {
			foreach ( $settings as $option ) {
				$optionFile = $this->demoUploadDir( $this->demoDir() ) . '/' . $option . '.json';
				$fileExists = file_exists( $optionFile );

				if ( $fileExists ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$data = file_get_contents( $optionFile );

					if ( $data ) {
						update_option( $option, json_decode( $data, true ) );
					}
				}
			}
		}

		$forms = $this->multiple ? Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'fluentFormsJson' ) : Helpers::getDemoData( $this->config, 'fluentFormsJson' );

		$formsExists = isset( $forms ) || is_plugin_active( 'fluentform/fluentform.php' );

		$formsFileExists = false;

		if ( $formsExists ) {
			$formFile        = $this->demoUploadDir( $this->demoDir() ) . '/' . $forms . '.json';
			$formsFileExists = file_exists( $formFile );
		}

		// Response.
		$this->prepareResponse(
			$formsFileExists ? 'sd_edi_import_fluent_forms' : 'sd_edi_import_widgets',
			$formsFileExists ? esc_html__( 'Importing Fluent forms.', 'easy-demo-importer' ) : esc_html__( 'Importing all widgets', 'easy-demo-importer' ),
			$settingsExists ? esc_html__( 'Theme settings are all set.', 'easy-demo-importer' ) : esc_html__( 'No theme settings import needed.', 'easy-demo-importer' )
		);
	}
}
