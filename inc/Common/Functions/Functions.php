<?php
/**
 * Functions Class: Functions.
 *
 * Main function class for external uses
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use SigmaDevs\EasyDemoImporter\Common\Abstracts\Base;

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
	 * Get plugin path.
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
	 * @return array
	 * @since 1.0.0
	 */
	public function getDemoConfig() {
		return apply_filters( 'sd/edi/importer/config', [] );
	}

	/**
	 * Get active theme.
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
			'sd/edi/supported_themes',
			[
				! empty( $this->getDemoConfig()['themeSlug'] ) ? esc_html( $this->getDemoConfig()['themeSlug'] ) : '',
			]
		);
	}

	/**
	 * Get the import table name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function getImportTable() {
		global $wpdb;

		return sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );
	}

	/**
	 * Truncate the import table.
	 *
	 * @since 1.0.0
	 */
	public function truncateImportTable() {
		global $wpdb;

		$tableName = $this->getImportTable();

		// Check if the table exists before truncation.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) ) === $tableName ) {
			$wpdb->query(
				$wpdb->prepare( 'TRUNCATE TABLE %s;', $tableName )
			);
		}
	}

	/**
	 * Get the original ID based on the new ID.
	 *
	 * @param int $newID The new ID.
	 *
	 * @return int|null
	 * @since 1.0.0
	 */
	public function getOriginalID( $newID ) {
		global $wpdb;

		$tableName  = $this->getImportTable();
		$originalID = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT original_id FROM {$tableName} WHERE new_id = %d",
				intval( $newID )
			)
		);

		return intval( $originalID );
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
		$newID     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT new_id FROM {$tableName} WHERE original_id = %d",
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

		// Check if the entry already exists.
		$existingEntry = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$tableName} WHERE original_id = %d",
				$originalID
			)
		);

		if ( $existingEntry ) {
			// Entry already exists, update the values.
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
}
