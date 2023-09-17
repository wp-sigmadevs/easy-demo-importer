<?php
/**
 * Model Class: Admin Page
 *
 * This class taps into WordPress Settings API to create admin pages.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Models;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Model Class: Admin Page
 *
 * @since 1.0.0
 */
class AdminPage {
	/**
	 * Admin pages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $adminPages = [];

	/**
	 * Admin Sub-Pages.
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $adminSubPages = [];

	/**
	 * Class Constructor.
	 *
	 * Registers Admin Pages.
	 *
	 * @param array $adminPages Admin Pages.
	 * @param array $adminSubPages Admin Sub-Pages.
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public function __construct( $adminPages, $adminSubPages ) {
		$this
			->addPages( $adminPages )
			->addSubPages( $adminSubPages );

		if ( ! empty( $this->adminPages ) || ! empty( $this->adminSubPages ) ) {
			add_action( 'admin_menu', [ $this, 'addAdminMenu' ] );
		}
	}

	/**
	 * Method to add admin pages.
	 *
	 * @param array $pages Admin pages.
	 *
	 * @return AdminPage
	 * @since 1.0.0
	 */
	protected function addPages( array $pages ) {
		$this->adminPages = array_merge( $this->adminPages, $pages );

		return $this;
	}

	/**
	 * Method to add admin sub pages.
	 *
	 * @param array $subPages Admin Sub-Pages.
	 *
	 * @return AdminPage
	 * @since 1.0.0
	 */
	protected function addSubPages( array $subPages ) {
		if ( ! empty( $this->adminPages ) ) {
			foreach ( $this->adminPages as $page ) {
				$this->adminSubPages[] = [
					'parent_slug' => $page['menu_slug'],
					'page_title'  => $page['page_title'],
					'menu_title'  => ( $page['top_menu_title'] ) ? $page['top_menu_title'] : $page['menu_title'],
					'capability'  => $page['capability'],
					'menu_slug'   => $page['menu_slug'],
					'callback'    => $page['callback'],
				];
			}
		}

		if ( ! empty( $this->adminSubPages ) ) {
			foreach ( $this->adminSubPages as $page ) {
				$this->adminSubPages[] = [
					'parent_slug' => $page['menu_slug'],
					'page_title'  => $page['page_title'],
					'menu_title'  => $page['menu_title'],
					'capability'  => $page['capability'],
					'menu_slug'   => $page['menu_slug'],
					'callback'    => $page['callback'],
				];
			}
		}

		$this->adminSubPages = array_merge( $this->adminSubPages, $subPages );

		return $this;
	}

	/**
	 * Method to add admin menu.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function addAdminMenu() {
		foreach ( $this->adminPages as $page ) {
			add_menu_page(
				$page['page_title'],
				$page['menu_title'],
				$page['capability'],
				$page['menu_slug'],
				$page['callback'],
				$page['icon_url'],
				$page['position']
			);
		}

		foreach ( $this->adminSubPages as $page ) {
			add_submenu_page(
				$page['parent_slug'],
				$page['page_title'],
				$page['menu_title'],
				$page['capability'],
				$page['menu_slug'],
				$page['callback']
			);
		}
	}
}
