<?php
/**
 * Importer Utility: ImportState
 *
 * Persists the resumable WXR importer's cross-request state (parsed posts and
 * every ID/orphan/remap map) to a single serialized file, keyed by import
 * session. Read once per AJAX batch, written back at the end of each batch, and
 * deleted when the import finalizes or the session is cancelled.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.1.7
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Importer;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Importer Utility: ImportState
 *
 * @since 1.1.7
 */
final class ImportState {
	/**
	 * Absolute path to the state file.
	 *
	 * @var string
	 * @since 1.1.7
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to the state file.
	 *
	 * @since 1.1.7
	 */
	public function __construct( string $path ) {
		$this->path = $path;
	}

	/**
	 * Builds a state store for a given import session inside a base directory.
	 *
	 * The filename embeds a hashed session id so concurrent imports of
	 * different demos never collide, and the leading dot keeps it out of any
	 * casual directory listing.
	 *
	 * @param string $baseDir   Directory that holds the demo's working files.
	 * @param string $sessionId Import session identifier.
	 *
	 * @return ImportState
	 * @since 1.1.7
	 */
	public static function forSession( string $baseDir, string $sessionId ): ImportState {
		$slug = $sessionId ? md5( $sessionId ) : 'default';

		return new self( trailingslashit( $baseDir ) . '.sd-edi-import-state-' . $slug . '.dat' );
	}

	/**
	 * Absolute path to the backing file.
	 *
	 * @return string
	 * @since 1.1.7
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Whether a persisted state file currently exists.
	 *
	 * @return bool
	 * @since 1.1.7
	 */
	public function exists(): bool {
		return is_file( $this->path );
	}

	/**
	 * Persists the state array to disk.
	 *
	 * Uses PHP serialization (not JSON) because parsed WXR data routinely
	 * contains serialized post meta and non-UTF-8 byte sequences that
	 * json_encode() would drop or fail on. Written atomically with an
	 * exclusive lock.
	 *
	 * @param array $data State to persist.
	 *
	 * @return bool True on success.
	 * @since 1.1.7
	 */
	public function save( array $data ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$payload = serialize( $data );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $this->path, $payload, LOCK_EX );
	}

	/**
	 * Loads and unserializes the persisted state.
	 *
	 * Object instantiation is disabled during unserialization as a
	 * defense-in-depth measure; the payload only ever contains arrays and
	 * scalars written by save().
	 *
	 * @return array|null Decoded state, or null if absent/unreadable/corrupt.
	 * @since 1.1.7
	 */
	public function load(): ?array {
		if ( ! is_file( $this->path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = file_get_contents( $this->path );

		if ( false === $payload || '' === $payload ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$data = unserialize( $payload, [ 'allowed_classes' => false ] );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Deletes the state file if present.
	 *
	 * @return void
	 * @since 1.1.7
	 */
	public function delete(): void {
		if ( is_file( $this->path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
