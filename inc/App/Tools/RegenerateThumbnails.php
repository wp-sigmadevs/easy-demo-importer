<?php
/**
 * Tools Class: RegenerateThumbnails
 *
 * A standalone "Regenerate Thumbnails" utility under the WordPress Tools menu.
 * Reuses the resumable, time-boxed ThumbnailRegenerator engine (the same one
 * that powers the import's regeneration phase) to rebuild intermediate image
 * sizes across the whole media library without timing out on large libraries.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Tools;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Importer\ThumbnailRegenerator
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Tools Class: RegenerateThumbnails
 *
 * @since 1.2.0
 */
class RegenerateThumbnails extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.2.0
	 */
	use Singleton;

	/**
	 * Required capability.
	 */
	const CAP = 'manage_options';

	/**
	 * Admin page slug.
	 */
	const SLUG = 'sd-edi-regenerate-thumbnails';

	/**
	 * AJAX action + nonce name.
	 */
	const ACTION = 'sd_edi_regen_thumbnails';

	/**
	 * Image IDs fetched per request before the time-box is checked.
	 */
	const PAGE_LIMIT = 50;

	/**
	 * Registers the Tools page and its AJAX handler.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register() {
		add_action( 'admin_menu', [ $this, 'menu' ] );
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handle' ] );
	}

	/**
	 * Adds the submenu under Tools.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function menu() {
		add_management_page(
			esc_html__( 'Regenerate Thumbnails', 'easy-demo-importer' ),
			esc_html__( 'Regenerate Thumbnails', 'easy-demo-importer' ),
			self::CAP,
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * AJAX: process one time-boxed page of image attachments.
	 *
	 * Stateless across requests: the cursor is the last processed attachment ID,
	 * round-tripped via the request. Idempotent — re-running metadata generation
	 * for an already-processed image is safe, so a re-issue simply continues.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function handle() {
		if ( ! check_ajax_referer( self::ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'Security check failed. Refresh the page and try again.', 'easy-demo-importer' ) ], 403 );
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => esc_html__( 'You do not have permission to do this.', 'easy-demo-importer' ) ], 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$after       = isset( $_POST['after'] ) ? absint( wp_unslash( $_POST['after'] ) ) : 0;
		$force       = isset( $_POST['force'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['force'] ) );
		$regenerated = isset( $_POST['regenerated'] ) ? absint( wp_unslash( $_POST['regenerated'] ) ) : 0;
		$skipped     = isset( $_POST['skipped'] ) ? absint( wp_unslash( $_POST['skipped'] ) ) : 0;
		$failed      = isset( $_POST['failed'] ) ? absint( wp_unslash( $_POST['failed'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$total = $this->totalImages();
		$ids   = $this->imageIdsAfter( $after, self::PAGE_LIMIT );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				[
					'done'        => true,
					'after'       => $after,
					'total'       => $total,
					'regenerated' => $regenerated,
					'skipped'     => $skipped,
					'failed'      => $failed,
				]
			);
		}

		$budget = (float) apply_filters( 'sd/edi/regen_tool_seconds', 15 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$start  = microtime( true );
		$last   = $after;

		foreach ( $ids as $id ) {
			$regenerator = ThumbnailRegenerator::forAttachment( (int) $id );

			if ( null === $regenerator ) {
				++$skipped;
			} elseif ( $regenerator->regenerate( ! $force ) ) {
				++$regenerated;
			} else {
				++$failed;
			}

			$last = (int) $id;

			// Finish the current image, then stop once over budget.
			if ( ( microtime( true ) - $start ) >= $budget ) {
				break;
			}
		}

		wp_send_json_success(
			[
				'done'        => false,
				'after'       => $last,
				'total'       => $total,
				'regenerated' => $regenerated,
				'skipped'     => $skipped,
				'failed'      => $failed,
			]
		);
	}

	/**
	 * Total number of image attachments in the library.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	private function totalImages(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
		);
	}

	/**
	 * The next page of image attachment IDs after a cursor, ascending.
	 *
	 * @param int $after Last processed attachment ID (0 to start).
	 * @param int $limit Page size.
	 *
	 * @return int[]
	 * @since 1.2.0
	 */
	private function imageIdsAfter( int $after, int $limit ): array {
		global $wpdb;

		$like = $wpdb->esc_like( 'image/' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				$like,
				$after,
				$limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Renders the Tools page.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'easy-demo-importer' ) );
		}

		$total  = $this->totalImages();
		$config = [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'action'  => self::ACTION,
			'nonce'   => wp_create_nonce( self::ACTION ),
			'total'   => $total,
			'i18n'    => [
				'running' => esc_html__( 'Regenerating…', 'easy-demo-importer' ),
				'done'    => esc_html__( 'Done.', 'easy-demo-importer' ),
				'failed'  => esc_html__( 'Something went wrong. Please try again.', 'easy-demo-importer' ),
				'start'   => esc_html__( 'Regenerate Thumbnails', 'easy-demo-importer' ),
			],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Regenerate Thumbnails', 'easy-demo-importer' ); ?></h1>
			<p class="description" style="max-width:640px">
				<?php esc_html_e( 'Rebuilds the intermediate image sizes for your media library. Runs in small, resumable batches so it will not time out on large libraries.', 'easy-demo-importer' ); ?>
			</p>

			<p>
				<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong>
				<?php esc_html_e( 'images found.', 'easy-demo-importer' ); ?>
			</p>

			<p>
				<label>
					<input type="checkbox" id="sd-edi-regt-force">
					<?php esc_html_e( 'Force-regenerate every size (slower — use only when a registered size changed dimensions). Otherwise only missing sizes are created.', 'easy-demo-importer' ); ?>
				</label>
			</p>

			<p>
				<button type="button" class="button button-primary" id="sd-edi-regt-start" <?php disabled( 0, $total ); ?>>
					<?php esc_html_e( 'Regenerate Thumbnails', 'easy-demo-importer' ); ?>
				</button>
			</p>

			<div id="sd-edi-regt-progress" style="display:none;max-width:640px">
				<div style="background:#dcdcde;border-radius:6px;overflow:hidden;height:22px">
					<div id="sd-edi-regt-bar" style="width:0;height:100%;background:#2271b1;color:#fff;text-align:center;line-height:22px;font-size:12px;transition:width .2s">0%</div>
				</div>
				<p id="sd-edi-regt-status" style="margin-top:8px;color:#50575e"></p>
			</div>
		</div>

		<script>
			( function () {
				var cfg   = <?php echo wp_json_encode( $config ); ?>;
				var btn   = document.getElementById( 'sd-edi-regt-start' );
				var force = document.getElementById( 'sd-edi-regt-force' );
				var wrap  = document.getElementById( 'sd-edi-regt-progress' );
				var bar   = document.getElementById( 'sd-edi-regt-bar' );
				var out   = document.getElementById( 'sd-edi-regt-status' );

				if ( ! btn ) { return; }

				var state = { after: 0, regenerated: 0, skipped: 0, failed: 0 };

				function render( d ) {
					var processed = d.regenerated + d.skipped + d.failed;
					var pct = d.total > 0 ? Math.min( 100, Math.round( processed / d.total * 100 ) ) : 100;
					bar.style.width = pct + '%';
					bar.textContent = pct + '%';
					out.textContent = ( d.done ? cfg.i18n.done + ' ' : cfg.i18n.running + ' ' ) +
						processed + ' / ' + d.total +
						' — ' + d.regenerated + ' regenerated, ' + d.skipped + ' skipped, ' + d.failed + ' failed';
				}

				function fail() {
					out.textContent = cfg.i18n.failed;
					btn.disabled = false;
				}

				function step() {
					var body = new FormData();
					body.append( 'action', cfg.action );
					body.append( 'nonce', cfg.nonce );
					body.append( 'after', state.after );
					body.append( 'force', force.checked ? 'true' : 'false' );
					body.append( 'regenerated', state.regenerated );
					body.append( 'skipped', state.skipped );
					body.append( 'failed', state.failed );

					fetch( cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
						.then( function ( r ) { return r.json(); } )
						.then( function ( res ) {
							if ( ! res || ! res.success ) { fail(); return; }
							var d = res.data;
							state.after = d.after;
							state.regenerated = d.regenerated;
							state.skipped = d.skipped;
							state.failed = d.failed;
							render( d );
							if ( d.done ) { btn.disabled = false; } else { step(); }
						} )
						.catch( fail );
				}

				btn.addEventListener( 'click', function () {
					btn.disabled = true;
					wrap.style.display = 'block';
					state = { after: 0, regenerated: 0, skipped: 0, failed: 0 };
					step();
				} );
			}() );
		</script>
		<?php
	}
}
