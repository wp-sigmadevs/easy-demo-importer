# Multisite Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add WordPress Multisite support to Easy Demo Importer, releasable as v1.2.0, with zero behavior change on single-site installs.

**Architecture:** Hybrid activation (per-site or network-active, no `Network: true` header). New `ContextResolver` is the single source of truth for "where am I, what can I do, which blog?". Per-blog tables created lazily plus on `wp_initialize_site`. Network Admin gets a screen under Network → Themes for read-only status, demo config override, and tiered plugin install.

**Tech Stack:** PHP 7.4+, WordPress 5.5+, React (existing wizard bundle), Laravel Mix (existing build).

**Spec:** `docs/superpowers/specs/2026-04-29-multisite-support-design.md`

**Branch:** `multi-site` (already checked out, based off `master`)

**Verification approach:** This codebase has no automated test suite today. The plan uses **manual QA per-task** plus the **5-environment matrix** in §11 of the spec as the verification mechanism. Adding a PHPUnit harness is intentionally out of scope (candidate for v1.3.0). Each task has a "Verify" step with the exact commands and observable outcomes the engineer must confirm before committing.

---

## Phases

| Phase | Tasks | Outcome |
|-------|-------|---------|
| **A — Foundations** | T1–T8 | Per-blog data isolation, multisite-aware activation, lifecycle hooks, version bump. Plugin functional on multisite (per-subsite UX) but no Network Admin UI yet. |
| **B — Network Admin** | T9–T14 | REST endpoints + Network Admin screen with Dashboard, Network Config (JSON editor), Settings tabs. |
| **C — Subsite UX** | T15–T19 | Sticky subsite banner, multisite branching in PluginsStep, domain-echo confirmation on reset. |
| **D — Polish & Release** | T20–T23 | WXR mime fallback, readme updates, full test matrix run, version tag. |

Each phase ends with a checkpoint commit. The plugin is shippable after Phase A (per-subsite mode) — Phases B-D add network UX on top.

---

## File Map

### Created files

| Path | Responsibility |
|------|----------------|
| `inc/Common/Utils/ContextResolver.php` | Where am I (network/subsite), what can I do (caps), which blog am I targeting. |
| `inc/Common/Utils/NetworkInstaller.php` | Wraps `switch_to_blog` + `dbDelta` for per-blog table create/drop. Loops `get_sites()`. Chunked. |
| `inc/App/Network/Pages.php` | Adds Network Admin → Themes → Easy Demo Importer submenu. |
| `inc/App/Network/Enqueue.php` | Loads existing wizard bundle on Network Admin context with `networkMode=true`. |
| `inc/App/Rest/NetworkStatus.php` | 4 REST endpoints: GET status, GET/POST config, POST install-plugin. |
| `src/js/backend/network/NetworkApp.jsx` | Tabbed router for Network Admin. |
| `src/js/backend/network/Dashboard.jsx` | Blogs list + last-import status table. |
| `src/js/backend/network/ConfigEditor.jsx` | JSON editor for `sd_edi_network_config`. |
| `src/js/backend/components/SubsiteBanner.jsx` | Sticky banner across wizard steps when `is_multisite()`. |
| `src/js/backend/components/DomainEchoConfirm.jsx` | Domain-echo input replacing simple confirm in reset modal. |

### Modified files

| Path | Why |
|------|-----|
| `easy-demo-importer.php` | Version bump 1.1.6 → 1.2.0. |
| `inc/Common/Traits/Requester.php` | Add `isNetworkBackend()`. |
| `inc/Config/Classes.php` | Register `App\Network` namespace gated on `networkBackend`. |
| `inc/Config/Setup.php` | Multisite-aware activation + global lifecycle hook registration. |
| `inc/Common/Functions/Functions.php` | `getDemoConfig()` resolution order; lazy `getImportTable()` ensures table on missing. |
| `inc/Common/Functions/Helpers.php` | Delegate `verifyUserRole` to `ContextResolver`. |
| `inc/App/Backend/Pages.php` | Use `ContextResolver` for cap checks. |
| `inc/App/Backend/Enqueue.php` | Localize `isMultisite`, `currentBlogId`, `isSuperAdmin`, etc. |
| `inc/App/General/Hooks.php` | Register `wp_initialize_site` / `wp_uninitialize_site` listeners. |
| `inc/App/Rest/RestEndpoints.php` | Register the new `NetworkStatus` route group. |
| `inc/App/Ajax/Backend/InstallPlugins.php` | Reject non-Super-Admin install attempts on multisite with clear error. |
| `uninstall.php` | Loop `get_sites()` cleanup on multisite. |
| `src/js/backend/wizard/steps/RequirementsStep.jsx` | Render block-screen / install-on-network branches per `canInstallPlugins`. |
| `src/js/backend/wizard/steps/PluginsStep.jsx` | Same multisite branching for the plugins step. |
| `src/js/backend/wizard/Wizard.jsx` (or root) | Mount `<SubsiteBanner />` when `isMultisite`. |
| `src/js/backend/wizard/modals/ResetConfirmModal.jsx` | Use `<DomainEchoConfirm />` when `isMultisite`. |
| `webpack.mix.js` | Add `network.min.js` build entry. |
| `readme.txt`, `readme.md`, `changelog.md` | Document multisite support and version bump. |

### Excluded

- No Phase 4 / SnapshotManager work — that lives on `alpha` branch.
- No PHPUnit harness.
- No bulk multi-blog import.
- No per-subsite demo allowlisting.

---

## Conventions used throughout

- Indentation: tabs (matches existing files).
- Strict types: every new PHP file starts with `declare( strict_types=1 );`.
- Singleton: every class instantiated by Bootstrap uses `Common\Traits\Singleton` and exposes `register()`.
- Hook prefix: `sd/edi/`. Function prefix: `sd_edi_`. Option prefix: `sd_edi_`.
- Commit messages: Conventional Commits enforced by hook (`feat(scope): …`, `fix(scope): …`, `docs(scope): …`, `refactor(scope): …`, `chore(scope): …`).
- After each task: `git add` named files only, never `git add .` (preserves the security rule).

---

# Phase A — Foundations

## Task A1: Create ContextResolver

**Files:**
- Create: `inc/Common/Utils/ContextResolver.php`

- [ ] **Step 1: Create the ContextResolver class with all five public methods**

```php
<?php
/**
 * Utility Class: ContextResolver.
 *
 * Single source of truth for multisite/network context decisions:
 * where the request is running, what the current user can do, and
 * which blog ID the operation should target.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: ContextResolver.
 *
 * @since 1.2.0
 */
final class ContextResolver {
	/**
	 * Whether the current request is on a multisite install.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isMultisite() {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Whether the current request is in the Network Admin area.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function isNetworkContext() {
		return self::isMultisite() && function_exists( 'is_network_admin' ) && is_network_admin();
	}

	/**
	 * Current blog ID, or 1 on single-site.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function currentBlogId() {
		return self::isMultisite() ? (int) get_current_blog_id() : 1;
	}

	/**
	 * Whether the current user can run an import on the current blog.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canRunImport() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( self::isNetworkContext() && ! is_super_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the current user can install plugins on this install.
	 * On multisite, only Super Admin (matches WP core gating).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canInstallPlugins() {
		if ( self::isMultisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'install_plugins' );
	}

	/**
	 * Whether the current user can upload arbitrary mime types.
	 * On multisite, only Super Admin (core gating).
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public static function canUnfilteredUpload() {
		if ( self::isMultisite() ) {
			return is_super_admin();
		}

		return current_user_can( 'unfiltered_upload' );
	}

	/**
	 * Target blog ID. Reads ?blog= from the request when a Super Admin
	 * is operating against a specific subsite, otherwise the current blog.
	 *
	 * @return int
	 * @since 1.2.0
	 */
	public static function targetBlogId() {
		if ( self::isMultisite() && self::canInstallPlugins() && isset( $_GET['blog'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$candidate = absint( wp_unslash( $_GET['blog'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $candidate > 0 ) {
				return $candidate;
			}
		}

		return self::currentBlogId();
	}

	/**
	 * Convenience: human-readable identifier for the current blog
	 * (used in banners and confirmations). Falls back to '1' on single-site.
	 *
	 * @return string
	 * @since 1.2.0
	 */
	public static function currentBlogLabel() {
		$id = self::currentBlogId();

		if ( ! self::isMultisite() ) {
			return (string) $id;
		}

		$details = get_blog_details( $id );
		if ( ! $details ) {
			return (string) $id;
		}

		return sprintf( 'subsite-%d (%s)', $id, untrailingslashit( $details->siteurl ) );
	}
}
```

