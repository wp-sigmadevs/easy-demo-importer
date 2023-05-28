<?php
/**
 * Backend Ajax Class: ImportMenus
 *
 * Initializes the menu import Process.
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
 * Backend Ajax Class: ImportMenus
 *
 * @since 1.0.0
 */
class ImportMenus extends ImporterAjax {
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

		add_action( 'wp_ajax_sd_edi_import_menus', [ $this, 'response' ] );
	}

	/**
	 * Ajax response.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function response() {
		Helpers::verifyAjaxCall();

		$menus = $this->multiple ? Helpers::keyExists( $this->config['demoData'][ $this->demoSlug ]['menus'], 'array' ) : Helpers::keyExists( $this->config['menus'], 'array' );

		if ( $menus ) {
			$this->setMenu( $menus );
		}

		// Response.
		$this->prepareResponse(
			'sd_edi_import_settings',
			esc_html__( 'Importing settings', 'easy-demo-importer' ),
			$menus ? esc_html__( 'Menus saved', 'easy-demo-importer' ) : esc_html__( 'No menus found', 'easy-demo-importer' )
		);
	}

	/**
	 * Set menus.
	 *
	 * @param array $menus Menu array.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function setMenu( $menus ) {
		if ( empty( $menus ) ) {
			return;
		}

		$locations = get_theme_mod( 'nav_menu_locations' );

		foreach ( $menus as $menuId => $menuName ) {
			$menuExists = wp_get_nav_menu_object( $menuName );

			if ( ! $menuExists ) {
				$menuTermId = wp_create_nav_menu( $menuName );
			} else {
				$menuTermId = $menuExists->term_id;
			}

			$locations[ $menuId ] = $menuTermId;
		}

		set_theme_mod( 'nav_menu_locations', $locations );
	}
}
