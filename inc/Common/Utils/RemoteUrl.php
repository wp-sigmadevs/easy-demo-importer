<?php
/**
 * Utility Class: RemoteUrl
 *
 * Shared validation and fetching for the two places the plugin pulls an
 * archive off the network: the demo ZIP (DownloadFiles) and required plugin
 * ZIPs (InstallPlugins). Both used to carry their own copy of the same
 * validate-then-fetch preamble, and both used `wp_remote_get`, which does not
 * re-validate redirect targets.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.1
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: RemoteUrl
 *
 * @since 2.0.1
 */
class RemoteUrl {

	/**
	 * Reason codes returned by validate().
	 *
	 * @since 2.0.1
	 */
	const INVALID_URL    = 'invalid_url';
	const INVALID_SCHEME = 'invalid_scheme';
	const BLOCKED_DOMAIN = 'blocked_domain';
	const LINK_LOCAL     = 'link_local';

	/**
	 * Whether an IP sits in a range that must never be fetched.
	 *
	 * Covers link-local IPv4 (169.254.0.0/16) — the cloud metadata range on
	 * AWS, GCP and Azure — plus its IPv6 equivalents: fe80::/10 and the
	 * IPv4-mapped form ::ffff:169.254.x.x. WordPress' own
	 * `wp_http_validate_url()` rejects loopback and RFC-1918 but not these,
	 * so a request for http://169.254.169.254/ passes core's check untouched.
	 *
	 * Pure — takes an IP string, resolves nothing.
	 *
	 * @param string $ip IP address.
	 *
	 * @return bool
	 * @since 2.0.1
	 */
	public static function isBlockedIp( string $ip ): bool {
		$ip = trim( $ip );

		if ( '' === $ip ) {
			return false;
		}

		// Unwrap the IPv4-mapped IPv6 form (::ffff:169.254.169.254) so the
		// IPv4 range check below sees it.
		if ( 0 === stripos( $ip, '::ffff:' ) && false !== strpos( $ip, '.' ) ) {
			$ip = substr( $ip, 7 );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 0 === strpos( $ip, '169.254.' );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( false === $packed || strlen( $packed ) < 2 ) {
				return false;
			}

			// fe80::/10 — first 10 bits are 1111111010.
			return 0xfe === ord( $packed[0] ) && 0x80 === ( ord( $packed[1] ) & 0xc0 );
		}

		return false;
	}

	/**
	 * Whether a host resolves to a blocked address.
	 *
	 * A bare IP is checked directly. A name is resolved and every A/AAAA
	 * record checked, so a DNS entry pointing at the metadata endpoint is
	 * caught. Resolution failure is not treated as a block — the request will
	 * fail on its own, and failing closed here would break hosts whose DNS is
	 * unavailable to PHP but fine for cURL.
	 *
	 * @param string $host Hostname or IP.
	 *
	 * @return bool
	 * @since 2.0.1
	 */
	public static function hostIsBlocked( string $host ): bool {
		$host = trim( $host, " \t\n\r\0\x0B[]" );

		if ( '' === $host ) {
			return false;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return static::isBlockedIp( $host );
		}

		foreach ( static::resolve( $host ) as $ip ) {
			if ( static::isBlockedIp( $ip ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolves a hostname to its A and AAAA records.
	 *
	 * Split out so tests can exercise the range logic without touching DNS.
	 *
	 * @param string $host Hostname.
	 *
	 * @return array<int,string> Resolved IPs, possibly empty.
	 * @since 2.0.1
	 */
	protected static function resolve( string $host ): array {
		$ips = [];

		if ( function_exists( 'dns_get_record' ) ) {
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					}

					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			$resolved = gethostbyname( $host );

			// gethostbyname() echoes the input back when it cannot resolve.
			if ( $resolved !== $host ) {
				$ips[] = $resolved;
			}
		}

		return $ips;
	}

	/**
	 * Validates a download URL before any network request is made.
	 *
	 * @param string $url The URL to validate.
	 *
	 * @return string|null A reason code, or null when the URL is acceptable.
	 * @since 2.0.1
	 */
	public static function validate( string $url ): ?string {
		if ( ! wp_http_validate_url( $url ) ) {
			return self::INVALID_URL;
		}

		if ( ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'http', 'https' ], true ) ) {
			return self::INVALID_SCHEME;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		// Allow theme authors to restrict which domains may serve files.
		// Return a non-empty array of hostnames to enable the allowlist.
		$allowed_domains = (array) apply_filters( 'sd/edi/allowed_download_domains', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		if ( ! empty( $allowed_domains ) && ! in_array( $host, $allowed_domains, true ) ) {
			return self::BLOCKED_DOMAIN;
		}

		if ( static::hostIsBlocked( $host ) ) {
			return self::LINK_LOCAL;
		}

		return null;
	}

	/**
	 * Fetches a validated URL, re-validating every redirect hop.
	 *
	 * `wp_safe_remote_get()`'s own per-hop check is core's
	 * `wp_http_validate_url()`, which does not cover the link-local metadata
	 * range this class exists to block (see isBlockedIp()). So we never let the
	 * transport follow the redirect itself: each request is issued with
	 * `redirection => 0`, and every `Location` is run back through validate()
	 * — scheme, allowlist and link-local — before it is followed. Redirects are
	 * still honoured (demo files are commonly served from a CDN or through an
	 * http→https hop) but capped by the caller's `redirection` value.
	 *
	 * Residual: a hostname that rebinds between validate()'s DNS lookup and the
	 * transport's own lookup is not closed here; pinning the connected IP would
	 * need an http_api_curl callback and is out of scope for this guard.
	 *
	 * @param string              $url  Validated URL.
	 * @param array<string,mixed> $args Extra wp_remote_get arguments.
	 *
	 * @return array<string,mixed>|\WP_Error
	 * @since 2.0.1
	 */
	public static function get( string $url, array $args = [] ) {
		$max_hops = isset( $args['redirection'] ) ? max( 0, (int) $args['redirection'] ) : 3;
		unset( $args['redirection'] );

		$args    = array_merge( [ 'reject_unsafe_urls' => true ], $args );
		$current = $url;

		for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
			$response = wp_safe_remote_get( $current, array_merge( $args, [ 'redirection' => 0 ] ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// Anything that is not a redirect is the final response.
			if ( $code < 300 || $code >= 400 ) {
				return $response;
			}

			$location = (string) wp_remote_retrieve_header( $response, 'location' );

			if ( '' === $location ) {
				return $response;
			}

			// Resolve relative redirects against the URL that issued them.
			$location = \WP_Http::make_absolute_url( $location, $current );

			// Re-run the full guard on the hop target before following it.
			if ( null !== static::validate( $location ) ) {
				return new \WP_Error(
					'edi_unsafe_redirect',
					__( 'The download was redirected to a disallowed address.', 'easy-demo-importer' )
				);
			}

			$current = $location;
		}

		return new \WP_Error(
			'http_request_failed',
			__( 'Too many redirects.', 'easy-demo-importer' )
		);
	}
}