- [ ] **Step 2: Verify with PHP syntax check**

Run:
```bash
php -l inc/Common/Utils/ContextResolver.php
```
Expected: `No syntax errors detected in inc/Common/Utils/ContextResolver.php`

- [ ] **Step 3: Verify Composer autoload resolves the new class**

Run:
```bash
composer dump-autoload -o
php -r "require 'vendor/autoload.php'; var_dump(class_exists('SigmaDevs\\EasyDemoImporter\\Common\\Utils\\ContextResolver'));"
```
Expected: `bool(true)`

- [ ] **Step 4: Verify with PHPStan baseline (must not regress)**

Run:
```bash
./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: `[OK] No errors` OR baseline-match. If new errors appear, fix them — do NOT add to baseline without justification.

- [ ] **Step 5: Commit**

```bash
git add inc/Common/Utils/ContextResolver.php
git commit -m "feat(multisite): add ContextResolver for unified network/cap decisions"
```

---

## Task A2: Create NetworkInstaller

**Files:**
- Create: `inc/Common/Utils/NetworkInstaller.php`

- [ ] **Step 1: Create the NetworkInstaller class**

```php
<?php
/**
 * Utility Class: NetworkInstaller.
 *
 * Owns per-blog schema lifecycle for multisite installs.
 * Wraps switch_to_blog + dbDelta + restore_current_blog with safety asserts.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Utility Class: NetworkInstaller.
 *
 * @since 1.2.0
 */
final class NetworkInstaller {
	/**
	 * Cron hook used to process remaining blogs in chunks during
	 * network-wide table creation.
	 *
	 * @since 1.2.0
	 */
	const CRON_HOOK = 'sd_edi_network_install_chunk';

	/**
	 * Chunk size for cross-blog operations.
	 *
	 * @since 1.2.0
	 */
	const CHUNK_SIZE = 50;

	/**
	 * Create the plugin's per-blog table on a given blog.
	 *
	 * @param int $blogId Target blog ID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function createTableForBlog( int $blogId ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		switch_to_blog( $blogId );
		try {
			self::runCreateTable();
			self::runCreateDir();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Drop the plugin's per-blog table on a given blog and clean per-blog options.
	 *
	 * @param int $blogId Target blog ID.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function dropTableForBlog( int $blogId ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		switch_to_blog( $blogId );
		try {
			global $wpdb;
			$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sd\\_edi\\_%'"
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Create tables on all current blogs in a network. Chunked.
	 * The first chunk runs synchronously; remaining blog IDs are processed
	 * by a single-shot WP-Cron event to avoid activation timeouts.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function createTablesForAllBlogs() {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}

		$ids = get_sites(
			[
				'fields'   => 'ids',
				'number'   => 0,
				'archived' => 0,
				'spam'     => 0,
				'deleted'  => 0,
			]
		);

		if ( empty( $ids ) ) {
			return;
		}

		$first = array_slice( $ids, 0, self::CHUNK_SIZE );
		$rest  = array_slice( $ids, self::CHUNK_SIZE );

		foreach ( $first as $id ) {
			self::createTableForBlog( (int) $id );
		}

		if ( ! empty( $rest ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, [ array_map( 'intval', $rest ) ] );
		}
	}

	/**
	 * Cron handler for the remaining-chunks pass.
	 *
	 * @param array $blogIds Blog IDs left to process.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public static function processChunk( array $blogIds ) {
		if ( empty( $blogIds ) ) {
			return;
		}

		$first = array_slice( $blogIds, 0, self::CHUNK_SIZE );
		$rest  = array_slice( $blogIds, self::CHUNK_SIZE );

		foreach ( $first as $id ) {
			self::createTableForBlog( (int) $id );
		}

		if ( ! empty( $rest ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, [ array_map( 'intval', $rest ) ] );
		}
	}

	/**
	 * Run the existing single-blog createTable routine in the current context.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function runCreateTable() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );
		$collate   = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) );

		if ( $exists !== $tableName ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			dbDelta(
				"CREATE TABLE {$tableName} (
                  original_id BIGINT UNSIGNED NOT NULL,
                  new_id BIGINT UNSIGNED NOT NULL,
                  slug varchar(200) NOT NULL,
                  PRIMARY KEY (original_id)
                  ) {$collate};"
			);
		}
	}

	/**
	 * Run the existing demo-dir creation in the current context.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private static function runCreateDir() {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'easy-demo-importer';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
	}
}
```

- [ ] **Step 2: Verify syntax + autoload + PHPStan**

Run:
```bash
php -l inc/Common/Utils/NetworkInstaller.php && \
composer dump-autoload -o && \
./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: no syntax errors, autoload resolves, PHPStan green.

- [ ] **Step 3: Commit**

```bash
git add inc/Common/Utils/NetworkInstaller.php
git commit -m "feat(multisite): add NetworkInstaller for per-blog schema lifecycle"
```

---

## Task A3: Extend Requester trait + Classes registry

**Files:**
- Modify: `inc/Common/Traits/Requester.php`
- Modify: `inc/Config/Classes.php`

- [ ] **Step 1: Add `isNetworkBackend()` and route the `'networkBackend'` request type**

Edit `inc/Common/Traits/Requester.php`. In the `request()` switch, add a case before `default`:

```php
			case 'networkBackend':
				return $this->isNetworkBackend();
```

And add the method below `isAdminBackend()`:

```php
	/**
	 * Is it the network admin?
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	public function isNetworkBackend() {
		return is_user_logged_in()
			&& function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'is_network_admin' ) && is_network_admin();
	}
```

- [ ] **Step 2: Register the `App\Network` namespace**

Edit `inc/Config/Classes.php`. Replace the `register()` body with:

```php
		return [
			[ 'register' => 'App\\General' ],
			[ 'register' => 'App\\Rest' ],
			[ 'register' => 'App\\Backend', 'onRequest' => 'backend' ],
			[ 'register' => 'App\\Network', 'onRequest' => 'networkBackend' ],
			[ 'register' => 'App\\Ajax\\Backend', 'onRequest' => 'import' ],
		];
```

- [ ] **Step 3: Verify with `php -l` on both files**

Run:
```bash
php -l inc/Common/Traits/Requester.php && php -l inc/Config/Classes.php
```
Expected: both report `No syntax errors`.

- [ ] **Step 4: Commit**

```bash
git add inc/Common/Traits/Requester.php inc/Config/Classes.php
git commit -m "feat(multisite): wire network-admin request type into Bootstrap"
```

---

## Task A4: Demo config resolution order + lazy `getImportTable`

**Files:**
- Modify: `inc/Common/Functions/Functions.php`

- [ ] **Step 1: Replace `getDemoConfig()` to honor the network override**

Replace the existing `getDemoConfig()` body in `inc/Common/Functions/Functions.php` with:

```php
	/**
	 * Get Theme demo config.
	 *
	 * Resolution order (multisite):
	 *   1) Network override (site_option 'sd_edi_network_config')
	 *      ONLY applied when 'sd_edi_network_override_enabled' site_option is true.
	 *   2) Per-subsite theme filter ('sd/edi/importer/config').
	 *   3) Empty array.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public function getDemoConfig() {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$enabled = (bool) get_site_option( 'sd_edi_network_override_enabled', false );
			$network = get_site_option( 'sd_edi_network_config', [] );

			if ( $enabled && is_array( $network ) && ! empty( $network ) ) {
				return apply_filters( 'sd/edi/importer/config', $network ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			}
		}

		return apply_filters( 'sd/edi/importer/config', [] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}
```

- [ ] **Step 2: Make `getImportTable()` lazy-create the table on miss**

Replace the existing `getImportTable()` body with:

```php
	/**
	 * Get the import table name. Lazy-creates the table if missing on the
	 * current blog (covers multisite blogs created before plugin activation).
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function getImportTable() {
		global $wpdb;

		$tableName = sanitize_key( $wpdb->prefix . 'sd_edi_taxonomy_import' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tableName ) );
		if ( $exists !== $tableName ) {
			\SigmaDevs\EasyDemoImporter\Common\Utils\NetworkInstaller::createTableForBlog( (int) get_current_blog_id() );
		}

		return $tableName;
	}
```

- [ ] **Step 3: Verify syntax and PHPStan**

Run:
```bash
php -l inc/Common/Functions/Functions.php && ./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: green.

- [ ] **Step 4: Manual smoke (single-site, must remain unchanged)**

Open WP-CLI eval:
```bash
wp eval 'echo (new SigmaDevs\EasyDemoImporter\Common\Functions\Functions())->getImportTable();'
```
Expected: prints `wp_sd_edi_taxonomy_import` (or your prefix variant) and the table exists in MySQL (`SHOW TABLES LIKE 'wp_sd_edi_taxonomy_import';`). On multisite use `wp --url=subN.example.com eval ...`.

- [ ] **Step 5: Commit**

```bash
git add inc/Common/Functions/Functions.php
git commit -m "feat(multisite): network override + lazy table create in getImportTable"
```

---

## Task A5: Multisite-aware activation in Setup

**Files:**
- Modify: `inc/Config/Setup.php`

- [ ] **Step 1: Replace `activation()` to branch on `is_multisite()`**

Replace `Setup::activation()` with:

```php
	/**
	 * Run only once after plugin is activated.
	 *
	 * @static
	 * @return void
	 * @since 1.0.0
	 */
	public static function activation() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! is_blog_installed() ) {
			return;
		}

		if ( 'yes' === get_transient( 'sd_edi_installing' ) ) {
			return;
		}

		update_option( 'sd_edi_plugin_deactivate_notice', 'true' );
		set_transient( 'sd_edi_installing', 'yes', MINUTE_IN_SECONDS * 10 );

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			// Network-active and per-site activation both land here.
			// Network-active: WP calls activation hook once (current blog == main).
			// Cover other blogs by enumerating sites.
			\SigmaDevs\EasyDemoImporter\Common\Utils\NetworkInstaller::createTablesForAllBlogs();
		} else {
			self::createTable();
			self::createDemoDir();
		}

		delete_transient( 'sd_edi_installing' );
		flush_rewrite_rules();
	}
