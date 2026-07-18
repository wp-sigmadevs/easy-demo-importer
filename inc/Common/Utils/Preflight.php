<?php
/**
 * Utility Class: Preflight
 *
 * Builds a pre-import readiness report — a list of environment checks (PHP,
 * memory, writable uploads, required extensions, required plugins, proxy) each
 * graded pass / warn / fail, plus a single `canProceed` flag that is false when
 * any blocking check fails. The wizard uses it to gate "Start Import" so a run
 * fails fast and legibly up front instead of dying mid-import.
 *
 * The value-based decisions (version compare, byte thresholds) are pure static
 * methods so they can be unit-tested without the environment; report() gathers
 * the real values and assembles the checks from them.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Common\Functions\Helpers;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: Preflight
 *
 * @since 2.0.0
 */
final class Preflight {

	/**
	 * Check statuses.
	 */
	const PASS = 'pass';
	const WARN = 'warn';
	const FAIL = 'fail';

	/**
	 * Minimum PHP version the importer requires.
	 */
	const MIN_PHP = '7.4';

	/**
	 * Recommended memory floor for a comfortable import.
	 */
	const RECOMMENDED_MEMORY = '256M';

	/**
	 * Recommended max_execution_time floor, in seconds. The chunked importer
	 * works around a low limit, but anything under this is unusually tight.
	 */
	const RECOMMENDED_EXECUTION_TIME = 30;

	/**
	 * Recommended free disk space for demo media downloads.
	 */
	const RECOMMENDED_DISK_SPACE = '200M';

	/**
	 * Builds the full readiness report.
	 *
	 * Required-plugin status is deliberately not part of this report — it's
	 * already shown in full by the Required Plugins list beside it, and isn't
	 * an environment concern the way the checks below are.
	 *
	 * @return array{checks:array<int,array>,canProceed:bool}
	 * @since 2.0.0
	 */
	public static function report(): array {
		$checks = [
			self::phpVersionCheck( PHP_VERSION, self::MIN_PHP ),
			self::memoryCheck( (string) ini_get( 'memory_limit' ), self::RECOMMENDED_MEMORY ),
			self::executionTimeCheck( (int) ini_get( 'max_execution_time' ) ),
			self::extensionCheck( 'ZipArchive', class_exists( '\ZipArchive' ), true ),
			self::extensionCheck( 'SimpleXML', extension_loaded( 'simplexml' ), true ),
			self::imageLibraryCheck( extension_loaded( 'gd' ), extension_loaded( 'imagick' ) ),
			self::uploadsWritableCheck(),
			self::diskSpaceCheck(),
			self::proxyCheck(),
		];

		$can_proceed = true;

		foreach ( $checks as $check ) {
			if ( self::FAIL === $check['status'] && ! empty( $check['blocking'] ) ) {
				$can_proceed = false;
				break;
			}
		}

		return [
			'checks'     => $checks,
			'canProceed' => $can_proceed,
		];
	}

