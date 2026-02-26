<?php
/**
 * Backend Ajax Class: ImportFluentForms
 *
 * Initializes the Fluent Forms import Process.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use FluentForm\App\Models\Form;
use FluentForm\App\Models\FormMeta;
use FluentForm\Framework\Support\Arr;
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
 * Backend Ajax Class: ImportFluentForms
 *
 * @since 1.0.0
 */
class ImportFluentForms extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_fluent_forms', [ $this, 'response' ] );
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

		$forms = $this->multiple ?
			Helpers::getDemoData( $this->config['demoData'][ $this->demoSlug ], 'fluentFormsJson' ) :
			Helpers::getDemoData( $this->config, 'fluentFormsJson' );

		$formsExists = isset( $forms ) || is_plugin_active( 'fluentform/fluentform.php' );

		if ( $formsExists ) {
			$this->importFluentForms( $forms );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_import_widgets',
			esc_html__( 'Importing all widgets.', 'easy-demo-importer' ),
			$formsExists ? esc_html__( 'Fluent Forms successfully imported.', 'easy-demo-importer' ) : esc_html__( 'No Fluent Forms found. Skipping.', 'easy-demo-importer' )
		);
	}

	/**
	 * Fluent Form imports.
	 *
	 * @param string $form Form JSON.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function importFluentForms( $form ) {
		$formFile   = $this->demoUploadDir( $this->demoDir() ) . '/' . $form . '.json';
		$fileExists = file_exists( $formFile );

		if ( $fileExists ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data          = file_get_contents( $formFile );
			$forms         = json_decode( $data, true );
			$insertedForms = [];

			if ( $forms && is_array( $forms ) ) {
				foreach ( $forms as $formItem ) {
					$formFields = wp_json_encode( [] );

					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
					if ( $fields = Arr::get( $formItem, 'form', '' ) ) {
						$formFields = wp_json_encode( $fields );
					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found, Squiz.PHP.DisallowMultipleAssignments.FoundInControlStructure
					} elseif ( $fields = Arr::get( $formItem, 'form_fields', '' ) ) {
						$formFields = wp_json_encode( $fields );
					}

					$form = [
						'title'       => Arr::get( $formItem, 'title' ),
						'form_fields' => $formFields,
						'status'      => Arr::get( $formItem, 'status', 'published' ),
						'has_payment' => Arr::get( $formItem, 'has_payment', 0 ),
						'type'        => Arr::get( $formItem, 'type', 'form' ),
						'created_by'  => get_current_user_id(),
					];

					if ( Arr::get( $formItem, 'conditions' ) ) {
						$form['conditions'] = Arr::get( $formItem, 'conditions' );
					}

					if ( isset( $formItem['appearance_settings'] ) ) {
						$form['appearance_settings'] = Arr::get( $formItem, 'appearance_settings' );
					}

					$formId                   = Form::insertGetId( $form );
					$insertedForms[ $formId ] = [
						'title'    => $form['title'],
						'edit_url' => admin_url( 'admin.php?page=fluent_forms&route=editor&form_id=' . $formId ),
					];

					if ( isset( $formItem['metas'] ) ) {
						foreach ( $formItem['metas'] as $metaData ) {
							$settings = [
								'form_id'  => $formId,
								// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
								'meta_key' => Arr::get( $metaData, 'meta_key' ),
								'value'    => Arr::get( $metaData, 'value' ),
							];

							FormMeta::insert( $settings );
						}
					} else {
						$oldKeys = [
							'formSettings',
							'notifications',
							'mailchimp_feeds',
							'slack',
						];

						foreach ( $oldKeys as $key ) {
							if ( isset( $formItem[ $key ] ) ) {
								FormMeta::persist( $formId, $key, wp_json_encode( Arr::get( $formItem, $key ) ) );
							}
						}
					}
				}
			}
		}
	}
}