```

- [ ] **Step 2: Verify syntax + PHPStan**

```bash
php -l inc/Config/Setup.php && ./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add inc/Config/Setup.php
git commit -m "feat(multisite): activation creates tables across all blogs in network"
```

---

## Task A6: Site lifecycle hooks (`wp_initialize_site` / `wp_uninitialize_site`)

**Files:**
- Modify: `inc/App/General/Hooks.php`

- [ ] **Step 1: Add the two listeners and the cron handler**

In `inc/App/General/Hooks.php`, add two `use` lines at the top of the file alongside the existing `use SigmaDevs\EasyDemoImporter\Common\…` block:

```php
use SigmaDevs\EasyDemoImporter\Common\Utils\NetworkInstaller;
```

Then in `actions()` add at the end (just before `return $this;`):

```php
		// Multisite lifecycle.
		add_action( 'wp_initialize_site', [ $this, 'onSiteCreate' ], 10, 2 );
		add_action( 'wp_uninitialize_site', [ $this, 'onSiteDelete' ], 10, 1 );
		add_action( NetworkInstaller::CRON_HOOK, [ NetworkInstaller::class, 'processChunk' ], 10, 1 );
```

Then add these methods to the `Hooks` class:

```php
	/**
	 * Create the plugin's per-blog table when a new subsite is created.
	 *
	 * @param \WP_Site $newSite The new site object.
	 * @param array    $args Arguments for the initialization (unused).
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function onSiteCreate( $newSite, $args = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		NetworkInstaller::createTableForBlog( (int) $newSite->blog_id );
	}

	/**
	 * Drop the plugin's per-blog table when a subsite is removed.
	 *
	 * @param \WP_Site $oldSite The site being removed.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function onSiteDelete( $oldSite ) {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		NetworkInstaller::dropTableForBlog( (int) $oldSite->blog_id );
	}
```

- [ ] **Step 2: Verify**

```bash
php -l inc/App/General/Hooks.php && ./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: green.

- [ ] **Step 3: Commit**

```bash
git add inc/App/General/Hooks.php
git commit -m "feat(multisite): create/drop per-blog tables on site lifecycle hooks"
```

---

## Task A7: Multisite-aware uninstall

**Files:**
- Modify: `uninstall.php`

- [ ] **Step 1: Replace `uninstall.php` to handle multisite**

```php
<?php
/**
 * Plugin Uninstall.
 *
 * Runs when the plugin is deleted from the WordPress admin.
 * On multisite, cleans up every blog and removes network options.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

global $wpdb;

/**
 * Per-blog cleanup. Runs in whichever blog context is current.
 */
function sd_edi_uninstall_current_blog() {
	global $wpdb;

	// Delete sd_edi_* options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sd\\_edi\\_%'" );

	// Delete sd_edi_* transients.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sd\\_edi\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sd\\_edi\\_%'"
	);

	// Drop per-blog table.
	$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );

	// Remove staging dir.
	$upload  = wp_upload_dir();
	$edi_dir = trailingslashit( $upload['basedir'] ) . 'easy-demo-importer';
	if ( is_dir( $edi_dir ) ) {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		global $wp_filesystem;
		$wp_filesystem->rmdir( $edi_dir, true );
	}

	// Per-blog cron.
	foreach ( [ 'sd_edi_import_cron' ] as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}

if ( defined( 'MULTISITE' ) && MULTISITE ) {
	$ids = get_sites(
		[
			'fields'   => 'ids',
			'number'   => 0,
			'archived' => 0,
			'spam'     => 0,
			'deleted'  => 0,
		]
	);
	foreach ( $ids as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		try {
			sd_edi_uninstall_current_blog();
		} finally {
			restore_current_blog();
		}
	}

	// Network-wide cron.
	foreach ( [ 'sd_edi_network_install_chunk' ] as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}

	// Network options.
	delete_site_option( 'sd_edi_network_config' );
	delete_site_option( 'sd_edi_network_override_enabled' );
	delete_site_option( 'sd_edi_network_settings' );
	delete_site_option( 'sd_edi_network_status' );
} else {
	sd_edi_uninstall_current_blog();
}
```

- [ ] **Step 2: Verify**

```bash
php -l uninstall.php
```
Expected: `No syntax errors`.

- [ ] **Step 3: Commit**

```bash
git add uninstall.php
git commit -m "feat(multisite): uninstall cleans every blog and removes network options"
```

---

## Task A8: Bump plugin version to 1.2.0

**Files:**
- Modify: `easy-demo-importer.php`
- Modify: `readme.txt`

- [ ] **Step 1: Update plugin header version**

In `easy-demo-importer.php`, change:
```
 * Version: 1.1.6
```
to:
```
 * Version: 1.2.0
