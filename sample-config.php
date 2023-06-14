<?php
/**
 * Sample theme Config file.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

add_filter( 'sd/edi/importer/config', 'edi_sample_config' );

/**
 * Sample config array.
 *
 * @return array
 */
function edi_sample_config() {
	return [
		'themeName'           => 'Viral Mag',
		'themeSlug'           => 'viral-mag',
		'demoZip'             => 'https://hashthemes.com/import-files/viral-mag/news-mag.zip',
		'settingsJson'        => [
			'sfm_settings',
		],
		'fluentFormsJson'     => 'fluentforms',
		'multipleZip'         => false,
		'urlToReplace'        => 'https://demo.hashthemes.com/viral-mag/news/wp-content/uploads/sites/2/',
		'replaceEmail'        => 'techlabpro24@gmail.com',
		'menus'               => [
			'viral-mag-primary-menu' => 'Main Menu',
		],
		'blogSlug'            => 'blog',
		'demoData'            => [
			'news-mag' => [
				'name'         => __( 'News Mag', 'easy-demo-importer' ),
				'previewImage' => 'https://hashthemes.com/import-files/viral-mag/screen/news.jpg',
				'demoZip'      => 'https://hashthemes.com/import-files/viral-mag/news-mag.zip',
				'previewUrl'   => 'https://demo.hashthemes.com/viral-mag/news',
				'blogSlug'     => 'blog',
				'menus'        => [
					'viral-mag-primary-menu' => 'Main Menu',
				],
				'demoType'     => [
					'free' => 'Free',
				],
				'pageBuilder'  => [
					'elementor' => __( 'Elementor', 'easy-demo-importer' ),
				],
				'plugins'      => [
					'simple-floating-menu' => [
						'name'     => 'Simple Floating Menu',
						'source'   => 'WordPress',
						'filePath' => 'simple-floating-menu/simple-floating-menu.php',
					],
					'elementor'            => [
						'name'     => 'Elementor',
						'source'   => 'WordPress',
						'filePath' => 'elementor/elementor.php',
					],
					'smart-blocks'         => [
						'name'     => 'Smart Blocks - Wordpress Gutenberg Blocks',
						'source'   => 'WordPress',
						'filePath' => 'smart-blocks/smart-blocks.php',
					],
					'fluentform'           => [
						'name'     => 'WP Fluent Forms',
						'source'   => 'WordPress',
						'filePath' => 'fluentform/fluentform.php',
					],
				],
			],
			'gadgets'  => [
				'name'         => __( 'Gadgets', 'easy-demo-importer' ),
				'previewImage' => 'https://hashthemes.com/import-files/viral-mag/screen/gadgets.jpg',
				'demoZip'      => 'https://hashthemes.com/import-files/viral-mag/gadgets.zip',
				'previewUrl'   => 'https://www.radiustheme.com/demo/wordpress/themes/gymat/home-2/',
				'blogSlug'     => 'news',
				'menus'        => [
					'viral-mag-primary-menu' => 'Primary Menu',
					'viral-news-top-menu'    => 'Header Menu',
				],
				'demoType'     => [
					'pro' => __( 'Pro', 'easy-demo-importer' ),
				],
				'pageBuilder'  => [
					'visual-composer' => __( 'Visual Composer', 'easy-demo-importer' ),
				],
				'plugins'      => [
					'simple-floating-menu' => [
						'name'     => 'Simple Floating Menu',
						'source'   => 'WordPress',
						'filePath' => 'simple-floating-menu/simple-floating-menu.php',
					],
					'rt-framework'         => [
						'name'     => 'RT Framework',
						'source'   => 'bundled',
						'filePath' => 'rt-framework/rt-framework.php',
						'location' => get_template_directory_uri() . '/bundle/rt-framework.zip',
					],
				],
			],
		],
		'elementorCptSupport' => [ 'gymat_class' ],
		'plugins'             => [
			'fluentform'           => [
				'name'     => 'WooCommerce',
				'source'   => 'wordpress',
				'filePath' => 'fluentform/fluentform.php',
			],
			'hash-elements'        => [
				'name'     => 'Hash Elements',
				'source'   => 'wordpress',
				'filePath' => 'hash-elements/hash-elements.php',
			],
			'simple-floating-menu' => [
				'name'     => 'Simple Floating Menu',
				'source'   => 'wordpress',
				'filePath' => 'simple-floating-menu/simple-floating-menu.php',
			],
			'elementor'            => [
				'name'     => 'Elementor',
				'source'   => 'wordpress',
				'filePath' => 'elementor/elementor.php',
			],
			'smart-blocks'         => [
				'name'     => 'Smart Blocks - Wordpress Gutenberg Blocks',
				'source'   => 'wordpress',
				'filePath' => 'smart-blocks/smart-blocks.php',
			],
			'rt-framework'         => [
				'name'     => 'RT Framework',
				'source'   => 'bundled',
				'filePath' => 'rt-framework/rt-framework.php',
				'location' => get_template_directory_uri() . '/bundle/rt-framework.zip',
			],
		],
	];
}
