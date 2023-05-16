<?php
/**
 * Backend Class: Plugin Row Meta
 *
 * This class adds plugin row meta.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend;

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
 * Backend Class: Plugin Row Meta
 *
 * @since 1.0.0
 */
class PluginRowMeta extends Base {
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
		$activeFile = $this->plugin->data()['plugin_active_file'];

		add_filter( 'plugin_action_links_' . $activeFile, [ $this, 'addRowMeta' ] );
	}

	/**
	 * Add action link.
	 *
	 * @param array $links Action links.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function addRowMeta( $links ) {
		$demoImportPage   = 'themes.php?page=' . $this->plugin->data()['demo_import_page'];
		$systemStatusPage = 'themes.php?page=' . $this->plugin->data()['system_status_page'];

		return array_merge(
			[
				'<a href="' . esc_url( admin_url( $demoImportPage ) ) . '">' . esc_html__( 'Install Demo Data', 'easy-demo-importer' ) . '</a>',
				'<a href="' . esc_url( admin_url( $systemStatusPage ) ) . '">' . esc_html__( 'System Status', 'easy-demo-importer' ) . '</a>',
			],
			$links
		);
	}
}
