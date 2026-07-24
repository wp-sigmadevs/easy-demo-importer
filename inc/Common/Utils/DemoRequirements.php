<?php
/**
 * Utility Class: DemoRequirements
 *
 * Evaluates a demo's optional `requires` block against the current server so
 * the wizard can grey out demos whose prerequisites are not met — preventing a
 * guaranteed-to-fail import instead of surfacing the failure mid-run. Checks
 * cover things that cannot be auto-resolved during import (unlike the `plugins`
 * list, which is installed automatically): a minimum PHP version, required PHP
 * extensions, and plugins that must already be active (e.g. premium/bundled
 * plugins the importer cannot fetch).
 *
 * The evaluation is a pure static method taking the `requires` array and
 * returning a met flag + a list of human-readable missing labels, so it is
 * unit-testable and reusable by REST, CLI, or preflight.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.1.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use SigmaDevs\EasyDemoImporter\Common\Functions\Helpers;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: DemoRequirements
 *
 * @since 2.1.0
 */
final class DemoRequirements {

	/**
	 * Plugin activation statuses that count as "active enough" to satisfy a
	 * requirement. 'update' means the plugin is active with an update available.
	 *
	 * @var string[]
	 */
	private const ACTIVE_STATUSES = [ 'active', 'update' ];

	/**
	 * Evaluate a demo's `requires` block against the current server.
	 *
	 * Expected shape (every key optional):
	 *   'requires' => [
	 *       'php'        => '8.0',                          // minimum PHP version
	 *       'extensions' => [ 'imagick', 'soap' ],          // loaded PHP extensions
	 *       'plugins'    => [ 'acf-pro/acf.php' => 'ACF Pro' ], // filePath => label, must be active
	 *   ]
	 *
	 * @param mixed $requires The demo's `requires` value (array or absent).
	 *
	 * @return array{met: bool, missing: string[]} Met flag + missing labels.
	 * @since 2.1.0
	 */
	public static function evaluate( $requires ): array {
		if ( ! is_array( $requires ) || empty( $requires ) ) {
			return [
				'met'     => true,
				'missing' => [],
			];
		}

		$missing = [];

		self::checkPhp( $requires, $missing );
		self::checkExtensions( $requires, $missing );
		self::checkPlugins( $requires, $missing );

		return [
			'met'     => empty( $missing ),
			'missing' => array_values( $missing ),
		];
	}

	/**
	 * Appends a label when the running PHP is older than the required version.
	 *
	 * @param array    $requires The `requires` block.
	 * @param string[] $missing  Missing-labels accumulator, by reference.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	private static function checkPhp( array $requires, array &$missing ): void {
		if ( empty( $requires['php'] ) ) {
			return;
		}

		$min = (string) $requires['php'];

		if ( version_compare( PHP_VERSION, $min, '<' ) ) {
			$missing[] = sprintf(
				/* translators: 1: required PHP version, 2: current PHP version */
				esc_html__( 'PHP %1$s (you have %2$s)', 'easy-demo-importer' ),
				$min,
				PHP_VERSION
			);
		}
	}

	/**
	 * Appends a label for each required PHP extension that is not loaded.
	 *
	 * @param array    $requires The `requires` block.
	 * @param string[] $missing  Missing-labels accumulator, by reference.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	private static function checkExtensions( array $requires, array &$missing ): void {
		if ( empty( $requires['extensions'] ) || ! is_array( $requires['extensions'] ) ) {
			return;
		}

		foreach ( $requires['extensions'] as $ext ) {
			if ( ! extension_loaded( (string) $ext ) ) {
				$missing[] = sprintf(
					/* translators: %s: PHP extension name */
					esc_html__( '%s PHP extension', 'easy-demo-importer' ),
					(string) $ext
				);
			}
		}
	}

	/**
	 * Appends a label for each required plugin that is not currently active.
	 *
	 * Accepts either a keyed map (filePath => label) or a plain list of file
	 * paths; for a plain list the file path doubles as the label.
	 *
	 * @param array    $requires The `requires` block.
	 * @param string[] $missing  Missing-labels accumulator, by reference.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	private static function checkPlugins( array $requires, array &$missing ): void {
		if ( empty( $requires['plugins'] ) || ! is_array( $requires['plugins'] ) ) {
			return;
		}

		foreach ( $requires['plugins'] as $filePath => $label ) {
			// Plain list ([ 'acf/acf.php' ]) — the path is also the label.
			if ( is_int( $filePath ) ) {
				$filePath = (string) $label;
			}

			$status = Helpers::pluginActivationStatus( (string) $filePath );

			if ( ! in_array( $status, self::ACTIVE_STATUSES, true ) ) {
				$missing[] = (string) $label;
			}
		}
	}
}
