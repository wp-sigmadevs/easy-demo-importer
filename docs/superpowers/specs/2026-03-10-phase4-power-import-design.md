# Phase 4 — Power Import (v1.5.0) Design Spec

**Goal:** Extend the import wizard with selective item picking, automatic dependency resolution, one-click rollback, automatic URL fix after import, and pre-import conflict detection.

**Version:** 1.5.0
**Branch target:** develop → master
**AI Phase:** v1.6.0 (separate spec, confirmed separately)

---

## Updated Wizard Flow

```
Welcome → Requirements★ → Plugins → Select Demo → Options → Select Items✦ → Confirm★ → Importing → Images → Complete★
```

- ★ = enhanced in Phase 4
- ✦ = new step (skipped/hidden if Content toggle is off in Options)

---

## 4.1 — Selective Import

**New wizard step:** `SelectItemsStep` inserted between Options and Confirm.

**Behaviour:**
- Only appears when the "Content" (Posts & Pages) toggle is ON in Options step.
- Shows post types as tabs across the top (tabbed layout — Option A chosen).
- Each tab loads its items lazily via REST on first click.
- Each tab has a search input that filters within that type.
- Each item is a checkbox; "Select All / Deselect All" per tab.
- Status bar: "X of Y pages selected" at the bottom of each tab panel.
- Default: all items pre-selected (import everything unless user deselects).

**XmlChunker changes:**
- Accepts `allowed_ids` (array of post IDs) + `allowed_types` (array of post type strings).
- Skips non-matching nodes during XML stream.
- If both are empty (no selective filter), behaviour is unchanged (import all).

**New REST endpoint:**
```
GET /wp-json/sd/edi/v1/demo-items?demo=<slug>&post_type=<type>
```
Returns: `{ items: [{ id, title, post_type, post_status, post_parent }] }`

**New PHP class:** `inc/App/Rest/DemoItems.php`

---

## 4.2 — Dependency Resolver

**Purpose:** Prevent broken imports when user deselects items that others depend on.

**Logic:**
- After user finalises selection in SelectItemsStep, client calls `POST /resolve-deps`.
- `DependencyResolver` scans the selected items' raw XML nodes for:
  - Attachment refs (`<wp:attachment_url>`, `<wp:post_parent>`)
  - Parent page IDs (`<wp:post_parent>`)
  - Term IDs (`<category domain="...">`)
- **Hard deps** (attachments, parent pages, terms): auto-included silently, not shown to user.
- **Soft deps** (nav menus, widgets, theme options): surfaced as optional advisory checkboxes on the ConfirmationStep under a "Also include related content?" section.

**New REST endpoint:**
```
POST /wp-json/sd/edi/v1/resolve-deps
Body: { demo: string, selected_ids: int[] }
Response: { hard: int[], soft: [{ id, label, type }] }
```

**New PHP classes:**
- `inc/Common/Utils/DependencyResolver.php`
- `inc/App/Rest/ResolveDeps.php`

---

## 4.3 — Import Rollback

**Purpose:** Let users undo an import within 24 hours.

**Pre-import snapshot (SnapshotManager):**
- Records: max `post_id` + max `term_id` at time of import (watermarks).
- Serialises affected `wp_options` keys (sidebars_widgets, nav_menu_locations, theme_mods_*, active_plugins — filtered by demo manifest).
- Stores snapshot in custom DB table `wp_sd_edi_snapshots` with columns: `id`, `demo_slug`, `snapshot_data` (JSON), `created_at`, `expires_at` (created_at + 24h).

**Rollback operation:**
- Deletes all posts with `ID > watermark_post_id` that were created after snapshot timestamp.
- Deletes all terms with `term_id > watermark_term_id`.
- Restores serialised options.
- Flushes object cache and rewrite rules.
- Logs to activity feed.

**UI exposure:**
- "Undo this import" button on CompleteStep (visible only if snapshot exists and < 24h old).
- "Undo" entry in System Status tab (REST server status endpoint) showing demo name, import time, expiry time.
- On rollback completion: success notice, button disappears, snapshot row deleted.

**New REST endpoint:**
```
POST /wp-json/sd/edi/v1/rollback/{snapshot_id}
```

**New PHP classes:**
- `inc/Common/Utils/SnapshotManager.php`
- `inc/App/Rest/Rollback.php`

**New DB table:** `wp_sd_edi_snapshots` — created on plugin activation via `dbDelta()`.

---

## 4.4 — Auto URL Fix

**Purpose:** Silently fix all demo domain references after import (no manual search-replace needed).

