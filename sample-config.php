<?php
/**
 * @author  RadiusTheme
 * @since   1.0
 * @version 1.0
 */

namespace radiustheme\Faktorie_Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Demo_Importer {

	public function __construct() {
		$pro = false;

		if ( $pro ) {
			add_filter( 'sd/edi/importer/config', [ $this, 'sd_edi_sample_config' ] );
		} else {
			add_filter(
				'sd/edi/config/no_demo',
				function () {
					return [
						'text'    => 'It seems you have not activated the theme. Please head to the license page and provide license key to activate demo import functionality. Thanks for your understanding.',
						'btnUrl'  => admin_url() . 'themes.php',
						'btnText' => 'Activate Theme',
					];
				}
			);
		}
	}

	public function sd_edi_sample_config() {
		return [
			'themeName'             => 'Faktorie',
			'themeSlug'             => 'faktorie',
			'demoZip'               => 'http://demo-import.test/faktorie.zip',
			'settingsJson'          => [
				'bcn_settings',
				'rtsb_settings',
			],
			'fluentFormsJson'       => 'fluentforms',
			'multipleZip'           => false,
			'urlToReplace'          => 'https://radiustheme.com/demo/wordpress/themes/faktorie/',
			'replaceCommenterEmail' => 'techlabpro24@gmail.com',
			'menus'                 => [
				'primary' => 'Main Menu',
			],
			'elementor_data_fix'    => [
				'rt-portfolio' => 'cat',
				'rt-post-grid' => 'catid',
				'rt-team'      => 'cat',
			],
			'blogSlug'              => 'news-media',
			'demoData'              => [
				'home-01' => [
					'name'         => esc_html__( 'Main Home', 'easy-demo-importer' ),
					'previewImage' => 'http://demo-import.test/faktorie-home-1.jpg',
					'previewUrl'   => 'https://demo.hashthemes.com/viral-mag/news',
				],
				'home-02' => [
					'name'         => esc_html__( 'Factory', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot2.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-02/',
				],
				'home-03' => [
					'name'         => esc_html__( 'Industry', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot3.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-03/',
				],
				'home-04' => [
					'name'         => esc_html__( 'Construction', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot4.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-04/',
				],
				'home-05' => [
					'name'         => esc_html__( 'Manufacture', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot5.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-05/',
				],
				'home-06' => [
					'name'         => esc_html__( 'Industry 02', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot6.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-06/',
				],
				'home-07' => [
					'name'         => esc_html__( 'Factory Black', 'easy-demo-importer' ),
					'previewImage' => 'https://demo.radiustheme.com/wordpress/demo-content/faktorie/edi/screens/screenshot7.jpg',
					'previewUrl'   => 'https://radiustheme.com/demo/wordpress/themes/faktorie/home-07/',
				],
			],
			'plugins'               => [
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
