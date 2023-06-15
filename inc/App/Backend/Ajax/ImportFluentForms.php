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

namespace SigmaDevs\EasyDemoImporter\App\Backend\Ajax;

use FluentForm\Framework\Helpers\ArrayHelper;
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
		Helpers::verifyAjaxCall();

		$forms = $this->multiple ? Helpers::keyExists( $this->config['demoData'][ $this->demoSlug ]['fluentFormsJson'] ) : Helpers::keyExists( $this->config['fluentFormsJson'] );

		$formsExists = isset( $forms ) || is_plugin_active( 'fluentform/fluentform.php' );

		if ( $formsExists ) {
			$this->importFluentForms( $forms );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_import_widgets',
			esc_html__( 'Widgets import in progress.', 'easy-demo-importer' ),
			$formsExists ? esc_html__( 'Fluent forms imported.', 'easy-demo-importer' ) : esc_html__( 'Fluent forms not needed.', 'easy-demo-importer' )
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
			$data          = file_get_contents( $formFile );
			$forms         = json_decode( $data, true );
			$insertedForms = [];

			if ( $forms && is_array( $forms ) ) {
				foreach ( $forms as $formItem ) {
					// First of all make the form object.
					$formFields = wp_json_encode( [] );

					if ( $fields = ArrayHelper::get( $formItem, 'form', '' ) ) {
						$formFields = wp_json_encode( $fields );
					} elseif ( $fields = ArrayHelper::get( $formItem, 'form_fields', '' ) ) {
						$formFields = wp_json_encode( $fields );
					} else {
					}

					$form = [
						'title'       => ArrayHelper::get( $formItem, 'title' ),
						'id'          => ArrayHelper::get( $formItem, 'id' ),
						'form_fields' => $formFields,
						'status'      => ArrayHelper::get( $formItem, 'status', 'published' ),
						'has_payment' => ArrayHelper::get( $formItem, 'has_payment', 0 ),
						'type'        => ArrayHelper::get( $formItem, 'type', 'form' ),
						'created_by'  => get_current_user_id(),
					];

					if ( ArrayHelper::get( $formItem, 'conditions' ) ) {
						$form['conditions'] = ArrayHelper::get( $formItem, 'conditions' );
					}

					if ( isset( $formItem['appearance_settings'] ) ) {
						$form['appearance_settings'] = $formItem['appearance_settings'];
					}

					// Insert the form to the DB.
					$formId = wpFluent()->table( 'fluentform_forms' )->insert( $form );

					$insertedForms[ $formId ] = [
						'title'    => $form['title'],
						'edit_url' => admin_url( 'admin.php?page=fluent_forms&route=editor&form_id=' . $formId ),
					];

					if ( isset( $formItem['metas'] ) ) {
						foreach ( $formItem['metas'] as $metaData ) {
							$settings = [
								'form_id'  => $formId,
								'meta_key' => $metaData['meta_key'],
								'value'    => $metaData['value'],
							];

							wpFluent()->table( 'fluentform_form_meta' )->insert( $settings );
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
								$settings = [
									'form_id'  => $formId,
									'meta_key' => $key,
									'value'    => json_encode( $formItem[ $key ] ),
								];

								wpFluent()->table( 'fluentform_form_meta' )->insert( $settings );
							}
						}
					}
				}
			}
		}
	}
}
