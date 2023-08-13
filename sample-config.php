<?php
/**
 * Sample theme Config file.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

namespace radiustheme\Faktorie_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Demo_Importer {

	public function __construct() {
		add_filter( 'sd/edi/importer/config', [ $this, 'edi_sample_config' ] );
	}

	public function edi_sample_config() {
		return [
			'themeName'          => 'Faktorie',
			'themeSlug'          => 'faktorie',
			'demoZip'            => 'http://demo-import.local/faktorie.zip',
			'settingsJson'       => [
				'bcn_settings',
				'rtsb_settings',
			],
			'fluentFormsJson'    => 'fluentforms',
			'multipleZip'        => false,
			'urlToReplace'       => 'https://radiustheme.com/demo/wordpress/themes/faktorie/',
			'replaceEmail'       => 'techlabpro24@gmail.com',
			'menus'              => [
				'primary' => 'Main Menu',
			],
			'elementor_data_fix' => [
				'rt-portfolio' => 'cat',
				'rt-post-grid' => 'catid',
				'rt-team'      => 'cat',
			],
			'blogSlug'           => 'news-media',
			'demoData'           => [
				'home-01' => [
					'name'         => __( 'Main Home', 'easy-demo-importer' ),
					'previewImage' => 'http://demo-import.local/faktorie-home-1.png',
					'previewUrl'   => 'https://demo.hashthemes.com/viral-mag/news',
				],
				'home-02' => [
					'name'         => __( 'Factory', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot2.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-02/',
				],
				'home-03' => [
					'name'         => __( 'Industry', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot3.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-03/',
				],
				'home-04' => [
					'name'         => __( 'Construction', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot4.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-04/',
				],
				'home-05' => [
					'name'         => __( 'Manufacture', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot5.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-05/',
				],
				'home-06' => [
					'name'         => __( 'Industry 02', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot6.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-06/',
				],
				'home-07' => [
					'name'         => __( 'Factory Black', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot7.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-07/',
				],
			],
			'plugins'            => [
				'rt-framework'     => [
					'name'     => 'RT Framework',
					'source'   => 'bundled',
					'filePath' => 'rt-framework/rt-framework.php',
					'location' => get_template_directory_uri() . '/inc/plugins/rt-framework.zip',
				],
				'breadcrumb-navxt' => [
					'name'     => 'Breadcrumb NavXT',
					'source'   => 'wordpress',
					'filePath' => 'breadcrumb-navxt/breadcrumb-navxt.php',
				],
				'elementor'        => [
					'name'     => 'Elementor Page Builder',
					'source'   => 'wordpress',
					'filePath' => 'elementor/elementor.php',
				],
				'fluentform'       => [
					'name'     => 'WP Fluent Forms',
					'source'   => 'wordpress',
					'filePath' => 'fluentform/fluentform.php',
				],
				'shopbuilder'      => [
					'name'     => 'ShopBuilder - WooCommerce Page Builder',
					'source'   => 'wordpress',
					'filePath' => 'shopbuilder/shopbuilder.php',
				],
				'woocommerce'      => [
					'name'     => 'WooCommerce',
					'source'   => 'wordpress',
					'filePath' => 'woocommerce/woocommerce.php',
				],
			],
		];
	}
}

new Demo_Importer();
