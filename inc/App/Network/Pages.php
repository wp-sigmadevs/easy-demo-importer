<?php
/**
 * Network Class: Pages.
 *
 * Adds a submenu under Network Admin → Themes for Easy Demo Importer.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Network;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Network Class: Pages.
 *
 * @since 1.2.0
 */
final class Pages extends Base {
	use Singleton;

	/**
	 * Menu slug for the Network screen.
	 */
	const SLUG = 'sd-edi-network';

	/**
	 * Register the Network Admin menu.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register(): void {
		add_action( 'network_admin_menu', [ $this, 'addMenu' ] );
	}

	/**
	 * Add submenu page.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function addMenu(): void {
		add_submenu_page(
			'themes.php',
			esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ),
			esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ),
			'manage_network_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the React mount point.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function render(): void {
		echo '<div class="wrap"><div id="sd-edi-network-app"></div></div>';
	}
}
