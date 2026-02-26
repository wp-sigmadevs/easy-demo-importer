# Easy Demo Importer — Improvements & Roadmap

> Comprehensive analysis of bugs, code quality issues, and a prioritized feature roadmap to beat OCDI, Merlin WP, and other demo importers.

---

## Table of Contents

- [Bug Fixes & Code Quality](#bug-fixes--code-quality)
- [Security Issues](#security-issues)
- [Performance Issues](#performance-issues)
- [WordPress Best Practices Not Followed](#wordpress-best-practices-not-followed)
- [Feature Roadmap — Highest to Lowest Priority](#feature-roadmap--highest-to-lowest-priority)
- [Competitor Comparison](#competitor-comparison)

---

## Image Regeneration — Dedicated Design

> This is a standalone design decision, not just a feature. Currently WordPress regenerates thumbnails during XML import as each attachment is created. This is slow, unpredictable, and gives the user zero visibility. The goal is to fully decouple image regeneration from the import pipeline and make it a first-class step with real numbers.

### How It Should Work

**During import (XML step):**
- Suppress ALL thumbnail generation using `add_filter('intermediate_image_sizes_advanced', '__return_empty_array')`
- Also suppress big image scaling: `add_filter('big_image_size_threshold', '__return_false')`
- Images are downloaded and saved as originals only — fast, no processing overhead
- Result: XML import step becomes significantly faster

**After import (new dedicated step — "Regenerate Images"):**
- Query all attachments imported during this session (tracked by import run ID)
- Show the user: `"47 images found — ready to regenerate"`
- Process one image at a time via AJAX loop
- For each image, show: `Regenerating image 14 of 47 — hero-banner.jpg`
- Show which sizes are being generated: `thumbnail, medium, large, custom-banner-1920x600`
- Track and display failures separately: `"3 images failed — view details"`

**User controls:**
- `Regenerate Now` — runs immediately as a wizard step (default)
- `Regenerate in Background` — queues via WP-Cron, user can close browser
- `Skip` — user can skip and regenerate later manually or via a "Re-run Image Regeneration" button on the status page

**Why this matters:**
No existing demo importer does this. Competitors either let WordPress handle it silently during import (slow, opaque) or skip it entirely and leave broken thumbnails. Being the first to show a clear, numbered, per-image regeneration step with failure tracking is a genuine differentiator and eliminates a huge category of post-import support questions ("why do my images look blurry / wrong size?").

---

---

## Already Shipped (Unreleased Commits in v1.1.5+)

| Commit | What was done | Roadmap impact |
|--------|--------------|----------------|
| `927f4cd` | PHP 8.4 XML importer fix — replaced `$this->maybe_unserialize()` with WP core's `maybe_unserialize()`, removed deprecated method | Phase 0.1 partially done |
| `4ae1181` | Post-processing condition refactored in XML importer | Phase 0.1 done |
| `640148e` | Nav menu item custom meta preserved during import | Shipped |
| `1616c31` | `skipImageRegeneration` toggle added to import setup UI + PHP filter hooks in `InstallDemo.php` | Phase 3.1 partially done — suppression exists, dedicated regen step still needed |

---

## Bug Fixes & Code Quality

### Critical

| # | File | Line | Issue |
|---|------|------|-------|
| 1 | `inc/App/Ajax/Backend/Initialize.php` | ~220 | `clearUploads()` does not check for symlinks — can cause infinite recursion |
| 2 | `inc/App/Ajax/Backend/Initialize.php` | ~221 | `scandir()` can return `false` but result is passed directly to `array_diff()` without a null check |
| 3 | `inc/Common/Abstracts/ImporterAjax.php` | ~158 | `array_key_first()` can return `null` if `demoData` config is empty — silently returned as slug |
| 4 | `inc/Config/Setup.php` | ~85 | `uninstall()` hook is completely empty — no cleanup of options or custom table on uninstall |

### Medium

| # | File | Line | Issue |
|---|------|------|-------|
| 5 | `inc/Common/Abstracts/ImporterAjax.php` | ~154 | Nonce failure returns empty string silently instead of hard-failing |
| 6 | `inc/App/Ajax/Backend/DownloadFiles.php` | ~117 | Fixed 60s timeout for file download — no retry logic for large demos |
| 7 | `inc/Common/Abstracts/ImporterAjax.php` | ~119 | `$this->config` is never validated for required keys — array access errors possible |
| 8 | `inc/App/Ajax/Backend/DownloadFiles.php` | ~91 | No path validation before `unzip_file()` — if download silently fails, unzip errors on missing file |

### Low

| # | File | Line | Issue |
|---|------|------|-------|
| 9 | `inc/App/Rest/RestEndpoints.php` | ~208 | REST uses `manage_options`, AJAX uses `import` — inconsistent capability checks |
| 10 | Multiple AJAX files | — | Import errors are lost after AJAX response — no persistent log |
| 11 | `inc/App/Ajax/Backend/InstallDemo.php` | ~142 | Entire XML import runs in one blocking AJAX call — noted in todo.txt |

---

## Security Issues

| Severity | File | Issue |
|----------|------|-------|
| Medium | `DownloadFiles.php` | Downloads any external URL without domain whitelist — admin-only but still risky |
| Low | `Initialize.php` | Raw SQL string concatenation in options delete — should use `$wpdb->prepare()` |
| Low | `DownloadFiles.php` | ZIP extracted without checking for path traversal in filenames |
| Low | All AJAX handlers | No rate limiting on import — could be abused on shared hosting |

---

## Performance Issues

| # | File | Issue |
|---|------|-------|
| 1 | `Initialize.php:161` | `SHOW TABLE STATUS` fetches ALL tables — no pagination/filtering |
| 2 | `InstallDemo.php:142` | XML import is single-threaded and blocking — timeouts on large demos |
| 3 | `RestEndpoints.php` | Server status endpoint fetches all plugin data on every page load — no transient caching |
| 4 | `Initialize.php:220` | Recursive PHP function for clearing uploads — inefficient on deep directories |
| 5 | All AJAX handlers | Entire import pipeline is synchronous — no background/async processing |

---

## WordPress Best Practices Not Followed

| # | Issue |
|---|-------|
| 1 | No `uninstall.php` — plugin data and custom table (`wp_sd_edi_taxonomy_import`) left behind on uninstall |
| 2 | Not using the WordPress Settings API — options stored manually without proper UI |
| 3 | Multisite not supported — site-specific configs not isolated |
| 4 | No capability registration — uses built-in `import` capability without registering custom ones |
| 5 | No transient cleanup in `uninstall()` |
| 6 | Custom table has no schema documentation |
| 7 | REST API endpoints have no inline schema documentation (`register_rest_field()`) |
| 8 | No `_deprecated_hook()` calls for renamed hooks — no backward compatibility path |
| 9 | No PHPUnit test suite for critical functionality |
| 10 | Only "System Status" link in plugin row meta — no "Settings" link |

---

## Feature Roadmap — Highest to Lowest Priority

> Priority ordered by user impact + competitive advantage vs OCDI / Merlin WP.

---

### Tier 1 — Must Have (Immediate competitive wins)

#### 1. Wizard-Style Onboarding Flow (Merlin WP killer)
- **Why:** Merlin WP's biggest advantage is a guided setup wizard embedded in the theme. Your plugin currently uses a modal-based approach. A full-page, step-by-step wizard (requirements check → plugin install → demo pick → import options → done) feels far more professional and less intimidating for end users.
- **Competitors:** Merlin WP does this well. OCDI doesn't have it.
- **Impact:** Huge UX improvement, especially for non-technical users.

#### 2. Import Log / Activity Feed
- **Why:** When something goes wrong, users have no idea what happened. Every competitor lacks a proper import log. Being the first to offer a real-time scrollable activity log during import would be a major differentiator.
- **What:** Timestamped log stored in DB, downloadable as `.txt`, displayed in UI during and after import.
- **Noted in:** `todo.txt`

#### 3. Split / Chunked XML Import
- **Why:** Single biggest cause of import failures. Large XML files time out. Splitting into batches (e.g., 20 posts per AJAX call) with a real progress bar would eliminate the #1 support complaint.
- **What:** Process XML in chunks, show `X of Y posts imported` progress.
- **Noted in:** `todo.txt`

#### 4. Pre-Import Conflict Detection & Warnings
- **Why:** Users install the plugin, hit import, and only discover conflicts mid-import. Detect issues before starting:
  - Active plugins that may conflict
  - Insufficient server resources (already partially done in System Status)
  - Existing content that will be overwritten
  - Elementor/page builder version mismatches
- **Impact:** Reduces failed imports and support tickets dramatically.

#### 5. Dedicated Image Regeneration Step (Plugin-Owned, Not WordPress)
- **Why:** WordPress silently regenerates thumbnails during XML import — slow, opaque, no count, no failures visible. See the full design in the [Image Regeneration section](#image-regeneration--dedicated-design) above.
- **What:** Suppress all regeneration during import. After import, run a dedicated step that shows `Regenerating image 14 of 47 — hero-banner.jpg` with failure tracking and skip/background options.
- **Competitive edge:** No competitor does this. Eliminates the entire "why are my images wrong size" support category.
- **Noted in:** `todo.txt`

#### 6. Proper uninstall.php + Data Cleanup
- **Why:** Currently a WordPress compliance failure. Required for submission to premium marketplaces (ThemeForest, etc.).
- **What:** Clean up all `sd_edi_*` options, transients, and the `wp_sd_edi_taxonomy_import` custom table on uninstall.

---

### Tier 2 — High Value (Clear differentiators)

#### 7. Selective / Partial Import — Including Per-Item Picking
- **Why:** Biggest gap vs OCDI. Go further than OCDI's manual file upload — let users pick at any level:
  - **Level 1 — By type:** content only, customizer only, widgets only
  - **Level 2 — By post type:** only Pages, only Products, only Portfolio CPT
  - **Level 3 — By individual item:** tick exactly "Home 2" and "About" and nothing else
- **How (Level 3):** Parse phase reads WXR XML without touching DB → selection UI shows all items grouped by post type with search → dependency scanner auto-includes referenced images/templates/parent pages → modified import skips all non-selected post IDs
- **Dependency handling:** Hard deps (images, terms, parent pages) auto-included silently. Soft deps (menus, widgets) shown as optional warnings.
- **Image regen benefit:** Only regenerates the images tied to selected content — not the whole demo library.
- **Impact:** Eliminates the "I only need 2 pages but got 200 posts" complaint entirely.

#### 8. Import Rollback / Undo
- **Why:** No competitor does this. Before import starts, snapshot the current state (serialized options, post IDs, menu IDs). User can restore it with one click.
- **What:** Store a "pre-import backup" in the DB. Show "Undo Last Import" button for 24 hours after completion.
- **Competitive edge:** This alone could make users choose your plugin over everything else.

#### 9. Demo Content Statistics Preview (Dry Run)
- **Why:** Before committing to an import, show users exactly what's inside the ZIP without writing anything to the DB.
- **What:** Parse the XML and return: `47 pages · 12 posts · 8 products · 134 images · 3 menus`. Show this on the confirmation screen.
- **Impact:** Builds user confidence. Sets expectations. Reduces abandoned imports.

#### 10. Smart Auto URL Fix
- **Why:** Elementor and other page builders store demo site URLs inside serialized data. Currently users must know to configure URL replacement manually. Make it automatic.
- **What:** After import, detect all URLs in post meta that don't match the current site URL and replace them silently. Show a count: `"Fixed 342 URL references"` in the import log.
- **OCDI gap:** OCDI doesn't do this at all. Elementor URL issues are their #1 complaint.

#### 11. Post-Import Cache Flush
- **Why:** After import, cached pages show old content. Users think the import failed.
- **What:** Automatically detect and flush popular caching plugins after import: WP Super Cache, W3 Total Cache, LiteSpeed Cache, WP Rocket, Elementor CSS cache, WooCommerce transients.
- **What to show:** `"Cleared cache: LiteSpeed Cache ✓, Elementor CSS ✓"` in the completion report.

#### 12. Real-Time Progress with Step Details
- **Why:** The current progress bar doesn't show what's happening inside each step. Users see it stuck and think it's broken.
- **What:** Show `Importing post X of Y`, `Downloading image X of Y`, `Installing plugin X of Y` inside each step in real time.

#### 13. Demo Preview Button
- **Why:** Users want to see the demo before committing to the import.
- **What:** Add `liveUrl` key to demo config. Show a "Preview Demo" button on each demo card that opens the live URL in a new tab.

#### 14. Multisite Support
- **Why:** Agency clients use multisite. No major competitor handles this properly. Being the first to support multisite demo import owns a niche.
- **Noted in:** `todo.txt`

---

### Tier 3 — Strong Additions (Polish & power features)

#### 15. White Label / Rebrandable UI
- **Why:** Theme authors selling on ThemeForest want the wizard to say their brand name, show their logo, use their colors — not "Easy Demo Importer".
- **What:** Filterable config keys: `pluginName`, `logo`, `primaryColor`, `supportUrl`. Theme authors set these once in their `functions.php`.
- **Competitive edge:** Merlin WP offers this. You don't yet. It's a major selling point for premium theme developers.

#### 16. Conditional Demo Visibility
- **Why:** Showing WooCommerce demos to users who don't have WooCommerce installed is confusing. Showing Elementor demos when WPML is missing creates failed imports.
- **What:** Theme authors can attach conditions to each demo: `requires: ['woocommerce', 'elementor']`. Demos that don't meet conditions are greyed out with a tooltip explaining what's missing.
- **Impact:** Reduces failed imports caused by missing plugins.

#### 17. Background Import via WP-Cron / Action Scheduler
- **Why:** Removes the browser dependency. User starts import, closes the tab, comes back to a finished site.
- **What:** Queue import steps as WP-Cron events or use Action Scheduler (bundled with WooCommerce). Show live progress via polling on return.

#### 18. Demo Badges & Tags
- **Why:** `New`, `Popular`, `WooCommerce`, `Elementor`, `Dark` badges on demo cards help users scan faster. Essential for themes with 15+ demos.
- **Noted in:** `todo.txt`

#### 19. Page Builder Auto-Detection & Specific Fixes
- **Why:** Different page builders need different post-import fixes. Currently only Elementor is handled.
- **What:** Detect the page builder used in the demo (Elementor, Bricks, Oxygen, Gutenberg, Divi) and apply builder-specific post-processing automatically.
- **Elementor:** Flush CSS cache, remap taxonomy IDs
- **Bricks:** Rebuild element cache
- **Gutenberg/FSE:** Re-save global styles and template parts

#### 20. Elementor Data Fix for Multi-ZIP Demos
- **Why:** Multi-ZIP themes with Elementor have data mapping issues. Already on your radar.
- **Noted in:** `todo.txt`

#### 21. Initial Pages for ShopBuilder
- **Noted in:** `todo.txt`

---

### Tier 4 — Forward-Looking (Long-term advantage)

#### 22. Full Site Editing (FSE) / theme.json Support
- **Why:** Block themes using FSE don't use the customizer at all — they use `theme.json`, global styles, and template parts. The entire import model needs to evolve.
- **What:** Export/import `theme.json` overrides, global style variations, and block template parts.
- **Competitive edge:** No demo importer supports this yet. First mover advantage as FSE adoption grows rapidly.

#### 23. Import Progress Email Notification
- **Why:** When background import completes (or fails), the admin should receive an email summary.
- **What:** Send email on completion/failure with a link to the import log. Opt-in during wizard setup.

#### 24. WP-CLI Support
- **Why:** Agencies and developers want to automate demo imports in CI/CD pipelines or staging setups.
- **What:** `wp edi import --demo=my-demo --reset --skip-media`
- **Impact:** Developers will love and recommend your plugin.

#### 25. Multi-Language Demo Support
- **Why:** Many themes need language-specific demos (English, Spanish, Arabic/RTL, etc.).
- **What:** Theme authors define `locale` per demo. Plugin shows only demos matching the current WordPress locale, with an option to show all.

#### 26. Demo Content Export (for Theme Authors)
- **Why:** The biggest friction for theme authors is creating the demo ZIP. Let them export the current site state as a demo package directly from the plugin.
- **What:** Export wizard: choose post types, include/exclude media, generate `content.xml`, `customizer.dat`, `widget.wie` automatically.
- **Competitive edge:** No other demo importer helps the theme author side. This doubles your audience.

#### 27. Import History & Re-run
- **Why:** Users want to see past imports and re-run them without reconfiguring.
- **What:** Store import history in DB (demo name, date, status, log). Allow one-click re-import of a previous session.

#### 28. ACF / Meta Box Field Mapping
- **Why:** Imported custom fields often break because field keys don't match between demo and live site.
- **What:** Detect ACF/Meta Box field keys in imported content and auto-map them to existing fields. Show unmapped fields as warnings.

#### 29. PHPUnit Test Suite
- **Why:** Required for long-term stability and contributor confidence. Cover DB reset, AJAX handlers, and the import pipeline.

#### 30. Accessibility (WCAG 2.1 AA)
- **Why:** The React UI should be fully keyboard-navigable with screen reader support. Required for enterprise/government theme clients.

---

## Competitor Comparison

| Feature | Easy Demo Importer | OCDI | Merlin WP |
|---------|-------------------|------|-----------|
| One-click import | ✅ | ✅ | ✅ |
| React-powered UI | ✅ | ❌ | ❌ |
| Category tabs + search | ✅ | ✅ | ❌ |
| System status checker | ✅ | ❌ | ✅ (partial) |
| Fluent Forms import | ✅ | ❌ | ❌ |
| Rev Slider import | ✅ | ❌ | ❌ |
| SVG sanitization | ✅ | ❌ | ❌ |
| RTL support | ✅ | ✅ | ❌ |
| URL/email replacement | ✅ | ❌ | ❌ |
| Elementor taxonomy fix | ✅ | ❌ | ❌ |
| Wizard-style onboarding | ❌ | ❌ | ✅ |
| Import log / activity feed | ❌ | ❌ | ❌ |
| Chunked XML import | ❌ | ❌ | ❌ |
| Dedicated image regen step | ❌ | ❌ | ❌ |
| Image count + failure tracking | ❌ | ❌ | ❌ |
| Selective / partial import | ❌ | ✅ (manual) | ❌ |
| Import rollback / undo | ❌ | ❌ | ❌ |
| Demo content stats preview | ❌ | ❌ | ❌ |
| Auto URL fix (silent) | ❌ | ❌ | ❌ |
| Post-import cache flush | ❌ | ❌ | ❌ |
| White label / rebrandable UI | ❌ | ❌ | ✅ |
| Conditional demo visibility | ❌ | ❌ | ❌ |
| Background import | ❌ | ❌ | ❌ |
| Multisite support | ❌ | ❌ | ❌ |
| FSE / theme.json import | ❌ | ❌ | ❌ |
| Demo content export (author tool) | ❌ | ❌ | ❌ |
| WP-CLI support | ❌ | ❌ | ❌ |
| Pre-import conflict detection | ❌ (partial) | ❌ | ❌ |
| Import history + re-run | ❌ | ❌ | ❌ |

> **You already lead** on integrations: Fluent Forms, Rev Slider, SVG sanitization, Elementor taxonomy fix, URL/email replacement, React UI.
>
> **Tier 1 closes the gap:** wizard onboarding + import log + chunked XML + dedicated image regen.
>
> **Tier 2 pulls ahead:** auto URL fix, cache flush, dry run stats, rollback, conditional demos — none of which competitors have.
>
> **Tier 4 owns the future:** FSE support and the demo content export tool (helps theme authors, not just users) are both uncontested territory.

---

*Last updated: 2026-02-24*
