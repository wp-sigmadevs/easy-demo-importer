<?php
/**
 * Utility Class: ManualContext
 *
 * Bridges "manual import" (user-uploaded files, no theme demo config) into the
 * existing config/demo-keyed import pipeline. A manual import stages its files
 * into a working directory named `manual-{key}` and supplies a minimal config
 * stub; every existing phase then resolves that directory the same way it would
 * for a packaged demo, and phases whose data the upload didn't provide
 * (settings, sliders, fluent forms, plugins) simply skip because the stub does
 * not declare them.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: ManualContext
 *
 * @since 2.0.0
 */
final class ManualContext {

	/**
	 * Reserved demo slug for manual imports.
	 */
	const SLUG = '__manual__';

	/**
	 * Working-directory name prefix.
	 */
	const DIR_PREFIX = 'manual-';

	/**
	 * Working-directory name (the "demoDir") for a manual key.
	 *
	 * @param string $key Manual key.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	public static function demoDir( string $key ): string {
		return self::DIR_PREFIX . self::sanitizeKey( $key );
	}

	/**
	 * Minimal config stub so the packaged-demo phases can operate on the manual
	 * working directory. `demoZip`'s basename is what `ImporterAjax::demoDir()`
	 * derives the directory from; the absence of everything else makes the
	 * settings/slider/plugin phases no-op.
	 *
	 * @param string $key Manual key.
	 *
	 * @return array
	 * @since 2.0.0
	 */
	public static function configStub( string $key ): array {
		return [
			'multipleZip' => false,
			'demoZip'     => self::demoDir( $key ) . '.zip',
		];
	}

	/**
	 * Reduces a manual key to a safe hex token — this becomes part of a
	 * filesystem path, so nothing but `[a-f0-9]` is allowed (no traversal).
	 *
	 * @param string $key Raw key.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	public static function sanitizeKey( string $key ): string {
		return (string) preg_replace( '/[^a-f0-9]/', '', strtolower( $key ) );
	}

	/**
	 * Whether the current request is flagged as a manual import.
	 *
	 * @return bool
	 * @since 2.0.0
	 */
	public static function isManual(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies the nonce first.
		return isset( $_POST['manual'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['manual'] ) );
	}

	/**
	 * The manual key from the current request.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	public static function requestKey(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verifies the nonce first.
		$key = isset( $_POST['manualKey'] ) ? sanitize_text_field( wp_unslash( $_POST['manualKey'] ) ) : '';

		return self::sanitizeKey( $key );
	}
}
