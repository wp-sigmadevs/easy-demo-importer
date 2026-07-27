<?php
/**
 * Minimal global WordPress runtime stubs for the Brain Monkey unit suite.
 *
 * The suite runs without WordPress core. A few tests now exercise code paths
 * that return a WP_Error or resolve a redirect Location, so they need the
 * WP_Error / is_wp_error / WP_Http primitives those paths touch. Everything
 * else stays mocked per-test with Brain Monkey.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Bare stand-in for core's WP_Error.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		protected $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		protected $message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 *
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_Http' ) ) {
	/**
	 * Stand-in exposing just the redirect-URL resolver RemoteUrl::get() uses.
	 */
	class WP_Http {

		/**
		 * Resolves a possibly-relative redirect target against its base URL.
		 *
		 * The unit tests use absolute Location values; this only needs to leave
		 * those untouched and join simple relative paths.
		 *
		 * @param string $maybe_relative_path Location header value.
		 * @param string $url                 URL that issued the redirect.
		 *
		 * @return string
		 */
		public static function make_absolute_url( $maybe_relative_path, $url ) {
			$maybe_relative_path = (string) $maybe_relative_path;

			if ( preg_match( '#^https?://#i', $maybe_relative_path ) ) {
				return $maybe_relative_path;
			}

			return rtrim( (string) $url, '/' ) . '/' . ltrim( $maybe_relative_path, '/' );
		}
	}
}
