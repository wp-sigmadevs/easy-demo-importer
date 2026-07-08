<?php
/**
 * Base test case for Brain Monkey unit tests.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit;

use Mockery;
use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Sets up and tears down Brain Monkey around every test, and provides small
 * reflection helpers for exercising private members.
 */
abstract class UnitTestCase extends TestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
	}

	/**
	 * @inheritDoc
	 */
	protected function tear_down() {
		// Count Brain Monkey / Mockery expectations as PHPUnit assertions so that
		// expectation-only tests are not reported as risky ("no assertions").
		if ( $container = Mockery::getContainer() ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}

		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Invokes a private/protected method.
	 *
	 * @param object $object The object.
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 *
	 * @return mixed
	 */
	protected function invoke( $object, string $method, array $args = [] ) {
		$ref = new \ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	/**
	 * Sets a private/protected property.
	 *
	 * @param object $object   The object.
	 * @param string $property Property name.
	 * @param mixed  $value    Value.
	 *
	 * @return void
	 */
	protected function setPrivate( $object, string $property, $value ): void {
		$ref = new \ReflectionProperty( $object, $property );
		$ref->setAccessible( true );
		$ref->setValue( $object, $value );
	}

	/**
	 * Reads a private/protected property.
	 *
	 * @param object $object   The object.
	 * @param string $property Property name.
	 *
	 * @return mixed
	 */
	protected function getPrivate( $object, string $property ) {
		$ref = new \ReflectionProperty( $object, $property );
		$ref->setAccessible( true );

		return $ref->getValue( $object );
	}
}