	/**
	 * PHP version check (blocking).
	 *
	 * @param string $current Current PHP version.
	 * @param string $minimum Minimum required version.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function phpVersionCheck( string $current, string $minimum ): array {
		$ok = version_compare( $current, $minimum, '>=' );

		if ( $ok ) {
			/* translators: %s: PHP version. */
			$message = sprintf( esc_html__( 'PHP %s', 'easy-demo-importer' ), $current );
		} else {
			/* translators: 1: current PHP version, 2: required version. */
			$message = sprintf( esc_html__( 'PHP %1$s — this plugin requires PHP %2$s or newer.', 'easy-demo-importer' ), $current, $minimum );
		}

		return self::check(
			'php_version',
			esc_html__( 'PHP version', 'easy-demo-importer' ),
			$ok ? self::PASS : self::FAIL,
			$message,
			true
		);
	}

	/**
	 * Memory limit check. A shortfall warns rather than blocks — WordPress can
	 * often raise the limit for the import, and the chunked importer keeps peak
	 * usage low.
	 *
	 * @param string $current  Current memory_limit ini value.
	 * @param string $required Recommended memory floor.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function memoryCheck( string $current, string $required ): array {
		$current_bytes = self::toBytes( $current );

		// -1 means unlimited.
		$ok = -1 === $current_bytes || $current_bytes >= self::toBytes( $required );

		if ( $ok ) {
			$message = -1 === $current_bytes ? esc_html__( 'Unlimited', 'easy-demo-importer' ) : $current;
		} else {
			/* translators: 1: current limit, 2: recommended limit. */
			$message = sprintf( esc_html__( '%1$s — %2$s or more is recommended for large imports.', 'easy-demo-importer' ), $current, $required );
		}

		return self::check(
			'memory_limit',
			esc_html__( 'PHP memory limit', 'easy-demo-importer' ),
			$ok ? self::PASS : self::WARN,
			$message,
			false
		);
	}

	/**
	 * max_execution_time check. A low limit warns rather than blocks — the
	 * importer runs in resumable chunks specifically to survive this.
	 *
	 * @param int $current Current max_execution_time ini value, in seconds.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function executionTimeCheck( int $current ): array {
		// 0 means unlimited (common on CLI or hosts that don't enforce it).
		$ok = 0 === $current || $current >= self::RECOMMENDED_EXECUTION_TIME;

		if ( 0 === $current ) {
			$message = esc_html__( 'Unlimited', 'easy-demo-importer' );
		} elseif ( $ok ) {
			/* translators: %d: seconds. */
			$message = sprintf( esc_html__( '%d seconds', 'easy-demo-importer' ), $current );
		} else {
			/* translators: %d: seconds. */
			$message = sprintf( esc_html__( '%d seconds — the import runs in resumable chunks to work around this, but a higher limit is safer.', 'easy-demo-importer' ), $current );
		}

		return self::check(
			'execution_time',
			esc_html__( 'PHP execution time', 'easy-demo-importer' ),
			$ok ? self::PASS : self::WARN,
			$message,
			false
		);
	}

	/**
	 * Converts a PHP shorthand byte value (e.g. "256M") to bytes.
	 *
	 * @param string $value Shorthand or numeric byte value.
	 *
	 * @return int Bytes; -1 is preserved (unlimited).
	 * @since 2.0.0
	 */
	public static function toBytes( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		if ( '-1' === $value ) {
			return -1;
		}

		$unit   = strtolower( $value[ strlen( $value ) - 1 ] );
		$number = (int) $value;

		switch ( $unit ) {
			case 'g':
				$number *= 1024;
				// Cascade g → m → k. no break.
			case 'm':
				$number *= 1024;
				// no break.
			case 'k':
				$number *= 1024;
		}

		return $number;
	}

	/**
	 * Presence check for a required PHP extension/class.
	 *
	 * @param string $label    Human label.
	 * @param bool   $present  Whether it is available.
	 * @param bool   $blocking Whether absence blocks the import.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function extensionCheck( string $label, bool $present, bool $blocking ): array {
		if ( $present ) {
			$message = esc_html__( 'Available', 'easy-demo-importer' );
		} else {
			/* translators: %s: extension name. */
			$message = sprintf( esc_html__( '%s is required but not available. Ask your host to enable it.', 'easy-demo-importer' ), $label );
		}

		return self::check(
			'ext_' . strtolower( $label ),
			$label,
			$present ? self::PASS : self::FAIL,
			$message,
			$blocking
		);
	}

	/**
	 * At least one image library (GD or Imagick) is needed for thumbnails.
	 *
	 * @param bool $gd      GD present.
	 * @param bool $imagick Imagick present.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function imageLibraryCheck( bool $gd, bool $imagick ): array {
		$ok = $gd || $imagick;

		return self::check(
			'image_library',
			esc_html__( 'Image library', 'easy-demo-importer' ),
			$ok ? self::PASS : self::WARN,
			$ok
				? ( $imagick ? 'Imagick' : 'GD' )
				: esc_html__( 'Neither GD nor Imagick is available — image thumbnails will not be generated.', 'easy-demo-importer' ),
			false
		);
	}

	/**
	 * Uploads directory must be writable for media + demo files (blocking).
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function uploadsWritableCheck(): array {
		$uploads  = wp_get_upload_dir();
		$writable = ! empty( $uploads['basedir'] ) && wp_is_writable( $uploads['basedir'] );

		return self::check(
			'uploads_writable',
			esc_html__( 'Uploads folder', 'easy-demo-importer' ),
			$writable ? self::PASS : self::FAIL,
			$writable
				? esc_html__( 'Writable', 'easy-demo-importer' )
				: esc_html__( 'The uploads folder is not writable. Fix its permissions before importing.', 'easy-demo-importer' ),
			true
		);
	}

	/**
	 * Free disk space in the uploads directory. Not blocking — the importer
	 * can't reliably tell in advance how much a demo's media will need — but
	 * a near-full disk is a common, confusing cause of a failed media download.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function diskSpaceCheck(): array {
		$uploads = wp_get_upload_dir();
		$path    = ! empty( $uploads['basedir'] ) ? $uploads['basedir'] : ABSPATH;
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $free ) {
			return self::check(
				'disk_space',
				esc_html__( 'Disk space', 'easy-demo-importer' ),
				self::PASS,
				esc_html__( 'Could not be determined — your host may restrict this check.', 'easy-demo-importer' ),
				false
			);
		}

		$required = self::toBytes( self::RECOMMENDED_DISK_SPACE );
		$ok       = $free >= $required;

		return self::check(
			'disk_space',
			esc_html__( 'Disk space', 'easy-demo-importer' ),
			$ok ? self::PASS : self::WARN,
			$ok
				? sprintf(
					/* translators: %s: free disk space. */
					esc_html__( '%s free', 'easy-demo-importer' ),
					size_format( (int) $free )
				)
				: sprintf(
					/* translators: 1: free disk space, 2: recommended minimum. */
					esc_html__( 'Only %1$s free — %2$s or more is recommended for demo media downloads.', 'easy-demo-importer' ),
					size_format( (int) $free ),
					self::RECOMMENDED_DISK_SPACE
				),
			false
		);
	}

	/**
	 * Reports required plugins that are not yet active. Not blocking — the wizard
	 * installs/activates them as a step — but worth surfacing up front.
	 *
	 * @param array $config Demo config.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function requiredPluginsCheck( array $config ): array {
		$plugins = self::collectPlugins( $config );
		$missing = [];

		foreach ( $plugins as $plugin ) {
			if ( empty( $plugin['filePath'] ) ) {
				continue;
			}

			if ( 'active' !== Helpers::pluginActivationStatus( $plugin['filePath'] ) ) {
				$missing[] = ! empty( $plugin['name'] ) ? $plugin['name'] : $plugin['filePath'];
			}
		}

		if ( empty( $missing ) ) {
			return self::check(
				'required_plugins',
				esc_html__( 'Required plugins', 'easy-demo-importer' ),
				self::PASS,
				esc_html__( 'All required plugins are active.', 'easy-demo-importer' ),
				false
			);
		}

		/* translators: %s: comma-separated plugin names. */
		$message = sprintf( esc_html__( 'These will be installed/activated during import: %s', 'easy-demo-importer' ), implode( ', ', $missing ) );

		return self::check(
			'required_plugins',
			esc_html__( 'Required plugins', 'easy-demo-importer' ),
			self::WARN,
			$message,
			false
		);
	}

	/**
	 * Notes when the site sits behind a reverse proxy (e.g. Cloudflare), which
	 * enforces a fixed request timeout the chunked importer is built to survive.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function proxyCheck(): array {
		$behind = isset( $_SERVER['HTTP_CF_RAY'] ) || isset( $_SERVER['HTTP_CF_CONNECTING_IP'] );

		return self::check(
			'proxy',
			esc_html__( 'Reverse proxy', 'easy-demo-importer' ),
			self::PASS,
			$behind
				? esc_html__( 'Cloudflare detected — the import runs in small resumable steps to stay under the gateway timeout. For images, a bundled media package is most reliable.', 'easy-demo-importer' )
				: esc_html__( 'None detected.', 'easy-demo-importer' ),
			false
		);
	}

	/**
	 * Flattens the demo config's required-plugin definitions across single- and
	 * multi-zip layouts into one list.
	 *
	 * @param array $config Demo config.
	 *
	 * @return array<int,array>
	 * @since 2.0.0
	 */
	public static function collectPlugins( array $config ): array {
		$plugins = [];

		if ( empty( $config['multipleZip'] ) ) {
			$plugins = ! empty( $config['plugins'] ) ? $config['plugins'] : [];
		} elseif ( ! empty( $config['demoData'] ) && is_array( $config['demoData'] ) ) {
			foreach ( $config['demoData'] as $demo ) {
				if ( ! empty( $demo['plugins'] ) && is_array( $demo['plugins'] ) ) {
					foreach ( $demo['plugins'] as $key => $plugin ) {
						$plugins[ $key ] = $plugin;
					}
				}
			}
		}

		return array_values( $plugins );
	}

	/**
	 * Builds a single check entry.
	 *
	 * @param string $id       Machine id.
	 * @param string $label    Display label.
	 * @param string $status   pass|warn|fail.
	 * @param string $message  Detail message.
	 * @param bool   $blocking Whether a fail blocks the import.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	private static function check( string $id, string $label, string $status, string $message, bool $blocking ): array {
		return [
			'id'       => $id,
			'label'    => $label,
			'status'   => $status,
			'message'  => $message,
			'blocking' => $blocking,
		];
	}
}
