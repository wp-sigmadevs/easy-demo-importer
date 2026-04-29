# Multisite Support — Design Spec

- **Date:** 2026-04-29
- **Branch:** `multi-site`
- **Base:** `master` (independent of `alpha` / Phase 4)
- **Target version:** next release after current master (confirm version number from `easy-demo-importer.php` plugin header before tagging — header currently reads `1.1.6`, project memory references `1.4.0`)
- **Status:** Approved, ready for implementation plan

---

## 1. Goal

Add WordPress Multisite support to Easy Demo Importer with zero behavior change on single-site installs. Plugin must work cleanly in three distinct contexts:

1. Single-site (today's behavior, unchanged)
2. Multisite, per-subsite activated
3. Multisite, network-activated

A subsite admin must be able to import a demo into their own subsite without breaking other subsites. A Super Admin must get an additional Network Admin screen for cross-network configuration and read-only status.

---

## 2. Non-goals (deferred)

- Bulk multi-blog import (one demo → many subsites in one run)
- Per-subsite demo allowlisting (assigning specific demos to specific blog IDs)
- Network license tier (single license assumed to cover the entire network)
- Phase 4 Power Import multisite parity — Phase 4 work lives on `alpha`, independent track

---

## 3. Decisions

| Axis | Decision | Rationale |
|------|----------|-----------|
| Activation model | **Hybrid** — installable both per-subsite and network-active | Per-subsite matches import semantics; network adds an admin overview screen for agencies. No `Network: true` header. |
| Demo config scope | **Network override** — Network Admin can register a network-wide config that wins over the per-subsite theme filter | One source of truth when every subsite shares a theme; falls back to current theme filter behavior otherwise. |
| Permission model | **Tiered** — subsite admin can run imports if required plugins are present; if missing, block with an "ask Network Admin" CTA. Super Admin sees an inline "Install on network" button. | Honest UX. Maps WP capability constraints to clear UI states. Avoids silent install failures. |
| Per-blog data isolation | **Per-blog tables** created lazily on first import per subsite + on `wp_initialize_site` | Hard isolation between subsites. Matches `$wpdb->prefix` semantics. |
| Network Admin UI placement | **Network Admin → Themes → Easy Demo Importer** | Mirrors the per-subsite location under `themes.php`. Demo importer is theme-adjacent. |
| Bulk import (multi-blog) | Deferred to a later release | Highest blast radius. Ship after v1 stabilizes. |

---

## 4. Architecture

```
Multisite mode (is_multisite() === true)
├── Plugin header:    NO `Network: true` → installable BOTH per-site and network-active
├── Network-active:   Adds Network Admin screen (Network Themes → Easy Demo Importer)
│                     Subsite menu still works → admin imports own subsite
└── Per-site only:    Subsite menu works as today; no Network Admin screen

Single-site mode
└── Behaves exactly as today. Zero behavior change.

Resolution layer (new):  inc/Common/Utils/ContextResolver.php
  ├── isNetworkContext()      → in network admin?
  ├── currentBlogId()         → wraps get_current_blog_id()
  ├── canRunImport()          → cap check, scoped per blog
  ├── canInstallPlugins()     → is_super_admin() on multisite, manage_plugins on single
  ├── canUnfilteredUpload()   → is_super_admin() on multisite
  └── targetBlogId()          → reads ?blog= param when SA opens subsite link

Per-blog data isolation:
  ├── wp_{N}_sd_edi_taxonomy_import   → created lazily per subsite
  ├── wp_{N}_sd_edi_snapshots         → created lazily per subsite (Phase 4)
  ├── options sd_edi_*                → per-blog (already correct via get_option)
  └── uploads/easy-demo-importer/     → per-blog (already correct via wp_get_upload_dir)

Network-wide data:
  ├── site_option sd_edi_network_config   → demo config override (Q2-B)
  ├── site_option sd_edi_network_settings → license, default required plugins, snapshot TTL
  └── site_option sd_edi_network_status   → last-import per blog (read-only dashboard)
```

---

## 5. File additions and modifications

### New PHP files

```
inc/Common/Utils/ContextResolver.php
  Single source of truth for "where am I, what can I do, which blog am I targeting".
  Used by Backend/Ajax/Rest in place of scattered is_multisite() / current_user_can() checks.

inc/Common/Utils/NetworkInstaller.php
  - createTableForBlog(int $blogId)  → switch_to_blog → dbDelta → restore
  - createTablesForAllBlogs()        → loops get_sites(), chunked
  - dropTableForBlog(int $blogId)    → cleanup on subsite delete
  Hooked from Setup::activation, wp_initialize_site, wp_uninitialize_site.

inc/App/Network/Pages.php
  Network Admin screens. Registered only when WP_NETWORK_ADMIN.
  Adds submenu under Network → Themes.
  Tabs: Dashboard | Network Config | Settings.

inc/App/Network/Enqueue.php
  Loads existing React wizard bundle on Network Admin context with
  network-mode flag (sdEdiAdminParams.networkMode = true).

inc/App/Rest/NetworkStatus.php
  GET  /sd-edi/v1/network/status         → list blogs + last_import meta
  GET  /sd-edi/v1/network/config         → read network override config
  POST /sd-edi/v1/network/config         → write (super admin only)
  POST /sd-edi/v1/network/install-plugin → tiered install path
```

### New JS files

```
src/js/backend/network/NetworkApp.jsx   → router for Network Admin tabs
src/js/backend/network/Dashboard.jsx    → blogs table, last import, status badges
src/js/backend/network/ConfigEditor.jsx → JSON/form editor for sd_edi_network_config
```

### Modified PHP files

```
easy-demo-importer.php
  No `Network: true` header (intentional — must work both modes).

inc/Config/Setup.php
  activation():  if is_multisite() → NetworkInstaller::createTablesForAllBlogs() (chunked)
                 else              → existing single-site flow
  Adds: hookSiteCreate(), hookSiteDelete() registered globally (NOT in activation).

inc/Config/Classes.php
  Adds: [ 'register' => 'App\\Network', 'onRequest' => 'networkBackend' ]

inc/Common/Traits/Requester.php
  Adds: isNetworkBackend()

inc/Common/Functions/Functions.php
  getDemoConfig(): resolution order → network site_option > theme filter > [].
  getActiveBlogId(): for logging/snapshot ownership.

inc/Common/Functions/Helpers.php
  verifyUserRole(): delegates to ContextResolver.
  pluginActivationStatus(): checks both per-blog and network-wide active state.

inc/Common/Models/DBSearchReplace.php
  Already multisite-aware — no change.

inc/App/Backend/Pages.php
  Capability check via ContextResolver.
  Adds "subsite-N: site.example.com" sticky banner across all wizard steps when is_multisite().

inc/App/Backend/Enqueue.php
  localizeData() adds:
    isMultisite, currentBlogId, currentBlogUrl, isSuperAdmin,
    networkRequiredPluginsMissing[], canInstallPlugins.

inc/Common/Utils/SnapshotManager.php (Phase 4 — only if/when alpha lands)
  Stores blog_id at creation; rollback gates on ownership.
  Lazy-creates table on first snapshot for current blog.

uninstall.php
  if (defined('MULTISITE') && MULTISITE):
    foreach get_sites(): switch_to_blog → cleanup options/table → restore
  Always: delete site_options sd_edi_network_*
```

No deletions.

---

## 6. Activation, deactivation, site lifecycle

```
Plugin activation (register_activation_hook → Setup::activation)
───────────────────────────────────────────────────────────────
Single-site:                     unchanged: createTable() + createDemoDir()
Multisite + per-site activate:   createTable() for current blog only
Multisite + network activate:    loop get_sites([number=0]), createTable per blog,
                                 chunked at 50; remainder scheduled via
                                 wp_schedule_single_event to avoid timeout on
                                 networks with thousands of subsites.

Plugin deactivation (register_deactivation_hook)
────────────────────────────────────────────────
Unchanged: flush_rewrite_rules. No data touched. Reactivation must be safe.

Site lifecycle (registered globally in Hooks.php, NOT in activation)
───────────────────────────────────────────────────────────────────
add_action('wp_initialize_site',   NetworkInstaller::onSiteCreate, 10, 2)
  → if plugin is network-active OR per-site-active on the new blog:
      switch_to_blog(new_id) → createTable() → createDemoDir() → restore
  → else: skip (table created lazily on first import attempt)

add_action('wp_uninitialize_site', NetworkInstaller::onSiteDelete, 10, 1)
  → drop wp_{N}_sd_edi_taxonomy_import (and snapshots if Phase 4 lands)
  → delete options sd_edi_*
  → unschedule per-blog cron events

Lazy table creation (defensive)
───────────────────────────────
Functions::getImportTable() (and SnapshotManager::ensureTable() if Phase 4 lands)
check $wpdb->get_var("SHOW TABLES LIKE …") on first call per request.
If missing → createTable() on the fly. Covers:
  - Plugin activated before multisite was enabled
  - Subsite created while plugin was deactivated network-wide
  - Restored from backup with missing schema

Plugin upgrade (existing single-site → multisite)
─────────────────────────────────────────────────
No migration script. Main blog already has table.
On first activation under multisite, NetworkInstaller picks up missing
subsites via wp_initialize_site for new ones + lazy-creation for existing
ones at import time. No data loss path.
```

Stop conditions:
- Forbidden: dropping main-site table on subsite uninstall, touching `wp_users` / `wp_usermeta`, modifying `wp_blogs` / `wp_site` core tables.
- Review trigger: any `switch_to_blog` without paired `restore_current_blog` in the same call stack.

---

## 7. Capability gating + tiered plugin install

```
ContextResolver::canRunImport()
───────────────────────────────
single-site:  current_user_can('manage_options')
multisite:    current_user_can('manage_options') AND
              ( !is_network_admin() || is_super_admin() )

ContextResolver::canInstallPlugins()
────────────────────────────────────
single-site:  current_user_can('install_plugins')
multisite:    is_super_admin()   // WP core gate

ContextResolver::canUnfilteredUpload()
──────────────────────────────────────
single-site:  current_user_can('unfiltered_upload')
multisite:    is_super_admin()   // core gate
              else: route uploads through sanitized mime allowlist


Pre-flight inside RequirementsStep
──────────────────────────────────
Server returns:
  {
    plugins:           [ { slug, name, status, installed }, ... ],
    canInstallPlugins: bool,
    isMultisite:       bool,
    isSuperAdmin:      bool,
    blockingMissing:   [ slug, ... ]
  }


React decision tree (PluginsStep.jsx)
─────────────────────────────────────
if blockingMissing.length === 0:
    → normal flow

else if isMultisite && !canInstallPlugins:
    → BLOCK screen:
        "Network Admin must install: <list>"
        [Notify Network Admin]   // mailto, prefilled
        [Refresh once installed]

else if isMultisite && canInstallPlugins:
    → "Install on network" button
      → POST /sd-edi/v1/network/install-plugin { slug }
        Server: download → unzip → activate_plugin($file, '', true) // network=true
      → poll status, then proceed


WXR upload with missing unfiltered_upload
─────────────────────────────────────────
Importer reads $config['allowedMimes'] (new optional config key, defaults to WP defaults).
On WXR <wp:attachment>, if mime not in allow list → log + skip (do not fail whole import).
Surface skipped count on CompleteStep.
```

### SECURITY-CRITICAL — DB reset confirmation

The reset modal must display the exact, current target subsite identity, in full unambiguous language regardless of economy tier:

> "This will permanently erase ALL content on:
>   subsite-N — `https://subN.example.com`
> This does NOT affect other sites in your network. Other subsites and the network root will remain untouched. This action cannot be undone except via Snapshot rollback."

The confirmation input must echo the subsite domain back to the user (typed input must match the exact domain) before the destructive operation runs. This requirement is non-negotiable and applies in all economy / output tiers.

### Rollback ownership (only if Phase 4 / SnapshotManager lands)

- Snapshot row stores `blog_id` at creation.
- Subsite admin: rollback allowed only when `snapshot.blog_id === current_blog_id`.
- Super Admin: may rollback any subsite. Confirmation modal must echo the target subsite domain.

---

## 8. UI flow

```
SUBSITE ADMIN  (per-blog, themes.php → Easy Demo Importer)
─────────────────────────────────────────────────────────
Welcome → Requirements → Plugins → Select Demo → Options → Confirm → Importing → Complete
                          │
                          ├─ all installed   → continue
                          ├─ missing + SA    → "Install on network" CTA (in-line)
                          └─ missing + !SA   → BLOCKED screen (Section 7)

Sticky banner across all steps when is_multisite():
  "Importing into:  subsite-N  ·  subN.example.com"

Reset confirmation modal: domain-echo input (Section 7).


SUPER ADMIN  (Network Admin → Themes → Easy Demo Importer)
──────────────────────────────────────────────────────────
Tab: Dashboard
  table: [Blog ID | Domain | Last Import | Demo | Status | Action]
  Action col: "Open in subsite" → /wp-admin/network/subsite-N/themes.php?page=sd-edi…
  No bulk import in v1.

Tab: Network Config
  Toggle: "Use network-wide demo config (overrides per-subsite theme filter)"
  When ON: JSON editor pre-loaded with current site_option('sd_edi_network_config')
  Save → POST /sd-edi/v1/network/config
  Server-side validation: required keys themeName, themeSlug, demoData; reject otherwise.

Tab: Settings
  License (single source, network-wide)
  Default required plugins (added on top of per-demo plugins)
  Snapshot retention TTL (default 24h, only if Phase 4 lands)
```

---

## 9. Data flow

```
Config resolution
─────────────────
Functions::getDemoConfig()
  ├─ $net = get_site_option('sd_edi_network_config')
  ├─ if (!empty($net) && network_override_enabled): return $net
  ├─ $theme = apply_filters('sd/edi/importer/config', [])
  └─ return $theme   // [] if nothing


Import on subsite-N
───────────────────
React POST → REST /sd-edi/v1/import   (rest_url() per blog → subsite-N)
  ContextResolver::currentBlogId()    → N
  capability gate                     → canRunImport()
  pre-flight                          → required plugins installed?
  ensureTable for blog N              → lazy create
  XmlChunker / Importers run          → all writes target wp_{N}_*
  UrlReplacer                         → home_url() returns subN.example.com
  SnapshotManager::create() (P4)      → row stamped with blog_id=N
  Final response                      → status, log, undo token (P4)


Rollback (Phase 4 only)
───────────────────────
POST /sd-edi/v1/rollback/{snapshot_id}
  load snapshot → assert blog_id matches current OR is_super_admin()
  if SA on a different blog → switch_to_blog(snapshot.blog_id) → restore → restore_current_blog()
  destroy post/term IDs above watermark, restore options, mark snapshot consumed


Asset loading
─────────────
Existing backend.min.js bundle reused.
Network Admin context loads same bundle plus a new entry NetworkApp.jsx via 'page' query check.
No second build pipeline.
```

---

## 10. Error handling

```
switch_to_blog safety
─────────────────────
Wrap every cross-blog operation in try/finally → restore_current_blog().
Static counter assertion: $GLOBALS['_wp_switched_stack'] depth == 0 post-call.
Log warning if non-zero.

Lazy table creation race
────────────────────────
dbDelta is idempotent → safe under concurrency. No lock needed.

Network plugin install failure
──────────────────────────────
- download fails → return error code, don't half-activate
- unzip fails    → cleanup partial dir
- activate fails → leave installed, surface error, don't block other plugins
Per-plugin status returned in array; React handles partial success.

WXR mime rejection
──────────────────
Skipped attachments logged with reason, returned in import summary. Not fatal.

Network config write contention
───────────────────────────────
Last-write-wins on the single site_option. Surface Updated-At timestamp.

Subsite deleted mid-import
──────────────────────────
wp_uninitialize_site fires → if active import session token belongs to that blog,
mark session abandoned. Do not run rollback (subsite is gone).
```

---

## 11. Testing matrix

```
Environments:
  [1] Single-site (regression)
  [2] Multisite, plugin per-site activated, subsite admin
  [3] Multisite, plugin per-site activated, super admin
  [4] Multisite, plugin network-active, subsite admin
  [5] Multisite, plugin network-active, super admin

Per env:
  - Activate plugin: tables created on correct blog(s)
  - Run full import: data lands in wp_{N}_*, NOT in main blog
  - URL replacement: home_url for current blog used
  - DB reset: only current blog's content erased
  - Snapshot + rollback (if Phase 4 lands): same-blog and cross-blog (SA only)
  - Required plugins missing on subsite: subsite admin blocked + SA can install
  - Create new subsite while plugin network-active: table appears on wp_initialize_site
  - Delete subsite: tables + options removed via wp_uninitialize_site
  - Plugin uninstall on multisite: every blog cleaned, network options gone
  - Domain-echo confirmation: typing wrong domain blocks reset
  - Snapshot cron purgeExpired: runs per-blog without leaking
```

No automated test suite in repo today. QA verifies via the matrix above; results documented as a checklist.

---

## 12. Rollout

```
Branch:   multi-site (current, already checked out)
Base:     master  (independent of alpha / Phase 4)
Version:  next release after current master (confirm exact number from
          easy-demo-importer.php header before tagging)
Phase 4 (alpha): out of scope here; independent track; decision deferred.

Beta: 2 weeks on a real multisite (5+ subsites) before tagging.

readme.txt:
  "Tested up to" + "Multisite: yes (per-site or network activate)" tag.
  Section "Multisite usage" with screenshots.

Backwards compat:
  Zero behavior change on single-site installs.
  Existing demo configs (theme filter) keep working untouched.
  No DB migration needed.

Deferred to a later release:
  - Bulk multi-blog import
  - Per-subsite demo allowlisting
  - Network license tiering (assume single license for entire network in v1)
```

---

## 13. Open items to confirm before implementation

1. Exact target version number — header reads `1.1.6`, memory references `1.4.0`. Confirm and align both.
2. Beta multisite test environment availability (subsite count, themes used).
3. Whether to ship the Network Config JSON editor in v1 or replace it with a guided form (JSON editor accepted as v1 baseline; can be revisited).

---