**Logic:**
1. After XML import completes (in ImportXmlChunk or a post-import hook), read `<wp:base_site_url>` from the WXR header.
2. Compare to `get_site_url()`.
3. If different: run DB search-replace across all tables for:
   - `http://demo-domain.com` → `https://site-url.com`
   - `https://demo-domain.com` → same
   - `http://www.demo-domain.com` → same
   - Trailing slash variants
4. If Elementor is active: call `Elementor\Plugin::$instance->files_manager->clear_cache()` (or equivalent WP-CLI-style static flush).
5. Log result to activity feed: "Replaced N occurrences of demo URL with site URL."
6. Filter opt-out: `apply_filters('sd/edi/auto_url_fix', true)` — returning false skips the step.

**New PHP class:** `inc/Common/Utils/UrlReplacer.php`

---

## 4.5 — Pre-Import Conflict Detection

Runs on the RequirementsStep (or as a blocking gate before Confirm).

### Hard Blocks (import prevented)
| Condition | Message |
|-----------|---------|
| Required plugin version below demo minimum | "Demo requires PluginX ≥ 2.0. You have 1.3. Please update." |
| PHP memory < 128MB | "PHP memory limit is 64MB. Minimum 128MB required." |

### Soft Warnings (advisory — user can proceed)
| Condition | Message |
|-----------|---------|
| Memory 128–256MB | "Memory is tight (192MB). Consider increasing to 256MB+" |
| max_execution_time < 300 | "Execution time limit is 30s. Import may time out on large demos." |
| Active cache plugin detected | "Cache plugin detected. Flush cache after import for best results." |
| DB reset enabled + existing posts > 10 | "⚠ Reset DB will permanently delete your 847 existing posts. Only use on staging." |
| Elementor version mismatch | "Demo was built with Elementor 3.18, you have 3.12. Some layouts may differ." |

**Where it lives:** RequirementsStep already runs environment checks. Extend it to also load demo manifest (which contains `min_plugin_versions`) and run conflict checks. Soft warnings shown inline on RequirementsStep with yellow badges; user can dismiss and continue.

---

## New PHP Classes / Files

| File | Purpose |
|------|---------|
| `inc/Common/Utils/DependencyResolver.php` | Scan XML nodes, classify hard/soft deps |
| `inc/Common/Utils/SnapshotManager.php` | Create/restore/expire import snapshots |
| `inc/Common/Utils/UrlReplacer.php` | DB search-replace demo URL → site URL |
| `inc/App/Rest/DemoItems.php` | GET /demo-items endpoint |
| `inc/App/Rest/ResolveDeps.php` | POST /resolve-deps endpoint |
| `inc/App/Rest/Rollback.php` | POST /rollback/{id} endpoint |

## New / Modified React Components

| File | Status |
|------|--------|
| `src/js/backend/wizard/steps/SelectItemsStep.jsx` | New |
| `src/js/backend/wizard/steps/CompleteStep.jsx` | Enhanced (Undo button) |
| `src/js/backend/wizard/steps/RequirementsStep.jsx` | Enhanced (conflict checks) |
| `src/js/backend/wizard/WizardLayout.jsx` | Add `select-items` step |
| `src/js/backend/App.jsx` | Add `/wizard/select-items` route |

---

## AI Phase — v1.6.0 (confirmed, separate spec)

All 5 features ship together in v1.6.0:

1. **AI Demo Finder** — user describes site in natural language → semantic match against pre-computed demo embeddings bundled in plugin JSON
2. **AI Content Personalizer** — post-import, replaces placeholder text with user's business details
3. **AI Post-Import Diagnostics** — scans for broken URLs, placeholder emails, missing plugin deps
4. **Natural Language Item Selection** — in SelectItemsStep, "import homepage, about, portfolio" → maps to IDs
5. **AI Conflict Advisor** — plain-English explanation + recommended action for each conflict detected in 4.5

**Architecture:** Cloudflare Worker proxy holds Gemini API key. Plugin calls proxy endpoint. Users never see or configure any key — works out of the box. Proxy rate-limits per WordPress site domain. Gemini free tier (1M tokens/day Flash + text-embedding-004).

---

## Open Questions (resolved)

| Question | Decision |
|----------|----------|
| Selective import placement | New dedicated wizard step (Option B) |
| Item picker layout | Tabbed by post type (Option A) |
| AI features | All 5, ship as v1.6.0 |
| AI API provider | Google Gemini via Cloudflare Worker proxy |
| User API key config | None — transparent, out of the box |
| Business model | Free for now; pro tier considered later |
| Rollback TTL | 24 hours |
