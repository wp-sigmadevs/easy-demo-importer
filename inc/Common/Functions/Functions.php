<?php
/**
 * Functions Class: Functions.
 *
 * Main function class for external uses.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\Base;
use SigmaDevs\EasyDemoImporter\Common\Utils\NetworkInstaller;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Functions Class: Functions.
 *
 * @since 1.0.0
 */
class Functions extends Base {
	/**
	 * Class Constructor.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function __construct() {
		parent::__construct();

		// Init Demo Configuration.
		$this->initDemoConfig();
	}

	/**
	 * Get plugin data by using sd_edi()->getData()
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getData() {
		return $this->plugin->data();
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function getPluginPath() {
		return $this->plugin->pluginPath();
	}

	/**
	 * Init Demo Config.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function initDemoConfig() {
		add_action( 'after_setup_theme', [ $this, 'getDemoConfig' ] );
	}

	/**
	 * Get Theme demo config.
	 *
	 * Resolution order (multisite):
	 *   1) Network override (site_option 'sd_edi_network_config')
	 *      ONLY applied when 'sd_edi_network_override_enabled' site_option is true.
	 *   2) Per-subsite theme filter ('sd/edi/importer/config').
	 *   3) Empty array.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getDemoConfig() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$enabled = (bool) get_site_option( 'sd_edi_network_override_enabled', false );
			$network = get_site_option( 'sd_edi_network_config', [] );

			if ( $enabled && is_array( $network ) && ! empty( $network ) ) {
				return apply_filters( 'sd/edi/importer/config', $network ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}

		return apply_filters( 'sd/edi/importer/config', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Get the active theme.
	 *
	 * @return false|mixed|null
	 * @since 1.0.0
	 */
	public function activeTheme() {
		return get_option( 'stylesheet' );
	}

	/**
	 * Supported Themes.
	 *
	 * @return mixed|null
	 * @since 1.0.0
	 */
	public function supportedThemes() {
		return apply_filters(
			'sd/edi/supported_themes', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			[
				! empty( $this->getDemoConfig()['themeSlug'] ) ? esc_html( $this->getDemoConfig()['themeSlug'] ) : '',
			]
		);
	}

	/**
	 * Get the import table name. Lazy-creates the table if missing on the
	 * current blog (covers multisite blogs created before plugin activation).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function getImportTable() {
		global $wpdb;

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) );
		if ( $exists !== $tableName ) {
			NetworkInstaller::createTableForBlog( (int) get_current_blog_id() );
		}

		return $tableName;
	}

	/**
	 * Get the new ID based on the original ID.
	 *
	 * @param int $originalID The original ID.
	 *
	 * @return int|null
	 * @since 1.0.0
	 */
	public function getNewID( $originalID ) {
		global $wpdb;

		$tableName = $this->getImportTable();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$newID = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				'SELECT new_id FROM %1$s WHERE original_id = %2$d',
				$tableName,
				intval( $originalID )
			)
		);

		return intval( $newID );
	}


	/**
	 * Create or update an entry in the import table.
	 *
	 * @param int    $originalID The original ID.
	 * @param int    $newID The new ID.
	 * @param string $slug The slug.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function createEntry( $originalID, $newID, $slug ) {
		global $wpdb;
		$tableName = $this->getImportTable();

		// Sanitize input values.
		$originalID = intval( $originalID );
		$newID      = intval( $newID );
		$slug       = sanitize_text_field( $slug );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existingEntry = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				'SELECT * FROM %1$s WHERE original_id = %2$d',
				$tableName,
				$originalID
			)
		);

		if ( $existingEntry ) {
			// Entry already exists, update the values.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$tableName,
				[
					'new_id' => $newID,
					'slug'   => $slug,
				],
				[ 'original_id' => $originalID ],
				[ '%d', '%s' ],
				[ '%d' ]
			);
		} else {
			// Entry doesn't exist, insert a new row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$tableName,
				[
					'original_id' => $originalID,
					'new_id'      => $newID,
					'slug'        => $slug,
				],
				[ '%d', '%d', '%s' ]
			);
		}
	}

	/**
	 * Adds slashes to only string values in an array of values.
	 *
	 * @param mixed $value Scalar or array of scalars.
	 *
	 * @return mixed Slashes $value
	 * @since 1.0.0
	 */
	public function slash_strings_only( $value ) {
		return map_deep( $value, [ $this, 'add_slashes_strings_only' ] );
	}

	/**
	 * Adds slashes only if the provided value is a string.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 * @since 1.0.0
	 */
	public function add_slashes_strings_only( $value ) {
		return is_string( $value ) ? addslashes( $value ) : $value;
	}
}
