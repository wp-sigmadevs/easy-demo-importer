<?php
/**
 * Unit tests for Functions (import ID-remap table helpers).
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Functions;

use Mockery;
use ReflectionClass;
use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Functions\Functions as EdiFunctions;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Functions\Functions
 */
final class FunctionsTest extends UnitTestCase {

	/**
	 * Builds a Functions instance without running its parent constructor.
	 *
	 * @return EdiFunctions
	 */
	private function functions(): EdiFunctions {
		return ( new ReflectionClass( EdiFunctions::class ) )->newInstanceWithoutConstructor();
	}

	public function test_get_import_table_is_prefixed_and_sanitised(): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';

		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $key ) )
		);

		self::assertSame(
			'wp_sd_edi_taxonomy_import',
			$this->functions()->getImportTable()
		);
	}

	public function test_get_new_id_maps_original_to_stored_new_id(): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';

		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $key ) )
		);

		$wpdb->shouldReceive( 'prepare' )
			->once()
			->with( Mockery::type( 'string' ), 'wp_sd_edi_taxonomy_import', 7 )
			->andReturn( 'PREPARED_SQL' );

		$wpdb->shouldReceive( 'get_var' )
			->once()
			->with( 'PREPARED_SQL' )
			->andReturn( '42' );

		self::assertSame( 42, $this->functions()->getNewID( 7 ) );
	}

	public function test_get_new_id_returns_zero_when_not_found(): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';

		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $key ) )
		);

		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( null );

		self::assertSame( 0, $this->functions()->getNewID( 999 ) );
	}
}