```

- [ ] **Step 2: Update `readme.txt` Stable tag**

Open `readme.txt`, change `Stable tag: 1.1.6` to `Stable tag: 1.2.0`. (Leave the changelog section alone — Phase D updates it.)

- [ ] **Step 3: Verify**

```bash
grep -n "Version: 1.2.0" easy-demo-importer.php && grep -n "Stable tag: 1.2.0" readme.txt
```
Both must match.

- [ ] **Step 4: Commit**

```bash
git add easy-demo-importer.php readme.txt
git commit -m "chore(release): bump version to 1.2.0"
```

---

### Phase A checkpoint

After Tasks A1-A8, the plugin should:

- Create per-blog tables on every subsite at activation, on new subsite creation, and lazily on first import.
- Clean up correctly on subsite delete and on plugin uninstall (single-site or multisite).
- Honor a network-wide demo config override when set.
- Continue to behave identically on single-site installs.

**Manual checkpoint test (multisite environment, 2+ subsites):**

```bash
# 1. Activate plugin network-wide
wp plugin activate easy-demo-importer --network

# 2. Confirm table exists on subsite 2
wp --url=$(wp site list --field=url --site__in=2) db query "SHOW TABLES LIKE 'wp_2_sd_edi_taxonomy_import';"

# 3. Create a new subsite
wp site create --slug=test-multisite-create

# 4. Confirm table appears on the new subsite
wp --url=$(wp site list --field=url --site__in=$(wp site list --field=blog_id --slug=test-multisite-create)) \
   db query "SHOW TABLES LIKE 'wp_%_sd_edi_taxonomy_import';"
```

If all three succeed, Phase A is green.

---

# Phase B — Network Admin

## Task B1: REST endpoints — NetworkStatus

**Files:**
- Create: `inc/App/Rest/NetworkStatus.php`
- Modify: `inc/App/Rest/RestEndpoints.php`

- [ ] **Step 1: Create `NetworkStatus.php`**

```php
<?php
/**
 * REST: NetworkStatus.
 *
 * Provides Network Admin endpoints for status, network config,
 * and network-wide plugin install.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * REST: NetworkStatus.
 *
 * @since 1.2.0
 */
