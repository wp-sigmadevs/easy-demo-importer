<?php
/**
 * Functions Class: Actions.
 *
 * List of all functions hooked in action hooks.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use WP_Query;
use SigmaDevs\EasyDemoImporter\Common\Models\DBSearchReplace;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Functions Class: Actions.
 *
 * @since 1.0.0
 */
class Actions {
	/**
	 * Check if the option to flush the rewrite rules has been set,
	 * and if so, flushes them and deletes the option.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function rewriteFlushCheck() {
		$theme  = str_replace( '-', '_', sd_edi()->activeTheme() );
		$option = $theme . '_sd_edi_rewrite_flash';

		if ( 'true' === get_option( $option ) ) {
			flush_rewrite_rules();
			delete_option( $option );
		}
	}

	/**
	 * Activation Script after plugin activation.
	 *
	 * @param string $plugin_path The path of the activated plugin's main file.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function pluginActivationActions( $plugin_path ) {
		$plugins = apply_filters(
			'sd/edi/plugin_activation_actions',
			[
				'woocommerce/woocommerce.php' => [
					'class'  => '\WC_Install',
					'action' => 'install',
				],
				'fluentform/fluentform.php'   => [
					'class'  => '\FluentForm\Database\DBMigrator',
					'action' => 'run',
				],
			]
		);

		foreach ( $plugins as $plugin => $actionInfo ) {
			if ( $plugin === $plugin_path && class_exists( $actionInfo['class'] ) ) {
				call_user_func( [ $actionInfo['class'], $actionInfo['action'] ] );
			}
		}
	}

	/**
	 * Executes operations before import.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function initImportActions( $obj ) {
		if ( $obj->reset ) {
			return;
		}

		self::cleanups()
			->deletePages()
			->draftPost();
	}

	/**
	 * Executes operations before import.
	 *
	 * @return void
	 * @since 1.1.1
	 */
	public static function beforeImportActions() {
		// Try to update PHP memory limit before import.
		// phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed, Squiz.PHP.DiscouragedFunctions.Discouraged
		ini_set( 'memory_limit', apply_filters( 'sd/edi/temp_boost_memory_limit', '350M' ) );

		// Try to increase PHP max execution time before import.
		if ( ( strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) === false ) && ini_get( 'max_execution_time' ) < 300 ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			set_time_limit( apply_filters( 'sd/edi/temp_boost_max_execution_time', 300 ) );
		}

