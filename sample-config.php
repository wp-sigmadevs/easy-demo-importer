<?php
/**
 * Sample Demo Importer Configuration File.
 * Need to require this file in the Twenty Twenty-One theme.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Sample Demo Importer Configuration File.
 */
class Demo_Importer {
	/**
	 * Class Constructor.
	 */
	public function __construct() {
		add_filter( 'sd/edi/importer/config', [ $this, 'sd_edi_single_site_config' ] );
	}

	/**
	 * Sample Config.
	 *
	 * @return array
	 */
	public function sd_edi_single_site_config() {
		return [
			'themeName'             => 'Twenty Twenty-One',
			'themeSlug'             => 'twentytwentyone',
			'multipleZip'           => true,
			'urlToReplace'          => 'sample.sigmadevs.com',
			'replaceCommenterEmail' => 'email@example.com',
			'demoData'              => [
				'default-home' => [
					'name'            => esc_html__( 'Default', 'easy-demo-importer' ),
					'previewImage'    => 'https://sample.sigmadevs.com/demos/twentytwentyone/default/preview.jpg',
					'previewUrl'      => 'https://sample.sigmadevs.com/',
					'demoZip'         => 'https://sample.sigmadevs.com/demos/twentytwentyone/default/default.zip',
					'blogSlug'        => 'blog',
					'settingsJson'    => [
						'rtsb_settings',
					],
					'fluentFormsJson' => 'fluentforms',
					'menus'           => [
						'primary' => 'Primary Menu',
						'footer'  => 'Secondary Menu',
					],
					'plugins'         => [
						'elementor'   => [
							'name'     => 'Elementor Page Builder',
							'source'   => 'wordpress',
							'filePath' => 'elementor/elementor.php',
						],
						'twentig'     => [
							'name'     => 'Twentig',
							'source'   => 'wordpress',
							'filePath' => 'twentig/twentig.php',
						],
						'fluentform'  => [
							'name'     => 'WP Fluent Forms',
							'source'   => 'wordpress',
							'filePath' => 'fluentform/fluentform.php',
						],
						'woocommerce' => [
							'name'     => 'WooCommerce',
							'source'   => 'wordpress',
							'filePath' => 'woocommerce/woocommerce.php',
						],
						'shopbuilder' => [
							'name'     => 'ShopBuilder',
							'source'   => 'wordpress',
							'filePath' => 'shopbuilder/shopbuilder.php',
						],
					],
				],
			],
		];
	}
}

new Demo_Importer();
