<?php
/**
 * Config Class: Plugin.
 *
 * Plugin data which are used through the plugin, most of them are defined
 * by the root file metadata. The data is being inserted in each class
 * that extends the Base abstract class.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Config;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Config Class: Plugin.
 *
 * @since 1.0.0
 */
final class Plugin {
	/**
	 * Get the plugin meta data.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function data() {
		return array_merge(
			apply_filters(
				'sd/edi/plugin_meta_data',
				$this->getPluginMetaData()
			),
			$this->getOwnPluginData()
		);
	}

	/**
	 * Get own plugin data.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getOwnPluginData() {
		return [
			'settings'            => get_option( 'sd_edi_settings' ),
			'plugin_path'         => untrailingslashit( plugin_dir_path( SD_EDI_ROOT_FILE ) ),
			'plugin_uri'          => untrailingslashit( plugin_dir_url( SD_EDI_ROOT_FILE ) ),
			'plugin_active_file'  => plugin_basename( SD_EDI_ROOT_FILE ),
			'demo_import_page'    => 'sd-easy-demo-importer',
			'system_status_page'  => admin_url( 'themes.php?page=sd-easy-demo-importer#/system_status_page' ),
			'views_folder'        => 'views',
			'template_folder'     => 'templates',
			'ext_template_folder' => 'sd-edi-templates',
			'assets_folder'       => 'assets',
		];
	}

	/**
	 * Get the plugin meta data.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getPluginMetaData() {
		return get_file_data(
			SD_EDI_ROOT_FILE,
			[
				'name'         => 'Plugin Name',
				'version'      => 'Version',
				'uri'          => 'Plugin URI',
				'text-domain'  => 'Text Domain',
				'domain-path'  => 'Domain Path',
				'namespace'    => 'Namespace',
				'required_php' => 'Requires PHP',
				'required_wp'  => 'Requires at least',
			],
			'plugin'
		);
	}

	/**
	 * Get the plugin path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function pluginPath() {
		return $this->data()['plugin_path'];
	}

	/**
	 * Get the plugin URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function pluginUri() {
		return $this->data()['plugin_uri'];
	}

	/**
	 * Get the plugin internal template path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function templatePath() {
		return $this->data()['plugin_path'] . '/' . $this->data()['template_folder'];
	}

	/**
	 * Get the plugin internal views folder name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function viewsFolder() {
		return $this->data()['views_folder'];
	}

	/**
	 * Get the plugin internal template folder name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function templateFolder() {
		return $this->data()['template_folder'];
	}

	/**
	 * Get the plugin external template path
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function extTemplateFolder() {
		return $this->data()['ext_template_folder'];
	}

	/**
	 * Get the plugin assets URL.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function assetsUri() {
		return $this->data()['plugin_uri'] . '/' . $this->data()['assets_folder'];
	}

	/**
	 * Get the plugin settings.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function settings() {
		return $this->data()['settings'];
	}

	/**
	 * Get the plugin version number.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function version() {
		return $this->data()['version'];
	}

	/**
	 * Get the plugin name.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function name() {
		return $this->data()['name'];
	}

	/**
	 * Get the plugin text domain.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function textDomain() {
		return $this->data()['text-domain'];
	}

	/**
	 * Get the plugin required php version.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function requiredPhp() {
		return $this->data()['required_php'];
	}

	/**
	 * Get the plugin required wp version.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function requiredWp() {
		return $this->data()['required_wp'];
	}

	/**
	 * Get the plugin namespace.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function namespace() {
		return $this->data()['namespace'];
	}
}
