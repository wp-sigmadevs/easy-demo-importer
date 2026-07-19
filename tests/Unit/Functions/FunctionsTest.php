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
	 * Reset the request-scoped static cache before each test so the memo from
	 * one test can't leak into another (it would swallow the DB call the next
	 * test's expectations rely on).
	 *
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		$prop = new \ReflectionProperty( EdiFunctions::class, 'newIdCache' );
		$prop->setAccessible( true );
		$prop->setValue( null, [] );
	}

	/**
	 * Builds a Functions instance without running its parent constructor.
	 *
	 * @return EdiFunctions
	 */
	private function functions(): EdiFunctions {
		return ( new ReflectionClass( EdiFunctions::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Primes the sanitize_key alias used by getImportTable().
	 *
	 * @return void
	 */
	private function stubSanitizeKey(): void {
		Functions\when( 'sanitize_key' )->alias(
			static fn( $key ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $key ) )
		);
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

	public function test_get_new_id_caches_repeat_lookups(): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';

		$this->stubSanitizeKey();

		// The DB must be hit exactly once even though we look up ID 7 twice.
		$wpdb->shouldReceive( 'prepare' )->once()->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_var' )->once()->andReturn( '42' );

		$functions = $this->functions();

		self::assertSame( 42, $functions->getNewID( 7 ) );
		self::assertSame( 42, $functions->getNewID( 7 ) );
	}

	public function test_create_entry_primes_the_lookup_cache(): void {
		global $wpdb;
		$wpdb         = Mockery::mock();
		$wpdb->prefix = 'wp_';

		$this->stubSanitizeKey();
		Functions\when( 'sanitize_text_field' )->returnArg();

		// createEntry writes the mapping...
		$wpdb->shouldReceive( 'prepare' )->andReturn( 'SQL' );
		$wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );
		$wpdb->shouldReceive( 'insert' )->once();

		// ...and getNewID must then resolve it WITHOUT another query.
		$wpdb->shouldNotReceive( 'get_var' );

		$functions = $this->functions();
		$functions->createEntry( 7, 42, 'news' );

		self::assertSame( 42, $functions->getNewID( 7 ) );
	}
}
