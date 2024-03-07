<?php
/**
 * Model Class: Customizer
 *
 * This class imports Customizer settings.
 * Code is mostly from the Customizer Export/Import plugin.
 *
 * @see https://wordpress.org/plugins/customizer-export-import/
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Models;

use stdClass;
use WP_Error;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Model Class: Customizer
 *
 * @since 1.0.0
 */
class Customizer {
	/**
	 * Imports uploaded mods
	 *
	 * @param string $customizerFile Customizer file.
	 * @param string $excludeImages Exclude Images.
	 *
	 * @return void|WP_Error
	 * @since 1.0.0
	 */
	public function import( $customizerFile, $excludeImages ) {
		global $wp_customize;

		$template = get_template();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data          = maybe_unserialize( file_get_contents( $customizerFile ) );
		$excludeImages = 'true' === $excludeImages;

		// Data checks.
		if ( 'array' != gettype( $data ) ) {
			return new WP_Error( 'sd_edi_customizer_import_data_error', esc_html__( 'The customizer import file is not in a correct format. Please make sure to use the correct customizer import file.', 'easy-demo-importer' ) );
		}

		if ( ! isset( $data['template'] ) || ! isset( $data['mods'] ) ) {
			return new WP_Error( 'sd_edi_customizer_import_no_data', esc_html__( 'Error importing settings! Please check that you uploaded a customizer export file.', 'easy-demo-importer' ) );
		}

		if ( $data['template'] !== $template ) {
			return new WP_Error( 'sd_edi_customizer_import_wrong_theme', esc_html__( 'The customizer import file is not suitable for current theme. You can only import customizer settings for the same theme or a child theme.', 'easy-demo-importer' ) );
		}

		// Import Images.
		if ( ! $excludeImages ) {
			$data['mods'] = $this->importCustomizerImages( $data['mods'] );
		}

		// Import custom options.
		if ( isset( $data['options'] ) ) {

			// Load WordPress Customize Setting Class.
			if ( ! class_exists( 'WP_Customize_Setting' ) ) {
				require_once ABSPATH . WPINC . '/class-wp-customize-setting.php';
			}

			foreach ( $data['options'] as $optionKey => $optionValue ) {
				$option = new CustomizerOption(
					$wp_customize,
					$optionKey,
					[
						'default'    => '',
						'type'       => 'option',
						'capability' => 'edit_theme_options',
					]
				);

				$option->import( $optionValue );
			}
		}

		// If wp_css is set then import it.
		if ( function_exists( 'wp_update_custom_css_post' ) && isset( $data['wp_css'] ) && '' !== $data['wp_css'] ) {
			wp_update_custom_css_post( $data['wp_css'] );
		}

		// Loop through theme mods and update them.
		if ( ! empty( $data['mods'] ) ) {
			foreach ( $data['mods'] as $key => $value ) {
				set_theme_mod( $key, $value );
			}
		}
	}

	/**
	 * Imports images for settings saved as mods.
	 *
	 * @param array $mods An array of customizer mods.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function importCustomizerImages( $mods ) {
		foreach ( $mods as $key => $value ) {

			if ( $this->isImageUrl( $value ) ) {
				$data = $this->mediaHandleSideload( $value );

				if ( ! is_wp_error( $data ) ) {
					$mods[ $key ] = $data->url;

					// Handle header image controls.
					if ( isset( $mods[ $key . '_data' ] ) ) {
						$mods[ $key . '_data' ] = $data;
						update_post_meta( $data->attachment_id, '_wp_attachment_is_custom_header', get_stylesheet() );
					}
				}
			}
		}

		return $mods;
	}

	/**
	 * Taken from the core media_sideload_image function and
	 * modified to return an array of data instead of html.
	 *
	 * @param string $file The image file path.
	 *
	 * @return bool|int|stdClass|string|WP_Error
	 * @since 1.0.0
	 */
	private function mediaHandleSideload( $file ) {
		$data = new stdClass();

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! empty( $file ) ) {
			// Set variables for storage, fix file filename for query strings.
			preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png|svg)\b/i', $file, $matches );

			$fileArray         = [];
			$fileArray['name'] = basename( $matches[0] );

			// Download file to temp location.
			$fileArray['tmp_name'] = download_url( $file );

			// If error storing temporarily, return the error.
			if ( is_wp_error( $fileArray['tmp_name'] ) ) {
				return $fileArray['tmp_name'];
			}

			// Do the validation and storage stuff.
			$id = media_handle_sideload( $fileArray, 0 );

			// If error storing permanently, unlink.
			if ( is_wp_error( $id ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $fileArray['tmp_name'] );

				return $id;
			}

			// Build the object to return.
			$meta                = wp_get_attachment_metadata( $id );
			$data->attachment_id = $id;
			$data->url           = wp_get_attachment_url( $id );
			$data->thumbnail_url = wp_get_attachment_thumb_url( $id );
			$data->height        = $meta['height'];
			$data->width         = $meta['width'];
		}

		return $data;
	}

	/**
	 * Checks to see whether an url is an image url or not.
	 *
	 * @param string $url The url to check.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	private function isImageUrl( $url ) {
		if ( is_string( $url ) && preg_match( '/\.(jpg|jpeg|png|gif|svg)/i', $url ) ) {
			return true;
		}

		return false;
	}
}
