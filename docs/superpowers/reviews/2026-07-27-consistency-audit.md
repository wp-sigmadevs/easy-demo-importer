# Consistency Audit — easy-demo-importer (2.0.2 candidate)

**Date:** 2026-07-27
**Scope:** Full-plugin sweep across five dimensions — Naming, Patterns, Documentation, Interfaces, Structure.
**Method:** Four parallel read-only auditors (one per dimension group), findings verified against `file:line` evidence, then synthesized.
**Coverage:** `inc/**/*.php`, `src/js/backend/**`, and project docs. Excluded: `vendor/`, `lib/wordpress-importer/` (bundled 3rd-party), `node_modules/`, `assets/` (built), `tests/` (except the Structure pass).
**Nature:** Flag-only. No code was modified during the audit.

---

## Executive summary

- **19 findings**: 2 latent bugs, 3 release-adjacent documentation errors, 1 metadata mismatch, 13 consistency/style drifts.
- **Clean dimensions:** singleton accessor, PSR-4 namespace↔directory mapping, PHP file skeleton, REST registration, AJAX gating, timer cleanup, version consistency (2.0.2 everywhere), CHANGELOG↔readme alignment.
- **Release impact:** nothing blocks the 2.0.2 release, but four cheap items are worth fixing before tagging because they live in the shipping code.

---

## Tier 1 — Latent bugs (behavioral)

### B1 — `sendError()` message is string-or-object, but the React consumer renders object-only
- **Evidence:** `inc/App/Rest/RestEndpoints.php:98` documents `@param string $error`; `buildList()` passes an **object** `{ text, btnUrl, btnText }` (`:513-523`) while `pluginList()` passes a plain **string** (`:552`). The consumer `src/js/backend/components/ErrorMessage.jsx:22-24` reads `message.text` / `.btnText` / `.btnUrl` only (its JSDoc `:8` even says `@param {string}`).
- **Impact:** the object case renders correctly; the **string case (`pluginList`) yields `message.text === undefined` and renders a blank error** in the UI. Documented contract (string) is contradicted by both the primary call site and the consumer.
- **Fix:** standardize on the object shape `{ text, btnUrl?, btnText? }`; wrap `pluginList`'s string as `{ text: '…' }`; correct the PHP docblock and the `ErrorMessage.jsx` JSDoc.

