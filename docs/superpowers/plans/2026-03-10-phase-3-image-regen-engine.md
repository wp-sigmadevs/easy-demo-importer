# Phase 3 — Image Regeneration Engine

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plugin takes full ownership of image regeneration — suppressed during XML import, then done in a dedicated wizard step with real-time progress, failure tracking, and background/skip modes.

**Architecture:** Image suppression filters are always applied during `ImportXmlChunk` (not opt-in), and attachment IDs are tracked in a session transient via `add_attachment` hook. A new `ImageRegenStep` wizard step (between `importing` and `complete`) offers three modes: Regenerate Now (AJAX polling loop), Background (WP-Cron), or Skip. A standalone `RegenerateImages` class processes batches and exposes check/regen/cron endpoints — registered manually from `Hooks.php` (not auto-registration). `ImageRegenEngine` is the shared utility for regen logic, batch size, and transient helpers.

**Tech Stack:** PHP 8.1+, WordPress AJAX, WP-Cron, React 18, Ant Design v5, React Router v6 (hash-based), Zustand, Laravel Mix build

---

## Key Codebase Facts (read before touching anything)

- **AJAX auto-registration**: `inc/Config/Classes.php` maps `'App\\Ajax\\Backend'` to `'onRequest' => 'import'`. The `import` request type requires `$_POST['demo']` (see `Requester::isImportProcess()`). Regen AJAX calls happen **after** the import finishes (session released, no `demo` field) — so `RegenerateImages` **will not be auto-registered** if placed in `App\Ajax\Backend`. Instead, call `RegenerateImages::getInstance()->register()` from `Hooks.php` directly (runs on all backend requests).
- **Nonce system**: `Helpers::nonceId()` returns `'sd_edi_nonce'` (the POST field name PHP validates). `Helpers::nonceText()` returns `'sd_edi_nonce_secret'` (the nonce action). `Enqueue.php` localizes: `Helpers::nonceId() => wp_create_nonce(...)` — so in JS, the nonce value is at `sdEdiAdminParams.sd_edi_nonce`. All AJAX calls from JS must send the body key `sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce`. **The existing `ImportingStep.jsx` uses `nonce: sdEdiAdminParams.nonce` which is wrong — fix this in Task 5.**
- **Session lifecycle**: `SessionManager::release()` is called by `Finalize.php` (last AJAX step). Session lock is gone by the time `ImageRegenStep` runs. The attachments transient (`sd_edi_session_{uuid}_attachments`, 2h TTL) is separate and outlives the session.
- **`add_attachment` hook**: `SD_EDI_WP_Import::process_attachment()` calls `wp_insert_attachment()` (line 1050 of `lib/wordpress-importer/class-wp-import.php`). WordPress core's `wp_insert_attachment()` fires the `add_attachment` action — so tracking via `add_action('add_attachment', $tracker)` is reliable.
- **Wizard routes**: defined in `src/js/backend/App.jsx` as children of `/wizard`. The `STEPS` array in `src/js/backend/wizard/WizardLayout.jsx` drives the step indicator header.
- **WizardContext**: `selectedDemo`, `importOptions`, `dryRunStats`, `importProgress`, `direction`. Add no new state to context — regen state lives inside `ImageRegenStep` locally.
- **skipImageRegeneration**: The old `Modal/steps/Setup.jsx` toggle and the `sharedDataStore.js` state for `skipImageRegeneration` must be removed in this phase (Task 5). The `ImportXmlChunk.php` PHP side always suppresses (no opt-in).
- **phpcs.xml exists** — run `composer run phpcs` after changes. Auto-fix with `composer run cbf`. PHPStan: `vendor/bin/phpstan analyse --memory-limit=2G`.

---

## Chunk 1: PHP Backend

### Task 1: `ImageRegenEngine` Utility + Always-Suppress in `ImportXmlChunk`

**Files:**
- Create: `inc/Common/Utils/ImageRegenEngine.php`
- Modify: `inc/App/Ajax/Backend/ImportXmlChunk.php`

#### Step 1a: Create `ImageRegenEngine.php`

- [ ] Create `inc/Common/Utils/ImageRegenEngine.php`:

