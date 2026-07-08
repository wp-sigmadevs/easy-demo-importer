<?php
/**
 * Backend Class: Callbacks
 *
 * The list of all callback functions.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Functions;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Class: Callbacks
 *
 * @since 1.0.0
 */
class Callbacks {
	/**
	 * Callback: Admin Section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function renderDemoImportPage() {
		Helpers::renderView( 'demo-import' );
	}

	/**
	 * Callback: Server Section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function renderServerStatusPage() {
		Helpers::renderView( 'server-status' );
	}

	/**
	 * Callback: Import Log Section.
	 *
	 * A self-contained, plain-PHP view of the activity log — no build step
	 * required. The live-polling React feed supersedes this in a later release.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function renderImportLogPage() {
		// Ensure the table exists on installs that pre-date the log.
		ImportLogger::maybeInstall();

		$entries = ImportLogger::get( '', 500 );

		$colors = [
			'error'   => '#d63638',
			'warning' => '#dba617',
			'success' => '#00a32a',
			'info'    => '#2271b1',
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Easy Demo Importer — Import Log', 'easy-demo-importer' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'The 500 most recent import events, newest first. Warnings and errors are also written to your server error log.', 'easy-demo-importer' ); ?>
			</p>

			<?php if ( empty( $entries ) ) : ?>
				<p><?php esc_html_e( 'No import activity has been logged yet.', 'easy-demo-importer' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:1em;">
					<thead>
						<tr>
							<th style="width:160px;"><?php esc_html_e( 'Time', 'easy-demo-importer' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Level', 'easy-demo-importer' ); ?></th>
							<th><?php esc_html_e( 'Message', 'easy-demo-importer' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $level = (string) $entry['level']; ?>
							<tr>
								<td><?php echo esc_html( $entry['logged_at'] ); ?></td>
								<td>
									<span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;text-transform:uppercase;background:<?php echo esc_attr( $colors[ $level ] ?? $colors['info'] ); ?>;">
										<?php echo esc_html( $level ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $entry['message'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
