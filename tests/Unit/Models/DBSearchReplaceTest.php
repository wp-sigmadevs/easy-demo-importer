<?php
/**
 * Unit tests for DBSearchReplace.
 *
 * Exercises the pure search/replace engine — plain, array, object and
 * serialized data — plus the escaping/unserialize helpers, independent of any
 * database. WordPress serialization predicates are stubbed with lightweight
 * equivalents keyed to the controlled test inputs.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Models;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Models\DBSearchReplace;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Models\DBSearchReplace
 */
final class DBSearchReplaceTest extends UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var DBSearchReplace
	 */
	private $dbsr;

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		Functions\when( 'is_serialized' )->alias(
			static function ( $data ) {
				if ( ! is_string( $data ) ) {
					return false;
				}
				$data = trim( $data );
				if ( 'N;' === $data ) {
					return true;
				}
				return (bool) preg_match( '/^[aOsbid]:/', $data );
			}
		);

		Functions\when( 'is_serialized_string' )->alias(
			static function ( $data ) {
				return is_string( $data ) && (bool) preg_match( '/^s:\d+:/', trim( $data ) );
			}
		);

		$this->dbsr = new DBSearchReplace();
	}

	/**
	 * str_replace wrapper is case-sensitive by default.
	 */
	public function test_str_replace_is_case_sensitive_by_default() {
		$this->assertSame(
			'new value KEEP',
			$this->dbsr->str_replace( 'old', 'new', 'old value KEEP' )
		);
		// Different case is NOT replaced.
		$this->assertSame(
			'OLD value',
			$this->dbsr->str_replace( 'old', 'new', 'OLD value' )
		);
	}

	/**
	 * str_replace wrapper honors the case-insensitive 'on' flag.
	 */
	public function test_str_replace_case_insensitive() {
		$this->assertSame(
			'new new',
			$this->dbsr->str_replace( 'old', 'new', 'OLD old', 'on' )
		);
	}

	/**
	 * mysql_escape_mimic escapes quotes, backslashes and control bytes.
	 */
	public function test_mysql_escape_mimic_escapes_specials() {
		$this->assertSame( "O\\'Brien", $this->dbsr->mysql_escape_mimic( "O'Brien" ) );
		$this->assertSame( 'a\\\\b', $this->dbsr->mysql_escape_mimic( 'a\\b' ) );
		$this->assertSame( 'x\\ny', $this->dbsr->mysql_escape_mimic( "x\ny" ) );
	}

	/**
	 * mysql_escape_mimic maps over arrays recursively.
	 */
	public function test_mysql_escape_mimic_handles_arrays() {
		$this->assertSame(
			[ "a\\'", 'b' ],
			$this->dbsr->mysql_escape_mimic( [ "a'", 'b' ] )
		);
	}

	/**
	 * A plain string is replaced directly.
	 */
	public function test_recursive_replace_plain_string() {
		$this->assertSame(
			'http://new.test/page',
			$this->dbsr->recursive_unserialize_replace( 'http://old.test', 'http://new.test', 'http://old.test/page' )
		);
	}

	/**
	 * Nested arrays are replaced element by element, structure preserved.
	 */
	public function test_recursive_replace_nested_array() {
		$in = [
			'url'    => 'http://old.test',
			'nested' => [ 'link' => 'http://old.test/a', 'n' => 3 ],
		];

		$expected = [
			'url'    => 'http://new.test',
			'nested' => [ 'link' => 'http://new.test/a', 'n' => 3 ],
		];

		$this->assertSame(
			$expected,
			$this->dbsr->recursive_unserialize_replace( 'http://old.test', 'http://new.test', $in )
		);
	}

	/**
	 * A serialized string is unserialized, replaced, and re-serialized — the
	 * length prefixes must be recalculated so the result is valid.
	 */
	public function test_recursive_replace_serialized_roundtrip() {
		$in       = serialize( [ 'site' => 'http://old.test', 'n' => 5 ] );
		$expected = serialize( [ 'site' => 'http://new.test', 'n' => 5 ] );

		$out = $this->dbsr->recursive_unserialize_replace( 'http://old.test', 'http://new.test', $in );

		$this->assertSame( $expected, $out );
		// Sanity: the result still unserializes cleanly.
		$this->assertSame(
			[ 'site' => 'http://new.test', 'n' => 5 ],
			unserialize( $out ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		);
	}

	/**
	 * Object properties are replaced in place.
	 */
	public function test_recursive_replace_object_properties() {
		$obj      = new \stdClass();
		$obj->url = 'http://old.test/x';

		$out = $this->dbsr->recursive_unserialize_replace( 'old.test', 'new.test', $obj );

		$this->assertSame( 'http://new.test/x', $out->url );
	}

	/**
	 * Data without the search term is returned untouched (incl. non-strings).
	 */
	public function test_recursive_replace_leaves_nonmatching_untouched() {
		$this->assertSame(
			'nothing here',
			$this->dbsr->recursive_unserialize_replace( 'old.test', 'new.test', 'nothing here' )
		);
		$this->assertSame(
			42,
			$this->dbsr->recursive_unserialize_replace( 'old.test', 'new.test', 42 )
		);
	}

	/**
	 * unserialize() returns false for non-serialized input, decodes valid input.
	 */
	public function test_unserialize_helper() {
		$this->assertFalse( DBSearchReplace::unserialize( 'plain string' ) );
		$this->assertSame(
			[ 'x' => 1 ],
			DBSearchReplace::unserialize( serialize( [ 'x' => 1 ] ) )
		);
	}
}