```php
<?php
/**
 * Class: ImageRegenEngine
 *
 * Shared utility for image regeneration: batch size tuning,
 * session attachment list helpers, and per-attachment regen.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.4.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImageRegenEngine
 *
 * @since 1.4.0
 */
class ImageRegenEngine {
	/**
	 * Transient key suffix for session attachment lists.
	 *
	 * @var string
	 */
	const ATTACHMENTS_SUFFIX = '_attachments';

	/**
	 * How long to keep the attachments transient after import.
	 * Two hours is more than enough for any regen step.
	 *
	 * @var int
	 */
	const ATTACHMENTS_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Return the number of attachments to process per AJAX call.
	 * Defaults to 5, auto-reduced to 1 when PHP memory limit < 256 MB.
	 * Filterable via sd/edi/regen_batch_size.
	 *
	 * @return int
	 * @since 1.4.0
	 */
	public static function batchSize(): int {
		$limit   = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$default = $limit > 0 && $limit < 256 * MB_IN_BYTES ? 1 : 5;

		return (int) apply_filters( 'sd/edi/regen_batch_size', $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Append one attachment ID to the session attachment list transient.
	 * Safe to call from an add_attachment hook during chunked XML import.
	 *
	 * @param string $session_id Active import session UUID.
	 * @param int    $post_id    Attachment post ID.
	 * @return void
	 * @since 1.4.0
	 */
	public static function appendAttachment( string $session_id, int $post_id ): void {
		if ( empty( $session_id ) || $post_id <= 0 ) {
			return;
		}

		$key  = 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX;
		$list = get_transient( $key );

		if ( ! is_array( $list ) ) {
			$list = [];
		}

		$list[] = $post_id;

		// array_unique avoids double-counting if the hook fires more than once per attachment.
		set_transient( $key, array_unique( $list ), self::ATTACHMENTS_TTL );
	}

	/**
	 * Return all tracked attachment IDs for a session.
	 *
	 * @param string $session_id Import session UUID.
	 * @return int[] List of attachment post IDs.
	 * @since 1.4.0
	 */
	public static function getSessionAttachments( string $session_id ): array {
		$key  = 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX;
		$list = get_transient( $key );

		return is_array( $list ) ? array_map( 'intval', $list ) : [];
	}

	/**
	 * Delete the attachments transient for a session once regen is complete.
	 *
	 * @param string $session_id Import session UUID.
	 * @return void
	 * @since 1.4.0
	 */
	public static function clearSessionAttachments( string $session_id ): void {
		delete_transient( 'sd_edi_session_' . $session_id . self::ATTACHMENTS_SUFFIX );
	}

	/**
	 * Regenerate thumbnails for a single attachment.
	 *
	 * @param int $attachment_id WordPress attachment post ID.
	 * @return array{sizes: string[], error: string} Result with generated size keys and error string.
	 * @since 1.4.0
	 */
	public static function regen( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return [
				'sizes' => [],
				'error' => sprintf(
					/* translators: %d: attachment post ID */
					__( 'File not found for attachment #%d.', 'easy-demo-importer' ),
					$attachment_id
				),
			];
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );

		if ( is_wp_error( $metadata ) ) {
			return [
				'sizes' => [],
				'error' => $metadata->get_error_message(),
			];
		}

		if ( empty( $metadata ) ) {
			return [
				'sizes' => [],
				'error' => sprintf(
					/* translators: %d: attachment post ID */
					__( 'No metadata generated for attachment #%d.', 'easy-demo-importer' ),
					$attachment_id
				),
			];
		}

		wp_update_attachment_metadata( $attachment_id, $metadata );

		return [
			'sizes' => array_keys( $metadata['sizes'] ?? [] ),
			'error' => '',
		];
	}
}
```

- [ ] Verify file saved correctly: `cat inc/Common/Utils/ImageRegenEngine.php | head -5`

#### Step 1b: Always suppress in `ImportXmlChunk::importChunkFile()`

`importChunkFile()` currently gates suppression on `$this->skipImageRegeneration`. Remove the gating — always suppress, always track.

- [ ] Open `inc/App/Ajax/Backend/ImportXmlChunk.php`
- [ ] Add `use SigmaDevs\EasyDemoImporter\Common\Utils\ImageRegenEngine;` to the imports block
- [ ] Replace the entire `importChunkFile()` method with:

```php
private function importChunkFile( string $chunk_path ): void {
	if ( ! defined( 'SD_EDI_LOAD_IMPORTERS' ) ) {
		define( 'SD_EDI_LOAD_IMPORTERS', true );
	}

	if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
		$importer_path = sd_edi()->getPluginPath() . '/lib/wordpress-importer/wordpress-importer.php';

		if ( file_exists( $importer_path ) ) {
			require_once $importer_path;
		}
	}

	if ( ! class_exists( 'SD_EDI_WP_Import' ) ) {
		return;
	}

	// Always suppress WP image regeneration during XML import.
	// The dedicated ImageRegenStep handles regeneration after the import completes.
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
	add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
	add_filter( 'big_image_size_threshold', '__return_false', 9999 );

	// Track every attachment created in this chunk for the regen step.
	// SD_EDI_WP_Import::process_attachment() calls wp_insert_attachment() which fires add_attachment.
	$session_id = $this->sessionId;
	$tracker    = static function ( int $post_id ) use ( $session_id ): void {
		ImageRegenEngine::appendAttachment( $session_id, $post_id );
	};
	add_action( 'add_attachment', $tracker );

	$exclude_images               = ! ( 'true' === $this->excludeImages );
	$wp_import                    = new \SD_EDI_WP_Import();
	$wp_import->fetch_attachments = $exclude_images;

	ob_start();
	$wp_import->import( $chunk_path );
	ob_end_clean();

	remove_action( 'add_attachment', $tracker );
	remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
	remove_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
	remove_filter( 'big_image_size_threshold', '__return_false', 9999 );
}
```

- [ ] In the `response()` method, find the block where offset is past the end (chunk import complete). Add a log line about regen deferral before `$this->prepareResponse(...)`:

```php
ImportLogger::log(
	__( 'Image regeneration deferred — dedicated step ready.', 'easy-demo-importer' ),
	'info',
	$this->sessionId
);
```

- [ ] Commit:

```bash
git add inc/Common/Utils/ImageRegenEngine.php inc/App/Ajax/Backend/ImportXmlChunk.php
git commit -m "feat: always suppress image regen during import and track attachment IDs"
```

#### Step 1c: Manual verification checklist

- [ ] Run `composer run phpcs 2>&1 | grep -E "ImageRegenEngine|ImportXmlChunk"` — zero errors expected
- [ ] Run `vendor/bin/phpstan analyse inc/Common/Utils/ImageRegenEngine.php inc/App/Ajax/Backend/ImportXmlChunk.php --memory-limit=2G` — no new errors
- [ ] If PHPCS errors: run `composer run cbf` and re-check

