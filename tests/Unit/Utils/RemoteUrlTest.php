<?php
/**
 * Unit tests for RemoteUrl.
 *
 * Covers the link-local range logic (pure, no DNS) and the validate() gate that
 * both download paths run before any network request. DNS is stubbed by
 * subclassing so `resolve()` never leaves the machine.
 *
 * @package SigmaDevs\EasyDemoImporter
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Tests\Unit\Utils;

use Brain\Monkey\Functions;
use SigmaDevs\EasyDemoImporter\Common\Utils\RemoteUrl;
use SigmaDevs\EasyDemoImporter\Tests\Unit\UnitTestCase;

/**
 * Test double that resolves hostnames from a fixed map instead of DNS.
 */
class FakeResolvingRemoteUrl extends RemoteUrl {

	/**
	 * Hostname => IP list.
	 *
	 * @var array<string,array<int,string>>
	 */
	public static $map = [];

	/**
	 * @inheritDoc
	 */
	protected static function resolve( string $host ): array {
		return self::$map[ $host ] ?? [];
	}
}

/**
 * @covers \SigmaDevs\EasyDemoImporter\Common\Utils\RemoteUrl
 */
final class RemoteUrlTest extends UnitTestCase {

	/**
	 * @inheritDoc
	 */
	protected function set_up() {
		parent::set_up();

		FakeResolvingRemoteUrl::$map = [];

		// wp_parse_url is a thin wrapper over parse_url for absolute URLs.
		Functions\when( 'wp_parse_url' )->alias(
			static function ( $url, $component = -1 ) {
				return parse_url( $url, $component );
			}
		);

		// Core's validator: accept anything absolute with a host by default.
		// The ranges it really blocks are irrelevant here — the point of these
		// tests is the range it does NOT block.
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) {
				return (bool) parse_url( (string) $url, PHP_URL_HOST ) ? $url : false;
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
	}

	public function test_ipv4_link_local_is_blocked(): void {
		// The AWS/GCP/Azure instance metadata endpoint.
		self::assertTrue( RemoteUrl::isBlockedIp( '169.254.169.254' ) );
		self::assertTrue( RemoteUrl::isBlockedIp( '169.254.0.1' ) );
	}

	public function test_ordinary_public_ipv4_is_allowed(): void {
		self::assertFalse( RemoteUrl::isBlockedIp( '93.184.216.34' ) );
		self::assertFalse( RemoteUrl::isBlockedIp( '8.8.8.8' ) );
	}

	public function test_similar_looking_ipv4_is_not_blocked(): void {
		// Guards against a naive "169.254" substring match.
		self::assertFalse( RemoteUrl::isBlockedIp( '169.25.4.1' ) );
		self::assertFalse( RemoteUrl::isBlockedIp( '1.169.254.1' ) );
	}

	public function test_ipv4_mapped_ipv6_link_local_is_blocked(): void {
		self::assertTrue( RemoteUrl::isBlockedIp( '::ffff:169.254.169.254' ) );
	}

	public function test_ipv6_link_local_is_blocked(): void {
		self::assertTrue( RemoteUrl::isBlockedIp( 'fe80::1' ) );
		self::assertTrue( RemoteUrl::isBlockedIp( 'febf::1' ) );
	}

	public function test_ordinary_ipv6_is_allowed(): void {
		self::assertFalse( RemoteUrl::isBlockedIp( '2606:2800:220:1:248:1893:25c8:1946' ) );
		self::assertFalse( RemoteUrl::isBlockedIp( 'fec0::1' ) );
	}

	public function test_garbage_is_not_treated_as_blocked(): void {
		self::assertFalse( RemoteUrl::isBlockedIp( '' ) );
		self::assertFalse( RemoteUrl::isBlockedIp( 'not-an-ip' ) );
	}

	public function test_bare_link_local_host_is_blocked_without_dns(): void {
		self::assertTrue( RemoteUrl::hostIsBlocked( '169.254.169.254' ) );
		self::assertTrue( RemoteUrl::hostIsBlocked( '[fe80::1]' ) );
	}

	public function test_hostname_resolving_to_link_local_is_blocked(): void {
		FakeResolvingRemoteUrl::$map = [ 'metadata.evil.test' => [ '169.254.169.254' ] ];

		self::assertTrue( FakeResolvingRemoteUrl::hostIsBlocked( 'metadata.evil.test' ) );
	}

	public function test_hostname_is_blocked_when_any_record_is_link_local(): void {
		FakeResolvingRemoteUrl::$map = [
			'mixed.test' => [ '93.184.216.34', 'fe80::1' ],
		];

		self::assertTrue( FakeResolvingRemoteUrl::hostIsBlocked( 'mixed.test' ) );
	}

	public function test_unresolvable_host_is_not_blocked(): void {
		// Failing closed would break hosts whose DNS is unavailable to PHP.
		self::assertFalse( FakeResolvingRemoteUrl::hostIsBlocked( 'nothing.test' ) );
	}

	public function test_valid_public_url_passes(): void {
		FakeResolvingRemoteUrl::$map = [ 'example.test' => [ '93.184.216.34' ] ];

		self::assertNull( FakeResolvingRemoteUrl::validate( 'https://example.test/demo.zip' ) );
	}

	public function test_malformed_url_is_rejected(): void {
		self::assertSame( RemoteUrl::INVALID_URL, RemoteUrl::validate( 'not a url' ) );
	}

	public function test_non_http_scheme_is_rejected(): void {
		self::assertSame(
			RemoteUrl::INVALID_SCHEME,
			RemoteUrl::validate( 'ftp://example.test/demo.zip' )
		);
	}

	public function test_link_local_url_is_rejected(): void {
		self::assertSame(
			RemoteUrl::LINK_LOCAL,
			RemoteUrl::validate( 'http://169.254.169.254/latest/meta-data/' )
		);
	}

	public function test_domain_allowlist_blocks_other_hosts(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'sd/edi/allowed_download_domains' === $hook
					? [ 'cdn.example.test' ]
					: $value;
			}
		);

		self::assertSame(
			RemoteUrl::BLOCKED_DOMAIN,
			RemoteUrl::validate( 'https://elsewhere.test/demo.zip' )
		);
	}

	public function test_domain_allowlist_admits_a_listed_host(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'sd/edi/allowed_download_domains' === $hook
					? [ 'cdn.example.test' ]
					: $value;
			}
		);

		FakeResolvingRemoteUrl::$map = [ 'cdn.example.test' => [ '93.184.216.34' ] ];

		self::assertNull(
			FakeResolvingRemoteUrl::validate( 'https://cdn.example.test/demo.zip' )
		);
	}

	public function test_get_forces_redirect_revalidation(): void {
		$captured = null;

		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return [ 'response' => [ 'code' => 200 ] ];
			}
		);

		RemoteUrl::get( 'https://example.test/demo.zip', [ 'timeout' => 300 ] );

		self::assertTrue( $captured['reject_unsafe_urls'] );
		self::assertSame( 3, $captured['redirection'] );
		self::assertSame( 300, $captured['timeout'] );
	}

	public function test_get_lets_callers_override_the_defaults(): void {
		$captured = null;

		Functions\when( 'wp_safe_remote_get' )->alias(
			static function ( $url, $args ) use ( &$captured ) {
				$captured = $args;
				return [];
			}
		);

		RemoteUrl::get( 'https://example.test/demo.zip', [ 'redirection' => 0 ] );

		self::assertSame( 0, $captured['redirection'] );
	}
}
