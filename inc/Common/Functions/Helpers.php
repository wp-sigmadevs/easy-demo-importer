<?php
/**
 * Functions Class: Helpers.
 *
 * List of all helper functions.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

use WP_Error;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class: Helpers.
 *
 * @since 1.0.0
 */
class Helpers {
	/**
	 * Gets Ajax URL.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function ajaxUrl() {
		return admin_url( 'admin-ajax.php', 'relative' );
	}

	/**
	 * Nonce Text.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function nonceText() {
		return 'sd_edi_nonce_secret';
	}

	/**
	 * Nonce ID.
	 *
	 * @static
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function nonceId() {
		return 'sd_edi_nonce';
	}

	/**
	 * Creates Nonce.
	 *
	 * @static
	 *
	 * @return void
	 * @since  1.0.0
	 */
	public static function createNonce() {
		wp_nonce_field( self::nonceText(), self::nonceId() );
	}

	/**
	 * Verifies the Nonce.
	 *
	 * @static
	 *
	 * @return bool
	 * @since  1.0.0
	 */
	public static function verifyNonce() {
		$nonce     = null;
		$nonceText = self::nonceText();

		if ( isset( $_REQUEST[ self::nonceId() ] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ self::nonceId() ] ) );
		}

		if ( ! wp_verify_nonce( $nonce, $nonceText ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Allowed Tags for wp_kses: Basic.
	 *
	 * @static
	 *
	 * @return array
	 * @since  1.0.0
	 */
	public static function allowedTags() {
		return [
			'a'          => [
				'class' => [],
				'href'  => [],
				'rel'   => [],
				'title' => [],
			],
			'b'          => [],
			'blockquote' => [
				'cite' => [],
			],
			'cite'       => [
				'title' => [],
			],
			'code'       => [],
			'div'        => [
				'class' => [],
				'title' => [],
				'style' => [],
			],
			'em'         => [],
			'h1'         => [
				'class' => [],
			],
			'h2'         => [
				'class' => [],
			],
			'h3'         => [
				'class' => [],
			],
			'h4'         => [
				'class' => [],
			],
			'h5'         => [
				'class' => [],
			],
			'h6'         => [
				'class' => [],
			],
			'i'          => [
				'class' => [],
			],
			'img'        => [
				'alt'    => [],
				'class'  => [],
				'height' => [],
				'src'    => [],
				'width'  => [],
			],
			'li'         => [
				'class' => [],
			],
			'ol'         => [
				'class' => [],
			],
			'p'          => [
				'class' => [],
			],
			'span'       => [
				'class' => [],
				'title' => [],
				'style' => [],
			],
			'strong'     => [],
			'ul'         => [
				'class' => [],
			],
		];
	}

	/**
	 * Sanitize field value
	 *
	 * @static
	 *
	 * @param array $field Meta Fields.
	 * @param mixed $value Value to sanitize.
	 *
	 * @return string|void
	 * @since  1.0.0
	 */
	public static function sanitize( $field = [], $value = null ) {
		if ( ! is_array( $field ) ) {
			return;
		}

		$type = ( ! empty( $field['type'] ) ? $field['type'] : 'text' );

		if ( 'number' === $type || 'select' === $type || 'checkbox' === $type || 'radio' === $type ) {
			$sanitizedValue = sanitize_text_field( $value );
		} elseif ( 'text' === $type ) {
			$sanitizedValue = wp_kses( $value, self::allowedTags() );
		} elseif ( 'url' === $type ) {
			$sanitizedValue = esc_url( $value );
		} elseif ( 'textarea' === $type ) {
			$sanitizedValue = wp_kses_post( $value );
		} elseif ( 'color' === $type ) {
			$sanitizedValue = self::sanitizeHexColor( $value );
		} else {
			$sanitizedValue = sanitize_text_field( $value );
		}

		return $sanitizedValue;
	}

	/**
	 * Sanitizes Hex Color.
	 *
	 * @param string $color Hex Color.
	 *
	 * @return string
	 * @since  1.0.0
	 */
	public static function sanitizeHexColor( $color ) {
		if ( function_exists( 'sanitize_hex_color' ) ) {
			return sanitize_hex_color( $color );
		} else {
			if ( '' === $color ) {
				return '';
			}

			// 3 or 6 hex digits, or the empty string.
			if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
				return $color;
			}
		}

		return '';
	}

	/**
	 * Renders Admin View.
	 *
	 * @param string $viewName View name.
	 * @param array  $args View args.
	 *
	 * @return WP_Error|void
	 * @since  1.0.0
	 */
	public static function renderView( $viewName, $args = [] ) {
		$file       = str_replace( '.', '/', $viewName );
		$file       = ltrim( $file, '/' );
		$pluginPath = sd_edi()->getData()['plugin_path'];
		$viewsPath  = sd_edi()->getData()['views_folder'];
		$viewFile   = trailingslashit( $pluginPath . '/' . $viewsPath ) . $file . '.php';

		if ( ! file_exists( $viewFile ) ) {
			return new WP_Error(
				'brock',
				/* translators: View file name. */
				sprintf( __( '%s file not found', 'easy-demo-importer' ), $viewFile )
			);
		}

		load_template( $viewFile, true, $args );
	}
}
