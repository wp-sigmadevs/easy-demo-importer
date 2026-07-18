<?php
/**
 * Utility Class: MediaSnapshot
 *
 * The filesystem half of a restore point: captures the `wp-content/uploads`
 * media library before an import so a rollback restores the actual image files,
 * not just the attachment rows in the database (see {@see Snapshot} for the DB
 * half, which drives this class).
 *
 * Two strategies, chosen by whether the import resets the database:
 *
 * - Reset import (uploads are about to be wiped): the whole uploads directory is
 *   renamed aside into a shadow directory — an instant, byte-free move — and a
 *   fresh empty uploads directory is put in its place for the import to write
 *   into. Rollback removes the import's media and renames the shadow back.
 *
 * - Non-reset import (existing media must stay live): a manifest of every file
 *   already in uploads is written. The import only adds files; rollback deletes
 *   any file not in the manifest, leaving the pre-import library intact.
 *
 * Both keep the media restore point in lockstep with the database one: created,
 * restored and discarded together.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   2.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use SigmaDevs\EasyDemoImporter\Config\Setup;
use SigmaDevs\EasyDemoImporter\Common\Functions\ImportLogger;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: MediaSnapshot
 *
 * @since 2.0.0
 */
final class MediaSnapshot {

	/**
	 * Option holding the current media restore point's descriptor
	 * (`session`, `mode`, `shadow`/`manifest` paths).
	 */
	const OPTION = 'sd_edi_mediasnap';

	/**
	 * Absolute path to the uploads base directory.
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private static function baseDir(): string {
		$uploads = wp_get_upload_dir();

		return isset( $uploads['basedir'] ) ? untrailingslashit( $uploads['basedir'] ) : '';
	}

	/**
	 * Shadow root — a protected sibling of the uploads directory, guaranteeing
	 * the same filesystem so the move-aside rename is atomic (not a cross-device
	 * copy).
	 *
	 * @return string
	 * @since 2.0.0
	 */
	private static function shadowRoot(): string {
		return trailingslashit( dirname( self::baseDir() ) ) . 'sd-edi-restore';
	}

	/**
	 * Captures the current uploads directory as a media restore point.
	 *
	 * @param string $sessionId Import session this restore point belongs to.
	 * @param bool   $reset     Whether the import resets (wipes) the database and
	 *                          uploads — selects the move-aside strategy over the
	 *                          manifest one.
	 * @param string $demoSlug  Demo slug, for the activity log.
	 *
	 * @return bool True when a media restore point is in place.
	 * @since 2.0.0
	 */
	public static function create( string $sessionId, bool $reset, string $demoSlug = '' ): bool {
		$base = self::baseDir();

		if ( '' === $base || ! is_dir( $base ) ) {
			return false;
		}

		self::discard();

		$root = self::shadowRoot();

		wp_mkdir_p( $root );
		Setup::protectDirectory( $root );

		return $reset
			? self::captureByMove( $base, $root, $sessionId, $demoSlug )
			: self::captureByManifest( $base, $root, $sessionId, $demoSlug );
	}

