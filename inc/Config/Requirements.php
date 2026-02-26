<?php
/**
 * Config Class: Requirements.
 *
 * Check if any requirements are needed to run this plugin.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since 1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Config;

use SigmaDevs\EasyDemoImporter\Common\{
	Utils\Notice,
	Utils\Errors,
	Abstracts\Base,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Config Class: Requirements.
 *
 * @since 1.0.0
 */
final class Requirements extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Specifications for the requirements.
	 *
	 * @return array Used to specify the requirements.
	 * @since 1.0.0
	 */
	public function specifications() {
		return apply_filters(
			'sd/edi/plugin_requirements',
			[
				'php' => $this->plugin->requiredPhp(),
				'wp'  => $this->plugin->requiredWp(),
			]
		);
	}

	/**
	 * Plugin requirements checker
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function check() {
		foreach ( $this->versionCompare() as $compatCheck ) {
			if ( version_compare(
				$compatCheck['compare'],
				$compatCheck['current'],
				'>='
			) ) {
				$error = Errors::errorMessage(
					$compatCheck['title'],
					$compatCheck['message'],
					plugin_basename( __FILE__ )
				);

				// Through error & kill plugin.
				$this->throughError( $error );
			}
		}
	}

	/**
	 * Compares PHP & WP versions and kills plugin if it's not compatible
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function versionCompare() {
		return [
			// PHP version check.
			[
				'current' => phpversion(),
				'compare' => $this->plugin->requiredPhp(),
				'title'   => esc_html__( 'PHP Version Too Low', 'easy-demo-importer' ),
				'message' => sprintf(
					/* translators: 1. Required php version, 2. Current php version */
					esc_html__( 'PHP %1$1s or higher is required. You are currently running PHP %2$2s.', 'easy-demo-importer' ),
					$this->plugin->requiredPhp(),
					phpversion()
				),
			],
			// WP version check.
			[
				'current' => get_bloginfo( 'version' ),
				'compare' => $this->plugin->requiredWp(),
				'title'   => esc_html__( 'WordPress Version Too Low', 'easy-demo-importer' ),
				'message' => sprintf(
					/* translators: 2. Required WordPress version, 2. Current WordPress version */
					esc_html__( 'WordPress %1$s or higher is required. You are currently running WordPress %2$s.', 'easy-demo-importer' ),
					$this->plugin->requiredWp(),
					get_bloginfo( 'version' )
				),
			],
		];
	}

	/**
	 * Gives an admin notice and deactivates plugin.
	 *
	 * @param string $error Error message.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function throughError( $error ) {
		// Gives a error notice.
		Notice::trigger( $error, 'error' );

		// Kill plugin.
		Errors::pluginDie();
	}
}
