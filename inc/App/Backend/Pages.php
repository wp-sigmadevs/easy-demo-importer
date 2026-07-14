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

use SigmaDevs\EasyDemoImporter\Common\
{
	Abstracts\Base,
	Traits\Singleton,
	Models\AdminPage,
	Functions\Callbacks
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
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
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
		$subPages   = [];
		$subPages[] = [
			'parent_slug' => 'themes.php',
			'page_title'  => esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ),
			'menu_title'  => apply_filters( 'sd/edi/admin_menu_title', esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'capability'  => 'manage_options',
			'menu_slug'   => $this->plugin->data()['demo_import_page'],
			'callback'    => [ Callbacks::class, 'renderDemoImportPage' ],
		];

		// System Status and Import Log are two tabs of a single page (rendered by
		// the React app on the status route), so only one submenu is registered.
		// Always available — the Import Log tab must stay reachable regardless of
		// whether the active theme ships a demo config.
		$subPages[] = [
			'parent_slug' => 'themes.php',
			'page_title'  => esc_html__( 'Status & Activity', 'easy-demo-importer' ),
			'menu_title'  => apply_filters( 'sd/edi/status_menu_title', esc_html__( 'Easy Status & Activity', 'easy-demo-importer' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'capability'  => 'manage_options',
			'menu_slug'   => esc_url( $this->plugin->data()['system_status_page'] ),
			'callback'    => '',
		];

		// Regenerate Thumbnails is a React route on the same page (like the
		// status/log tabs), so it's registered as a URL submenu with no callback.
		$subPages[] = [
			'parent_slug' => 'themes.php',
			'page_title'  => esc_html__( 'Regenerate Thumbnails', 'easy-demo-importer' ),
			'menu_title'  => apply_filters( 'sd/edi/regen_menu_title', esc_html__( 'Easy Thumbnails', 'easy-demo-importer' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'capability'  => 'manage_options',
			'menu_slug'   => esc_url( $this->plugin->data()['regen_thumbs_page'] ),
			'callback'    => '',
		];

		return $subPages;
	}

	/**
	 * Remove Admin Notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function removePageNotices() {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// Conditionally removing all notices.
		if ( 'themes.php' === $pagenow && ( 'sd-easy-demo-importer' === $page || 'sd-edi-demo-importer-status' === $page ) ) {
			add_action( 'admin_init', [ $this, 'removeAllNotices' ], 99 );
		}
	}

	/**
	 * Removes All Notices.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function removeAllNotices() {
		remove_all_actions( 'admin_notices' );
	}
}
