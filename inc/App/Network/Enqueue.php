<?php
/**
 * Network Class: Enqueue.
 *
 * Loads the Network Admin React bundle on the Easy Demo Importer screen.
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
 * Network Class: Enqueue.
 *
 * @since 1.2.0
 */
final class Enqueue extends Base {
	use Singleton;

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register(): void {
		add_action( 'network_admin_menu', [ $this, 'lateRegister' ], 11 );
	}

	/**
	 * Hook enqueue on our specific screen only.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function lateRegister(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue assets when on the Network EDI screen.
	 *
	 * @param string $hook Hook suffix.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue( $hook ): void {
		if ( 'themes_page_' . Pages::SLUG !== $hook ) {
			return;
		}

		$base = $this->plugin->assetsUri();
		$ver  = $this->plugin->version();

		wp_enqueue_style( 'sd-edi-network-styles', esc_url( $base . '/css/backend.css' ), [], $ver );
		wp_enqueue_script( 'sd-edi-network-script', esc_url( $base . '/js/network.min.js' ), [ 'wp-element', 'wp-i18n' ], $ver, true );

		wp_localize_script(
			'sd-edi-network-script',
			'sdEdiNetworkParams',
			[
				'restUrl'   => esc_url_raw( rest_url( 'sd-edi/v1/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'logo'      => esc_url( $base . '/images/sd-edi-logo.svg' ),
				'i18n'      => [
					'dashboard'       => esc_html__( 'Dashboard', 'easy-demo-importer' ),
					'networkConfig'   => esc_html__( 'Network Config', 'easy-demo-importer' ),
					'settings'        => esc_html__( 'Settings', 'easy-demo-importer' ),
					'overrideEnabled' => esc_html__( 'Use network-wide demo config (overrides per-subsite theme filter)', 'easy-demo-importer' ),
					'configInvalid'   => esc_html__( 'Network config must include themeName, themeSlug, and demoData.', 'easy-demo-importer' ),
					'save'            => esc_html__( 'Save', 'easy-demo-importer' ),
					'openInSubsite'   => esc_html__( 'Open in subsite', 'easy-demo-importer' ),
					'lastImport'      => esc_html__( 'Last import', 'easy-demo-importer' ),
					'noImport'        => esc_html__( 'Not yet imported', 'easy-demo-importer' ),
				],
			]
		);
	}
}
