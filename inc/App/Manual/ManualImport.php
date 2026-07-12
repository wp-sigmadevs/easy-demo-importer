<?php
/**
 * Manual Class: ManualImport
 *
 * Handles the "manual import" upload: validates and stages the user-supplied
 * files (WXR content, optional customizer .dat, optional widgets file) into a
 * manual working directory, starts an import session, and returns the manual key
 * + session id. The wizard then runs the existing import pipeline against that
 * directory (see ManualContext + ImporterAjax's manual branch).
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Manual;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Functions\Helpers,
	Functions\SessionManager,
	Utils\ManualContext
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Manual Class: ManualImport
 *
 * @since 1.2.0
 */
class ManualImport extends Base {
	/**
	 * Singleton trait.
	 *
	 * @see Singleton
	 * @since 1.2.0
	 */
	use Singleton;

	/**
	 * AJAX action for the upload.
	 */
	const ACTION = 'sd_edi_manual_upload';

	/**
	 * Registers the upload handler.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handleUpload' ] );
	}

	/**
	 * Validates + stages the uploaded files, starts a session, returns the key.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function handleUpload() {
		if ( ! check_ajax_referer( Helpers::nonceText(), Helpers::nonceId(), false ) ) {
			$this->fail( esc_html__( 'Security check failed. Refresh the page and try again.', 'easy-demo-importer' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( esc_html__( 'You do not have permission to do this.', 'easy-demo-importer' ), 403 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( empty( $_FILES['content'] ) || ! is_array( $_FILES['content'] ) ) {
			$this->fail( esc_html__( 'A content (WXR/XML) file is required.', 'easy-demo-importer' ) );
		}

		$max = (int) apply_filters( 'sd/edi/manual_upload_max_bytes', 128 * MB_IN_BYTES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		$key = substr( md5( uniqid( 'sdedi', true ) ), 0, 20 );
		$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'easy-demo-importer/' . ManualContext::demoDir( $key );

		if ( ! wp_mkdir_p( $dir ) ) {
			$this->fail( esc_html__( 'Could not create the working directory. Check uploads folder permissions.', 'easy-demo-importer' ) );
		}

		// ── Content (required WXR) ──────────────────────────────────────────────
		$content = $this->normalizeFile( $_FILES['content'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! $this->uploadOk( $content, $max ) ) {
			$this->fail( esc_html__( 'The content file failed to upload or is too large.', 'easy-demo-importer' ) );
		}

		if ( ! $this->looksLikeWxr( $content['tmp_name'], $content['name'] ) ) {
			$this->fail( esc_html__( 'That does not look like a WordPress export (WXR/XML) file.', 'easy-demo-importer' ) );
		}

		if ( ! $this->stage( $content['tmp_name'], $dir . '/content.xml' ) ) {
			$this->fail( esc_html__( 'Could not save the content file.', 'easy-demo-importer' ) );
		}

		// ── Customizer (optional .dat) ──────────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_FILES['customizer'] ) && ! empty( $_FILES['customizer']['name'] ) ) {
			$cust = $this->normalizeFile( $_FILES['customizer'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( $this->uploadOk( $cust, $max ) && $this->hasExtension( $cust['name'], [ 'dat' ] ) ) {
				// The customizer model unserializes with allowed_classes => false,
				// so a hostile .dat cannot instantiate objects.
				$this->stage( $cust['tmp_name'], $dir . '/customizer.dat' );
			}
		}

		// ── Widgets (optional .wie/.json) ───────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_FILES['widgets'] ) && ! empty( $_FILES['widgets']['name'] ) ) {
			$widgets = $this->normalizeFile( $_FILES['widgets'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( $this->uploadOk( $widgets, $max ) && $this->hasExtension( $widgets['name'], [ 'wie', 'json' ] ) && $this->isValidJson( $widgets['tmp_name'] ) ) {
				$this->stage( $widgets['tmp_name'], $dir . '/widget.wie' );
			}
		}

		$session = SessionManager::start();

		wp_send_json_success(
			[
				'manualKey' => $key,
				'sessionId' => $session['session_id'],
			]
		);
	}

	/**
	 * Normalises a single-file $_FILES entry to the fields we use.
	 *
	 * @param array $file Raw $_FILES entry.
	 *
	 * @return array{name:string,tmp_name:string,size:int,error:int}
	 * @since 1.2.0
	 */
	private function normalizeFile( array $file ): array {
		return [
			'name'     => isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '',
			'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
			'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
			'error'    => isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
		];
	}

	/**
	 * Whether an upload arrived cleanly and is within the size cap.
	 *
	 * @param array $file Normalised file.
	 * @param int   $max  Max bytes.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function uploadOk( array $file, int $max ): bool {
		return UPLOAD_ERR_OK === $file['error']
			&& '' !== $file['tmp_name']
			&& is_uploaded_file( $file['tmp_name'] )
			&& $file['size'] > 0
			&& $file['size'] <= $max;
	}

	/**
	 * Case-insensitive extension check.
	 *
	 * @param string   $name       File name.
	 * @param string[] $extensions Allowed extensions (no dot).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function hasExtension( string $name, array $extensions ): bool {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		return in_array( $ext, $extensions, true );
	}

	/**
	 * Sniffs the head of an upload to confirm it is a WordPress export.
	 *
	 * @param string $tmp  Temp path.
	 * @param string $name File name.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function looksLikeWxr( string $tmp, string $name ): bool {
		if ( ! $this->hasExtension( $name, [ 'xml' ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$head = (string) file_get_contents( $tmp, false, null, 0, 4096 );

		if ( '' === $head || false === stripos( $head, '<?xml' ) ) {
			return false;
		}

		return false !== stripos( $head, 'wxr_version' ) || false !== stripos( $head, '<rss' );
	}

	/**
	 * Whether a file contains decodable JSON.
	 *
	 * @param string $tmp Temp path.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function isValidJson( string $tmp ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$raw = (string) file_get_contents( $tmp );

		if ( '' === $raw ) {
			return false;
		}

		json_decode( $raw );

		return JSON_ERROR_NONE === json_last_error();
	}

	/**
	 * Moves an uploaded temp file to its staged destination.
	 *
	 * @param string $tmp  Temp path.
	 * @param string $dest Destination path.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function stage( string $tmp, string $dest ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_move_uploaded_file
		return is_uploaded_file( $tmp ) && move_uploaded_file( $tmp, $dest );
	}

	/**
	 * Sends an error response and exits.
	 *
	 * @param string $message Error message.
	 * @param int    $code    HTTP status.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function fail( string $message, int $code = 400 ) {
		wp_send_json_error( [ 'message' => $message ], $code );
	}
}