	/**
	 * Move-aside capture: rename the whole uploads directory into the shadow root
	 * and recreate an empty uploads directory for the import to populate.
	 *
	 * @param string $base      Uploads base directory.
	 * @param string $root      Shadow root directory.
	 * @param string $sessionId Import session ID.
	 * @param string $demoSlug  Demo slug, for the activity log.
	 *
	 * @return bool
	 * @since 2.0.0
	 */
	private static function captureByMove( string $base, string $root, string $sessionId, string $demoSlug ): bool {
		$shadow = trailingslashit( $root ) . 'uploads-' . $sessionId;

		// A same-filesystem rename is instant and atomic; a failure means uploads
		// live on a different device (custom UPLOADS path). Rather than a slow,
		// timeout-prone recursive copy inside the import request, fall back to a
		// manifest so the import's own new media is still removable on rollback —
		// the pre-existing files simply are not moved, and stay live. rename() is
		// used deliberately over WP_Filesystem::move(), which can degrade to a
		// non-atomic copy-then-delete of the whole uploads tree.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $base, $shadow ) ) {
			// captureByMove only runs for a reset import, which wipes the uploads
			// folder next. A manifest (filename-only) snapshot records nothing it
			// can restore, so falling back to one would silently lose the entire
			// pre-existing media library on rollback. Refuse the media restore
			// point instead: the database still rolls back; only the media library
			// is not recoverable, and the warning says so accurately.
			ImportLogger::warning(
				esc_html__( 'Could not create a media restore point — the uploads folder is on a separate disk and cannot be captured before the database reset. Rolling back this import will restore the database but not the media library.', 'easy-demo-importer' ),
				$sessionId,
				$demoSlug
			);

			return false;
		}

		wp_mkdir_p( $base );

		update_option(
			self::OPTION,
			[
				'session' => $sessionId,
				'mode'    => 'move',
				'shadow'  => $shadow,
			],
			false
		);

