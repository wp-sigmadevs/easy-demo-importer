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

		// Block known sensitive core options that must never be overwritten by demo data.
		$blocked_options = [
			'siteurl',
			'home',
			'admin_email',
			'default_role',
			'active_plugins',
			'blogname',
			'blogdescription',
			'users_can_register',
			'default_comment_status',
			'permalink_structure',
			'template',
			'stylesheet',
			'db_version',
			'cron',
			'rewrite_rules',
			'auth_key',
			'auth_salt',
			'logged_in_key',
			'logged_in_salt',
			'nonce_key',
			'nonce_salt',
			'sidebars_widgets',
		];

		// Manual import: an optional settings.json holding a flat
		// { option_name: value } map. Importing arbitrary options is powerful, so
		// the same blocklist applies plus a guard on any *user_roles capability
		// map — a hostile file must not be able to change site URLs, active
		// plugins, the default role, or grant capabilities.
		$manualSettingsImported = false;

		if ( $this->manual ) {
			$manualFile = $this->demoUploadDir( $this->demoDir() ) . '/settings.json';

			if ( file_exists( $manualFile ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = file_get_contents( $manualFile );
				$map = is_string( $raw ) ? json_decode( $raw, true ) : null;

				if ( is_array( $map ) ) {
					foreach ( $map as $option => $value ) {
						$option = (string) $option;

						if ( '' === $option
							|| in_array( $option, $blocked_options, true )
							|| 'user_roles' === substr( $option, -10 ) ) {
							continue;
						}

						update_option( $option, $value );
						$manualSettingsImported = true;
					}
				}
			}
		}

		if ( $settingsExists ) {
			foreach ( $settings as $option ) {
				// Skip blocked core options.
				if ( in_array( $option, $blocked_options, true ) ) {
					continue;
				}

				// Strip directory separators from theme-config value before building path.
				$option     = basename( $option );
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
			// Strip directory separators from the theme-config value before building a path.
			$forms           = basename( $forms );
			$formFile        = $this->demoUploadDir( $this->demoDir() ) . '/' . $forms . '.json';
			$formsFileExists = file_exists( $formFile );
		}

		// Response. Friendlier text in the modal; the log keeps the neutral
		// equivalent.
		$this->prepareResponse(
			$formsFileExists ? 'sd_edi_import_fluent_forms' : 'sd_edi_import_widgets',
			$formsFileExists ? esc_html__( 'Importing Fluent Forms.', 'easy-demo-importer' ) : esc_html__( 'Importing all widgets.', 'easy-demo-importer' ),
			( $settingsExists || $manualSettingsImported ) ? esc_html__( 'Theme settings are all set!', 'easy-demo-importer' ) : esc_html__( 'No theme settings import needed.', 'easy-demo-importer' ),
			false,
			'',
			'',
			( $settingsExists || $manualSettingsImported ) ? esc_html__( 'Theme settings imported.', 'easy-demo-importer' ) : null
		);
	}
}