		/**
		 * Temporarily disable big image scaling.
		 *
		 * @since 1.1.5
		 */
		add_filter( 'big_image_size_threshold', '__return_false' );
	}

	/**
	 * Executes operations after import.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function afterImportActions( $obj ) {
		// Setting up Home and Blog pages.
		self::setPages( $obj )

			// Replace URLs'.
			->replaceUrls( $obj )

			// WooCommerce Actions.
			->WooCommerceActions()

			// Elementor Actions.
			->ElementorActions( $obj )

			// Update Permalinks.
			->updatePermalinks()

			// Update a rewrite flag.
			->rewriteFlag();
	}

	/**
	 * Executes a chain of cleanup operations.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	private static function cleanups() {
		// Delete widgets.
		Helpers::deleteWidgets();

		// Delete ThemeMods.
		Helpers::deleteThemeMods();

		// Delete Nav Menus.
		Helpers::deleteNavMenus();

		return new static();
	}

	/**
	 * Deletes some pages.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	private static function deletePages() {
		$pagesToDelete = [
			'My Account',
			'Checkout',
			'Sample Page',
		];

		foreach ( $pagesToDelete as $pageTitle ) {
			$page = Helpers::getPageByTitle( $pageTitle );

			if ( $page ) {
				wp_delete_post( $page->ID, true );
			}
		}

		return new static();
	}

	/**
	 * Updates the 'Hello World!' blog post by making it a draft
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private static function draftPost() {
		$helloWorld = Helpers::getPageByTitle( 'Hello World!', 'post' );

		if ( $helloWorld ) {
			$helloWorldArgs = [
				'ID'          => $helloWorld->ID,
				'post_status' => 'draft',
			];

			wp_update_post( $helloWorldArgs );
		}
	}

	/**
	 * Setting up pages.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return static
	 * @since 1.1.0
	 */
	public static function setPages( $obj ) {
		$homeSlug      = $obj->demoSlug;
		$blogSlug      = '';
		$frontPageBlog = ( $obj->config['frontPageBlog'] ?? false ) || ( $obj->config['demoData'][ $obj->demoSlug ]['frontPageBlog'] ?? false );

		if ( $frontPageBlog ) {
			update_option( 'show_on_front', 'posts' );
			update_option( 'page_for_posts', 0 );

			return new static();
		}

		if ( ! empty( $obj->config['blogSlug'] ) || ! empty( $obj->config['demoData'][ $obj->demoSlug ]['blogSlug'] ) ) {
			$blogSlug = $obj->multiple ? $obj->config['demoData'][ $obj->demoSlug ]['blogSlug'] : $obj->config['blogSlug'];
		}

		// Setting up front page.
		if ( $homeSlug ) {
			$page = get_page_by_path( $homeSlug );

			if ( $page ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $page->ID );
			} else {
				$page = Helpers::getPageByTitle( 'Home' );

				if ( $page ) {
					update_option( 'show_on_front', 'page' );
					update_option( 'page_on_front', $page->ID );
				}
			}
		}

		// Setting up blog page.
		if ( $blogSlug ) {
			$blog = get_page_by_path( $blogSlug );

			if ( $blog ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_for_posts', $blog->ID );
			}
		}

		if ( ! $homeSlug && ! $blogSlug ) {
			update_option( 'show_on_front', 'posts' );
		}

		return new static();
	}

	/**
	 * Replaces URL.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function replaceUrls( $obj ) {
		global $wpdb;

		$emailToReplace = ! empty( $obj->config['replaceCommenterEmail'] ) ? $obj->config['replaceCommenterEmail'] : '';
		$urlToReplace   = ! empty( $obj->config['urlToReplace'] ) ? $obj->config['urlToReplace'] : '';

		if ( $obj->config['multipleZip'] ) {
			$emailToReplace = ! empty( $obj->config['demoData'][ $obj->demoSlug ]['replaceCommenterEmail'] ) ? $obj->config['demoData'][ $obj->demoSlug ]['replaceCommenterEmail'] : $emailToReplace;
			$urlToReplace   = ! empty( $obj->config['demoData'][ $obj->demoSlug ]['urlToReplace'] ) ? $obj->config['demoData'][ $obj->demoSlug ]['urlToReplace'] : $urlToReplace;
		}

		if ( ! empty( $emailToReplace ) ) {
			// Commenter email.
			$commenter_email     = esc_sql( $emailToReplace );
			$commenter_new_email = esc_sql( get_bloginfo( 'admin_email' ) );
			$commenter_new_url   = esc_sql( home_url() );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $wpdb->comments SET comment_author_email = %s, comment_author_url = %s WHERE comment_author_email = %s",
					$commenter_new_email,
					$commenter_new_url,
					$commenter_email
				)
			);
		}

		if ( empty( $urlToReplace ) ) {
			return new static();
		}

		$tables = apply_filters(
			'sd/edi/db_search/tables',
			[
				$wpdb->prefix . 'commentmeta',
				$wpdb->prefix . 'comments',
				$wpdb->prefix . 'fluentform_forms',
				$wpdb->prefix . 'options',
				$wpdb->prefix . 'postmeta',
				$wpdb->prefix . 'posts',
			]
		);

		$urls = [
			'unslash' => [
				'old' => untrailingslashit( $urlToReplace ),
				'new' => home_url(),
			],
			'slash'   => [
				'old' => trailingslashit( $urlToReplace ),
				'new' => home_url( '/' ),
			],
		];

		foreach ( $urls as $url ) {
			$oldUrl = esc_url_raw( $url['old'] );
			$newUrl = esc_url_raw( $url['new'] );

			// Search and replace URLs in Elementor data (postmeta).
			self::searchReplaceElementorUrls( $oldUrl, $newUrl );

			// Replace GUID.
			self::replaceGUIDs( $oldUrl, $newUrl );
		}

		// Search and replace URLs in DB.
		self::searchReplaceUrls( $urls, $tables );

		return new static();
	}

	/**
	 * Search and replace URLs in DB.
	 *
	 * @param array $urls The URLs' to search & replace.
	 * @param array $tables The tables to search & replace.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function searchReplaceUrls( $urls, $tables ) {
		$page       = 0;
		$tableCount = count( $tables );

		// Initialize the model.
		$dbsr = new DBSearchReplace();
		$args = [
			'select_tables'   => array_map( 'trim', $tables ),
			'completed_pages' => 0,
			'dry_run'         => 'off',
			'total_pages'     => $dbsr->get_total_pages( $tables ),
		];

		foreach ( $urls as $urlType => $url ) {
			$oldUrl = esc_url_raw( $url['old'] );
			$newUrl = esc_url_raw( $url['new'] );

			$args['search_for']   = $oldUrl;
			$args['replace_with'] = $newUrl;

			for ( $i = 0; $i < $tableCount; $i++ ) {
				$dbsr->srdb( $args['select_tables'][ $i ], $page, $args );
			}

			if ( 'slash' === $urlType ) {
				$args['search_for']   = str_replace( '/', '\/', trailingslashit( $oldUrl ) );
				$args['replace_with'] = str_replace( '/', '\/', $newUrl );

				for ( $i = 0; $i < $tableCount; $i++ ) {
					$dbsr->srdb( $args['select_tables'][ $i ], $page, $args );
				}
			}
		}
	}

	/**
	 * Search and replace URLs in Elementor data (postmeta).
	 *
	 * @param string $oldUrl The old URL to search for.
	 * @param string $newUrl The new URL to replace with.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function searchReplaceElementorUrls( $oldUrl, $newUrl ) {
		global $wpdb;

		$oldUrl = str_replace( '/', '\/', $oldUrl );
		$newUrl = str_replace( '/', '\/', $newUrl );

		// Prepare and execute the SQL query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} " .
				'SET `meta_value` = REPLACE(`meta_value`, %s, %s) ' .
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
				"WHERE `meta_key` = '_elementor_data' AND `meta_value` LIKE '[%%' ;",
				$oldUrl,
				$newUrl
			)
		);
	}

	/**
	 * Replace GUIDs in WordPress posts table.
	 *
	 * @param string $oldUrl The old URL to search for in GUIDs.
	 * @param string $newUrl The new URL to replace with.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function replaceGUIDs( $oldUrl, $newUrl ) {
		global $wpdb;

		// Sanitize the URLs for use in SQL query.
		$oldUrlLike = $wpdb->esc_like( $oldUrl ) . '%';

		// Prepare and execute the SQL query.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->posts " .
				'SET guid = REPLACE(guid, %s, %s) ' .
				'WHERE guid LIKE %s',
				$oldUrl,
				$newUrl,
				$oldUrlLike
			)
		);
	}

	/**
	 * WooCommerce actions.
	 *
	 * @return static
	 * @since 1.1.0
	 */
	public static function WooCommerceActions() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new static();
		}

		// Setting up WooCommerce Pages.
		self::setWooPages()

			// Fixing default product category.
			->fixDefaultCategory()

			// Fixing product stock.
			->fixProductStock();

		return new static();
	}

	/**
	 * Setting up WooCommerce pages.
	 *
	 * @return static
	 * @since 1.1.0
	 */
	public static function setWooPages() {
		global $wpdb;

		$wcPages = [
			'shop'      => [
				'name'  => 'shop',
				'title' => 'Shop',
			],
			'cart'      => [
				'name'  => 'cart',
				'title' => 'Cart',
			],
			'checkout'  => [
				'name'  => 'checkout',
				'title' => 'Checkout',
			],
			'myaccount' => [
				'name'  => 'my-account',
				'title' => 'My Account',
			],
		];

		// Set WC pages properly.
		foreach ( $wcPages as $key => $wcPage ) {

			// Get the ID of every page with matching name or title.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pageIds = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE (post_name = %s OR post_title = %s) AND post_type = 'page' AND post_status = 'publish'",
					$wcPage['name'],
					$wcPage['title']
				)
			);

			// Create page if $pageIds returns null.
			if ( empty( $pageIds ) ) {
				$pageId = wp_insert_post(
					[
						'post_title'   => $wcPage['title'],
						'post_name'    => $wcPage['name'],
						'post_content' => '',
						'post_status'  => 'publish',
						'post_type'    => 'page',
					]
				);

				if ( $pageId ) {
					update_option( 'woocommerce_' . $key . '_page_id', $pageId );
				}
			} else {
				$pageId    = 0;
				$deleteIds = [];

				// Retrieve page with greater id and delete others.
				if ( count( $pageIds ) > 1 ) {
					foreach ( $pageIds as $page ) {
						if ( $page->ID > $pageId ) {
							if ( $pageId ) {
								$deleteIds[] = $pageId;
							}

							$pageId = $page->ID;
						} else {
							$deleteIds[] = $page->ID;
						}
					}
				} else {
					$pageId = $pageIds[0]->ID;
				}

				// Delete posts.
				foreach ( $deleteIds as $delete_id ) {
					wp_delete_post( $delete_id, true );
				}

				// Update WC page.
				if ( $pageId > 0 ) {
					wp_update_post(
						[
							'ID'        => $pageId,
							'post_name' => sanitize_title( $wcPage['name'] ),
						]
					);
					update_option( 'woocommerce_' . $key . '_page_id', $pageId );
				}
			}
		}

		// We no longer need WC setup wizard redirect.
		delete_transient( '_wc_activation_redirect' );

		return new static();
	}

	/**
	 * Fix default product category.
	 *
	 * @return static
	 * @since 1.1.4
	 */
	public static function fixDefaultCategory() {
		$defaultCategoryID = get_option( 'default_product_cat' );
		$defaultCategoryID = sd_edi()->getNewID( absint( $defaultCategoryID ) ) ?? 0;

		if ( $defaultCategoryID ) {
			update_option( 'default_product_cat', absint( $defaultCategoryID ) );
		}

		return new static();
	}

	/**
	 * Fix product stock.
	 *
	 * @return static
	 * @since 1.1.0
	 */
	public static function fixProductStock() {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'   => '_stock_status',
					'value' => 'outofstock',
				],
				[
					'key'   => '_stock',
					'value' => '',
				],
			],
			'fields'         => 'ids',
		];

		$query = new WP_Query( $args );

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$productId = get_the_ID();

				update_post_meta( $productId, '_stock_status', 'instock' );
			}

			wp_reset_postdata();
		}

		return new static();
	}

	/**
	 * Elementor actions.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return static
	 * @since 1.1.0
	 */
	public static function ElementorActions( $obj ) {
		if ( ! defined( 'ELEMENTOR_PATH' ) ) {
			return new static();
		}

		// Setting up active kit.
		self::setElementorActiveKit()

			// Set some settings.
			->setElementorSettings()

			// Taxonomy mapping.
			->elementorTaxonomyFix( $obj );

		return new static();
	}

	/**
	 * Sets the active Elementor kit.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function setElementorActiveKit() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pageIds = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE (post_name = %s OR post_title = %s) AND post_type = 'elementor_library' AND post_status = 'publish'",
				'default-kit',
				'Default Kit'
			)
		);

		if ( ! is_null( $pageIds ) ) {
			$pageId    = 0;
			$deleteIds = [];

			// Retrieve page with greater id and delete others.
			if ( count( $pageIds ) > 1 ) {
				foreach ( $pageIds as $page ) {
					if ( $page->ID > $pageId ) {
						if ( $pageId ) {
							$deleteIds[] = $pageId;
						}

						$pageId = $page->ID;
					} else {
						$deleteIds[] = $page->ID;
					}
				}
			} else {
				$pageId = $pageIds[0]->ID;
			}

			// Update `elementor_active_kit` page.
			if ( $pageId > 0 ) {
				wp_update_post(
					[
						'ID'        => $pageId,
						'post_name' => sanitize_title( 'Default Kit' ),
					]
				);
				update_option( 'elementor_active_kit', $pageId );
			}
		}

		return new static();
	}

	/**
	 * Sets the Elementor default settings.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function setElementorSettings() {
		$defaultPostTypes = [ 'page', 'post' ];

		$customPostTypes = get_post_types(
			[
				'public'   => true,
				'_builtin' => false,
			],
			'names'
		);

		$excludedPostTypes = [
			'e-landing-page',
			'elementor_library',
		];

		$postTypesSupport = apply_filters( 'sd/edi/elementor_post_types_support', array_diff( array_merge( $defaultPostTypes, array_keys( $customPostTypes ) ), $excludedPostTypes ) );

		update_option( 'elementor_cpt_support', $postTypesSupport );

		if ( ! apply_filters( 'sd/edi/disabled_elementor_options', false ) ) {
			update_option( 'elementor_disable_color_schemes', 'yes' );
			update_option( 'elementor_disable_typography_schemes', 'yes' );
			update_option( 'elementor_unfiltered_files_upload', '1' );
		}

		return new static();
	}

	/**
	 * Category IDs' fix in Elementor data.
	 *
	 * @param object $obj Reference object.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function elementorTaxonomyFix( $obj ) {
		if ( empty( $obj->config['elementor_data_fix'] ) ) {
			return new static();
		}

		global $wpdb;

		// Search for the pages that contain '_elementor_data' in postmeta table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$postmeta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
				'_elementor_data'
			)
		);

		// Iterate through each row and update the category ID.
		foreach ( $postmeta_rows as $row ) {
			$post_id    = $row->post_id;
			$meta_value = $row->meta_value;

			// Decode the JSON data.
			$data = json_decode( $meta_value, true );

			// Search and replace the IDs.
			self::searchReplaceID( $data, $obj->config['elementor_data_fix'] );

			// Update the meta_value in the database.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->postmeta,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				[ 'meta_value' => wp_json_encode( $data ) ],
				[
					'post_id'  => $post_id,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key' => '_elementor_data',
				],
				[ '%s' ],
				[ '%d', '%s' ]
			);
		}

		return new static();
	}

	/**
	 * Search and replace taxonomy IDs.
	 *
	 * @param array $data Postmeta data.
	 * @param array $configData Configuration data.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function searchReplaceID( &$data, $configData ) {
		// Iterate through each element in the data.
		foreach ( $data as &$element ) {
			if ( is_array( $element ) ) {
				self::searchReplaceID( $element, $configData );
			}

			// Perform search and replace within the array element.
			self::performSearchReplace( $element, $configData );
		}
	}

	/**
	 * Perform Search and Replace on Element Data.
	 *
	 * @param array $element The element data to be modified by reference.
	 * @param array $data The data array containing category keys and their corresponding replacement values.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function performSearchReplace( &$element, $data ) {
		// Check if the element's widgetType exists in the data array.
		if ( isset( $element['widgetType'] ) && isset( $data[ $element['widgetType'] ] ) ) {
			$catKeys = $data[ $element['widgetType'] ];

			// Check if this is a WordPress widget.
			$isWPWidget = strpos( $element['widgetType'], 'wp-widget-' ) === 0;

			// Check if it contains multiple controls.
			if ( is_array( $catKeys ) ) {
				foreach ( $catKeys as $catKey => $catID ) {
					// Check if it contains repeater fields.
					if ( is_string( $catKey ) && strpos( $catKey, 'repeater_' ) === 0 ) {
						$catKey = substr( $catKey, strlen( 'repeater_' ) );

						if ( isset( $element['settings'][ $catKey ] ) && is_array( $element['settings'][ $catKey ] ) ) {
							foreach ( $element['settings'][ $catKey ] as &$repeaterItem ) {
								self::replaceCategoryInRepeater( $repeaterItem, $catID );
							}
						}
					} else {
						// Not a repeater field. Process based on widget type.
						$isWPWidget
							? self::replaceCategoryWP( $element, $catID )
							: self::replaceCategory( $element, $catID );
					}
				}
			} else {
				// Has single control to work with.
				$isWPWidget
					? self::replaceCategoryWP( $element, $catKeys )
					: self::replaceCategory( $element, $catKeys );
			}
		}
	}

	/**
	 * Replace category IDs in element settings.
	 *
	 * @param array  $element The element data to be modified by reference.
	 * @param string $catKey  The control name to be replaced.
	 *
	 * @return void
	 * @since 1.0.2
	 */
	public static function replaceCategory( &$element, $catKey ) {
		if ( isset( $element['settings'][ $catKey ] ) ) {
			$oldIds = $element['settings'][ $catKey ];

			if ( is_array( $oldIds ) ) {
				$newIds                         = array_map(
					function ( $oldID ) {
						return sd_edi()->getNewID( $oldID );
					},
					$oldIds
				);
				$element['settings'][ $catKey ] = $newIds;
			} else {
				$newId                          = sd_edi()->getNewID( $oldIds );
				$element['settings'][ $catKey ] = $newId;
			}
		}
	}

	/**
	 * Replace category IDs in WordPress widget settings (nested under 'wp' key).
	 *
	 * @param array  $element The element data to be modified by reference.
	 * @param string $catKey  The control name to be replaced.
	 *
	 * @return void
	 * @since 1.1.5
	 */
	public static function replaceCategoryWP( &$element, $catKey ) {
		if ( isset( $element['settings']['wp'][ $catKey ] ) ) {
			$oldIds = $element['settings']['wp'][ $catKey ];

			if ( is_array( $oldIds ) ) {
				$newIds                               = array_map(
					function ( $oldID ) {
						return sd_edi()->getNewID( $oldID );
					},
					$oldIds
				);
				$element['settings']['wp'][ $catKey ] = $newIds;
			} else {
				$newId                                = sd_edi()->getNewID( $oldIds );
				$element['settings']['wp'][ $catKey ] = $newId;
			}
		}
	}

	/**
	 * Replace category IDs in repeater settings.
	 *
	 * @param array  $element The repeater data to be modified by reference.
	 * @param string $catKey  The control name to be replaced.
	 *
	 * @return void
	 * @since 1.0.2
	 */
	public static function replaceCategoryInRepeater( &$element, $catKey ) {
		if ( isset( $element[ $catKey ] ) ) {
			$oldIds = $element[ $catKey ];

			if ( is_array( $oldIds ) ) {
				$newIds             = array_map(
					function ( $oldID ) {
						return sd_edi()->getNewID( $oldID );
					},
					$oldIds
				);
				$element[ $catKey ] = $newIds;
			} else {
				$newId              = sd_edi()->getNewID( $oldIds );
				$element[ $catKey ] = $newId;
			}
		}
	}

	/**
	 * Updates the permalink structure to "/%postname%/".
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function updatePermalinks() {
		update_option( 'permalink_structure', '/%postname%/' );
		delete_option( 'sd_edi_plugin_deactivate_notice' );
		flush_rewrite_rules();

		return new static();
	}

	/**
	 * Sets rewrite flag.
	 *
	 * @return static
	 * @since 1.0.0
	 */
	public static function rewriteFlag() {
		$theme  = str_replace( '-', '_', sd_edi()->activeTheme() );
		$option = $theme . '_sd_edi_rewrite_flash';

		update_option( $option, 'true' );
		update_option( 'sd_edi_import_success', 'true' );

		return new static();
	}
}
