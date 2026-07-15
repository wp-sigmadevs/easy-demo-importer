<?php
/**
 * CLI Class: Commands
 *
 * WP-CLI commands for headless / staging / CI automation. Registered under the
 * `wp edi` namespace. These reuse the plugin's decoupled utilities directly
 * (config, ThumbnailRegenerator, Snapshot) so they run entirely outside the
 * browser/AJAX pipeline — no gateway timeout, no nonces.
 *
 * A full-parity `wp edi import` (content + customizer + widgets + settings) is a
 * planned follow-up: the import phases are currently AJAX-coupled and need their
 * logic separated from the wp_send_json response layer before the CLI can drive
 * them cleanly.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Cli;

use WP_CLI;
use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Utils\Snapshot,
	Importer\ThumbnailRegenerator
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * CLI Class: Commands
 *
 * @since 2.0.0
 */
class Commands extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 2.0.0
	 */
	use Singleton;

	/**
	 * Registers the `wp edi` command when running under WP-CLI.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function register() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'edi', $this );
		}
	}

	/**
	 * Lists the demos exposed by the active theme's configuration.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp edi demos
	 *     wp edi demos --format=json
	 *
	 * @subcommand demos
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function demos( $args, $assoc_args ) {
		$config = sd_edi()->getDemoConfig();

		if ( empty( $config ) || empty( $config['demoData'] ) ) {
			WP_CLI::error( 'No demo configuration found. Is the theme providing the sd/edi/importer/config filter?' );
		}

		$rows = [];

		foreach ( $config['demoData'] as $slug => $demo ) {
			$plugins = ! empty( $demo['plugins'] ) && is_array( $demo['plugins'] )
				? implode( ', ', wp_list_pluck( $demo['plugins'], 'name' ) )
				: '';

			$rows[] = [
				'slug'    => (string) $slug,
				'name'    => isset( $demo['name'] ) ? (string) $demo['name'] : (string) $slug,
				'plugins' => $plugins,
			];
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		WP_CLI\Utils\format_items( $format, $rows, [ 'slug', 'name', 'plugins' ] );
	}

	/**
	 * Regenerates thumbnail sizes for the whole media library.
	 *
	 * Runs entirely in one process (no gateway limit), so the whole library is
	 * processed in a single pass. Only missing sizes are rebuilt unless --force
	 * is passed.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Rebuild every registered size, not just the missing ones.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edi regenerate
	 *     wp edi regenerate --force
	 *
	 * @subcommand regenerate
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function regenerate( $args, $assoc_args ) {
		$force = isset( $assoc_args['force'] );

		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No image attachments found.' );
			return;
		}

		$progress    = WP_CLI\Utils\make_progress_bar( 'Regenerating', count( $ids ) );
		$regenerated = 0;
		$skipped     = 0;
		$failed      = 0;

		foreach ( $ids as $id ) {
			$regenerator = ThumbnailRegenerator::forAttachment( (int) $id );

			if ( null === $regenerator ) {
				++$skipped;
			} elseif ( $regenerator->regenerate( ! $force ) ) {
				++$regenerated;
			} else {
				++$failed;
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success(
			sprintf( '%d regenerated, %d skipped, %d failed.', $regenerated, $skipped, $failed )
		);
	}

	/**
	 * Rolls the site back to the restore point saved before the last import.
	 *
	 * Reverts content and settings to the moment the snapshot was taken. Anything
	 * created after the import is lost.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp edi rollback
	 *     wp edi rollback --yes
	 *
	 * @subcommand rollback
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function rollback( $args, $assoc_args ) {
		if ( ! Snapshot::exists() ) {
			WP_CLI::error( 'No restore point is available to roll back to.' );
		}

		WP_CLI::confirm(
			'This restores your site to before the last import. Anything created since will be permanently lost. Continue?',
			$assoc_args
		);

		if ( ! Snapshot::restore() ) {
			WP_CLI::error( 'Rollback failed. Your site was not changed.' );
		}

		WP_CLI::success( 'Site rolled back to the pre-import restore point.' );
	}
}