---

### Task 2: `RegenerateImages` AJAX Handler

Three AJAX actions: `sd_edi_regen_check` (get count), `sd_edi_regenerate_images` (process batch), `sd_edi_background_regen` (schedule cron).

**Files:**
- Create: `inc/App/Ajax/Backend/RegenerateImages.php`

**Registration note:** This class is placed in `inc/App/Ajax/Backend/` but is **NOT** auto-registered by `Classes.php` (because `'onRequest' => 'import'` requires `$_POST['demo']`, which regen AJAX calls don't include). Instead, `Hooks.php` manually calls `RegenerateImages::getInstance()->register()` on every backend request (Task 3).

The `register()` method only adds the three `wp_ajax_*` actions — no session validation, no `handlePostSubmission()`. Nonce + capability checking is done inside each method via `verifyRequest()`.

**Why not extend `ImporterAjax`:** After `sd_edi_finalize_demo`, the session lock is released. `ImporterAjax::handlePostSubmission()` validates the session ID against the active lock — this would always fail for regen. `RegenerateImages` does its own nonce + capability check.

- [ ] Create `inc/App/Ajax/Backend/RegenerateImages.php`:

```php
<?php
/**
 * Backend Ajax Class: RegenerateImages
 *
 * Handles image regeneration AJAX actions after demo import.
 * Does NOT extend ImporterAjax because the import session lock is
 * already released by the time regen runs — validation is nonce + capability.
 *
 * Registration: NOT auto-registered via Classes.php (requires demo POST field).
 * Instead, Hooks::actions() calls RegenerateImages::getInstance()->register()
 * on every admin backend request.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.4.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Utils\ImageRegenEngine,
	Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class RegenerateImages
 *
 * @since 1.4.0
 */
class RegenerateImages {
	use Singleton;

	/**
	 * Registers AJAX actions.
	 * Called by Hooks::actions() on every admin backend request.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function register(): void {
		add_action( 'wp_ajax_sd_edi_regen_check',       [ $this, 'regenCheck' ] );
		add_action( 'wp_ajax_sd_edi_regenerate_images', [ $this, 'regenerateImages' ] );
		add_action( 'wp_ajax_sd_edi_background_regen',  [ $this, 'scheduleBackground' ] );
	}

	/**
	 * Return the total attachment count and first filename for the regen step UI.
	 *
	 * POST params: sd_edi_nonce, sessionId
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function regenCheck(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		$first_filename = '';

		if ( $total > 0 ) {
			$file = get_attached_file( $ids[0] );
			if ( $file ) {
				$first_filename = basename( $file );
			}
		}

		wp_send_json_success(
			[
				'total'          => $total,
				'first_filename' => $first_filename,
			]
		);
	}

	/**
	 * Regenerate one batch of attachments.
	 *
	 * POST params: sd_edi_nonce, sessionId, offset
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function regenerateImages(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		if ( 0 === $total ) {
			wp_send_json_success(
				[
					'done'      => 0,
					'total'     => 0,
					'completed' => true,
				]
			);
			return;
		}

		$batch      = ImageRegenEngine::batchSize();
		$slice      = array_slice( $ids, $offset, $batch );
		$failed     = [];
		$last_sizes = [];
		$last_file  = '';

		foreach ( $slice as $attachment_id ) {
			$file = get_attached_file( $attachment_id );
			if ( $file ) {
				$last_file = basename( $file );
			}

			$result = ImageRegenEngine::regen( $attachment_id );

			if ( ! empty( $result['error'] ) ) {
				$failed[] = [
					'id'       => $attachment_id,
					'filename' => $last_file,
					'error'    => $result['error'],
				];
			} else {
				$last_sizes = $result['sizes'];
			}
		}

		$done      = min( $offset + $batch, $total );
		$completed = $done >= $total;

		if ( $completed ) {
			$fail_count = count( $failed );

			ImportLogger::log(
				sprintf(
					/* translators: 1: total images, 2: failure count */
					_n(
						'Image regeneration complete: %1$d image processed, %2$d failure.',
						'Image regeneration complete: %1$d images processed, %2$d failures.',
						$total,
						'easy-demo-importer'
					),
					$total,
					$fail_count
				),
				0 === $fail_count ? 'success' : 'warning',
				$session_id
			);

			ImageRegenEngine::clearSessionAttachments( $session_id );

			// Store completion record: date, total, and failure count for System Status display.
			update_option(
				'sd_edi_last_regen',
				[
					'date'     => current_time( 'mysql' ),
					'count'    => $total,
					'failures' => $fail_count,
				],
				false
			);
			delete_option( 'sd_edi_background_regen_session' );
		}

		wp_send_json_success(
			[
				'done'             => $done,
				'total'            => $total,
				'current_filename' => $last_file,
				'sizes_generated'  => $last_sizes,
				'failed'           => $failed,
				'completed'        => $completed,
			]
		);
	}

	/**
	 * Schedule a WP-Cron event to regenerate images in the background.
	 *
	 * POST params: sd_edi_nonce, sessionId
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function scheduleBackground(): void {
		$this->verifyRequest();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$session_id = isset( $_POST['sessionId'] ) ? sanitize_text_field( wp_unslash( $_POST['sessionId'] ) ) : '';

		$ids = ImageRegenEngine::getSessionAttachments( $session_id );

		if ( empty( $ids ) ) {
			wp_send_json_success( [ 'scheduled' => false, 'total' => 0 ] );
			return;
		}

		// Store session ID so admin_notices can show progress.
		update_option( 'sd_edi_background_regen_session', $session_id, false );

		// Schedule one cron event firing as soon as possible.
		wp_schedule_single_event( time(), 'sd_edi_background_regen', [ $session_id, 0 ] );

		wp_send_json_success( [ 'scheduled' => true, 'total' => count( $ids ) ] );
	}

	/**
	 * WP-Cron callback: process one batch of background image regeneration.
	 * Registered in Hooks::actions() via add_action('sd_edi_background_regen', ...).
	 *
	 * Schedules itself again until all attachments are processed.
	 *
	 * @param string $session_id Import session UUID.
	 * @param int    $offset     Current offset into the attachment list.
	 * @return void
	 * @since 1.4.0
	 */
	public static function cronRegen( string $session_id, int $offset ): void {
		$ids   = ImageRegenEngine::getSessionAttachments( $session_id );
		$total = count( $ids );

		if ( 0 === $total ) {
			delete_option( 'sd_edi_background_regen_session' );
			return;
		}

		$batch_size = 10;
		$slice      = array_slice( $ids, $offset, $batch_size );
		$batch_fails = 0;

		foreach ( $slice as $attachment_id ) {
			$result = ImageRegenEngine::regen( $attachment_id );
			if ( ! empty( $result['error'] ) ) {
				++$batch_fails;
			}
		}

		$done = min( $offset + $batch_size, $total );

		// Read prior progress (which also carries accumulated failure count across batches).
		$prior    = get_transient( 'sd_edi_background_regen_progress_' . $session_id );
		$failures = ( is_array( $prior ) ? (int) ( $prior['failures'] ?? 0 ) : 0 ) + $batch_fails;

		// Store progress + accumulated failures for admin notice and final record.
		set_transient(
			'sd_edi_background_regen_progress_' . $session_id,
			[ 'done' => $done, 'total' => $total, 'failures' => $failures ],
			HOUR_IN_SECONDS
		);

		if ( $done >= $total ) {
			ImageRegenEngine::clearSessionAttachments( $session_id );
			delete_option( 'sd_edi_background_regen_session' );
			delete_transient( 'sd_edi_background_regen_progress_' . $session_id );

			// Store completion record: date, total, and accumulated failure count for System Status display.
			update_option(
				'sd_edi_last_regen',
				[
					'date'     => current_time( 'mysql' ),
					'count'    => $done,
					'failures' => $failures,
				],
				false
			);
		} else {
			wp_schedule_single_event( time(), 'sd_edi_background_regen', [ $session_id, $done ] );
		}
	}

	/**
	 * Verify AJAX nonce and user capability. Sends JSON error and dies on failure.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	private function verifyRequest(): void {
		if ( ! check_ajax_referer( Helpers::nonceText(), Helpers::nonceId(), false ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Security check failed. Please refresh the page.', 'easy-demo-importer' ),
				],
				403
			);
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				[
					'errorMessage' => esc_html__( 'Insufficient permissions.', 'easy-demo-importer' ),
				],
				403
			);
		}
	}
}
```

- [ ] Commit:

```bash
git add inc/App/Ajax/Backend/RegenerateImages.php
git commit -m "feat: add RegenerateImages AJAX handler with check, regen, and background modes"
```

- [ ] Run `composer run phpcs 2>&1 | grep -E "RegenerateImages"` — zero errors
- [ ] Run `vendor/bin/phpstan analyse inc/App/Ajax/Backend/RegenerateImages.php --memory-limit=2G` — if errors appear about `ImportLogger::log` signature, check the actual parameter order in `ImportLogger.php` and fix

---

### Task 3: Wire `RegenerateImages` into `Hooks.php` + Admin Notice

Register the `RegenerateImages` AJAX actions and cron callback from `Hooks.php` (runs on every admin request). Add an admin notice for in-progress background regen.

**Files:**
- Modify: `inc/App/General/Hooks.php`
- Modify: `inc/Common/Functions/Actions.php`

#### Step 3a: Register in `Hooks.php`

- [ ] Open `inc/App/General/Hooks.php`
- [ ] Add the `RegenerateImages` use statement to the namespace import block at the top:

```php
use SigmaDevs\EasyDemoImporter\App\Ajax\Backend\RegenerateImages;
```

- [ ] In `actions()`, add three lines after the existing `add_action` calls (before `return $this;`):

```php
// Register regen AJAX actions and cron callback on every admin request.
// NOT auto-registered via Classes.php because regen calls have no 'demo' POST field.
RegenerateImages::getInstance()->register();
add_action( 'sd_edi_background_regen', [ RegenerateImages::class, 'cronRegen' ], 10, 2 );
add_action( 'admin_notices', [ Actions::class, 'backgroundRegenNotice' ] );
```

The updated `actions()` method should look like:

```php
public function actions() {
	// Check the rewrite flush.
	add_action( 'init', [ Actions::class, 'rewriteFlushCheck' ] );

	// Actions during importer initialization.
	add_action( 'sd/edi/importer_init', [ Actions::class, 'initImportActions' ] );

	// Actions during plugins activation.
	add_action( 'sd/edi/after_plugin_activation', [ Actions::class, 'pluginActivationActions' ] );

	// Actions before import.
	add_action( 'sd/edi/before_import', [ Actions::class, 'beforeImportActions' ] );

	// Actions after import.
	add_action( 'sd/edi/after_import', [ Actions::class, 'afterImportActions' ] );

	// Register regen AJAX actions and cron callback on every admin request.
	// NOT auto-registered via Classes.php because regen calls have no 'demo' POST field.
	RegenerateImages::getInstance()->register();
	add_action( 'sd_edi_background_regen', [ RegenerateImages::class, 'cronRegen' ], 10, 2 );
	add_action( 'admin_notices', [ Actions::class, 'backgroundRegenNotice' ] );

	return $this;
}
```

#### Step 3b: Admin notice for background regen

- [ ] Open `inc/Common/Functions/Actions.php`
- [ ] Add this static method at the end of the class (before the closing `}`):

```php
/**
 * Show an admin notice when background image regeneration is running or complete.
 *
 * @return void
 * @since 1.4.0
 */
public static function backgroundRegenNotice(): void {
	// Check if a background regen just completed.
	$last = get_option( 'sd_edi_last_regen', [] );

	if ( ! empty( $last['date'] ) && ! empty( $last['notified'] ) ) {
		// Already shown the completion notice — skip.
	} elseif ( ! empty( $last['date'] ) && empty( $last['notified'] ) ) {
		// Show completion notice once, then mark it notified.
		$count = (int) ( $last['count'] ?? 0 );
		$fails = (int) ( $last['failures'] ?? 0 );

		update_option(
			'sd_edi_last_regen',
			array_merge( $last, [ 'notified' => true ] ),
			false
		);

		if ( 0 === $fails ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of images regenerated */
						_n(
							'Easy Demo Importer: %d image regenerated in the background.',
							'Easy Demo Importer: %d images regenerated in the background.',
							$count,
							'easy-demo-importer'
						),
						$count
					)
				)
			);
		} else {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: total images, 2: failure count */
						__( 'Easy Demo Importer: Background image regen complete — %1$d done, %2$d failed. Check System Status for details.', 'easy-demo-importer' ),
						$count,
						$fails
					)
				)
			);
		}
		return;
	}

	// Check for in-progress background regen.
	$session_id = (string) get_option( 'sd_edi_background_regen_session', '' );

	if ( empty( $session_id ) ) {
		return;
	}

	$progress = get_transient( 'sd_edi_background_regen_progress_' . $session_id );

	if ( ! is_array( $progress ) ) {
		return;
	}

	printf(
		'<div class="notice notice-info"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: done count, 2: total count */
				__( 'Easy Demo Importer: Image regeneration running in background — %1$d of %2$d done.', 'easy-demo-importer' ),
				(int) $progress['done'],
				(int) $progress['total']
			)
		)
	);
}
```

- [ ] Commit:

```bash
git add inc/App/General/Hooks.php inc/Common/Functions/Actions.php
git commit -m "feat: register regen AJAX/cron from Hooks.php and add background regen admin notice"
```

- [ ] Run PHPCS + PHPStan on modified files, fix any violations

---

## Chunk 2: React Frontend + Integration

### Task 4: `ImageRegenStep` React Component

**Files:**
- Create: `src/js/backend/wizard/steps/ImageRegenStep.jsx`

**Component behaviour:**

1. On mount: POST `sd_edi_regen_check` with `sessionId` → get `{ total, first_filename }`
2. If `total === 0`: auto-navigate to `/wizard/complete` after 500ms
3. If `total > 0`: show pre-step screen:
   - Heading: `"N images found — ready to regenerate"` (e.g., `"47 images found — ready to regenerate"`)
   - Three buttons: **Regenerate Now**, **In Background**, **Skip**
4. **Regenerate Now** → polling loop via `sd_edi_regenerate_images`:
   - Show: progress bar (`done / total`), current filename, size pills below filename
   - Failures collapse into `⚠ N images failed` section (expandable with `Collapse` from antd)
   - On `completed: true`: show success summary + navigate to `/wizard/complete` after 1200ms
5. **In Background** → POST `sd_edi_background_regen` → navigate to `/wizard/complete`
6. **Skip** → navigate to `/wizard/complete`

**Key nonce detail:** `Helpers::nonceId()` returns `'sd_edi_nonce'`. PHP `verifyRequest()` checks `$_REQUEST['sd_edi_nonce']`. The localized param in JS is `sdEdiAdminParams.sd_edi_nonce`. All AJAX bodies must send `sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce` — NOT `nonce: sdEdiAdminParams.nonce`.

- [ ] Create `src/js/backend/wizard/steps/ImageRegenStep.jsx`:

```jsx
import { useState, useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Progress, Space, Collapse, Alert, Typography, Spin } from 'antd';
import { PictureOutlined, WarningOutlined } from '@ant-design/icons';
import useSharedDataStore from '../../utils/sharedDataStore';

/* global sdEdiAdminParams, ajaxurl */

const { Text } = Typography;

const ImageRegenStep = () => {
	const navigate            = useNavigate();
	const { activeSessionId } = useSharedDataStore();
	const sessionId           = activeSessionId || '';

	const [ phase,           setPhase           ] = useState( 'checking' );
	const [ total,           setTotal           ] = useState( 0 );
	const [ firstFilename,   setFirstFilename   ] = useState( '' );
	const [ done,            setDone            ] = useState( 0 );
	const [ currentFilename, setCurrentFilename ] = useState( '' );
	const [ totalSizes,      setTotalSizes      ] = useState( 0 );  // cumulative sizes generated
	const [ failures,        setFailures        ] = useState( [] );
	const [ error,           setError           ] = useState( null );

	const abortRef = useRef( false );

	// ── AJAX helper ──────────────────────────────────────────────────────────
	// Nonce key is 'sd_edi_nonce' (Helpers::nonceId()), value at sdEdiAdminParams.sd_edi_nonce.
	const ajaxPost = async ( action, extra = {} ) => {
		const body = new URLSearchParams( {
			action,
			sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
			sessionId,
			...extra,
		} );

		const res  = await fetch( ajaxurl, { method: 'POST', body } );
		const json = await res.json();

		if ( ! json.success ) {
			throw new Error( json.data?.errorMessage ?? 'Request failed' );
		}

		return json.data;
	};

	// On mount: check how many attachments need regen.
	useEffect( () => {
		if ( ! sessionId ) {
			navigate( '/wizard/complete' );
			return;
		}

		( async () => {
			try {
				const data = await ajaxPost( 'sd_edi_regen_check' );

				if ( data.total === 0 ) {
					setTimeout( () => navigate( '/wizard/complete' ), 500 );
					return;
				}

				setTotal( data.total );
				setFirstFilename( data.first_filename || '' );
				setPhase( 'prompt' );
			} catch ( err ) {
				setError( err.message );
				setPhase( 'prompt' );
			}
		} )();

		return () => { abortRef.current = true; };
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const handleSkip = () => navigate( '/wizard/complete' );

	const handleBackground = async () => {
		try {
			await ajaxPost( 'sd_edi_background_regen' );
		} catch {
			// Non-fatal — still proceed to complete.
		}
		navigate( '/wizard/complete' );
	};

	const handleRegenNow = async () => {
		setPhase( 'running' );
		let offset   = 0;
		let allFails = [];

		try {
			while ( ! abortRef.current ) {
				const data = await ajaxPost( 'sd_edi_regenerate_images', { offset } );

				setDone( data.done );
				setCurrentFilename( data.current_filename || '' );
				// Accumulate total sizes generated (cumulative counter, not per-image pills).
				setTotalSizes( ( prev ) => prev + ( data.sizes_generated?.length ?? 0 ) );

				if ( data.failed?.length ) {
					allFails = [ ...allFails, ...data.failed ];
					setFailures( [ ...allFails ] );
				}

				if ( data.completed ) break;

				offset = data.done;
			}

			setPhase( 'done' );
			setTimeout( () => navigate( '/wizard/complete' ), 1200 );
		} catch ( err ) {
			setError( err.message );
			setPhase( 'done' );
		}
	};

	const pct = total > 0 ? Math.round( ( done / total ) * 100 ) : 0;

	if ( 'checking' === phase ) {
		return (
			<div style={ { textAlign: 'center', padding: '40px 0' } }>
				<Spin size="large" />
				<div style={ { marginTop: 16, color: '#595959' } }>Checking images…</div>
			</div>
		);
	}

	if ( 'prompt' === phase ) {
		return (
			<div style={ { textAlign: 'center', padding: '24px 0' } }>
				<PictureOutlined style={ { fontSize: 48, color: '#6366f1', marginBottom: 16 } } />
				<div style={ { fontSize: 20, fontWeight: 600, marginBottom: 8 } }>
					{ `${ total } image${ total !== 1 ? 's' : '' } found — ready to regenerate` }
				</div>
				{ firstFilename && (
					<div style={ { color: '#8c8c8c', marginBottom: 24, fontSize: 13 } }>
						Starting with { firstFilename }
					</div>
				) }
				{ error && (
					<Alert type="warning" message={ error } style={ { marginBottom: 24, textAlign: 'left' } } />
				) }
				<Space size="middle" wrap>
					<Button type="primary" size="large" onClick={ handleRegenNow }>
						Regenerate Now
					</Button>
					<Button size="large" onClick={ handleBackground }>
						In Background
					</Button>
					<Button type="link" size="large" onClick={ handleSkip }>
						Skip — I'll handle this manually
					</Button>
				</Space>
			</div>
		);
	}

	return (
		<div>
			<div style={ { marginBottom: 24 } }>
				<div style={ { fontSize: 14, color: '#595959', marginBottom: 8 } }>
					{ 'done' === phase
						? `Regeneration complete — ${ done } / ${ total } images`
						: `Regenerating — ${ currentFilename || '…' }` }
				</div>
				<Progress
					percent={ pct }
					status={ error ? 'exception' : 'done' === phase ? 'success' : 'active' }
					strokeColor={ { '0%': '#6366f1', '100%': '#818cf8' } }
				/>
				<div style={ { display: 'flex', gap: 20, marginTop: 4, fontSize: 12, color: '#8c8c8c' } }>
					<span>{ done } / { total } images</span>
					{ totalSizes > 0 && <span>{ totalSizes } sizes generated</span> }
				</div>
			</div>

			{ failures.length > 0 && (
				<Collapse
					size="small"
					items={ [ {
						key:      '1',
						label:    <><WarningOutlined style={ { color: '#faad14' } } /> { failures.length } image{ failures.length !== 1 ? 's' : '' } failed</>,
						children: (
							<ul style={ { margin: 0, paddingLeft: 16 } }>
								{ failures.map( ( f, i ) => (
									<li key={ i }>
										<Text code>{ f.filename }</Text> — { f.error }
									</li>
								) ) }
							</ul>
						),
					} ] }
				/>
			) }

			{ error && (
				<Alert type="error" showIcon message="Regeneration Error" description={ error } style={ { marginTop: 16 } } />
			) }
		</div>
	);
};

export default ImageRegenStep;
```

- [ ] Commit:

```bash
git add src/js/backend/wizard/steps/ImageRegenStep.jsx
git commit -m "feat: add ImageRegenStep wizard component with now/background/skip modes"
```

---

### Task 5: Wire Routes + Fix Nonce + Remove skipImageRegeneration

Five surgical edits.

**Files:**
- Modify: `src/js/backend/wizard/WizardLayout.jsx`
- Modify: `src/js/backend/App.jsx`
- Modify: `src/js/backend/wizard/steps/ImportingStep.jsx`
- Modify: `src/js/backend/components/Modal/steps/Setup.jsx`
- Modify: `src/js/backend/utils/sharedDataStore.js`
- Modify: `src/js/backend/components/Modal/ModalComponent.jsx`
- Modify: `src/js/backend/utils/Api.js`

#### Step 5a: Add `regen` to STEPS array in `WizardLayout.jsx`

- [ ] Open `src/js/backend/wizard/WizardLayout.jsx`
- [ ] Insert `{ key: 'regen', title: 'Images' }` between `importing` and `complete`:

```js
const STEPS = [
	{ key: 'welcome',      title: 'Welcome'      },
	{ key: 'requirements', title: 'Requirements' },
	{ key: 'plugins',      title: 'Plugins'      },
	{ key: 'demos',        title: 'Select Demo'  },
	{ key: 'options',      title: 'Options'      },
	{ key: 'confirm',      title: 'Confirm'      },
	{ key: 'importing',    title: 'Importing'    },
	{ key: 'regen',        title: 'Images'       },
	{ key: 'complete',     title: 'Done'         },
];
```

#### Step 5b: Add `/wizard/regen` route in `App.jsx`

- [ ] Open `src/js/backend/App.jsx`
- [ ] Add import at top: `import ImageRegenStep from './wizard/steps/ImageRegenStep';`
- [ ] Add route between `importing` and `complete`: `{ path: 'regen', element: <ImageRegenStep /> }`

#### Step 5c: Fix `ImportingStep.jsx` — redirect and nonce

- [ ] Open `src/js/backend/wizard/steps/ImportingStep.jsx`
- [ ] Change `navigate( '/wizard/complete' )` to `navigate( '/wizard/regen' )`
- [ ] Fix nonce in `ajaxPost`: change `nonce: sdEdiAdminParams.nonce,` to `sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,`

The updated `ajaxPost` body should be:
```js
const body = new URLSearchParams( {
    action,
    sd_edi_nonce: sdEdiAdminParams.sd_edi_nonce,
    sessionId:    sessionIdRef.current,
    demo:         selectedDemo?.slug ?? '',
    excludeImages:           importOptions.media   ? '' : '1',
    reset:                   importOptions.resetDb ? 'true' : 'false',
    ...extra,
} );
```

Note: `skipImageRegeneration` is removed from the POST body entirely — the PHP side always suppresses now.

#### Step 5d: Remove `skipImageRegeneration` toggle from old Modal UI

The old `Modal/steps/Setup.jsx` still has a "Skip Image Regeneration" toggle. This toggle told PHP to suppress image regeneration filters — which is now always-on regardless. The toggle is a no-op on the PHP side.

The Skip *intent* is preserved: wizard users see `ImageRegenStep` after import and can click **"Skip — I'll handle this manually"**. Removing the pre-import toggle from the modal is safe; it had no effect anyway. Old modal users who want to skip regen simply don't trigger the wizard flow.

- [ ] Open `src/js/backend/components/Modal/steps/Setup.jsx`
- [ ] Remove the destructured `skipImageRegeneration` and `setSkipImageRegeneration` from `useSharedDataStore()`
- [ ] Remove the entire toggle block for skip image regeneration (the `Switch` component and surrounding markup that references `skipImageRegenerationTitle` and `skipImageRegenerationHint`)

- [ ] Open `src/js/backend/utils/sharedDataStore.js`
- [ ] Remove `skipImageRegeneration: false` from initial state
- [ ] Remove `setSkipImageRegeneration: (value) => set({ skipImageRegeneration: value })` from actions
- [ ] Remove `skipImageRegeneration: false` from the reset block

- [ ] Open `src/js/backend/components/Modal/ModalComponent.jsx`
- [ ] Remove `skipImageRegeneration` from the destructured `useSharedDataStore()` call
- [ ] Remove `skipImageRegeneration` from wherever it's passed to child components or the API request

- [ ] Open `src/js/backend/utils/Api.js`
- [ ] Remove `params.append('skipImageRegeneration', request.skipImageRegeneration);`

- [ ] Commit all Task 5 changes:

```bash
git add src/js/backend/wizard/WizardLayout.jsx \
        src/js/backend/App.jsx \
        src/js/backend/wizard/steps/ImportingStep.jsx \
        src/js/backend/components/Modal/steps/Setup.jsx \
        src/js/backend/utils/sharedDataStore.js \
        src/js/backend/components/Modal/ModalComponent.jsx \
        src/js/backend/utils/Api.js
git commit -m "feat: add regen step to wizard, fix nonce, remove skipImageRegeneration toggle"
```

---

### Task 6: System Status Integration + Release

**Files:**
- Modify: `inc/App/Rest/RestEndpoints.php`
- Modify: `easy-demo-importer.php` — version `1.4.0`
- Modify: `readme.txt` — stable tag + changelog
- Modify: `package.json` — version

#### Step 6a: Add regen info tab to System Status

The status tab shows last regen date, total count, and failure count. No Re-run button — the attachments transient expires after 2h, making a re-run button unreliable. If the user needs to re-run regen, they start a new import.

- [ ] Open `inc/App/Rest/RestEndpoints.php`
- [ ] In `serverStatusTabs()`, add a new tab before `copy_system_data`:

```php
$tabs['regen_info'] = [
    'label'  => esc_html__( 'Image Regeneration', 'easy-demo-importer' ),
    'fields' => $this->regenInfoFields(),
];
```

- [ ] Add the `regenInfoFields()` private method:

```php
/**
 * Image Regeneration Status Fields.
 *
 * @return array
 * @since 1.4.0
 */
private function regenInfoFields(): array {
    $fields = [];

    $session_id = (string) get_option( 'sd_edi_background_regen_session', '' );

    if ( ! empty( $session_id ) ) {
        $progress = get_transient( 'sd_edi_background_regen_progress_' . $session_id );

        if ( is_array( $progress ) ) {
            $fields['regen_status'] = [
                'label' => esc_html__( 'Status', 'easy-demo-importer' ),
                'value' => sprintf(
                    /* translators: 1: done count, 2: total count */
                    esc_html__( 'Running — %1$d of %2$d images done', 'easy-demo-importer' ),
                    (int) $progress['done'],
                    (int) $progress['total']
                ),
            ];

            return $fields;
        }
    }

    $last = get_option( 'sd_edi_last_regen', [] );

    if ( ! empty( $last['date'] ) ) {
        $fields['regen_last_date'] = [
            'label' => esc_html__( 'Last Run', 'easy-demo-importer' ),
            'value' => esc_html( $last['date'] ),
        ];
        $fields['regen_last_count'] = [
            'label' => esc_html__( 'Images Processed', 'easy-demo-importer' ),
            'value' => (int) ( $last['count'] ?? 0 ),
        ];
        $fields['regen_last_failures'] = [
            'label' => esc_html__( 'Failures', 'easy-demo-importer' ),
            'value' => (int) ( $last['failures'] ?? 0 ),
        ];
    } else {
        $fields['regen_status'] = [
            'label' => esc_html__( 'Status', 'easy-demo-importer' ),
            'value' => esc_html__( 'No regeneration session recorded.', 'easy-demo-importer' ),
        ];
    }

    return $fields;
}
```

- [ ] Commit:

```bash
git add inc/App/Rest/RestEndpoints.php
git commit -m "feat: add image regeneration status tab to System Status page"
```

#### Step 6b: Build + version bump + changelog

- [ ] Run `npm run production` — confirm `assets/js/backend.min.js` rebuilt successfully
- [ ] Update `easy-demo-importer.php`: `* Version: 1.4.0`
- [ ] Update `readme.txt`: `Stable tag: 1.4.0`
- [ ] Add changelog to `readme.txt` under `== Changelog ==`:

```
= 1.4.0 (10-March-2026) =
* New: Image regeneration is now always deferred during XML import (never happens silently)
* New: Dedicated Image Regen wizard step between Importing and Complete
* New: Regenerate Now mode — real-time per-image progress with size pill tags
* New: In Background mode — WP-Cron processes images after the wizard completes
* New: Skip mode — proceed to Complete without regenerating (images can be regenerated later)
* New: Per-image failure tracking with expandable error list in wizard UI
* New: Admin notice shows background regen progress and completion
* New: Image Regeneration tab on System Status page with last-run date, count, and failure count
* New: big_image_size_threshold filter suppressed during import for cleaner regen
* Fix: Removed opt-in skipImageRegeneration gate — suppression is now always-on during import
* Fix: Removed obsolete Skip Image Regeneration toggle from import modal setup step
* Fix: Nonce field name corrected in wizard AJAX calls (sd_edi_nonce)
* Filter: sd/edi/regen_batch_size controls images per AJAX call (default 5, auto-reduces to 1 under 256MB)
```

- [ ] Update `package.json`: `"version": "1.4.0"`
- [ ] Commit everything:

```bash
git add easy-demo-importer.php readme.txt package.json assets/
git commit -m "Bump version to 1.4.0 and write changelog"
```

#### Step 6c: Final PHPCS + PHPStan pass

- [ ] `vendor/bin/phpstan analyse --memory-limit=2G` — must exit 0
- [ ] `composer run cbf` — auto-fix any remaining violations
- [ ] `composer run phpcs` — review remaining violations; errors in `lib/` and pre-existing `samples/` are expected and acceptable
- [ ] Commit any auto-fix changes:

```bash
git add -p
git commit -m "Fix PHPCS violations after phpcbf auto-fix in Phase 3 files"
```

---

## Quick Reference: New AJAX Actions

| Action | Handler method | POST params | Returns |
|--------|----------------|-------------|---------|
| `sd_edi_regen_check` | `regenCheck()` | `sd_edi_nonce`, `sessionId` | `{ total, first_filename }` |
| `sd_edi_regenerate_images` | `regenerateImages()` | `sd_edi_nonce`, `sessionId`, `offset` | `{ done, total, current_filename, sizes_generated, failed[], completed }` |
| `sd_edi_background_regen` | `scheduleBackground()` | `sd_edi_nonce`, `sessionId` | `{ scheduled, total }` |

## Quick Reference: New Transients/Options

| Key | Type | Purpose |
|-----|------|---------|
| `sd_edi_session_{uuid}_attachments` | Transient (2h TTL) | Attachment IDs tracked during chunk import |
| `sd_edi_background_regen_session` | Option | Session ID of in-progress background regen |
| `sd_edi_last_regen` | Option | `{ date, count, failures, notified }` — last regen completion record |
| `sd_edi_background_regen_progress_{uuid}` | Transient (1h TTL) | `{ done, total }` for admin notice |
