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
 * @since   1.2.0
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
 * @since 1.2.0
 */
final class ImportState {
	/**
	 * Absolute path to the state file.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $path Absolute path to the state file.
	 *
	 * @since 1.2.0
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
	 * @param string $kind      State kind, e.g. 'import' (content) or 'regen'
	 *                          (image regeneration). Lets one session hold more
	 *                          than one independent state file without colliding.
	 *
	 * @return ImportState
	 * @since 1.2.0
	 */
	public static function forSession( string $baseDir, string $sessionId, string $kind = 'import' ): ImportState {
		$slug = $sessionId ? md5( $sessionId ) : 'default';

		return new self( trailingslashit( $baseDir ) . '.sd-edi-' . $kind . '-state-' . $slug . '.dat' );
	}

	/**
	 * Absolute path to the backing file.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Whether a persisted state file currently exists.
	 *
	 * @return bool
	 * @since 1.2.0
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
	 * @since 1.2.0
	 */
	public function save( array $data ): bool {
		return $this->write( $this->path, $data );
	}

	/**
	 * Persists the write-once immutable parse output (parsed posts, authors,
	 * base_url …) to a sibling file.
	 *
	 * Kept separate from save() so the multi-megabyte parsed-WXR blob is written
	 * exactly once during prepare() instead of being re-serialized and rewritten
	 * on every batch alongside the small, fast-changing cursor + remap maps.
	 *
	 * @param array $data Immutable state to persist.
	 *
	 * @return bool True on success.
	 * @since 1.2.0
	 */
	public function saveImmutable( array $data ): bool {
		return $this->write( $this->immutablePath(), $data );
	}

	/**
	 * Persists one chunk of parsed posts to its own numbered file.
	 *
	 * The parsed post array is split into fixed-size chunks at prepare() so each
	 * batch can load only the slice it processes, instead of unserializing the
	 * entire (potentially tens-of-MB) post array on every request.
	 *
	 * @param int   $index Zero-based chunk index.
	 * @param array $posts Posts in this chunk.
	 *
	 * @return bool True on success.
	 * @since 1.2.0
	 */
	public function savePostsChunk( int $index, array $posts ): bool {
		return $this->write( $this->postsChunkPath( $index ), $posts );
	}

	/**
	 * Loads a single chunk of parsed posts.
	 *
	 * @param int $index Zero-based chunk index.
	 *
	 * @return array|null Posts in the chunk, or null if absent/corrupt.
	 * @since 1.2.0
	 */
	public function loadPostsChunk( int $index ): ?array {
		return $this->read( $this->postsChunkPath( $index ) );
	}

	/**
	 * Serializes an array to a path atomically with an exclusive lock.
	 *
	 * @param string $path Absolute destination path.
	 * @param array  $data Data to persist.
	 *
	 * @return bool True on success.
	 * @since 1.2.0
	 */
	private function write( string $path, array $data ): bool {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$payload = serialize( $data );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $payload, LOCK_EX );
	}

	/**
	 * Loads and unserializes the persisted state.
	 *
	 * Object instantiation is disabled during unserialization as a
	 * defense-in-depth measure; the payload only ever contains arrays and
	 * scalars written by save().
	 *
	 * @return array|null Decoded state, or null if absent/unreadable/corrupt.
	 * @since 1.2.0
	 */
	public function load(): ?array {
		return $this->read( $this->path );
	}

	/**
	 * Loads the write-once immutable parse output written by saveImmutable().
	 *
	 * @return array|null Decoded state, or null if absent/unreadable/corrupt.
	 * @since 1.2.0
	 */
	public function loadImmutable(): ?array {
		return $this->read( $this->immutablePath() );
	}

	/**
	 * Reads and unserializes a persisted state file.
	 *
	 * Object instantiation is disabled during unserialization as a
	 * defense-in-depth measure; the payload only ever contains arrays and
	 * scalars written by write().
	 *
	 * @param string $path Absolute path to read.
	 *
	 * @return array|null Decoded state, or null if absent/unreadable/corrupt.
	 * @since 1.2.0
	 */
	private function read( string $path ): ?array {
		if ( ! is_file( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = file_get_contents( $path );

		if ( false === $payload || '' === $payload ) {
			return null;
		}

		// Silenced: a truncated/corrupt state file is expected to fail here and is
		// handled by returning null (the caller then re-runs prepare or falls back).
		// unserialize() would otherwise emit an E_WARNING into the error log.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		$data = @unserialize( $payload, [ 'allowed_classes' => false ] );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Sibling path holding the write-once immutable parse output.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function immutablePath(): string {
		return $this->path . '.imm';
	}

	/**
	 * Path for a numbered posts chunk file.
	 *
	 * @param int $index Zero-based chunk index.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	private function postsChunkPath( int $index ): string {
		return $this->path . '.posts.' . $index;
	}

	/**
	 * Deletes the state file if present.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function delete(): void {
		$paths = [ $this->path, $this->immutablePath() ];

		// Sweep up every numbered posts chunk file written at prepare().
		$chunks = glob( $this->path . '.posts.*' );

		if ( is_array( $chunks ) ) {
			$paths = array_merge( $paths, $chunks );
		}

		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
}
