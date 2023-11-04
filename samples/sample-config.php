<?php
/**
 * Sample Demo Importer Configuration File.
 * Want to see in action? Require this file in the Twenty Twenty-One theme.
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
class SD_EDI_Demo_Importer {
	/**
	 * Class Constructor.
	 */
	public function __construct() {
		// Sample Config for full site (single zip).
		add_filter( 'sd/edi/importer/config', [ $this, 'sd_edi_full_site_config' ] );

		// Sample Config for single site (multiple zip). Comment the top filter line and uncomment below line to check.
		// add_filter( 'sd/edi/importer/config', [ $this, 'sd_edi_single_site_config' ] );
	}

	/**
	 * Sample Config for full site (single zip).
	 *
	 * @return array
	 */
	public function sd_edi_full_site_config() {
		return [
			// Name of the theme.
			'themeName'             => 'Twenty Twenty-One',

			// Theme slug.
			'themeSlug'             => 'twentytwentyone',

			// Allow multiple zip files to true (for single site)
			// or false (for full site).
			'multipleZip'           => false,

			// URL to the demo content ZIP file.
			'demoZip'               => 'https://sample.sigmadevs.com/demos/twentytwentyone/right-place/right-place.zip',

			// Slug for the blog page.
			'blogSlug'              => 'news',

			// Source URL to replace in the content.
			'urlToReplace'          => 'https://demoimport4.sigmadevs.com/',

			// Commenter email to replace.
			'replaceCommenterEmail' => 'email@example.com',

			// Fix elementor taxonomy data.
			// 'elementor_data_fix'    => [
			// Need to pass the Elementor widget name and control name pair.
			// 'elementor-widget-name' => 'control_widget_name',
			// ],

			// JSON data file name for Fluent Forms.
			'fluentFormsJson'       => 'fluentforms',

			// Array of settings JSON data. You need to include these JSON files
			// in the demo zip file. The file names are representing
			// the option names.
			'settingsJson'          => [
				'__fluentform_global_form_settings',
				'rtsb_settings',
				'rtsb_tb_template_default_archive',
				'rtsb_tb_template_default_cart',
				'rtsb_tb_template_default_checkout',
				'rtsb_tb_template_default_product',
				'rtsb_tb_template_default_shop',
			],

			// Associative array of menus and their names like:
			// menu_location => Menu Name.
			'menus'                 => [
				'primary' => 'Primary Menu',
				'footer'  => 'Footer Menu',
			],

			// Array of demo data consisting of demo name, demo preview image
			// and demo preview URL.
			'demoData'              => [
				// The array key needs to be the same as the home page slug to automatically setting it up as the home page.
				'home-1' => [
					'name'         => esc_html__( 'Right Place', 'easy-demo-importer' ),
					'previewImage' => 'https://sample.sigmadevs.com/demos/twentytwentyone/right-place/preview-1.jpg',
					'previewUrl'   => 'https://demoimport4.sigmadevs.com/',
				],
				'home-2' => [
					'name'         => esc_html__( 'Digital Agency', 'easy-demo-importer' ),
					'previewImage' => 'https://sample.sigmadevs.com/demos/twentytwentyone/right-place/preview-2.jpg',
					'previewUrl'   => 'https://demoimport4.sigmadevs.com/home-2/',
				],
				'home-3' => [
					'name'         => esc_html__( 'Organic Farm', 'easy-demo-importer' ),
					'previewImage' => 'https://sample.sigmadevs.com/demos/twentytwentyone/right-place/preview-3.jpg',
					'previewUrl'   => 'https://demoimport4.sigmadevs.com/home-3/',
				],
				'home-4' => [
					'name'         => esc_html__( 'Interiors', 'easy-demo-importer' ),
					'previewImage' => 'https://sample.sigmadevs.com/demos/twentytwentyone/right-place/preview-4.jpg',
					'previewUrl'   => 'https://demoimport4.sigmadevs.com/home-4/',
				],

				// Add more demo data as needed.
			],

			// Array of plugins to install.
			'plugins'               => [
				'elementor'   => [
					'name'     => 'Elementor Page Builder',
					'source'   => 'wordpress',
					'filePath' => 'elementor/elementor.php',
				],
				'fluentform'  => [
					'name'     => 'WP Fluent Forms',
					'source'   => 'wordpress',
					'filePath' => 'fluentform/fluentform.php',
				],
				'twentig'     => [
					'name'     => 'Twentig',
					'source'   => 'wordpress',
					'filePath' => 'twentig/twentig.php',
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

				// If you need to include bundled plugins, you can use as below.

				/*
				'theme-core' => [
					'name'     => 'Theme Core',
					'source'   => 'bundled',
					'filePath' => 'theme-core/theme-core.php',
					'location' => 'https://abcd.com/theme-core.zip',

					// If you want to include the plugin from the theme directory, use as below.
					'location' => trailingslashit( get_template_directory() ) . 'inc/plugins/theme-core.zip',
				],
				*/

				// Add more plugins as needed.
			],
		];
	}

	/**
	 * Sample Config for single site (multiple zip).
	 *
	 * @return array
	 */
	public function sd_edi_single_site_config() {
		return [
			'themeName'   => 'Twenty Twenty-One',
			'themeSlug'   => 'twentytwentyone',
			'multipleZip' => true,
			'demoData'    => [
				// Treat individual array as a separate demo.
				'marketing-agency' => [
					'name'                  => esc_html__( 'Marketing Agency', 'easy-demo-importer' ),
					'previewImage'          => 'https://sample.sigmadevs.com/demos/twentytwentyone/marketing-agency/preview.jpg',
					'previewUrl'            => 'https://demoimport.sigmadevs.com/',
					'demoZip'               => 'https://sample.sigmadevs.com/demos/twentytwentyone/marketing-agency/marketing-agency.zip',
					'blogSlug'              => 'blog',
					'urlToReplace'          => 'https://demoimport.sigmadevs.com/',
					'replaceCommenterEmail' => 'email@example.com',
					'settingsJson'          => [
						'__fluentform_global_form_settings',
						'rtsb_settings',
						'rtsb_tb_template_default_archive',
						'rtsb_tb_template_default_cart',
						'rtsb_tb_template_default_checkout',
						'rtsb_tb_template_default_product',
						'rtsb_tb_template_default_shop',
					],
					'fluentFormsJson'       => 'fluentforms',
					'menus'                 => [
						'primary' => 'Main Menu',
						'footer'  => 'Secondary',
					],
					'plugins'               => [
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
				'sigma-restaurant' => [
					'name'                  => esc_html__( 'Sigma Restaurant', 'easy-demo-importer' ),
					'previewImage'          => 'https://sample.sigmadevs.com/demos/twentytwentyone/sigma-restaurant/preview.jpg',
					'previewUrl'            => 'https://demoimport2.sigmadevs.com/',
					'demoZip'               => 'https://sample.sigmadevs.com/demos/twentytwentyone/sigma-restaurant/sigma-restaurant.zip',
					'blogSlug'              => 'blog',
					'urlToReplace'          => 'https://demoimport2.sigmadevs.com/',
					'replaceCommenterEmail' => 'email@example.com',
					'menus'                 => [
						'primary' => 'Header Menu',
						'footer'  => 'Social Menu',
					],
					'plugins'               => [
						'twentig' => [
							'name'     => 'Twentig',
							'source'   => 'wordpress',
							'filePath' => 'twentig/twentig.php',
						],
					],
				],
				'copywriter'       => [
					'name'                  => esc_html__( 'Copywriter', 'easy-demo-importer' ),
					'previewImage'          => 'https://sample.sigmadevs.com/demos/twentytwentyone/copywriter/preview.jpg',
					'previewUrl'            => 'https://demoimport3.sigmadevs.com/',
					'demoZip'               => 'https://sample.sigmadevs.com/demos/twentytwentyone/copywriter/copywriter.zip',
					'blogSlug'              => 'news',
					'urlToReplace'          => 'https://demoimport3.sigmadevs.com/',
					'replaceCommenterEmail' => 'email@example.com',
					'settingsJson'          => [
						'rtsb_settings',
						'rtsb_tb_template_default_archive',
						'rtsb_tb_template_default_cart',
						'rtsb_tb_template_default_checkout',
						'rtsb_tb_template_default_product',
						'rtsb_tb_template_default_shop',
					],
					'menus'                 => [
						'primary' => 'Primary Nav',
						'footer'  => 'Footer Socials',
					],
					'plugins'               => [
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

				// Add more demo data as needed.
			],
		];
	}
}

new SD_EDI_Demo_Importer();