### B2 — DB table identifier bound as `%1$s` to `prepare()` (known anti-pattern)
- **Evidence:** `inc/App/Ajax/Backend/Initialize.php:300,390` (`prepare('TRUNCATE TABLE %1$s', $table)`) and `inc/Common/Models/DBSearchReplace.php:170` (`prepare('DESCRIBE %1$s', $table)`). The majority pattern interpolates the pre-escaped identifier and binds only values: `ImportLogger.php:581`, `Snapshot.php:281/330/399`, and `DBSearchReplace.php:239` — the latter **contradicting its own line 170**.
- **Impact:** fragile/incorrect identifier quoting; the project's own rule is "never pass a table name as `%1$s` to `prepare()` — interpolate outside." Flagged by PluginCheck.
- **Fix:** interpolate the (prefix-derived or `esc_sql`'d) identifier into the SQL string; bind only values.

---

## Tier 2 — Wrong in the code about to ship as 2.0.2 (trivial fixes)

| # | Finding | Evidence |
|---|---------|----------|
| D1 | `getRuns()` `@param int $limit Maximum entries to scan.` is stale — the parameter is `$maxRuns` (a run/session count). Introduced by the run-windowing change; `recentRunRows()` right above it already documents the new param correctly. | `inc/Common/Functions/ImportLogger.php:378` |
| D2 | `RemoteUrl` is tagged `@since 2.0.1` (×8) but the file was added **after** the `2.0.1` tag, and SSRF hardening is a 2.0.2 item in all three changelogs. Should be `@since 2.0.2`. | `inc/Common/Utils/RemoteUrl.php:12,27,34,55,100,130,169,217` |
| D3 | `@since 1.2.0` — a version that never existed (line history goes 1.1.6 → 2.0.0). Introduced in the 2.0.0 era. Should be `@since 2.0.0`. | `src/js/backend/components/ErrorBoundary.jsx:7` |
| M1 | `package.json` declares `"license": "MIT"` and `"author": "SM Rafiz"`, disagreeing with the plugin header / `readme.txt` (`License: GPLv3`, `Author: Sigma Devs`). Reconcile before a WordPress.org submission. | `package.json` vs `easy-demo-importer.php`, `readme.txt` |

---

## Tier 3 — Consistency drift (no functional impact)

### Naming
- **`sessionId` (camel) vs `session_id` (snake)** for the same concept. REST params split — `download-progress` uses `sessionId` (`RestEndpoints.php:179`) while `failed-media`/`import/log` use `session_id` (`:286`,`:431`); `download-progress` is the lone camelCase REST param (snake_case is the established REST convention). PHP method params are also split (loggers/session/failed-media = snake; snapshot/download/state = camel); the AJAX layer is uniformly camel (`$_POST['sessionId']`). Callers currently match their endpoint — nothing broken.
- **`regen` vs `regenerate`** — the standalone Tools feature uses `regen` (`sd_edi_regen_thumbnails`, `regenAction`/`regenNonce`, `sd/edi/regen_*` hooks); the in-pipeline phase uses the full word (`sd_edi_regenerate_images`, `regenerateImages`).
- **Deactivate nonce breaks the `sd_edi_` prefix** — `DeactivateNotice.php:125-126` uses `deactivate_sd_edi_plugin` / `_deactivate_sd_edi_plugin_nonce` (prefix mid-string); everything else leads with `sd_edi_`.
- **Filter namespace: slash vs flat underscore** — `sd/edi/importer/config` & `importer/bundled_media_enabled` (slash) vs `sd/edi/importer_init` (underscore) for the same "importer" concept.
- **Two confusingly-near chunk-size filters** — `sd/edi/import_posts_chunk_size` (`ChunkedImport.php:175`, sets `chunkSize`) vs `sd/edi/import_chunk_posts` (`:265`, sets per-step `$step`).

### Patterns
- **Store bypass** — `RestorePointBanner.jsx:37` and `Success.jsx:65` fetch the `failed-media` GET directly via `Api.get(...)`; every other server GET goes through a zustand store action (`sharedDataStore.js`).
- **Two store error conventions** — `fetchImportList`/`fetchPluginList` only `console.error`; `fetchServerData`/`fetchPreflightData`/`fetchLogData` set structured `{ success:false, message }` (the richer majority, 3/5). All five swallow internally, so the component-level `try/catch` wrappers around them are effectively dead code.
- **Nonce verified two ways** — handlers use `Helpers::verifyAjaxCall()`; the abstract `ImporterAjax::handlePostSubmission` (`:233-241`) re-implements the same check inline.
- **Error-to-log surfacing uneven** — `prepareResponse()` logs every error to `ImportLogger`; raw `wp_send_json_error` exits (e.g. `InstallDemo.php:678`) don't all log, though adjacent success paths do.
- **Two AJAX response envelopes** — pipeline phases return a raw unwrapped object (`prepareResponse`→`wp_send_json($this->response)`); auxiliary handlers use the WP `{ success, data }` wrapper. Callers match each; maintenance trap only.
- **Uneven REST `args` schemas** — `import/log` declares full `type`+`default`+`sanitize`; `failed-media` only `sanitize`; `download-progress` `required`+`sanitize` but no `type`/`default`. Handlers defensively cast.

### Structure
- **Docblock role-labels inconsistent** — `Callbacks.php` = "Backend Class:" (siblings "Functions Class:"); `ImportLogger`/`SessionManager` = bare "Class:"; `ChunkedImport` = "Importer Class:" vs `BundledMedia`/`ImportState`/`ThumbnailRegenerator` = "Importer Utility:".
- **`Helpers.php:30`** is the only class in the codebase indented one tab instead of column 0.
- **`ImportLogger` & `SessionManager` live in `Functions/` but are `Utils/`-style** session-keyed stateful services (structurally identical to `FailedMedia`).
- **JS export outliers** — `ImportBar.jsx` and `ProgressMessage.jsx` are named-export-only vs 22 default-exporting components; `sharedDataStore.js` default-exports vs named-only sibling utils.
- **Test-tree drift** — `tests/Unit/Ajax/` drops the `Backend/` segment; `OutputGuard.php` untested while 8/11 Utils siblings are; `Callbacks.php` untested; `BackfillUrlsTest.php` / `downloadProgress.test.js` named by feature not class; `ImportRevSliderTest` vs `ImportLayerSliderTest` use different namespace styles.
- **REST vs AJAX split** — `/rollback` and `/discard-restore-point` are the only import mutations exposed as REST POST; all sibling mutations (`retry_media`, `cancel_session`, `mark_interrupted`) are AJAX.

---

## Recommended action plan

1. **Pre-tag (≈15 min, in-scope for 2.0.2):** D1, D2, D3 doc tags + M1 metadata. Pure doc/config, zero runtime risk.
2. **Fold into 2.0.2 or 2.0.3 (small, contained):** B1 (`sendError` → always object) and B2 (interpolate the 3 identifiers).
3. **Backlog (2.1.x consistency sweep):** the Tier-3 drifts — batch them; none are urgent.

---

## Clean — verified consistent

- **Singleton accessor:** `instance()` everywhere; no `getInstance` anywhere in scope.
- **PSR-4 namespace↔directory:** 59/59 namespaced files map correctly.
- **PHP file skeleton:** docblock → `declare(strict_types=1)` → namespace → `use` → ABSPATH guard → class docblock → Singleton + `register()`, uniform across 60 files.
- **REST registration:** all 9 routes share `permission_callback => [$this,'permission']` and go through `sendResponse`/`sendError`; no raw `wp_send_json` in any callback.
- **AJAX gating:** all 13 handlers gate via `Helpers::verifyAjaxCall()`.
- **Timer cleanup:** poll/drain intervals return a stop function and clear in `finally`/unmount — no leaks.
- **Logger convenience methods:** consistent `(message, session_id, demo_slug)` arg order.
- **Inline comments:** no misleading/stale comments found in high-churn areas.
- **Version consistency:** plugin header, Stable tag, package.json, readme.md badge, CHANGELOG, readme.txt all agree on 2.0.2.
- **CHANGELOG.md ↔ readme.txt:** 2.0.2 entries substantively aligned (CHANGELOG adds an Internal section, expected).