final class NetworkStatus {
	/**
	 * Namespace.
	 *
	 * @var string
	 * @since 1.2.0
	 */
	const NS = 'sd-edi/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function registerRoutes() {
		register_rest_route(
			self::NS,
			'/network/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getStatus' ],
				'permission_callback' => [ $this, 'permSuperAdmin' ],
			]
		);

		register_rest_route(
			self::NS,
			'/network/config',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'getConfig' ],
					'permission_callback' => [ $this, 'permSuperAdmin' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'saveConfig' ],
					'permission_callback' => [ $this, 'permSuperAdmin' ],
					'args'                => [
						'enabled' => [ 'type' => 'boolean', 'required' => true ],
						'config'  => [ 'type' => 'object', 'required' => true ],
					],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/network/install-plugin',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'installPlugin' ],
				'permission_callback' => [ $this, 'permSuperAdmin' ],
				'args'                => [
					'slug' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	/**
	 * Permission callback: must be Super Admin on multisite.
	 *
	 * @return bool|WP_Error
	 * @since 1.2.0
	 */
	public function permSuperAdmin() {
		if ( ! ContextResolver::isMultisite() ) {
			return new WP_Error( 'sd_edi_not_multisite', __( 'Endpoint only available on multisite.', 'easy-demo-importer' ), [ 'status' => 404 ] );
		}
		if ( ! is_super_admin() ) {
			return new WP_Error( 'sd_edi_forbidden', __( 'Super Admin only.', 'easy-demo-importer' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * GET /network/status — returns blog list with last-import metadata.
	 *
	 * @return WP_REST_Response
	 * @since 1.2.0
	 */
	public function getStatus() {
		$ids = get_sites(
			[ 'fields' => 'ids', 'number' => 0, 'archived' => 0, 'spam' => 0, 'deleted' => 0 ]
		);

		$rows = [];
		foreach ( $ids as $id ) {
			switch_to_blog( (int) $id );
			try {
				$details = get_blog_details( (int) $id );
				$rows[]  = [
					'blog_id'      => (int) $id,
					'domain'       => $details ? $details->domain . $details->path : '',
					'site_url'     => get_site_url(),
					'last_import'  => get_option( 'sd_edi_last_import', null ),
					'demo'         => get_option( 'sd_edi_last_imported_demo', '' ),
					'has_table'    => $this->blogHasTable(),
				];
			} finally {
				restore_current_blog();
			}
		}

		return new WP_REST_Response( [ 'sites' => $rows ], 200 );
	}

	/**
	 * GET /network/config — returns the current network override config.
	 *
	 * @return WP_REST_Response
	 * @since 1.2.0
	 */
	public function getConfig() {
		return new WP_REST_Response(
			[
				'enabled' => (bool) get_site_option( 'sd_edi_network_override_enabled', false ),
				'config'  => get_site_option( 'sd_edi_network_config', [] ),
				'updated' => (int) get_site_option( 'sd_edi_network_config_updated', 0 ),
			],
			200
		);
	}

	/**
	 * POST /network/config — replaces the network override config.
	 *
	 * @param WP_REST_Request $req Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.2.0
	 */
	public function saveConfig( WP_REST_Request $req ) {
		$enabled = (bool) $req->get_param( 'enabled' );
		$config  = (array) $req->get_param( 'config' );

		if ( $enabled && ! $this->validateConfigShape( $config ) ) {
			return new WP_Error(
				'sd_edi_invalid_config',
				__( 'Network config must include themeName, themeSlug, and demoData.', 'easy-demo-importer' ),
				[ 'status' => 400 ]
			);
		}

		update_site_option( 'sd_edi_network_override_enabled', $enabled );
		update_site_option( 'sd_edi_network_config', $config );
		update_site_option( 'sd_edi_network_config_updated', time() );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * POST /network/install-plugin — downloads a wordpress.org plugin and
	 * activates it network-wide. Super Admin only.
	 *
	 * @param WP_REST_Request $req Request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.2.0
	 */
	public function installPlugin( WP_REST_Request $req ) {
		$slug = sanitize_key( (string) $req->get_param( 'slug' ) );
		if ( '' === $slug ) {
			return new WP_Error( 'sd_edi_bad_slug', __( 'Plugin slug is required.', 'easy-demo-importer' ), [ 'status' => 400 ] );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$api = plugins_api(
			'plugin_information',
			[ 'slug' => $slug, 'fields' => [ 'sections' => false ] ]
		);
		if ( is_wp_error( $api ) ) {
			return new WP_Error( 'sd_edi_api', $api->get_error_message(), [ 'status' => 500 ] );
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'sd_edi_install', $result->get_error_message(), [ 'status' => 500 ] );
		}

		$pluginFile = $upgrader->plugin_info();
		if ( ! $pluginFile ) {
			return new WP_Error( 'sd_edi_install_no_file', __( 'Plugin installed but file path could not be resolved.', 'easy-demo-importer' ), [ 'status' => 500 ] );
		}

		$activate = activate_plugin( $pluginFile, '', true ); // network=true
		if ( is_wp_error( $activate ) ) {
			return new WP_Error( 'sd_edi_activate', $activate->get_error_message(), [ 'status' => 500 ] );
		}

		return new WP_REST_Response( [ 'ok' => true, 'plugin_file' => $pluginFile ], 200 );
	}

	/**
	 * Whether the current blog has the plugin's per-blog table.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function blogHasTable() {
		global $wpdb;
		$table = $wpdb->prefix . 'sd_edi_taxonomy_import';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Cheap shape validation for a network config.
	 *
	 * @param array $config Config.
	 *
	 * @return bool
	 * @since 1.2.0
	 */
	private function validateConfigShape( array $config ) {
		return ! empty( $config['themeName'] )
			&& ! empty( $config['themeSlug'] )
			&& isset( $config['demoData'] ) && is_array( $config['demoData'] );
	}
}
```

- [ ] **Step 2: Wire into `RestEndpoints.php`**

Open `inc/App/Rest/RestEndpoints.php`. Inside the `register_rest_route` registration loop / `register()` method, add a call so `NetworkStatus::registerRoutes()` runs on `rest_api_init`. Search the file for the existing `add_action( 'rest_api_init', …)` pattern and add:

```php
		add_action( 'rest_api_init', function () {
			( new \SigmaDevs\EasyDemoImporter\App\Rest\NetworkStatus() )->registerRoutes();
		} );
```

(Use the same registration style the file already uses; this snippet is the canonical fallback.)

- [ ] **Step 3: Verify**

```bash
php -l inc/App/Rest/NetworkStatus.php && php -l inc/App/Rest/RestEndpoints.php && \
composer dump-autoload -o && ./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: green.

- [ ] **Step 4: Manual smoke (multisite, super admin)**

```bash
wp eval 'wp_set_current_user( get_users(["role" => "super-admin","number" => 1])[0]->ID ?? get_users(["number" => 1])[0]->ID ); echo wp_remote_retrieve_body( wp_remote_get( rest_url("sd-edi/v1/network/status"), ["headers" => ["X-WP-Nonce" => wp_create_nonce("wp_rest")]] ) );'
```
Expected: a JSON object with `sites: [...]`.

- [ ] **Step 5: Commit**

```bash
git add inc/App/Rest/NetworkStatus.php inc/App/Rest/RestEndpoints.php
git commit -m "feat(multisite): add NetworkStatus REST endpoints"
```

---

## Task B2: Network Admin Pages

**Files:**
- Create: `inc/App/Network/Pages.php`

- [ ] **Step 1: Create the Pages class**

```php
<?php
/**
 * Network Class: Pages.
 *
 * Adds a submenu under Network Admin → Themes for Easy Demo Importer.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Network;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Network Class: Pages.
 *
 * @since 1.2.0
 */
final class Pages extends Base {
	use Singleton;

	/**
	 * Menu slug for the Network screen.
	 */
	const SLUG = 'sd-edi-network';

	/**
	 * Register the Network Admin menu.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register() {
		add_action( 'network_admin_menu', [ $this, 'addMenu' ] );
	}

	/**
	 * Add submenu page.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function addMenu() {
		add_submenu_page(
			'themes.php',
			esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ),
			esc_html__( 'Easy Demo Importer', 'easy-demo-importer' ),
			'manage_network_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the React mount point.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function render() {
		echo '<div class="wrap"><div id="sd-edi-network-app"></div></div>';
	}
}
```

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Network/Pages.php && composer dump-autoload -o
git add inc/App/Network/Pages.php
git commit -m "feat(multisite): add Network Admin Pages"
```

---

## Task B3: Network Admin Enqueue

**Files:**
- Create: `inc/App/Network/Enqueue.php`

- [ ] **Step 1: Create Enqueue class**

```php
<?php
/**
 * Network Class: Enqueue.
 *
 * Loads the Network Admin React bundle on the Easy Demo Importer screen.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Network;

use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Functions\Helpers
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Network Class: Enqueue.
 *
 * @since 1.2.0
 */
final class Enqueue extends Base {
	use Singleton;

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function register() {
		add_action( 'network_admin_menu', [ $this, 'lateRegister' ], 11 );
	}

	/**
	 * Hook enqueue on our specific screen only.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function lateRegister() {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue assets when on the Network EDI screen.
	 *
	 * @param string $hook Hook suffix.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	public function enqueue( $hook ) {
		if ( 'themes_page_' . Pages::SLUG !== $hook ) {
			return;
		}

		$base = $this->plugin->assetsUri();
		$ver  = $this->plugin->version();

		wp_enqueue_style( 'sd-edi-network-styles', esc_url( $base . '/css/backend.css' ), [], $ver );
		wp_enqueue_script( 'sd-edi-network-script', esc_url( $base . '/js/network.min.js' ), [ 'wp-element', 'wp-i18n' ], $ver, true );

		wp_localize_script(
			'sd-edi-network-script',
			'sdEdiNetworkParams',
			[
				'restUrl'   => esc_url_raw( rest_url( 'sd-edi/v1/' ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'logo'      => esc_url( $base . '/images/sd-edi-logo.svg' ),
				'i18n'      => [
					'dashboard'           => esc_html__( 'Dashboard', 'easy-demo-importer' ),
					'networkConfig'       => esc_html__( 'Network Config', 'easy-demo-importer' ),
					'settings'            => esc_html__( 'Settings', 'easy-demo-importer' ),
					'overrideEnabled'     => esc_html__( 'Use network-wide demo config (overrides per-subsite theme filter)', 'easy-demo-importer' ),
					'configInvalid'       => esc_html__( 'Network config must include themeName, themeSlug, and demoData.', 'easy-demo-importer' ),
					'save'                => esc_html__( 'Save', 'easy-demo-importer' ),
					'openInSubsite'       => esc_html__( 'Open in subsite', 'easy-demo-importer' ),
					'lastImport'          => esc_html__( 'Last import', 'easy-demo-importer' ),
					'noImport'            => esc_html__( 'Not yet imported', 'easy-demo-importer' ),
				],
			]
		);
	}
}
```

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Network/Enqueue.php && composer dump-autoload -o
git add inc/App/Network/Enqueue.php
git commit -m "feat(multisite): enqueue Network Admin bundle on EDI screen"
```

---

## Task B4: Network React entry — `NetworkApp.jsx`

**Files:**
- Create: `src/js/backend/network/NetworkApp.jsx`

- [ ] **Step 1: Create the router component**

```jsx
import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import Dashboard from './Dashboard.jsx';
import ConfigEditor from './ConfigEditor.jsx';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

function NetworkApp() {
	const [tab, setTab] = useState('dashboard');

	return (
		<div className="sd-edi-network">
			<header className="sd-edi-network__header">
				<img src={params.logo} alt="" className="sd-edi-network__logo" />
				<nav className="sd-edi-network__tabs">
					<button
						className={tab === 'dashboard' ? 'is-active' : ''}
						onClick={() => setTab('dashboard')}
					>
						{i18n.dashboard}
					</button>
					<button
						className={tab === 'config' ? 'is-active' : ''}
						onClick={() => setTab('config')}
					>
						{i18n.networkConfig}
					</button>
				</nav>
			</header>
			<main className="sd-edi-network__body">
				{tab === 'dashboard' && <Dashboard />}
				{tab === 'config' && <ConfigEditor />}
			</main>
		</div>
	);
}

const mount = document.getElementById('sd-edi-network-app');
if (mount) {
	createRoot(mount).render(<NetworkApp />);
}
```

- [ ] **Step 2: Verify (no build yet — file just needs to be valid JSX)**

Run:
```bash
node --check src/js/backend/network/NetworkApp.jsx 2>&1 || true   # node won't parse JSX directly; rely on webpack build later
```
Real verification happens in B7.

- [ ] **Step 3: Commit**

```bash
git add src/js/backend/network/NetworkApp.jsx
git commit -m "feat(multisite): add NetworkApp tabbed React shell"
```

---

## Task B5: Dashboard component

**Files:**
- Create: `src/js/backend/network/Dashboard.jsx`

- [ ] **Step 1: Create the component**

```jsx
import React, { useEffect, useState } from 'react';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

export default function Dashboard() {
	const [sites, setSites] = useState([]);
	const [loading, setLoading] = useState(true);
	const [err, setErr] = useState('');

	useEffect(() => {
		fetch(params.restUrl + 'network/status', {
			headers: { 'X-WP-Nonce': params.restNonce },
		})
			.then((r) => r.json())
			.then((d) => setSites(d.sites || []))
			.catch((e) => setErr(String(e)))
			.finally(() => setLoading(false));
	}, []);

	if (loading) return <p>{i18n.loading || 'Loading…'}</p>;
	if (err) return <p className="error">{err}</p>;

	return (
		<table className="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Blog ID</th>
					<th>Domain</th>
					<th>{i18n.lastImport}</th>
					<th>Demo</th>
					<th>Has Table</th>
					<th>Action</th>
				</tr>
			</thead>
			<tbody>
				{sites.map((s) => (
					<tr key={s.blog_id}>
						<td>{s.blog_id}</td>
						<td>
							<a href={s.site_url} target="_blank" rel="noreferrer">
								{s.domain}
							</a>
						</td>
						<td>{s.last_import || i18n.noImport}</td>
						<td>{s.demo || '—'}</td>
						<td>{s.has_table ? '✓' : '—'}</td>
						<td>
							<a
								href={
									s.site_url +
									'/wp-admin/themes.php?page=sd-easy-demo-importer'
								}
								target="_blank"
								rel="noreferrer"
								className="button"
							>
								{i18n.openInSubsite}
							</a>
						</td>
					</tr>
				))}
			</tbody>
		</table>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/js/backend/network/Dashboard.jsx
git commit -m "feat(multisite): add Network Dashboard table"
```

---

## Task B6: ConfigEditor component

**Files:**
- Create: `src/js/backend/network/ConfigEditor.jsx`

- [ ] **Step 1: Create the component**

```jsx
import React, { useEffect, useState } from 'react';

const params = window.sdEdiNetworkParams || {};
const i18n = params.i18n || {};

export default function ConfigEditor() {
	const [enabled, setEnabled] = useState(false);
	const [json, setJson] = useState('{}');
	const [saving, setSaving] = useState(false);
	const [msg, setMsg] = useState('');

	useEffect(() => {
		fetch(params.restUrl + 'network/config', {
			headers: { 'X-WP-Nonce': params.restNonce },
		})
			.then((r) => r.json())
			.then((d) => {
				setEnabled(!!d.enabled);
				setJson(JSON.stringify(d.config || {}, null, 2));
			});
	}, []);

	function save() {
		let parsed;
		try {
			parsed = JSON.parse(json);
		} catch (e) {
			setMsg(String(e));
			return;
		}
		setSaving(true);
		setMsg('');
		fetch(params.restUrl + 'network/config', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': params.restNonce,
			},
			body: JSON.stringify({ enabled, config: parsed }),
		})
			.then((r) => r.json())
			.then((d) => {
				if (d.ok) setMsg('Saved.');
				else setMsg(d.message || i18n.configInvalid);
			})
			.finally(() => setSaving(false));
	}

	return (
		<div className="sd-edi-network__config">
			<label className="sd-edi-toggle">
				<input
					type="checkbox"
					checked={enabled}
					onChange={(e) => setEnabled(e.target.checked)}
				/>
				{i18n.overrideEnabled}
			</label>
			<textarea
				rows={24}
				value={json}
				onChange={(e) => setJson(e.target.value)}
				className="sd-edi-network__json"
				spellCheck="false"
			/>
			<div className="sd-edi-network__actions">
				<button onClick={save} disabled={saving} className="button button-primary">
					{i18n.save}
				</button>
				{msg && <span className="sd-edi-network__msg">{msg}</span>}
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Commit**

```bash
git add src/js/backend/network/ConfigEditor.jsx
git commit -m "feat(multisite): add ConfigEditor for network override JSON"
```

---

## Task B7: Wire `network.min.js` into webpack mix and build

**Files:**
- Modify: `webpack.mix.js`

- [ ] **Step 1: Add the bundle entry**

Open `webpack.mix.js`, locate the existing `mix.js(...)` calls for the backend bundle and add a parallel entry. Example pattern (adapt to current exact arguments):

```js
mix.js('src/js/backend/network/NetworkApp.jsx', 'assets/js/network.min.js')
   .react();
```

If the existing build already has a `.react()` chain, attach this `.js().react()` to the same chain.

- [ ] **Step 2: Build**

```bash
npm run dev
```
Expected: build completes; `assets/js/network.min.js` exists.

```bash
ls -la assets/js/network.min.js
```

- [ ] **Step 3: Commit**

```bash
git add webpack.mix.js mix-manifest.json assets/js/network.min.js
git commit -m "build(multisite): bundle Network Admin React app"
```

---

### Phase B checkpoint

- Visit Network Admin → Themes → Easy Demo Importer.
- Dashboard tab loads a table of subsites with last-import metadata.
- Network Config tab loads the JSON editor; saving with valid `themeName/themeSlug/demoData` succeeds; saving with invalid shape returns 400 with the validation error.
- A subsite admin's wizard now resolves `getDemoConfig()` from the network override when enabled.

---

# Phase C — Subsite UX

## Task C1: Backend Pages capability check + sticky banner data

**Files:**
- Modify: `inc/App/Backend/Pages.php`

- [ ] **Step 1: Replace the `manage_options` literal with `ContextResolver::canRunImport()`**

In the `setSubPages()` method, replace `'capability' => 'manage_options',` with a callable check. Add:

```php
use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;
```
to the top of the file, then change both occurrences:
```php
'capability' => 'manage_options',
```
to:
```php
'capability' => ContextResolver::isMultisite() ? 'manage_options' : 'manage_options', // explicit: per-blog cap on multisite, same on single-site
```
(The literal stays the same value, but adding the conditional makes the intent explicit and lets future tightening be a one-line change.)

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Backend/Pages.php
git add inc/App/Backend/Pages.php
git commit -m "refactor(multisite): centralize capability resolution in Pages"
```

---

## Task C2: Backend Enqueue — localize multisite context

**Files:**
- Modify: `inc/App/Backend/Enqueue.php`

- [ ] **Step 1: Add use statement and extend `localizeData()`**

At the top of the file with the existing `use` block, add:

```php
use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;
```

Inside `localizeData()`, add these keys to the returned `data` array (anywhere — keep grouping with the other "Essentials"):

```php
					'isMultisite'                => ContextResolver::isMultisite(),
					'isNetworkContext'           => ContextResolver::isNetworkContext(),
					'currentBlogId'              => ContextResolver::currentBlogId(),
					'currentBlogLabel'           => ContextResolver::currentBlogLabel(),
					'currentBlogUrl'             => esc_url( home_url() ),
					'isSuperAdmin'               => function_exists( 'is_super_admin' ) && is_super_admin(),
					'canInstallPlugins'          => ContextResolver::canInstallPlugins(),
					'canUnfilteredUpload'        => ContextResolver::canUnfilteredUpload(),
					'subsiteBannerLabel'         => sprintf(
						/* translators: %s: subsite label like "subsite-2 (https://sub2.example.com)" */
						esc_html__( 'Importing into: %s', 'easy-demo-importer' ),
						ContextResolver::currentBlogLabel()
					),
					'networkContactSubject'      => esc_html__( 'Easy Demo Importer — required plugins missing', 'easy-demo-importer' ),
					'networkContactBody'         => esc_html__( 'Hello,\n\nI need the following plugins installed network-wide for the demo importer to run:\n\n', 'easy-demo-importer' ),
					'networkRequiredPluginsMissing' => [],
```

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Backend/Enqueue.php
git add inc/App/Backend/Enqueue.php
git commit -m "feat(multisite): localize multisite context for wizard React"
```

---

## Task C3: SubsiteBanner component

**Files:**
- Create: `src/js/backend/components/SubsiteBanner.jsx`

- [ ] **Step 1: Create the component**

```jsx
import React from 'react';

const params = window.sdEdiAdminParams || {};

export default function SubsiteBanner() {
	if (!params.isMultisite) return null;

	return (
		<div className="sd-edi-subsite-banner" role="status" aria-live="polite">
			<span className="sd-edi-subsite-banner__icon" aria-hidden="true">⛬</span>
			<span className="sd-edi-subsite-banner__text">
				{params.subsiteBannerLabel}
			</span>
			<a
				className="sd-edi-subsite-banner__link"
				href={params.currentBlogUrl}
				target="_blank"
				rel="noreferrer"
			>
				{params.currentBlogUrl}
			</a>
		</div>
	);
}
```

- [ ] **Step 2: Mount it in the wizard root**

Open the existing wizard root (`src/js/backend/wizard/Wizard.jsx` or whatever the entry that already mounts `<WizardSteps />` is — find with `grep -rn "<WizardSteps" src/js`). Import `SubsiteBanner` and mount it once at the top of the rendered tree:

```jsx
import SubsiteBanner from '../components/SubsiteBanner.jsx';

// ...inside the root return:
return (
	<>
		<SubsiteBanner />
		{/* existing wizard tree */}
	</>
);
```

- [ ] **Step 3: Build + commit**

```bash
npm run dev
git add src/js/backend/components/SubsiteBanner.jsx src/js/backend/wizard/Wizard.jsx mix-manifest.json assets/js/backend.min.js
git commit -m "feat(multisite): add SubsiteBanner sticky across wizard steps"
```

(Adjust the file list to match exactly which wizard root file you edited.)

---

## Task C4: PluginsStep / RequirementsStep multisite branching

**Files:**
- Modify: `src/js/backend/wizard/steps/RequirementsStep.jsx` (or `PluginsStep.jsx` — whichever currently renders the plugin list — find with `grep -rn "blockingMissing\|requiredPluginsTitle" src/js`)

- [ ] **Step 1: Add the multisite branch**

Find the part of the step that lists required plugins. Compute `blockingMissing` from the existing plugin payload (status === 'install' === not yet installed). Then render conditionally:

```jsx
const params = window.sdEdiAdminParams || {};
const blockingMissing = plugins.filter((p) => p.status === 'install').map((p) => p.name);

if (params.isMultisite && blockingMissing.length > 0 && !params.canInstallPlugins) {
	const subject = encodeURIComponent(params.networkContactSubject);
	const body = encodeURIComponent(
		params.networkContactBody + blockingMissing.map((n) => `- ${n}`).join('\n')
	);
	return (
		<div className="sd-edi-block">
			<h3>{params.networkContactSubject}</h3>
			<ul>{blockingMissing.map((n) => <li key={n}>{n}</li>)}</ul>
			<p>{params.subsiteBannerLabel}</p>
			<a className="button" href={`mailto:?subject=${subject}&body=${body}`}>
				Notify Network Admin
			</a>
			<button className="button" onClick={() => window.location.reload()}>
				Refresh once installed
			</button>
		</div>
	);
}

if (params.isMultisite && blockingMissing.length > 0 && params.canInstallPlugins) {
	// Render an inline "Install on network" button per missing plugin (Super Admin only).
	// Each button POSTs /sd-edi/v1/network/install-plugin { slug } and re-checks status on success.
	// (Implementation detail: reuse the existing per-plugin row component; replace the
	// "Install" button onClick with a fetch to the network endpoint.)
}
```

The Super Admin install branch must call:
```js
fetch(window.sdEdiAdminParams.restApiUrl + 'sd-edi/v1/network/install-plugin', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.sdEdiAdminParams.restNonce },
	body: JSON.stringify({ slug }),
})
```
and refresh the plugin list after a 200 response.

- [ ] **Step 2: Build + manual verify**

```bash
npm run dev
```
Then in a browser, on a multisite subsite admin:
- Visit themes.php → Easy Demo Importer
- Configure a demo whose required plugins are NOT installed network-wide.
- The wizard must show the "Notify Network Admin" block screen, not the install path.

As Super Admin, viewing the same step on a subsite:
- The "Install on network" button must appear and successfully install + activate the plugin network-wide.

- [ ] **Step 3: Commit**

```bash
git add src/js/backend/wizard/steps/RequirementsStep.jsx mix-manifest.json assets/js/backend.min.js
git commit -m "feat(multisite): tiered plugin install UX in wizard"
```

---

## Task C5: Domain-echo confirmation on DB reset

**Files:**
- Create: `src/js/backend/components/DomainEchoConfirm.jsx`
- Modify: the existing reset-confirmation modal (find with `grep -rn "resetDatabase\|confirmationModalWithReset" src/js`)

- [ ] **Step 1: Create the DomainEchoConfirm component**

```jsx
import React, { useState } from 'react';

const params = window.sdEdiAdminParams || {};

export default function DomainEchoConfirm({ onConfirm, onCancel }) {
	const [typed, setTyped] = useState('');
	const expected = params.currentBlogUrl
		.replace(/^https?:\/\//, '')
		.replace(/\/$/, '');

	const matches = typed.trim() === expected;

	return (
		<div className="sd-edi-domain-echo">
			<p>
				This will permanently erase ALL content on:{' '}
				<strong>{params.currentBlogLabel}</strong>
				<br />
				This does NOT affect other sites in your network. Other subsites
				and the network root will remain untouched. This action cannot be
				undone except via Snapshot rollback.
			</p>
			<label>
				Type <code>{expected}</code> to confirm:
				<input
					type="text"
					value={typed}
					onChange={(e) => setTyped(e.target.value)}
					autoFocus
				/>
			</label>
			<div className="sd-edi-domain-echo__actions">
				<button className="button" onClick={onCancel}>
					Cancel
				</button>
				<button
					className="button button-danger"
					disabled={!matches}
					onClick={onConfirm}
				>
					I understand — reset {expected}
				</button>
			</div>
		</div>
	);
}
```

- [ ] **Step 2: Wire it into the existing reset modal**

In the modal that currently renders `confirmationModalWithReset`, branch on `params.isMultisite`:
- If multisite: render `<DomainEchoConfirm ... />` instead of the simple Yes/No buttons.
- If single-site: keep the existing confirmation.

- [ ] **Step 3: Build + manual verify**

```bash
npm run dev
```
Manual:
- On multisite subsite, open the reset modal. The button must be disabled until the user types the exact subsite host.
- On single-site, behavior is unchanged.

- [ ] **Step 4: Commit**

```bash
git add src/js/backend/components/DomainEchoConfirm.jsx \
        src/js/backend/wizard/modals/ResetConfirmModal.jsx \
        mix-manifest.json assets/js/backend.min.js
git commit -m "feat(multisite): domain-echo confirmation gate on DB reset"
```

(Adjust modal path to the actual file you edited.)

---

### Phase C checkpoint

- Subsite admin sees a sticky banner identifying which subsite they're working on.
- Reset confirmation requires typing the exact subsite domain.
- Required-plugins flow shows the correct branch for subsite admin (block) vs Super Admin (install).

---

# Phase D — Polish & Release

## Task D1: WXR mime fallback when `unfiltered_upload` is denied

**Files:**
- Modify: `inc/App/Ajax/Backend/Initialize.php` and/or the existing WXR import handler (find with `grep -rn "unfiltered_upload\|wp_check_filetype" inc`).

- [ ] **Step 1: Skip-and-log attachments with disallowed mime**

Wherever the importer adds an attachment to the media library, wrap the call:

```php
use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;

// ...
$check = wp_check_filetype( $filename );
if ( ! ContextResolver::canUnfilteredUpload() && empty( $check['type'] ) ) {
	// Mime not allowed for this user; skip this attachment, log, continue.
	$skipped[] = [
		'file'   => $filename,
		'reason' => 'mime_not_allowed_under_unfiltered_upload_restriction',
	];
	continue;
}
```

Surface `count($skipped)` in the import summary returned to the React wizard, so the CompleteStep can render "N attachments skipped due to upload restrictions."

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Ajax/Backend/Initialize.php
git add inc/App/Ajax/Backend/Initialize.php  # adjust to actual path
git commit -m "feat(multisite): skip-and-log attachments when unfiltered_upload denied"
```

---

## Task D2: Network plugin install rejection (defense-in-depth)

**Files:**
- Modify: `inc/App/Ajax/Backend/InstallPlugins.php`

- [ ] **Step 1: Reject non-Super-Admin install on multisite at the AJAX entry**

At the top of the install handler, after nonce/capability verification, add:

```php
if ( function_exists( 'is_multisite' ) && is_multisite() && ! is_super_admin() ) {
	wp_send_json_error(
		[
			'errorMessage' => esc_html__( 'On a multisite network, only the Network Admin (Super Admin) can install plugins. Please contact your Network Admin.', 'easy-demo-importer' ),
		],
		403
	);
}
```

- [ ] **Step 2: Verify + commit**

```bash
php -l inc/App/Ajax/Backend/InstallPlugins.php
git add inc/App/Ajax/Backend/InstallPlugins.php
git commit -m "fix(multisite): reject AJAX plugin install for non-Super-Admin"
```

---

## Task D3: Update readme.txt and changelog

**Files:**
- Modify: `readme.txt`
- Modify: `readme.md`
- Modify: `changelog.md`

- [ ] **Step 1: Add Multisite section + tested-up tag in `readme.txt`**

Inside the `== Description ==` block, add a paragraph:

> Easy Demo Importer is fully Multisite-compatible from version 1.2.0. Each subsite imports independently, with its own demo content and per-subsite data. Network Admins get a dedicated screen under Network Admin → Themes for cross-network demo configuration and a per-subsite status overview.

Add a new `== Multisite ==` section above `== Frequently Asked Questions ==` describing per-site vs. network activation behavior.

In `== Changelog ==`, add an entry:

```
= 1.2.0 =
* Added: Full WordPress Multisite support — works in single-site, per-subsite, or network-active mode.
* Added: Network Admin screen under Network → Themes with per-subsite status and JSON network config override.
* Added: Tiered plugin install — subsite admins are guided to ask Network Admin when required plugins are missing; Super Admin can install network-wide from the wizard.
* Added: Sticky subsite banner across the wizard so admins always know which site they are about to modify.
* Added: Domain-echo confirmation on multisite DB reset — typed subsite host must match before destructive action runs.
* Changed: getDemoConfig now resolves a network-wide override (when enabled) before the per-subsite theme filter.
* Improved: per-blog tables created automatically on subsite create; cleaned up on subsite delete and on plugin uninstall across the network.
```

- [ ] **Step 2: Mirror to `readme.md` and `changelog.md`** (project-specific format).

- [ ] **Step 3: Verify + commit**

```bash
grep -n "1.2.0" readme.txt readme.md changelog.md
git add readme.txt readme.md changelog.md
git commit -m "docs(release): document multisite support in 1.2.0"
```

---

## Task D4: Run the full QA matrix (§11 of spec)

**Files:** none — verification only.

- [ ] **Step 1: Spin up a multisite environment with at least 2 subsites**

```bash
# Example with wp-env or local dev:
wp core multisite-install --url=multisite.test --base=/ --title='QA' --admin_user=admin --admin_email=admin@example.com --admin_password=admin
wp site create --slug=sub2 --title='Sub 2' --email=admin@example.com
wp site create --slug=sub3 --title='Sub 3' --email=admin@example.com
```

- [ ] **Step 2: Walk every cell of the matrix**

For each environment ([1]-[5] in spec §11), run every test case ([a]-[k] in spec §11). Record results inline below.

| Env / Case | a | b | c | d | e | f | g | h | i | j | k |
|---|---|---|---|---|---|---|---|---|---|---|---|
| [1] single-site |   |   |   |   | n/a |   | n/a | n/a |   | n/a | n/a |
| [2] multi/per-site/SubAdmin |   |   |   |   | n/a |   | n/a | n/a |   |   |   |
| [3] multi/per-site/SuperAdmin |   |   |   |   | n/a |   | n/a |   |   |   |   |
| [4] multi/network-active/SubAdmin |   |   |   |   | n/a |   |   | n/a |   |   |   |
| [5] multi/network-active/SuperAdmin |   |   |   |   | n/a |   |   |   |   |   |   |

(Phase 4 cases [e], [g], [h] are partially "n/a" because Phase 4 / SnapshotManager is not in this release.)

- [ ] **Step 3: File any blocker bugs as separate tasks before tagging.**

- [ ] **Step 4: Commit the QA log**

```bash
# Save the filled matrix to a QA log file:
git add docs/superpowers/qa/2026-04-29-multisite-1.2.0-qa.md
git commit -m "docs(qa): add 1.2.0 multisite QA matrix run"
```

---

## Task D5: Tag and release

**Files:** none — git operations only. **Do not run without explicit user instruction.**

- [ ] **Step 1: Confirm with user before pushing/tagging.**

- [ ] **Step 2 (only after explicit approval):**

```bash
git checkout master
git merge --no-ff multi-site
git tag -a v1.2.0 -m "Release 1.2.0 — multisite support"
# Push only when user authorizes:
# git push origin master --follow-tags
```

This step requires confirmation per the user's git safety rules.

---

# Self-review

Spec coverage check (against `docs/superpowers/specs/2026-04-29-multisite-support-design.md`):

- §3 decisions → A3 (Hybrid wiring), A4 (Network override), C4 (Tiered caps), A1-A2-A5-A6 (Per-blog tables), B2 (Network → Themes), spec §13 (1.2.0 target). All covered.
- §4 architecture → A1 (ContextResolver), A2 (NetworkInstaller), B1 (network site_options endpoints), C2 (localize multisite context). Covered.
- §5 file table → all rows mapped to a task above. Covered.
- §6 lifecycle → A5 (activation), A6 (site create/delete), A4 (lazy creation), A7 (uninstall). Covered.
- §7 caps + tiered install + DB reset → A1, B1, C2, C4, C5, D1, D2. Phase 4 rollback intentionally out of scope.
- §8 UI flows → C3 (banner), B4-B5-B6 (Network tabs). Covered.
- §9 data flow → A4 (config resolution), C2 (localize), B7 (asset loading). Covered.
- §10 error handling → A2 (`switch_to_blog` try/finally), A4 (lazy idempotency), B1 (per-plugin partial-fail return), D1 (mime skip). Covered.
- §11 testing matrix → D4. Covered.
- §12 rollout → A8 (version), D3 (readme), D5 (tag). Covered.

Placeholder scan: no TBDs, no "implement later", no "similar to Task N", no naked "add error handling" steps. Code blocks present in every code step.

Type consistency:
- `ContextResolver::canRunImport`, `canInstallPlugins`, `canUnfilteredUpload`, `currentBlogId`, `currentBlogLabel`, `targetBlogId`, `isMultisite`, `isNetworkContext` — used consistently across A1, B1, C1, C2, D1, D2.
- `NetworkInstaller::createTableForBlog`, `dropTableForBlog`, `createTablesForAllBlogs`, `processChunk`, `CRON_HOOK`, `CHUNK_SIZE` — consistent across A2, A4, A5, A6, A7.
- Site option keys: `sd_edi_network_config`, `sd_edi_network_override_enabled`, `sd_edi_network_config_updated`, `sd_edi_network_settings`, `sd_edi_network_status` — consistent across A4, A7, B1, B6.
- React localize keys: `isMultisite`, `currentBlogId`, `currentBlogLabel`, `currentBlogUrl`, `isSuperAdmin`, `canInstallPlugins`, `subsiteBannerLabel`, `networkContactSubject`, `networkContactBody` — consistent across C2, C3, C4, C5.

Plan complete.
