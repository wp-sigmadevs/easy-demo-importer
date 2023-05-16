<?php
/**
 * Utility Class: Notice.
 *
 * Gives an admin notice.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Common\Functions\Helpers;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: Notice.
 *
 * @since 1.0.0
 */
class Notice {
	/**
	 * Notice message.
	 *
	 * @static
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private static $message;

	/**
	 * Type of notice.
	 * Possible values are error, warning, success, info.
	 *
	 * @static
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private static $type;

	/**
	 * Close button.
	 * If true, notice will include a close button.
	 *
	 * @static
	 *
	 * @var bool
	 * @since 1.0.0
	 */
	private static $close = false;

	/**
	 * Initialize the class.
	 *
	 * @static
	 *
	 * @param string  $message Notice message.
	 * @param string  $type Type of notice.
	 * @param boolean $close Close button.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function trigger( $message, $type, $close = false ) {
		self::$message = $message;
		self::$type    = $type;
		self::$close   = $close;

		add_action( 'admin_notices', [ __CLASS__, 'notice' ] );
	}

	/**
	 * Admin notice.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function notice() {
		$hasClose = self::$close ? ' is-dismissible' : '';

		echo wp_kses(
			sprintf(
				'<div class="notice notice-' . self::$type . esc_attr( $hasClose ) . '">%s</div>',
				self::$message
			),
			Helpers::allowedTags()
		);
	}
}
