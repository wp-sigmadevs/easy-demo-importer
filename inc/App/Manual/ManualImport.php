<?php
/**
 * Manual Class: ManualImport
 *
 * Handles the "manual import" upload. Files are uploaded in chunks, one target
 * at a time, into a per-session working directory; a final "finalize" request
 * then unpacks and routes anything that needs it (a settings .zip of per-option
 * JSONs, an images .zip, or a single bundle .zip containing everything) and
 * starts the import session. The wizard then runs the existing import pipeline
 * against that directory (see ManualContext + ImporterAjax's manual branch).
 *
 * Two client modes feed the same machinery:
 *   - Separate: content.xml (required) + optional customizer.dat / widget.wie /
 *     settings(.json|.zip) / images.zip, each uploaded to its own target.
 *   - Bundle: a single bundle.zip that is unpacked and mapped by extension.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Manual;

use SigmaDevs\EasyDemoImporter\Config\Setup;
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
	 * Cron hook that sweeps abandoned upload artifacts.
	 */
	const CLEANUP_HOOK = 'sd_edi_manual_cleanup';

	/**
	 * Allowed upload targets → the filename each assembles into.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	private function targets(): array {
		return [
			'content'    => 'content.xml',
			'customizer' => 'customizer.dat',
			'widgets'    => 'widget.wie',
			'settings'   => 'settings.json',
			'settingsZip' => 'settings.zip',
			'images'     => 'images.zip',
			'bundle'     => 'bundle.zip',
		];
	}

	/**
	 * Image extensions extracted into the uploads folder.
	 *
	 * @return string[]
	 * @since 1.2.0
	 */
	private function imageExtensions(): array {
		return [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico', 'bmp' ];
	}

	/**
	 * Registers the upload handler + the daily cleanup sweep.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register() {
		add_action( 'wp_ajax_' . self::ACTION, [ $this, 'handleUpload' ] );
		add_action( self::CLEANUP_HOOK, [ $this, 'cleanupStale' ] );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Deletes abandoned manual working dirs (an upload begun but never
	 * finished/imported). TTL filterable.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function cleanupStale() {
		$base = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'easy-demo-importer';

		if ( ! is_dir( $base ) ) {
			return;
		}

		$ttl = (int) apply_filters( 'sd/edi/manual_artifact_ttl', 6 * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$now = time();

		foreach ( (array) glob( $base . '/.manual-tmp-*.part' ) as $part ) {
			if ( is_file( $part ) && ( $now - (int) filemtime( $part ) ) > $ttl ) {
				wp_delete_file( $part );
			}
		}

		foreach ( (array) glob( $base . '/manual-*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_dir( $dir ) && ( $now - (int) filemtime( $dir ) ) > $ttl ) {
				$this->deleteDir( $dir );
			}
		}
	}

	/**
	 * Recursively deletes a directory via WP_Filesystem.
	 *
	 * @param string $dir Absolute directory path.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function deleteDir( string $dir ) {
		$this->fs()->delete( $dir, true );
	}

	/**
	 * Lazily boots and returns WP_Filesystem.
	 *
	 * @return \WP_Filesystem_Base|null
	 * @since 1.2.0
	 */
	private function fs() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Entry point: gate the request, then either assemble a chunk or finalize.
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

		// Never steal a running import's lock (SessionManager::start() would
		// force-take it, breaking the other import mid-pipeline).
		if ( SessionManager::isLocked() ) {
			$this->fail( esc_html__( 'Another import is already in progress. Please wait for it to finish.', 'easy-demo-importer' ), 409 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( isset( $_POST['finalize'] ) ) {
			$this->finalize();
			return;
		}

		$this->assembleChunk();
	}

	/**
	 * Resolves (and creates) the working directory for an upload session.
	 *
	 * @return array{key:string,dir:string} Manual key + absolute working dir.
	 * @since 1.2.0
	 */
	private function workingDir(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handleUpload().
		$upload_id = ManualContext::sanitizeKey( isset( $_POST['uploadId'] ) ? sanitize_text_field( wp_unslash( $_POST['uploadId'] ) ) : '' );

		if ( '' === $upload_id ) {
			$this->fail( esc_html__( 'Invalid upload session.', 'easy-demo-importer' ) );
		}

		$dir = trailingslashit( wp_get_upload_dir()['basedir'] ) . 'easy-demo-importer/' . ManualContext::demoDir( $upload_id );

		if ( ! wp_mkdir_p( $dir ) ) {
			$this->fail( esc_html__( 'Could not create the working directory. Check uploads folder permissions.', 'easy-demo-importer' ) );
		}

		// Block direct web access to the staged upload (private content + creds).
		Setup::protectDirectory( dirname( $dir ) );

		return [
			'key' => $upload_id,
			'dir' => $dir,
		];
	}

	/**
	 * Appends one chunk of a target file, renaming it into place on the last one.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function assembleChunk() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handleUpload().
		$target = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		$index  = isset( $_POST['chunkIndex'] ) ? absint( wp_unslash( $_POST['chunkIndex'] ) ) : 0;
		$total  = isset( $_POST['totalChunks'] ) ? max( 1, absint( wp_unslash( $_POST['totalChunks'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$targets = $this->targets();

		if ( ! isset( $targets[ $target ] ) ) {
			$this->fail( esc_html__( 'Unknown upload target.', 'easy-demo-importer' ) );
		}

		$filename = $targets[ $target ];
		$max      = (int) apply_filters( 'sd/edi/manual_upload_max_bytes', 512 * MB_IN_BYTES ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$chunk = ! empty( $_FILES['chunk'] ) ? $this->normalizeFile( $_FILES['chunk'] ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $chunk ) || UPLOAD_ERR_OK !== $chunk['error'] || '' === $chunk['tmp_name'] || ! is_uploaded_file( $chunk['tmp_name'] ) ) {
			$this->fail( esc_html__( 'A chunk failed to upload.', 'easy-demo-importer' ) );
		}

		$context = $this->workingDir();
		$part    = $context['dir'] . '/.part-' . $filename;

		// First chunk starts a fresh assembly file.
		if ( 0 === $index && file_exists( $part ) ) {
			wp_delete_file( $part );
		}

		$existing = file_exists( $part ) ? (int) filesize( $part ) : 0;

		if ( $existing + $chunk['size'] > $max ) {
			wp_delete_file( $part );
			$this->fail( esc_html__( 'That file is too large.', 'easy-demo-importer' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$data = (string) file_get_contents( $chunk['tmp_name'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $part, $data, FILE_APPEND | LOCK_EX );

		if ( $index + 1 < $total ) {
			wp_send_json_success(
				[
					'done'     => false,
					'file'     => $target,
					'received' => $index + 1,
					'total'    => $total,
				]
			);
		}

		// Last chunk: the assembled .part is the complete target file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $part, $context['dir'] . '/' . $filename ) ) {
			wp_delete_file( $part );
			$this->fail( esc_html__( 'Could not save an uploaded file.', 'easy-demo-importer' ) );
		}

		wp_send_json_success(
			[
				'done' => true,
				'file' => $target,
			]
		);
	}

	/**
	 * Unpacks and routes the staged files, validates the content, then starts the
	 * session and returns the manual key.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function finalize() {
		$context     = $this->workingDir();
		$dir         = $context['dir'];
		$has_images  = false;

		// Single bundle → unzip and map its contents into the standard files.
		if ( is_file( $dir . '/bundle.zip' ) ) {
			$has_images = $this->routeBundle( $dir ) || $has_images;
		}

		// A settings .zip of per-option JSONs → one flat settings.json.
		if ( is_file( $dir . '/settings.zip' ) ) {
			$this->expandSettingsZip( $dir . '/settings.zip', $dir );
			wp_delete_file( $dir . '/settings.zip' );
		}

		// Bundled media → staged under the working dir; the importer attaches it
		// locally (no remote download) and creates Media Library entries.
		if ( is_file( $dir . '/images.zip' ) ) {
			$this->extractImages( $dir . '/images.zip', $dir );
			wp_delete_file( $dir . '/images.zip' );
			$has_images = true;
		}

		// Content (WXR) is required in both modes.
		if ( ! is_file( $dir . '/content.xml' ) || ! $this->looksLikeWxrContent( $dir . '/content.xml' ) ) {
			$this->fail( esc_html__( 'A content (WXR/XML) file is required, and it must be a valid WordPress export.', 'easy-demo-importer' ) );
		}

		$this->finish( $context['key'], $has_images );
	}

	/**
	 * Unzips a bundle and maps its files, by extension, into the standard names.
	 * `*.xml`→content, `*.dat`→customizer, `*.wie`→widgets, `*.json`→settings
	 * (a `settings.json` is a flat map; any other JSON is one option keyed by its
	 * filename), and an `uploads/` folder is staged under the working dir for the
	 * importer's bundled-media path to attach.
	 *
	 * @param string $dir Working directory (holds bundle.zip).
	 *
	 * @return bool Whether any bundled media was staged for import.
	 * @since 1.2.0
	 */
	private function routeBundle( string $dir ): bool {
		$extract = $dir . '/_bundle';
		wp_mkdir_p( $extract );

		if ( is_wp_error( $this->unzip( $dir . '/bundle.zip', $extract ) ) ) {
			$this->deleteDir( $extract );
			wp_delete_file( $dir . '/bundle.zip' );
			$this->fail( esc_html__( 'The bundle .zip could not be unpacked.', 'easy-demo-importer' ) );
		}

		$settings   = [];
		$has_images = false;
		$staging    = $dir . '/uploads';

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $extract, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$path = $file->getPathname();
			$name = $file->getFilename();
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			$rel  = ltrim( str_replace( $extract, '', $path ), '/\\' );

			// Media placed under an uploads/ folder (at any depth — the zip may
			// nest everything under a wrapper folder) mirrors the live structure.
			if ( in_array( $ext, $this->imageExtensions(), true ) && preg_match( '#(?:^|/)uploads/(.+)$#i', $rel, $m ) ) {
				$dest = trailingslashit( $staging ) . $m[1];
				wp_mkdir_p( dirname( $dest ) );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				@rename( $path, $dest );
				$has_images = true;
				continue;
			}

			if ( 'xml' === $ext && ! is_file( $dir . '/content.xml' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				@rename( $path, $dir . '/content.xml' );
			} elseif ( 'dat' === $ext && ! is_file( $dir . '/customizer.dat' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				@rename( $path, $dir . '/customizer.dat' );
			} elseif ( 'wie' === $ext && ! is_file( $dir . '/widget.wie' ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
				@rename( $path, $dir . '/widget.wie' );
			} elseif ( 'json' === $ext ) {
				$this->collectSettingJson( $name, $path, $settings );
			}
		}

		if ( ! empty( $settings ) ) {
			$this->writeSettings( $settings, $dir . '/settings.json' );
		}

		$this->deleteDir( $extract );
		wp_delete_file( $dir . '/bundle.zip' );

		return $has_images;
	}

	/**
	 * Expands a settings .zip (one JSON per option, keyed by filename) into a
	 * single flat settings.json. A `settings.json` inside is merged as a flat map.
	 *
	 * @param string $zip Absolute path to the settings zip.
	 * @param string $dir Working directory.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function expandSettingsZip( string $zip, string $dir ) {
		$extract = $dir . '/_settings';
		wp_mkdir_p( $extract );

		if ( is_wp_error( $this->unzip( $zip, $extract ) ) ) {
			$this->deleteDir( $extract );
			$this->fail( esc_html__( 'The settings .zip could not be unpacked.', 'easy-demo-importer' ) );
		}

		$settings = [];

		foreach ( (array) glob( $extract . '/*.json' ) as $json ) {
			$this->collectSettingJson( basename( $json ), $json, $settings );
		}

		$this->writeSettings( $settings, $dir . '/settings.json' );
		$this->deleteDir( $extract );
	}

	/**
	 * Adds one JSON file to the settings map: `settings.json` is a flat map merged
	 * in; any other file becomes a single option keyed by its (extension-less)
	 * filename with the decoded JSON as its value.
	 *
	 * @param string $name     File name.
	 * @param string $path     Absolute file path.
	 * @param array  $settings Settings map, by reference.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function collectSettingJson( string $name, string $path, array &$settings ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$raw     = (string) file_get_contents( $path );
		$decoded = json_decode( $raw, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return;
		}

		if ( 'settings.json' === strtolower( $name ) && is_array( $decoded ) ) {
			$settings = array_merge( $settings, $decoded );
			return;
		}

		$option              = sanitize_key( pathinfo( $name, PATHINFO_FILENAME ) );
		$settings[ $option ] = $decoded;
	}

	/**
	 * Writes the assembled settings map as JSON.
	 *
	 * @param array  $settings Settings map.
	 * @param string $dest     Destination path.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function writeSettings( array $settings, string $dest ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dest, (string) wp_json_encode( $settings ) );
	}

	/**
	 * Extracts an images .zip into the working dir's `uploads/` staging folder,
	 * preserving its structure. The importer's bundled-media path
	 * (resolve_bundled_media → import_local_file) reads from here to create real
	 * Media Library attachments — copying each file into wp-content/uploads and
	 * remapping content URLs — instead of just dropping raw files on disk.
	 *
	 * @param string $zip Absolute path to the images zip.
	 * @param string $dir Working directory.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function extractImages( string $zip, string $dir ) {
		$staging = $dir . '/uploads';
		wp_mkdir_p( $staging );

		if ( is_wp_error( $this->unzip( $zip, $staging ) ) ) {
			$this->fail( esc_html__( 'The images .zip could not be unpacked into the uploads folder.', 'easy-demo-importer' ) );
		}
	}

	/**
	 * Unzips an archive via WP core, booting WP_Filesystem first.
	 *
	 * @param string $zip  Archive path.
	 * @param string $dest Destination directory.
	 *
	 * @return true|\WP_Error
	 * @since 1.2.0
	 */
	private function unzip( string $zip, string $dest ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$this->fs();

		return unzip_file( $zip, $dest );
	}

	/**
	 * Starts the session and returns the manual key.
	 *
	 * @param string $key       Manual key.
	 * @param bool   $hasImages Whether bundled media was extracted into uploads.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function finish( string $key, bool $hasImages = false ) {
		$session = SessionManager::start();

		wp_send_json_success(
			[
				'manualKey' => $key,
				'sessionId' => $session['session_id'],
				'hasImages' => $hasImages,
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
	 * Sniffs a file's head for WordPress-export markers.
	 *
	 * @param string $path File path.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function looksLikeWxrContent( string $path ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$head = (string) file_get_contents( $path, false, null, 0, 4096 );

		if ( '' === $head || false === stripos( $head, '<?xml' ) ) {
			return false;
		}

		return false !== stripos( $head, 'wxr_version' ) || false !== stripos( $head, '<rss' );
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
