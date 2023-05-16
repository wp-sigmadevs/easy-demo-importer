<?php
/**
 * Backend Class: Pages
 *
 * This class creates the necessary admin pages.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend;

use SigmaDevs\EasyDemoImporter\Common\Models\AdminPage;
use SigmaDevs\EasyDemoImporter\Common\Functions\Callbacks;
use SigmaDevs\EasyDemoImporter\Common\
{
	Abstracts\Base,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Class: Pages
 *
 * @since 1.0.0
 */
class Pages extends Base {

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
	 * @see Requester::isAdminBackend()
	 * @see Bootstrap::registerServices
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register() {
		$this
			->setpage()
			->removePageNotices();
	}

	/**
	 * Set Page.
	 *
	 * @return $this
	 * @since 1.0.0
	 */
	public function setPage() {
		new AdminPage(
			[],
			$this->setSubPages()
		);

		return $this;
	}

	/**
	 * Method to accumulate admin pages list.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function setSubPages() {
		return [
			[
				'parent_slug' => 'themes.php',
				'page_title'  => __( 'Easy Demo Importer', 'easy-demo-importer' ),
				'menu_title'  => __( 'Install Demo Content', 'easy-demo-importer' ),
				'capability'  => 'manage_options',
				'menu_slug'   => 'sd-easy-demo-importer',
				'callback'    => [ Callbacks::class, 'renderDemoImportPage' ],
			],
		];
	}

	/**
	 * Remove Admin Notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function removePageNotices() {
		add_action( 'admin_init', [ $this, 'removeAllNotices' ] );
	}

	/**
	 * Conditionally Remove All Notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function removeAllNotices() {
		global $pagenow;

		if ( 'themes.php' === $pagenow && isset( $_GET['page'] ) && ( 'sd-easy-demo-importer' === $_GET['page'] || 'sd-edi-demo-importer-status' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			remove_all_actions( 'admin_notices' );
		}
	}
}
