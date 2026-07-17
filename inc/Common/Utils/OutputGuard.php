<?php
/**
 * Utility Class: OutputGuard
 *
 * Keeps the plugin's AJAX responses parseable when other code prints during
 * the same request.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use wpdb;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: OutputGuard
 *
 * An import phase answers with JSON, but admin-ajax.php shares its output with
 * every other plugin on the site. Anything they print — a PHP notice, a stray
 * echo, a wpdb error block — lands in the body ahead of that JSON and makes it
 * unparseable, so the wizard stops mid-pipeline and the modal reports a success
 * that never happened.
 *
 * This is not hypothetical. A database reset clears `woocommerce_version`, so
 * WooCommerce's check_version() (init, priority 5 — before this plugin has even
 * booted) calls WC_Install::install() on every subsequent request, and that
 * takes its lock by firing an INSERT it expects to fail: "Insert will fail if it
 * already exists so this functions as a mutex". With WP_DEBUG_DISPLAY on, wpdb
 * echoes each collision as HTML. WooCommerce is behaving exactly as designed;
 * the fragility is ours.
 *
 * So: buffer from plugins_loaded (ahead of init, where the printing happens),
 * then drop whatever leaked once the handler starts. Buffering also keeps the
 * headers unsent, so wp_send_json() can still set its Content-Type instead of
 * warning that headers were already sent.
 *
 * @since 2.0.0
 */
class OutputGuard {
	/**
	 * Whether this request's output buffer was opened here.
	 *
	 * @var bool
	 * @since 2.0.0
	 */
	private static $buffering = false;

	/**
	 * Opens an output buffer for the plugin's own AJAX requests.
	 *
	 * Hooked early on plugins_loaded so that it is already capturing by the time
	 * init runs. Deliberately does not check the nonce: this only decides whether
	 * to buffer, and every handler still verifies its own request before acting.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function start(): void {
		if ( self::$buffering || ! self::isPluginAjax() ) {
			return;
		}

		self::$buffering = ob_start();
	}

	/**
	 * Discards anything printed so far and silences further wpdb output.
	 *
	 * Called once the handler is running, by which point every init-time printer
	 * has had its turn. The buffer stays open so the JSON written after this
	 * still flushes normally. hide_errors() only stops wpdb echoing — it still
	 * calls error_log(), so debug.log keeps the full record.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function reset(): void {
		if ( self::$buffering && ob_get_level() > 0 ) {
			ob_clean();
		}

		global $wpdb;

		if ( $wpdb instanceof wpdb ) {
			$wpdb->hide_errors();
		}
	}

	/**
	 * Whether this request is one of the plugin's admin-ajax actions.
	 *
	 * @return bool
	 * @since 2.0.0
	 */
	private static function isPluginAjax(): bool {
		if ( ! wp_doing_ajax() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- reads the action name only, to decide whether to buffer; the handler verifies the nonce before doing any work.
		$action = isset( $_POST['action'] ) ? sanitize_text_field( wp_unslash( $_POST['action'] ) ) : '';

		return 0 === strpos( $action, 'sd_edi_' );
	}
}
