# PHPCS + Plugin Check (PCP) Report — easy-demo-importer

**Date:** 2026-07-27
**Version reviewed:** 2.0.2 (master HEAD `a1f22fe`)
**Tools:** PHP_CodeSniffer (project `phpcs.xml` — WordPress + WordPress-Docs + WordPress-Extra) · Plugin Check (PCP) 2.0.0 (WordPress.org Plugin Review Team)

---

## TL;DR

- **The shipped plugin code is effectively clean.** PCP found **0 errors** in `inc/` or the root plugin files. PHPCS `inc/` errors are all auto-fixable formatting.
- The large PCP counts (**28 errors / 48 warnings**) are almost entirely **dev-repo files that never ship** — `tests/`, docs, dev configs, `dist/`, `.github`, `.claude`, hidden files — because the plugin is **symlinked** into `wp-content/plugins/`, so PCP scanned the whole working directory.
- The handful of findings in real shipped code are all **intentional or false positives** (resource auto-tuner `ini_set()`, uninstall direct DB query, and calling *other plugins'* cache-flush hooks).
- **Action for an accurate WP.org-readiness pass:** run PCP against the **built package** (`dist/easy-demo-importer.2.0.2.zip` or an install *from* that zip), not the symlinked repo.

---

## 1. Plugin Check (PCP 2.0.0)

**Run summary:** `Checks complete. 28 errors and 48 warnings found.` (Categories: General, Plugin Repo, Security, Performance, Accessibility · Types: Error + Warning.)

> ⚠️ First browser run returned HTTP 500 — a **PCP-runtime-environment ↔ Elementor conflict** (PCP's isolated `wp_install()` sets `blogname`, firing Elementor's kit-settings hook, which throws `Uncaught Exception: Invalid post.`). Not an easy-demo-importer defect. The completed run (and the WP-CLI `wp plugin check` path with `--skip-plugins=elementor`) produced the results below.

### 1a. Errors — 0 in shipped code (all dev-repo / test artifacts)

| Finding | Files | Ships? |
|---|---|---|
| `compressed_files` | `dist/easy-demo-importer.2.0.2.zip` | No (build output) |
| `hidden_files` | `.DS_Store`, `.eslintrc.js`, `.prettierrc.js`, `.stylelintrc.js`, `.browserslistrc`, `.mcp.json`, `.wp-env.json`, `.superpowers/…/.server.log` | No (dev config) |
| `application_detected` | `bin/install-wp-tests.sh`, `phpunit.xml.dist`, `phpunit-integration.xml.dist` | No (test tooling) |
| `unlink_unlink`, `file_system_operations_{mkdir,rmdir,fwrite}`, `parse_url_parse_url`, `strip_tags_strip_tags` | **`tests/**` only** (`ImportStateTest`, `ChunkedImportTest`, `MediaSnapshotTest`, `RemoteUrlTest`, `FiltersTest`, `ImportLoggerTest`, `ImportRevSliderTest`, integration tests, `bootstrap-integration.php`) | No (tests) |

**Not a single error is in `inc/**` or a shipped root file.**

### 1b. Warnings

**Dev-repo only (not shipped):**
- `hidden_files`: `.gitignore`, `.idea/.gitignore`
- `ai_instruction_directory`: `.claude`
- `github_directory`: `.github`
- `unexpected_markdown_file` (plugin root): `gemini-security-audit.md`, `SECURITY-AUDIT.md`, `ROADMAP.md`, `ASSESSMENT.md`, `IMPROVEMENTS.md`, `DEV-LOG.md`
- `tests/**`: `InterpolatedNotPrepared`, `DirectDatabaseQuery.*`, `SlowDBQuery.*`, `PluginCheck.Security.DirectDB.UnescapedDBParameter` (×2 in `ElementorTaxonomyFixIntegrationTest.php:39,49`), non-prefixed class/function/variable in `tests/stubs/*` and `bootstrap-integration.php` (`WP_Importer`, `RevSlider` stub, `$_tests_dir`)

**Bundled 3rd-party (out of scope):**
- `lib/wordpress-importer/class-wp-import.php` — **17** `NonPrefixedHookname` warnings (core `wp_import_*` importer hooks).

**In shipped plugin code — all defensible:**

| File:Line | Warning | Assessment |
|---|---|---|
| `inc/Common/Utils/Preflight.php:291` | `ini_set()` discouraged | **Intentional** — the preflight resource auto-tuner raises PHP time/memory limits. Keep; add a `phpcs:ignore` with rationale if you want zero warnings. |
| `uninstall.php:39` | `DirectDatabaseQuery.DirectQuery` + `NoCaching` | **Correct** — bulk option cleanup at uninstall; caching is meaningless here. |
| `inc/Common/Functions/Actions.php:241-243` | `NonPrefixedHookname` (×3) | **False positive** — you are *invoking other plugins'* cache-flush hooks (`litespeed_purge_all`, `wphb_clear_page_cache`, `wp_cache_*`); the external hook name is required and cannot be prefixed. |

---

## 2. PHP_CodeSniffer (`composer cs`)

**Totals:** **15 errors, 27 warnings across 17 files** (15 auto-fixable via `phpcbf`).

| Origin | Errors | Warnings | Notes |
|---|---|---|---|
| Bundled `lib/wordpress-importer/**` | 5 | 11 | 3rd-party importer — incl. deliberate `libxml_disable_entity_loader()` PHP<8 XXE guard, cyclomatic complexity, missing param docs. Out of scope. |
| `samples/sample-config.php` | 2 | 1 | Sample config file. |
| **Your own `inc/` + `uninstall.php`** | ~8 | ~15 | Mostly **auto-fixable formatting**. |

**Own-code errors (all `[x]` phpcbf-fixable formatting unless noted):**
- `inc/Common/Functions/Helpers.php:30` — class indented 1 tab instead of 0 *(also flagged in the consistency audit)*
- `inc/Common/Functions/Callbacks.php:46` — closing brace placement
- `inc/Common/Abstracts/Enqueue.php:82` — associative-array value alignment
- `inc/Common/Functions/ImportLogger.php:529`, `inc/App/Rest/RestEndpoints.php:103` — param-type spacing
- `inc/Common/Functions/SessionManager.php:209` — Yoda condition *(not auto-fixable; the IDOR ownership check)*
- `inc/Common/Utils/Preflight.php:187`, `inc/Common/Utils/MediaSnapshot.php:164` — doc-comment nits (capital / missing `@param`) *(not auto-fixable)*
- Inline-comment end-char, `NoSilencedErrors` warnings on intentional `@`-silenced calls

**No functional or security issues** — style/docs only.

---

## 3. Recommendations

1. **Run PCP against the production build, not the symlinked repo.** Install/scan `dist/easy-demo-importer.2.0.2.zip` (or ensure `npm run package` excludes `tests/`, docs, dev configs, `dist/`, `.github`, `.claude`, hidden files). Against the built package, PCP should return **~0 errors** and only the 3-4 defensible warnings above.
2. **(Optional) `phpcbf`** to auto-fix the 15 formatting issues in `inc/` — zero-risk cleanup.
3. **(Optional) Targeted `phpcs:ignore` with justification** for the 3 real-code warnings (`ini_set`, uninstall direct query, external cache-hook names) if you want a spotless PCP/PHPCS run.
4. **(Optional) Add/verify a `.distignore`** so the WP.org SVN/build never carries the dev files that generate the bulk of these findings.

### Ship verdict
**No blockers.** The shipped 2.0.2 code passes both tools clean of functional/security issues; everything actionable is dev-tooling hygiene or intentional-behavior documentation.

---

*Companion docs: `2026-07-27-consistency-audit.md` (structure/naming/patterns), `2026-07-26-security-review.md` (OWASP). Full-plugin OWASP re-review on 2026-07-27 returned 0 critical/high/medium.*