		return true;
	}

	/**
	 * Manifest capture: record every file currently in uploads so rollback can
	 * delete anything the import adds on top.
	 *
	 * @param string $base      Uploads base directory.
	 * @param string $root      Shadow root directory.
	 * @param string $sessionId Import session ID.
	 *
	 * @return bool
	 * @since 2.0.0
	 */
	private static function captureByManifest( string $base, string $root, string $sessionId, string $demoSlug = '' ): bool {
		$manifest = trailingslashit( $root ) . 'manifest-' . $sessionId . '.txt';

		// The uploads walk is unbounded; on a very large library it would block the
		// import request (and rollback walks it again). Cap it — like the database
		// half's row cap — and skip the media restore point past the limit rather
		// than risk a timeout. Filter `sd/edi/snapshot_max_media_files` to 0 to
		// disable the cap.
		$cap = (int) apply_filters( 'sd/edi/snapshot_max_media_files', 50000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $manifest, 'wb' );

		if ( false === $handle ) {
			return false;
		}

		$count = 0;

		foreach ( self::files( $base ) as $relative ) {
			if ( $cap > 0 && ++$count > $cap ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				wp_delete_file( $manifest );

				ImportLogger::warning(
					esc_html__( 'Media restore point skipped — this site has too many files to snapshot safely; the import will continue and the database can still be rolled back.', 'easy-demo-importer' ),
					$sessionId,
					$demoSlug
				);

				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fwrite( $handle, $relative . "\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		update_option(
			self::OPTION,
			[
				'session'  => $sessionId,
				'mode'     => 'manifest',
				'manifest' => $manifest,
			],
			false
		);

		return true;
	}

	/**
	 * Restores the uploads directory from the media restore point, then discards
	 * it. Safe to call when none exists (returns false).
	 *
	 * @return bool True if a media restore point existed and was applied.
	 * @since 2.0.0
	 */
	public static function restore(): bool {
		$state = get_option( self::OPTION );

		if ( ! is_array( $state ) || empty( $state['mode'] ) ) {
			return false;
		}

		$base = self::baseDir();

		if ( '' === $base ) {
			return false;
		}

		if ( 'move' === $state['mode'] ) {
			$shadow = $state['shadow'] ?? '';

			if ( '' === $shadow || ! is_dir( $shadow ) ) {
				self::discard();

				return false;
			}

			// Drop the import's media, then move the original library back. An
			// atomic same-filesystem rename, deliberately over WP_Filesystem::move()
			// (which can copy-then-delete the whole tree non-atomically).
			self::rrmdir( $base );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @rename( $shadow, $base ) ) {
				return false;
			}
		} else {
			$manifest = $state['manifest'] ?? '';
			$keep     = ( '' !== $manifest && is_file( $manifest ) )
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file
				? array_flip( array_map( 'trim', file( $manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ) )
				: [];

			// Materialize the full list before deleting: files() walks the tree
			// lazily, and mutating a directory mid-walk (removing entries the
			// iterator has not yet reached) is undefined. Snapshot restore is
			// safety-critical, so collect first, then delete.
			foreach ( iterator_to_array( self::files( $base ), false ) as $relative ) {
				if ( ! isset( $keep[ $relative ] ) ) {
					wp_delete_file( trailingslashit( $base ) . $relative );
				}
			}

			self::pruneEmptyDirs( $base );
		}

		self::discard();

		return true;
	}

	/**
	 * Discards the media restore point (removes the shadow copy or manifest) and
	 * forgets it. Called when a new snapshot supersedes it or after a restore.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public static function discard(): void {
		$state = get_option( self::OPTION );

		if ( is_array( $state ) ) {
			if ( 'move' === ( $state['mode'] ?? '' ) && ! empty( $state['shadow'] ) && is_dir( $state['shadow'] ) ) {
				self::rrmdir( $state['shadow'] );
			}

			if ( 'manifest' === ( $state['mode'] ?? '' ) && ! empty( $state['manifest'] ) && is_file( $state['manifest'] ) ) {
				wp_delete_file( $state['manifest'] );
			}
		}

		delete_option( self::OPTION );
	}

	/**
	 * Yields every file under a directory as an uploads-relative path, skipping
	 * symlinks so a restore can never reach outside the uploads tree.
	 *
	 * A generator (not an array) so callers walk lazily: the manifest builder's
	 * file-count cap can abandon the iteration the moment it is exceeded instead
	 * of paying to enumerate — and hold in memory — an entire oversized uploads
	 * tree first. Callers that mutate the tree while consuming it (rollback
	 * deletes files) must materialize the paths first via iterator_to_array().
	 *
	 * @param string $base Directory to walk.
	 *
	 * @return iterable<string>
	 * @since 2.0.0
	 */
	private static function files( string $base ): iterable {
		if ( ! is_dir( $base ) ) {
			return;
		}

		$baseLen  = strlen( trailingslashit( $base ) );
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file ) {
			if ( $file->isLink() || ! $file->isFile() ) {
				continue;
			}

			yield substr( $file->getPathname(), $baseLen );
		}
	}

	/**
	 * Recursively deletes a directory, skipping symlinks.
	 *
	 * @param string $dir Directory to remove.
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );

		if ( false === $entries ) {
			return;
		}

		foreach ( array_diff( $entries, [ '.', '..' ] ) as $entry ) {
			$path = trailingslashit( $dir ) . $entry;

			if ( is_link( $path ) ) {
				continue;
			}

			is_dir( $path ) ? self::rrmdir( $path ) : wp_delete_file( $path );
		}

		// Remove the now-empty directory via WP_Filesystem. If it cannot boot
		// (a non-direct/FTP host), the empty directory is harmless and is simply
		// left in place rather than risking a fatal on a null filesystem.
		$fs = self::fs();

		if ( $fs ) {
			$fs->rmdir( $dir );
		}
	}

	/**
	 * Lazily boots and returns WP_Filesystem (the direct method on hosts whose
	 * uploads are writable by the web server, which covers rollback's use case).
	 *
	 * @return \WP_Filesystem_Base|null
	 * @since 2.0.0
	 */
	private static function fs() {
		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem;
	}

	/**
	 * Removes directories left empty after a manifest restore, deepest first.
	 *
	 * @param string $base Uploads base directory (never itself removed).
	 *
	 * @return void
	 * @since 2.0.0
	 */
	private static function pruneEmptyDirs( string $base ): void {
		if ( ! is_dir( $base ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		$fs = self::fs();

		if ( ! $fs ) {
			return;
		}

		foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				$fs->rmdir( $file->getPathname() );
			}
		}
	}
}
