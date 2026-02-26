# Easy Demo Importer — Implementation Roadmap

> Revised with Sequential Thinking, Context7 (WordPress API docs), and 21st.dev UI patterns.
> Every phase has been cross-checked for architectural dependencies, API correctness, and forward-compatibility.

**Phase Timeline Diagram:** [Open in FigJam →](https://www.figma.com/online-whiteboard/create-diagram/2714f6d5-b04c-4ada-b035-c418197d962e?utm_source=claude&utm_content=edit_in_figjam)
**Wizard Onboarding Flow Diagram:** [Open in FigJam →](https://www.figma.com/online-whiteboard/create-diagram/33e09b82-12cc-4ae3-a39a-fcf3bd9875b3?utm_source=claude&utm_content=edit_in_figjam)
**Selective Import Flow Diagram:** [Open in FigJam →](https://www.figma.com/online-whiteboard/create-diagram/194bc2b3-55db-479d-8cf3-54a42d206c60?utm_source=claude&utm_content=edit_in_figjam)

---

## Phase Overview

| Phase | Version | Label | Scope |
|-------|---------|-------|-------|
| [0](#phase-0--emergency-compatibility-hotfix) | `1.1.6` | 🚨 Emergency | WP 6.9 + PHP 8.4 + critical runtime bugs |
| [1](#phase-1--foundation--stability) | `1.2.0` | 🔧 Stability | Session management, bug hardening, uninstall, static analysis |
| [2](#phase-2--wizard--xml-engine) | `1.3.0` | 🧙 Wizard + XML | Onboarding wizard, XMLReader chunker, live log, cache flush |
| [3](#phase-3--image-regeneration-engine) | `1.4.0` | 🖼️ Image Engine | Plugin-owned regen, per-image count, failure tracking |
| [4](#phase-4--power-import) | `1.5.0` | ⚡ Power Import | Selective items, dependency resolver, rollback, auto URL fix |
| [5](#phase-5--polish--competitive-edge) | `1.6.0` | ✨ Polish | White label, badges, builder detection, Elementor fixes |
| [6](#phase-6--developer--agency) | `2.0.0` | 🏗️ Developer | Background import, multisite, WP-CLI, history, email |
| [7](#phase-7--future) | `2.x` | 🚀 Future | FSE, export tool, ACF mapping, test suite, a11y |

### Cross-Phase Design Contracts
These decisions are made early so later phases require no rework:
- **Phase 2** XML chunker accepts `$allowed_post_ids = []` from day one → **Phase 4** just passes the selection list
- **Phase 2** activity log table is reused as-is by **Phase 4** rollback and **Phase 6** history
- **Phase 3** image regen pipeline scoped to a session's attachments → **Phase 4** narrows it to selected-content attachments only
- **Phase 1** session ID + mutex lock → every subsequent phase uses the same session system

---

## Phase 0 — Emergency Compatibility Hotfix

> **Version:** `1.1.6`
> **Ship immediately.** Plugin is 8 months stale. WP 6.9 + PHP 8.4 are current stable.
> **Rule:** No new features. Compatibility and critical runtime safety only.

---

### 0.0 — Test Environment Setup (Do This First)

Before touching a single line of code, set up a local environment matching production targets.

- [ ] Install `@wordpress/env` (`wp-env`): `npm install -g @wordpress/env`
- [ ] Create `.wp-env.json` at project root:
  ```json
  {
    "core": "WordPress/WordPress#6.9",
    "phpVersion": "8.4",
    "plugins": [ "." ],
    "themes": [ "twentytwentyfour" ]
  }
  ```
- [ ] Start env: `wp-env start`
- [ ] Verify PHP 8.4 is active: `wp-env run cli php --version`
- [ ] Enable `E_ALL` error reporting in `wp-config.php` inside the env — zero deprecation notices is the exit criteria for Phase 0
- [ ] Install Rector as a dev dependency: `composer require --dev rector/rector`
- [ ] Create `rector.php` at project root with `SetList::PHP_84` rule set

---

### 0.1 — PHP 8.4 Fixes

#### Implicitly Nullable Parameters (Widespread)

PHP 8.4 deprecates `function foo(SomeType $param = null)`. Must become `?SomeType $param = null`.

- [ ] Run Rector auto-fix: `vendor/bin/rector process inc/ --set php84`
- [ ] Review Rector diff — accept all implicitly nullable fixes
- [ ] Manually fix any that Rector can't auto-correct (union types, intersection types)
- [ ] Run again with `--dry-run` to confirm zero remaining violations
- [ ] Files most at risk (audit manually after Rector):
  - `inc/Common/Abstracts/ImporterAjax.php`
  - `inc/Common/Abstracts/Enqueue.php`
  - `inc/App/Ajax/Backend/*.php` (all 12 handlers)
  - `inc/Common/Models/*.php`

#### Variable Variable Syntax — ✅ Already Fixed (commit `927f4cd`)

The `lib/wordpress-importer` PHP 8.4 fixes were shipped in an unreleased commit:
- Replaced all `$this->maybe_unserialize()` calls with WP core's `maybe_unserialize()`
- Removed the deprecated custom `maybe_unserialize()` method entirely
- Post-processing condition refactored (`4ae1181`)
- Menu item meta preservation added (`640148e`)

No action needed here — **already done**. Verify with: `git show 927f4cd`

#### PHP 8.4 New Features — Use Them

PHP 8.4 adds `array_find()` and `array_find_key()`. Where the codebase uses `reset(array_filter(...))` pattern, prefer `array_find()` for clarity.

- [ ] Search for `reset( array_filter(` patterns and evaluate if `array_find()` is cleaner
- [ ] Use PHP 8.4's new `Dom\Document` class for the XML pre-parser in Phase 2 (note for later, don't implement now)

#### Runtime Safety Fixes (Critical — Moved From Phase 1)

These two bugs will surface during compat testing, so fix them in Phase 0:

- [ ] **`scandir()` null guard** (`Initialize.php:~221`):
  ```php
  $scan = scandir( $dir );
  if ( ! is_array( $scan ) ) {
      return;
  }
  $files = array_diff( $scan, [ '.', '..' ] );
  ```
- [ ] **`array_key_first()` null guard** (`ImporterAjax.php:~158`):
  ```php
  $firstDemoSlug = array_key_first( $this->config['demoData'] ?? [] );
  if ( null === $firstDemoSlug ) {
      wp_send_json_error( [ 'message' => __( 'No demo data configured.', 'easy-demo-importer' ) ], 400 );
      wp_die();
  }
  ```

---

### 0.2 — WordPress 6.9 Fixes

#### Script Enqueue — Modern Args Array + `strategy`

Context7 confirmed: WP 6.3+ supports `strategy` key in the args array. WP 6.9 expects this pattern.

- [ ] Update `inc/Common/Abstracts/Enqueue.php` — replace all `wp_register_script()` / `wp_enqueue_script()` boolean `$in_footer` calls:
  ```php
  // Before (legacy since WP 6.3)
  wp_register_script( $handle, $uri, $deps, $version, true );

  // After (WP 6.9 preferred — also adds defer loading strategy)
  wp_register_script( $handle, $uri, $deps, $version, [
      'in_footer' => true,
      'strategy'  => 'defer',
  ] );
  ```
- [ ] Apply same update to `inc/App/Backend/Enqueue.php`
- [ ] Note: use `strategy: async` only for scripts with no DOM dependencies. `defer` is safe for all admin scripts

#### WP_Dependencies Audit

- [ ] Search entire codebase: `Grep 'WP_Dependencies'`
- [ ] If found anywhere, replace with `WP_Scripts` or `WP_Styles` as appropriate
- [ ] `WP_Dependencies` is deprecated in WP 6.9 — direct instantiation will trigger notices

#### jQuery Availability

- [ ] Verify `jquery` handle is explicitly enqueued in admin context (WP 6.9 still ships it but confirm)
- [ ] In `inc/App/Backend/Enqueue.php`, ensure `jquery` is in the dependencies array of the main admin script

#### REST API Permission Callbacks

- [ ] In `inc/App/Rest/RestEndpoints.php`, verify all `permission_callback` lambdas return strict `true` (not just truthy):
  ```php
  // Safe pattern
  'permission_callback' => function() {
      return current_user_can( 'import' );
  },
  ```
- [ ] WP 6.9 enforces that `permission_callback` must be explicitly set — confirm none are missing (will throw a `_doing_it_wrong()` notice)

#### Database Layer

- [ ] Test `SHOW TABLE STATUS` query in `Initialize.php:~161` on WP 6.9 — verify column names haven't changed
- [ ] Wrap column access with `isset()` guards

---

### 0.3 — Dependencies + Release

- [ ] `composer update` — verify no dependency breaks on PHP 8.4
- [ ] `npm update` — update all JS dependencies
- [ ] `npm run production` — rebuild all compiled assets
- [ ] Pull latest `lib/wordpress-importer/` from WordPress.org upstream
- [ ] Update `readme.txt`: `Tested up to: 6.9`
- [ ] Sync version to `1.1.6` in `easy-demo-importer.php`, `readme.txt`, `package.json`
- [ ] Run full import pipeline on `wp-env` (WP 6.9 + PHP 8.4) — zero deprecation notices, zero fatal errors
- [ ] Run PHPCS: `vendor/bin/phpcs inc/ --standard=WordPress`
- [ ] Tag and release `1.1.6`

---

## Phase 1 — Foundation & Stability

> **Version:** `1.2.0`
> **Goal:** Invisible but essential. Hardened error handling, proper session management, static analysis baseline, full uninstall support.

---

### 1.1 — Import Session Management

All subsequent phases (chunked XML, image regen, rollback, history) share a session system. Build it once here.

- [ ] `sd_edi_start_session()` helper — generates a `session_id` (UUID v4 via `wp_generate_uuid4()`) and stores it in a transient
- [ ] `sd_edi_import_lock` — a DB option that acts as a mutex. Set to `session_id` at import start, cleared at import end. Checked at AJAX entry point — reject concurrent imports with a clear error message
- [ ] TTL: lock auto-expires after 30 minutes (in case of crashed import), filterable via `sd/edi/lock_ttl`
- [ ] All AJAX handlers receive `session_id` as a POST param and validate it against the active lock
- [ ] `sd_edi_get_active_session()` — returns current session data or `null`
- [ ] Orphan cleanup: on next import start, detect and clean up any orphaned session data from previous crashed import

---

### 1.2 — Remaining Bug Fixes

- [ ] **`clearUploads()` symlink guard** (`Initialize.php:~220`):
  ```php
  if ( is_link( "$dir/$file" ) ) {
      continue; // Skip symlinks entirely — never follow them
  }
  ```
- [ ] **Nonce hard-fail** (`ImporterAjax.php:~154`): Silent empty string return → `wp_send_json_error(['message' => 'Security check failed.'], 403)` + `wp_die()`
- [ ] **Config validation** (`ImporterAjax.php:~119`): Validate required keys exist before access. Return clear AJAX error if missing
- [ ] **Download guard** (`DownloadFiles.php:~91`): Check `is_wp_error($response)` and HTTP status before `unzip_file()`. Return clear error on failure
- [ ] **Download timeout** (`DownloadFiles.php:~117`): Default `120` seconds. Add `sd/edi/download_timeout` filter

---

### 1.3 — Uninstall + Data Cleanup

- [ ] Create `uninstall.php` at plugin root — WordPress calls this on plugin deletion
- [ ] Delete all `sd_edi_*` options from `wp_options`
- [ ] Delete all `sd_edi_*` transients
- [ ] Drop custom table: `DROP TABLE IF EXISTS {$wpdb->prefix}sd_edi_taxonomy_import`
- [ ] Clear any scheduled cron events registered by the plugin
- [ ] Remove empty `uninstall()` stub from `Setup.php` — add a comment pointing to `uninstall.php`

---

### 1.4 — Code Hardening

- [ ] **Capability consistency**: pick one — either use `import` everywhere (AJAX + REST) or `manage_options` everywhere. `import` is the correct semantic choice
- [ ] **Download domain allowlist**: add `sd/edi/allowed_download_domains` filter. Default: allow all. Theme authors can restrict to their own CDN
- [ ] **ZIP path traversal guard**: after `unzip_file()`, validate no extracted path leaves the upload directory
- [ ] **REST endpoint caching**: cache server status endpoint response in a 5-minute transient. Add a `?force=1` param to bypass
- [ ] **PHPStan baseline**: `composer require --dev phpstan/phpstan`. Run at level 5. Fix all errors. Commit baseline

---

### 1.5 — Release

- [ ] Write `CHANGELOG.md` entry for `1.2.0`
- [ ] Tag release

---

## Phase 2 — Wizard + XML Engine

> **Version:** `1.3.0`
> **Goal:** The release that overtakes OCDI. Wizard UI, streaming XML import, live activity log, dry-run stats, cache flush, conditional demos.
> **Key architectural decisions baked in here that P4 depends on — do not cut corners.**

---

### 2.0 — Pre-Work: UI Stack Confirmation

- ✅ **Ant Design v5 already installed** (`^5.26.0`) — no upgrade needed, v4→v5 migration already done
- ✅ **React 18** already in use
- ✅ **React Router v6** already in use (added in v1.1.2)
- ✅ **Zustand** already in use for state (`useSharedDataStore`)
- [ ] Do NOT add Framer Motion — keep bundle small. Use CSS transitions (`transition-all duration-300`) for wizard step animations. React Router handles route-based step transitions
- [ ] Use Ant Design v5's `ConfigProvider` + design token system for any visual customisation (see UI decision note below)

### 2.0.1 — UI Library Decision: Keep Ant Design v5

> **Decision: Do not migrate to shadcn/ui. Stay on Ant Design v5.**

**Why shadcn is wrong for this context:**
- Tailwind's preflight CSS (`*, h1–h6, p, a, button` resets) collides directly with WordPress admin styles. Requires `important` + `prefix` config and a scoped wrapper — non-trivial and ongoing maintenance burden.
- Every existing component (Setup.jsx uses `Button`, `Switch`, `Tooltip`, `Skeleton`, `Row`, `Col` — and that's one file) would need to be rewritten. Weeks of work, zero new user-facing value.
- shadcn is designed for Next.js/Vite. Adding it to a Laravel Mix + WordPress admin context is fighting the tool.

**Why Ant Design v5 is already the right choice:**
- CSS-in-JS scoping means antd styles cannot leak into or be affected by WordPress admin styles — ideal for a plugin.
- Tree-shaking means only imported components ship. `import { Button } from 'antd'` only bundles Button.
- Already deeply integrated across the entire codebase. The hardest migration (v4 → v5) is already done.

**How to improve visuals without migrating — use the design token system:**
```jsx
// Wrap the app root in ConfigProvider
<ConfigProvider theme={{
  token: {
    colorPrimary:    '#6366f1',   // brand purple
    colorSuccess:    '#10b981',
    colorWarning:    '#f59e0b',
    colorError:      '#ef4444',
    borderRadius:    10,
    borderRadiusLG:  14,
    fontFamily:      '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
    boxShadow:       '0 4px 24px rgba(0,0,0,0.08)',
  },
  components: {
    Button:   { borderRadius: 8, controlHeight: 40 },
    Switch:   { colorPrimary: '#6366f1' },
    Modal:    { borderRadiusLG: 16 },
    Progress: { colorInfo: '#6366f1' },
    Steps:    { colorPrimary: '#6366f1' },
  }
}}>
  <App />
</ConfigProvider>
```
This gives full visual control — same antd, completely custom look, zero migration.

---

### 2.1 — Wizard Architecture

Replace the modal-based flow with a full-page wizard. React Router routes map to wizard steps.

**Routes:**
```
/wizard/welcome
/wizard/requirements
/wizard/plugins
/wizard/demos
/wizard/options
/wizard/confirm
/wizard/importing
/wizard/complete
```

- [ ] `WizardLayout` — shared shell: step indicator top bar, back/next nav, step counter (`Step 3 of 8`)
- [ ] Step indicator: icon circles → filled with checkmark ✓ when complete → active ring on current
- [ ] CSS slide transition between steps: outgoing step slides left, incoming slides in from right (reverse on Back)
- [ ] `WizardContext` — React context holding: `sessionId`, `selectedDemo`, `importOptions`, `pluginStatus`, `importProgress`
- [ ] Add `sd/edi/wizard/steps` PHP filter so theme authors can add/remove/reorder steps

**Step implementations:**
- [ ] `WelcomeStep` — plugin intro, feature highlights, estimated time badge
- [ ] `RequirementsStep` — consumes existing system status REST endpoint. Pass = green ✓. Fail = red ✗ + fix link. Warn = amber ⚠. Block `Next` on any hard fail
- [ ] `PluginInstallerStep` — per-plugin rows with install → activate progress. Polled via REST
- [ ] `DemoSelectStep` — full-page card grid (no modal). Category tabs + search bar + conditional visibility (see 2.5). Clicking a card selects it — no separate "confirm" click
- [ ] `ImportOptionsStep` — checkbox groups: content types, media, customizer, widgets, menus, plugin settings. DB reset toggle with `⚠ This will delete N existing posts` inline warning (count fetched from REST)
- [ ] `ConfirmationStep` — displays dry-run stats (see 2.3). Last chance to go back. `Start Import` button
- [ ] `ImportingStep` — live progress bar + `ActivityFeed` component polling log endpoint. Step detail line below bar: `Importing page 34 of 87 — Our Services`. "Do not close this tab" banner
- [ ] `CompleteStep` — health report summary, quick links (View Site, Customize, Docs), Undo button (if Phase 4 available, else stubbed)

---

### 2.2 — Dry-Run Stats Endpoint

- [ ] New REST endpoint: `GET /sd/edi/v1/demo-stats?demo={slug}`
- [ ] Parses WXR XML **without writing to DB** — reads item counts by `<wp:post_type>`, counts `<wp:attachment>`, counts `<wp:term>`, counts nav menus
- [ ] Response:
  ```json
  {
    "pages": 23,
    "posts": 8,
    "products": 14,
    "portfolio": 6,
    "attachments": 134,
    "menus": 3,
    "estimated_size_mb": 42
  }
  ```
- [ ] Cache result per demo slug in a 5-minute transient
- [ ] Display on `ConfirmationStep` as: `23 pages · 8 posts · 134 images · 3 menus`

---

### 2.3 — Chunked XML Import (XMLReader — Streaming)

**Critical architectural decision:** Use `XMLReader` (PHP's streaming XML parser), not `DOMDocument`. `XMLReader` processes XML as a forward-only stream — it never loads the full file into memory. This is why large imports time out with the current approach.

**Design contract for Phase 4:** The chunker accepts `$allowed_post_ids = []` from day one. When empty (default), all posts are imported. When populated (Phase 4), only matching IDs are processed. Zero rework in Phase 4.

- [ ] `XmlChunker` class in `inc/Common/Utils/XmlChunker.php`:
  ```php
  class XmlChunker {
      public function getItems( string $file_path ): array {} // Parse, return all item metadata (no DB write)
      public function importChunk( string $file_path, int $offset, int $limit, array $allowed_ids = [] ): array {}
  }
  ```
- [ ] `getItems()` uses `XMLReader` to stream through XML — returns flat array of `[post_id, post_type, post_title, parent_id]` for UI display and dependency scanning. Runs in under 2s even on 10MB XML files
- [ ] `importChunk()` streams XML again, skips to `$offset`, processes up to `$limit` items, respects `$allowed_ids`
- [ ] Store pending chunk queue in DB (not transient — transients have size limits for large demos): `wp_sd_edi_import_queue` table with `(session_id, item_index, post_id, status)`
- [ ] New AJAX handler `sd_edi_import_xml_chunk` — process one chunk, return `{done: 40, total: 87, current_title: "Our Services"}`
- [ ] Frontend polls: fires next chunk AJAX on previous response, updates progress bar and detail text
- [ ] On AJAX failure: store last successful `item_index` in session. Retry from there
- [ ] Add `sd/edi/xml_chunk_size` filter — default `20` items per chunk. Auto-reduce to `5` if `memory_limit < 256MB`

---

### 2.4 — Activity Log

- [ ] Create `wp_sd_edi_import_log` table: `(id INT, session_id VARCHAR, timestamp DATETIME, level ENUM('info','success','warning','error'), message TEXT)`
- [ ] `ImportLogger::log( string $message, string $level, string $session_id )` static helper
- [ ] Instrument all import steps to write meaningful log entries:
  - Plugin installed/activated (name)
  - Post imported (title, type)
  - Image downloaded (filename, size)
  - URL references replaced (count)
  - Cache cleared (which plugins)
  - Any error or skipped item (reason)
- [ ] REST endpoint `GET /sd/edi/v1/import-log?session_id={id}&since={timestamp}` — returns new entries since last poll
- [ ] `ActivityFeed` React component — polls every 2 seconds during import, renders timestamped entries with level-coloured icons
- [ ] Post-import: log readable on `CompleteStep` and on System Status page
- [ ] "Download Log" button — full log as `.txt` file
- [ ] Auto-prune: delete logs older than 7 days on next import start

---

### 2.5 — Conditional Demo Visibility

Moved here from Phase 5 — this belongs at the demo selection step.

- [ ] Theme authors add `requires` key to demo config:
  ```php
  'requires' => [ 'woocommerce', 'elementor' ],
  ```
- [ ] On `DemoSelectStep` load, check each demo's `requires` against `get_plugins()` active list
- [ ] Unmet demos: render as greyed-out card with lock icon and tooltip: `"Requires WooCommerce and Elementor"`
- [ ] "Show incompatible demos" toggle at top of grid for advanced users

---

### 2.6 — Post-Import Cache Flush

Moved here from Phase 5 — this is part of the `Finalize` step and belongs in the same release as the wizard.

- [ ] Extend `Finalize.php` to detect and flush caches after import:
  ```php
  // WP Super Cache
  if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
  // W3 Total Cache
  if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
  // LiteSpeed Cache
  do_action( 'litespeed_purge_all' );
  // WP Rocket
  if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
  // Elementor CSS
  if ( class_exists( '\Elementor\Plugin' ) ) {
      \Elementor\Plugin::$instance->files_manager->clear_cache();
  }
  // WooCommerce transients
  if ( function_exists( 'wc_delete_product_transients' ) ) wc_delete_product_transients();
  // WP object cache
  wp_cache_flush();
  ```
- [ ] Log each flushed cache to activity log: `"Cache cleared: LiteSpeed Cache ✓, Elementor CSS ✓"`
- [ ] Add `sd/edi/flush_caches` filter returning array of handlers — developers can add or remove

---

### 2.7 — Release

- [ ] Update screenshots in `readme.txt` (wizard screenshots required)
- [ ] Write changelog for `1.3.0`
- [ ] Tag release

---

## Phase 3 — Image Regeneration Engine

> **Version:** `1.4.0`
> **Goal:** Plugin takes full ownership of image regeneration. WordPress does nothing. Plugin shows every image by name with a count, failure tracking, and three user-controlled modes.
> **No competitor does this.**

See also: `IMPROVEMENTS.md → Image Regeneration — Dedicated Design`

---

### 3.1 — Suppress WP Regeneration During Import — ✅ Partially Done (commit `1616c31`)

The suppression filters are already applied in `inc/App/Ajax/Backend/InstallDemo.php` when `skipImageRegeneration` is `true`:

```php
// Already in production code
add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
```

A "Skip Image Regeneration" toggle also exists in the Setup step UI (`src/js/backend/components/Modal/steps/Setup.jsx`).

**What's still missing for Phase 3:**
- [ ] Make regen suppression the **default** during import (not opt-in) — regeneration should always happen in the dedicated step, not silently during XML import
- [ ] Add `add_filter('big_image_size_threshold', '__return_false', 9999)` alongside the existing filters (currently missing)
- [ ] Track all attachment post IDs created during import in a session record: `sd_edi_session_{session_id}_attachments` — this is the input list for the dedicated regen step
- [ ] Remove the current "Skip Image Regeneration" toggle from Setup — replace it with the 3-mode regen step (Now / Background / Skip) in the wizard
- [ ] Log to activity feed: `"Image regeneration deferred — dedicated step ready"`

---

### 3.2 — Dedicated Regen Step (Wizard Step 8.5)

New wizard step inserted between `ImportingStep` and `CompleteStep`. Shown unless user chooses Skip.

**Pre-step screen:**
```
47 images found — ready to regenerate
[ Regenerate Now ]  [ In Background ]  [ Skip ]
```

**New AJAX handler `sd_edi_regenerate_images`:**
- [ ] Accepts `session_id` + `offset`
- [ ] Fetches next N attachment IDs from session attachments list
- [ ] Calls `wp_generate_attachment_metadata( $attachment_id, get_attached_file( $attachment_id ) )` for each
- [ ] Updates the attachment's metadata via `wp_update_attachment_metadata()`
- [ ] Returns:
  ```json
  {
    "done": 14,
    "total": 47,
    "current_filename": "hero-banner.jpg",
    "sizes_generated": ["thumbnail", "medium", "large", "hero-1920x600"],
    "failed": []
  }
  ```
- [ ] `ImageRegenStep` React component:
  - Progress bar: `14 / 47`
  - Current file: `Regenerating — hero-banner.jpg`
  - Active sizes as small pill tags below filename
  - Failures collected in an expandable `⚠ 2 images failed` section with filenames + error messages
- [ ] Batch size: default 5 images per AJAX call. Auto-reduce to 1 if `memory_limit < 256MB`. Filterable: `sd/edi/regen_batch_size`
- [ ] On finish: `47 images regenerated · thumbnail, medium, large, custom-1920x600 · 2 failures`

---

### 3.3 — Background Regen Mode

- [ ] Register WP-Cron event `sd_edi_background_regen_{session_id}`
- [ ] Cron callback processes 10 images per tick, stores progress in transient
- [ ] Admin notice on return to dashboard: `"Image regeneration running in background — 34 of 47 done"`
- [ ] On cron completion: admin notice + update session log

---

### 3.4 — System Status Integration

- [ ] System Status page: show last regen session (date, total count, failure count)
- [ ] "Re-run Image Regeneration" button — triggers a new regen AJAX session for all existing attachments

---

### 3.5 — Release

- [ ] Write changelog for `1.4.0`
- [ ] Tag release

---

## Phase 4 — Power Import

> **Version:** `1.5.0`
> **Goal:** Selective item import, dependency resolution, rollback, and silent auto URL fix. Together these make every other demo importer feel half-baked.

---

### 4.1 — Selective Import (Three Levels)

**Level 1 — By data type:** Already built in Phase 2 wizard options step. ✓

**Level 2 — By post type:**
- [ ] After enabling "Content" in Import Options, show post type breakdown sourced from the dry-run endpoint
- [ ] Per-type checkboxes: `Pages (23)  Posts (8)  Products (14)  Portfolio (6)`
- [ ] Selected types written to import session config

**Level 3 — By individual item:**
- [ ] New REST endpoint `GET /sd/edi/v1/demo-items?demo={slug}&post_type={type}`:
  - Uses `XmlChunker::getItems()` (Phase 2.3 — already built)
  - Returns: `[{ id, title, slug, post_type, parent_title }]`
- [ ] `DemoItemPicker` React component: searchable checklist panel per post type, "Choose specific items" trigger
- [ ] Selected item IDs stored in session config

**Chunker integration (zero rework — by design):**
- [ ] Pass `selected_ids` from session to `XmlChunker::importChunk( ..., $allowed_ids = $selected_ids )`
- [ ] Items not in the list are skipped during streaming — never written to DB

---

### 4.2 — Dependency Resolver

- [ ] `DependencyResolver` class in `inc/Common/Utils/DependencyResolver.php`
- [ ] Scans selected items' raw XML for:
  - Attachment IDs referenced in Elementor `_elementor_data` JSON
  - Attachment IDs in standard `_thumbnail_id` post meta
  - Parent post IDs from `<wp:post_parent>`
  - Term IDs from `<category domain>` tags
- [ ] **SSRF protection:** any URL extracted from meta is validated via `wp_http_validate_url()` and blocked if it resolves to a private IP range (RFC1918). Never make HTTP requests from the resolver
- [ ] Returns two lists:
  - `auto_include`: hard deps (images, terms, parent pages) — included silently
  - `warnings`: soft deps (menus, widgets) — shown as optional checkboxes on ConfirmationStep
- [ ] REST endpoint `POST /sd/edi/v1/resolve-deps` — accepts `{session_id, item_ids: []}`, returns resolver output
- [ ] ConfirmationStep: collapsible "Also importing" section showing auto-includes. Optional soft deps as checkboxes

---

### 4.3 — Import Rollback / Undo

- [ ] Before any import begins, create a pre-import snapshot stored in `wp_sd_edi_snapshots` table: `(id, session_id, created_at, snapshot_data LONGTEXT, status)`
- [ ] Snapshot captures:
  - Serialise affected options using `wp_json_encode()` (not PHP `serialize()` — more portable and readable)
  - Record `max(ID)` from `wp_posts` and `max(term_id)` from `wp_terms` at import start
  - Record current menu location assignments
- [ ] After import: show "Undo this import" button on `CompleteStep` (24-hour TTL, filterable via `sd/edi/rollback_ttl`)
- [ ] Rollback process:
  - Delete all posts with ID > snapshot's post_id watermark that were created by this session
  - Delete all terms above watermark
  - Restore `wp_json_decode()`'d options
  - Flush caches
  - Show confirmation: `"Import from [date] has been undone"`
- [ ] System Status page: show last snapshot date, TTL remaining, Rollback button if within window
- [ ] Auto-prune snapshots older than TTL on next import start

---

### 4.4 — Smart Auto URL Fix

- [ ] After XML import, read `<wp:base_site_url>` from the WXR file (the demo site's original URL)
- [ ] Compare against `get_site_url()`. If different, run `DBSearchReplace` (already in the plugin) silently:
  - Replace `http://` and `https://` variants
  - Handle trailing slash variants
  - Handle subdirectory installs
- [ ] Extend to Elementor serialised JSON: `\Elementor\Plugin::$instance->files_manager->clear_cache()` after URL replace if Elementor active
- [ ] Log to activity feed: `"Auto URL fix: replaced 342 references (old-demo.com → your-site.com)"`
- [ ] If URLs already match (localhost-to-localhost import): skip silently, log `"URLs match — auto fix skipped"`
- [ ] `sd/edi/auto_url_fix` filter (default `true`) — allow opt-out

---

### 4.5 — Pre-Import Conflict Detection

Expand Requirements step to surface these before import starts:

- [ ] PHP memory < 256MB → hard warn
- [ ] `max_execution_time` < 300 → warn
- [ ] Required plugin version below demo's minimum → block with version mismatch message
- [ ] Active caching plugin detected → warn: "Disable cache during import for best results"
- [ ] DB reset enabled + existing post count > 10 → strong warning: `"This will permanently delete 847 existing posts"`
- [ ] Elementor version check if demo uses Elementor

---

### 4.6 — Release

- [ ] Write changelog for `1.5.0`
- [ ] Tag release

---

## Phase 5 — Polish & Competitive Edge

> **Version:** `1.6.0`
> **Goal:** The details users talk about. White label, badges, builder-specific fixes, Elementor multi-ZIP, ShopBuilder.
> Cache flush and conditional demos already shipped in Phase 2.

---

### 5.1 — White Label / Rebrandable UI

- [ ] `sd/edi/branding` PHP filter:
  ```php
  add_filter( 'sd/edi/branding', function( $b ) {
      $b['plugin_name']   = 'My Theme Setup';
      $b['logo_url']      = get_template_directory_uri() . '/logo.svg';
      $b['primary_color'] = '#e74c3c';   // Applied as CSS custom property
      $b['support_url']   = 'https://my-theme.com/support';
      return $b;
  });
  ```
- [ ] Apply `plugin_name` to: wizard title, admin menu label, page `<title>`, completion screen
- [ ] Apply `logo_url` to: wizard header, completion screen hero
- [ ] Apply `primary_color` as `--edi-primary` CSS custom property → wizard step indicator, buttons, progress bar
- [ ] Apply `support_url` to: error screens, completion help link
- [ ] Fallback to default Easy Demo Importer branding if no filter registered

---

### 5.2 — Demo Badges + Tags

- [ ] `badges` array in demo config: `['new', 'popular', 'woocommerce', 'elementor', 'dark', 'rtl', 'ltr']`
- [ ] Rendered as coloured pill chips on demo cards: `new`=green, `popular`=orange, `woocommerce`=purple, `elementor`=pink, `dark`=slate, `rtl`=blue
- [ ] Badge filter in demo grid — click a badge chip to filter cards
- [ ] `todo.txt` item resolved ✓

---

### 5.3 — Page Builder Auto-Detection

- [ ] After import, detect active page builder from demo config or from imported post meta
- [ ] Apply builder-specific post-processing:
  - **Elementor:** `\Elementor\Plugin::$instance->files_manager->clear_cache()` + taxonomy remap
  - **Bricks Builder:** trigger Bricks cache rebuild via its internal API
  - **Oxygen:** clear Oxygen CSS files from uploads directory
  - **Gutenberg/FSE:** call `wp_set_global_styles_individually()` to re-save block styles (WP 6.6+ API)
  - **Divi:** clear Divi's static CSS cache option
- [ ] Log builder detected and which post-processing ran

---

### 5.4 — Elementor Multi-ZIP Fix

- [ ] Research and document the exact taxonomy ID remapping failure in multi-ZIP Elementor imports
- [ ] Fix by running the existing taxonomy remap process per-ZIP rather than once at the end
- [ ] Add a `samples/multi-zip-sample/` test config for regression testing
- [ ] `todo.txt` item resolved ✓

---

### 5.5 — ShopBuilder Initial Pages

- [ ] After import, detect ShopBuilder plugin active
- [ ] Create required WooCommerce pages (shop, cart, checkout, my account) via `wc_create_page()` if not present
- [ ] Assign page IDs to ShopBuilder settings via its option keys
- [ ] `todo.txt` item resolved ✓

---

### 5.6 — Demo Preview Button

- [ ] Add optional `live_url` key to demo config
- [ ] If present, show `"Preview Demo ↗"` chip on demo card — opens new tab
- [ ] Show live URL link on ConfirmationStep alongside demo thumbnail

---

### 5.7 — Release

- [ ] Write changelog for `1.6.0`
- [ ] Tag release

---

## Phase 6 — Developer & Agency

> **Version:** `2.0.0` — Major version. Background import + multisite + WP-CLI = agency-grade tool.

---

### 6.1 — Background Import (WP-Cron / Action Scheduler)

- [ ] "Import in Background" toggle on Import Options step
- [ ] If Action Scheduler is available (WooCommerce present): prefer it over WP-Cron (more reliable, retryable)
- [ ] Queue each import step as a scheduled action/cron event with 30s intervals
- [ ] Progress stored in session transient — polled on dashboard return
- [ ] Admin notice on start: `"Demo import running in background — you can safely close this tab"`
- [ ] On return: detect in-progress session, resume progress polling automatically
- [ ] On completion/failure: admin notice + email notification (opt-in, see 6.5)

---

### 6.2 — Multisite Support

- [ ] Detect multisite on plugin activation
- [ ] Per-subsite import — each subsite has its own demo config, import session, and history
- [ ] Network admin option: push a demo import to all subsites simultaneously
- [ ] `wp_sd_edi_*` tables created per-blog in multisite (`$wpdb->get_blog_prefix($blog_id)`)
- [ ] `uninstall.php` cleans up all subsites
- [ ] `todo.txt` item resolved ✓

---

### 6.3 — Import History + Re-run

- [ ] `wp_sd_edi_import_history` table: `(id, session_id, demo_slug, demo_name, started_at, completed_at, status, summary_json)`
- [ ] Admin sub-page: `Appearance → Demo Import History`
- [ ] Each row: demo name, date, status badge, item counts, "View Log" link
- [ ] "Re-import" button: restores import options from that session, starts new import

---

### 6.4 — WP-CLI Support

```bash
wp edi import --demo=my-demo [--reset] [--skip-media] [--skip-plugins]
wp edi list
wp edi status
wp edi regen-images [--session=id]
wp edi rollback [--session=id]
wp edi log [--session=id] [--tail=50]
```

- [ ] `WP_CLI::add_command( 'edi', EasyDemoImporter\CLI\Commands::class )`
- [ ] All commands respect capability checks and config system
- [ ] `wp edi import` shows WP-CLI progress bars per step
- [ ] `wp edi status` outputs system status table (reuses REST endpoint data)

---

### 6.5 — Multi-Language Demo Support

- [ ] Add `locale` key to demo config: `'locale' => 'ar_SA'`
- [ ] Demo grid filters to current `get_locale()` by default
- [ ] Language flag icon on demo cards (ISO 3166-1 alpha-2 mapped to emoji flags)
- [ ] "Show all languages" toggle

---

### 6.6 — Import Progress Email Notification

- [ ] Opt-in toggle in Import Options step
- [ ] On complete: `wp_mail()` to admin email — demo name, date/time, duration, item counts, log link, rollback link
- [ ] On failure: email with failed step, error excerpt, log link

---

### 6.7 — Release

- [ ] Write full `2.0.0` changelog
- [ ] Update documentation site
- [ ] Tag release

---

## Phase 7 — Future

> **Version:** `2.x` (individual minor releases)
> **Goal:** Uncontested territory. FSE support, export tool for theme authors, field mapping, full test coverage.

---

### 7.1 — Full Site Editing (FSE) / `theme.json`

No demo importer supports this. First-mover advantage as block theme adoption accelerates.

- [ ] Detect FSE vs classic theme on import start — route to correct pipeline
- [ ] Import `theme.json` overrides (global colour palette, typography, spacing presets)
- [ ] Import global style variations (dark mode, high-contrast)
- [ ] Import block templates (`wp_template` CPT): `header.html`, `footer.html`, `single.html`
- [ ] Import block template parts (`wp_template_part` CPT)
- [ ] New config key: `fse_data_url` pointing to `fse-content.zip`

---

### 7.2 — Demo Content Export Tool (for Theme Authors)

Doubles the target audience. Helps theme authors create demo packages without manual work.

- [ ] New admin sub-page: `Appearance → Export Demo Data`
- [ ] Step 1: choose post types, taxonomies, menus, widgets, customizer
- [ ] Step 2: media handling — include all, referenced only, or exclude
- [ ] Step 3: generates `content.xml` + `customizer.dat` + `widget.wie` + pre-filled `demo-config-sample.php`
- [ ] Packages as a ZIP ready for CDN upload
- [ ] Uncontested territory — no other demo importer helps the author side

---

### 7.3 — ACF / Meta Box Field Mapping

- [ ] After import, detect ACF/Meta Box field definitions on target site
- [ ] Scan imported post meta for field keys not present on target site
- [ ] Auto-map where possible (same name + same type)
- [ ] Log unmapped fields: `"Field 'event_date' not found on target site"`
- [ ] Optional: import ACF field group definitions from `acf-fields.json` in ZIP

---

### 7.4 — PHPUnit Test Suite

- [ ] `composer require --dev wp-phpunit/wp-phpunit`
- [ ] Test coverage: DB reset, `XmlChunker`, `DependencyResolver`, `ImportLogger`, rollback snapshot, all REST endpoints, all AJAX handlers
- [ ] GitHub Actions CI: run tests on every PR against PHP 8.1, 8.2, 8.3, 8.4 + WP latest

---

### 7.5 — Accessibility (WCAG 2.1 AA)

- [ ] Full keyboard navigation through wizard steps
- [ ] ARIA roles + labels on all interactive wizard elements
- [ ] Screen reader announcements on step transitions (use `aria-live` regions)
- [ ] Focus management: step heading receives focus on transition
- [ ] Colour contrast audit: all text ≥ 4.5:1
- [ ] `prefers-reduced-motion`: disable CSS slide transitions, use opacity fade only

---

## Quick Reference

| Version | Headline deliverable |
|---------|---------------------|
| `1.1.6` | WP 6.9 + PHP 8.4 compat, Rector auto-fix, critical runtime bugs |
| `1.2.0` | Session system, mutex lock, uninstall, PHPStan baseline |
| `1.3.0` | 8-step wizard, XMLReader chunker, activity log, cache flush, conditional demos |
| `1.4.0` | Plugin-owned image regen — counted, named, failure-tracked |
| `1.5.0` | Selective item import, dependency resolver, rollback, auto URL fix |
| `1.6.0` | White label, badges, builder-specific fixes, Elementor multi-ZIP |
| `2.0.0` | Background import, multisite, WP-CLI, import history + email |
| `2.x`   | FSE/block theme, demo export tool, ACF mapping, tests, a11y |

## todo.txt Carry-Over

| Item | Resolved in |
|------|-------------|
| Run AJAX only for provided files | Phase 4.1 (selective import) |
| Split XML import | Phase 2.3 (XMLReader chunker) |
| Initial pages for ShopBuilder | Phase 5.5 |
| Multi-site support | Phase 6.2 |
| Fix import error when no required plugins | Phase 1.2 (config validation) |
| Elementor data fix for multiZip | Phase 5.4 |
| Remove default image regeneration | Phase 3.1 |
| Add custom image regeneration support | Phase 3.2–3.3 |
| Rebuild XML import with split phases | Phase 2.3 |
| Add import log support | Phase 2.4 |
| Add badge support | Phase 5.2 |

---

*Revised: 2026-02-24 — consulted Sequential Thinking (architectural analysis), Context7/WordPress API docs (hook signatures, script strategy API, REST patterns), 21st.dev Magic (wizard UI patterns)*
