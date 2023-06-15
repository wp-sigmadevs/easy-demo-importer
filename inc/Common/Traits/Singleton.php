<?php
/**
 * Trait: Singleton.
 *
 * The singleton skeleton trait to instantiate the class only once.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Traits;

use Exception;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Trait: Singleton.
 *
 * @since 1.0.0
 */
trait Singleton {
	/**
	 * Refers to a single instance of this class.
	 *
	 * @static
	 * @var null|object
	 * @since 1.0.0
	 */
	private static $instance;

	/**
	 * Prevent cloning of the instance.
	 *
	 * @return void
	 * @throws Exception On cloning attempt.
	 */
	public function __clone() {
		throw new Exception( 'Cloning is not allowed.' );
	}

	/**
	 * Prevent serialization of the instance.
	 *
	 * @return void
	 * @throws Exception On serialization attempt.
	 */
	public function __sleep() {
		throw new Exception( 'Serialization is not allowed.' );
	}

	/**
	 * Prevent deserialization of the instance.
	 *
	 * @return void
	 * @throws Exception On deserialization attempt.
	 */
	public function __wakeup() {
		throw new Exception( 'Deserialization is not allowed.' );
	}

	/**
	 * Access the single instance of this class.
	 *
	 * @static
	 * @return Singleton
	 * @since 1.0.0
	 */
	final public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}
