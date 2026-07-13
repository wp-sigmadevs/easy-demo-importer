# Session work — 2026-07-12

Detailed record of the work done on 2026-07-12: performance hardening of the
resumable importer, a new integration-test CI pipeline, and four user-facing
features (Regenerate Thumbnails tool, pre-import readiness gate, retry-failed
media, and snapshot rollback). Ends with the full list of what is still pending.

---

## 1. Branch topology

All of today's work is stacked on one line of feature branches (no work on
`master`). Everything below is **pushed** and **CI-green** unless noted.

```
master (bf2a840)
  └─ wxr-chunking (b41d802)          v1.2.0 candidate + Wave-1 review fixes
       └─ wxr-state-split (d6601fa)  perf work + integration-test CI job
            ├─ preflight-check (6c68c57)      preflight gate (merged onward)
            └─ regenerate-thumbnails         regen tool + preflight[merged] + retry + rollback + WP-CLI
                 └─ manual-import (f367363)   ← TIP: full manual import + review fixes + rollback overhaul (§13)
```

- **`manual-import`** is the accumulation branch and carries the entire stack.
- `preflight-check` was merged into `regenerate-thumbnails` (`45ede65`); the only
  merge conflict was the generated `languages/*.pot`, resolved by regenerating it.

**Test suite:** 98 unit tests / 199 assertions (was 30 at the start of the WXR
work). CI runs the unit matrix (PHP 7.4–8.4) and a WordPress integration suite
(real WP + MariaDB, PHP 7.4 + 8.3) on every push and PR. Everything below is
**pushed** and **CI-green** unless noted.

---

## 2. Performance hardening (branch `wxr-state-split`)

The resumable chunked importer already fixed the 524/503 gateway timeouts. Today
addressed its remaining structural costs.

### 2.1 State split — stop rewriting the parsed-WXR blob every batch (`43d0500`)
`ImportState` / `ChunkedImport`. Previously every batch re-serialized and
rewrote the **entire** parsed-post array (multi-MB) alongside the small cursor.
Split into:
- **immutable** parse output (`id`, `version`, `authors`, `posts`, `base_url`) —
  written once at `prepare()` to a sibling `.imm` file;
- **mutable** cursor + ID/orphan/remap maps — the only thing rewritten per batch.

Eliminated the O(posts²) write amplification. Integration test (`4d067d3`)
asserts the parse output is written once and a full resume reconstructs from both
files.

### 2.2 Read split — load posts per-chunk, not the whole array (`d6601fa`)
The state split fixed writes; reads still unserialized the whole `posts` array
every batch just to slice a few items. Now `prepare()` writes posts into fixed
-size **chunk files** (`.posts.0`, `.posts.1`, … 100 posts/chunk, filter
`sd/edi/import_posts_chunk_size`) and frees the array; `processBatch()` loads only
the chunk it is currently processing. Each chunk file is read ~once across the
whole import (a boundary chunk re-read on resume, kept idempotent by
`post_exists()`). `finalize()` loads **no** posts at all. Bonus: per-batch memory
drops from all-posts to one-chunk. Integration test updated to assert the chunk
files are written once and a full resume still imports every post.

### 2.3 recountTerms narrowing (`7f1cff8`)
`ChunkedImport::recountTerms()` previously recounted **every term in every
taxonomy** site-wide at finalize (heavy on large existing catalogues). Now it
gathers only the terms attached to **this run's** imported posts (+ ancestors of
hierarchical terms, so WooCommerce `product_cat` roll-up counts stay correct) via
one query and recounts just those. Integration test proves an unrelated term with
a deliberately wrong count is left untouched.

### 2.4 Mutex-wait softened (`e8aa195`)
`respondWaiting` `retryAfter` 5s → 2s, to reduce the spurious stall when the
`retryAfter:0` batch loop races the previous request's shutdown-handler lock
release. **This is a partial fix** — the full token-mutex fix is deferred (see
Pending §8).

---

## 3. Integration-test CI (`95712fa`, `a3ece34`, `e5faa27`)

Added `.github/workflows/integration-tests.yml` and the canonical
`bin/install-wp-tests.sh`. The integration suite (`tests/Integration`,
`WP_UnitTestCase`) now runs against a real WordPress + **MariaDB 10.6** service on
every push/PR — so the resumable import cycle is validated end-to-end, not just
unit-mocked.

- Matrix settled at **PHP 7.4 + 8.3** (WordPress's fully-supported range). 8.4 was
  tried but reverted for integration: WordPress only "beta-supports" 8.4 and its
  core test-lib can emit deprecations. The **unit** matrix still covers 7.4–8.4.

---

## 4. Feature: Regenerate Thumbnails tool (`821e0f6`, test `b4ea63f`)

A standalone **Tools → Regenerate Thumbnails** page. Reuses the existing
`ThumbnailRegenerator` engine (resumable, time-boxed, "only missing sizes", skips
icons/non-images) and drives it over the **whole** media library in self-resuming
15s batches with a progress bar. A **"Force-regenerate every size"** toggle passes
`regenerate($onlyMissing = false)` for when a registered size's dimensions
actually changed.

- New: `inc/App/Tools/RegenerateThumbnails.php` (Tools page + AJAX driver, cursor
  round-trips via the request). Registered as always-on `App\Tools` in
  `Classes.php`.
- Engine change is backward-compatible (default = only-missing, unchanged for the
  import phase). Unit test covers force vs only-missing filter registration.

---

## 5. Feature: Pre-import readiness gate (`baa5b0c`, `6c68c57`)

Before "Start Import," the wizard fetches `/sd/edi/v1/preflight` and shows a
green/amber/red **readiness checklist**; **Start is disabled if any blocking check
fails.**

- **Checks:** PHP version (block), memory limit (warn), ZipArchive (block),
  SimpleXML (block), GD/Imagick (warn), uploads writable (block), required plugins
  active (warn — installed during import anyway), Cloudflare/proxy detection
  (info, with chunking/bundled-media guidance).
- Decision logic is **pure static methods** (`toBytes`, `phpVersionCheck`,
  `memoryCheck`, …) → 8 unit tests, no environment needed; `report()` gathers real
  values; the REST endpoint is a thin wrapper.
- New: `inc/Common/Utils/Preflight.php`, REST `/preflight`,
  `src/js/backend/components/PreflightPanel.jsx`, gated in `Setup.jsx`.

---

## 6. Feature: Retry failed media (`766040f`, `6a0ed6f`)

A fully-failed image **download** never creates an attachment (the importer skips
it), so retry re-runs attachment import for the specific WXR entries that failed.

- **Capture:** when `process_attachment` errors, the importer records
  `{url, post-data}` (`$failed_attachments`). Persisted across chunked batches
  (`MUTABLE_PROPS`) and saved per-session at finalize (chunked + single-shot) via
  new `inc/Common/Utils/FailedMedia.php` (5 unit tests). Logged.
- **Retry:** result screen fetches the failed count (`/failed-media`); if > 0 it
  shows **"Retry failed images."** The button drives a resumable, time-boxed
  admin-ajax loop (`InstallDemo::retryMedia`) that re-attempts each via a fresh
  importer and **rewrites content URLs** for recoveries, then reports
  "X recovered, Y still failed."
- Uses a `retrySession` param (not the pipeline `sessionId`) so the post-import
  request bypasses the live-session guard.
- **Bonus fix:** found + fixed a pre-existing runtime bug — Wave-1's `logSessionId`
  removal left two dangling `setLogSessionId('')` calls (undefined →
  `ReferenceError`); repointed to a new persisted `resultSessionId`.

**MVP limitation:** a completed retry pass clears the stored list, so images that
fail *again* are not re-retryable without another import.

---

## 7. Feature: Snapshot rollback (`13bec51`, `74272c9`)

Opt-in "restore point" — the cleanest full revert for fresh setup sites, because
it reverts **content AND settings/customizer/widgets** by restoring the actual
rows.

- **Engine:** `inc/Common/Utils/Snapshot.php`. Shadow-table approach —
  `CREATE TABLE … LIKE` + `INSERT … SELECT` clone the import's blast-radius tables
  (`posts`, `postmeta`, `terms`, `term_taxonomy`, `term_relationships`, `termmeta`,
  `comments`, `commentmeta`, `options`) into `{prefix}sd_edi_snap_*`. Both run
  entirely inside MySQL — **no PHP row loop, no per-row timeout, no chunking
  needed** for typical sizes. Restore = `TRUNCATE` + `INSERT … SELECT` back +
  `wp_cache_flush()` + drop shadows. Never touches `users`. Pure name-mapping is
  unit-tested (3 tests).
- **Opt-in toggle** on the configure step, with a full details paragraph.
  Threaded end-to-end as a `snapshot` flag (`ImporterAjax` → `Api.js` → response
  round-trip); the snapshot is created once at the start of `response()`.
- **Rollback** via REST `POST /sd/edi/v1/rollback` (`manage_options`) — decoupled
  from the import flow. Reachable from **two** places, both with a danger-confirm →
  restore → reload:
  1. the result screen ("Roll Back" button, when a restore point exists);
  2. a **persistent `RestorePointBanner`** at the top of the main importer page
     (shown whenever `Snapshot::exists()`), so rollback survives closing the
     result screen.

**Caveats (all surfaced in the UI):**
- Restore reverts to the pre-import moment — anything created after the import is
  lost (fine for fresh setup sites, the target case; risky on live sites).
- A single `INSERT … SELECT` on a very large table could be slow (no chunking yet
  — a v1.3 candidate).
- **Not CI-testable:** `Snapshot` uses `TRUNCATE`/`CREATE`/`DROP TABLE`, and that
  DDL forces an implicit commit that would break `WP_UnitTestCase`'s transactional
  isolation. Production is unaffected; the create→restore cycle needs **manual
  QA**.

---

## 8. Feature: WP-CLI (`792ee65`)

`wp edi` commands, headless (no browser/AJAX/gateway limit), reusing the decoupled
utilities: **`wp edi demos`** (list), **`wp edi regenerate [--force]`**,
**`wp edi rollback [--yes]`**. New `inc/App/Cli/Commands.php`, registered always-on
as `App\Cli`. `wp edi import`/`reset` deferred — the import phases are AJAX-coupled
(`$_POST` + `wp_send_json`) and need decoupling first.

---

## 9. Feature: FULL manual import — OCDI-style (`1ee56b1`, `c945444`, `dfcfb14`, `d136a71`)

A **"Manual Import"** button on the importer page → upload your own files → a full
functional clone, **without** the theme providing demo config:

| Upload | Import |
|---|---|
| Content WXR `.xml` (required, **chunked** — any size) | posts/pages/products/**media**/terms/menus |
| Customizer `.dat` (optional) | theme mods (safe `allowed_classes=false`) |
| Widgets `.wie`/`.json` (optional) | widgets |
| Settings `.json` (optional, flat `{option:value}`) | theme options (**blocklisted**) |

**Key design:** `inc/App/Manual/ManualImport.php` validates + stages the uploads
into a `manual-{key}/` working dir with the exact filenames the phases already read
(`content.xml`/`customizer.dat`/`widget.wie`/`settings.json`); `inc/Common/Utils/
ManualContext.php` supplies a minimal **config stub**, and `ImporterAjax`'s manual
branch uses it instead of `getDemoConfig()`. So **every existing phase runs
unchanged** and phases the upload didn't provide simply no-op. Inherits the whole
resumable pipeline: image regeneration, retry-failed-media, snapshot rollback
(default-on in the modal). Large files upload in 4 MB chunks assembled server-side
(`.part` file + WXR sniff on the last chunk). Plan: `docs/superpowers/plans/
2026-07-12-manual-import.md` (status: COMPLETE).

---

## 10. Multi-lens review + fixes (run twice; `7a4e1a3`, `f14446a`)

Three parallel specialist agents (security / performance / correctness) over the
full branch diff. All MUST + actionable SHOULD findings fixed:

- **MUST** — uploaded files were in a web-reachable dir → new
  `Setup::protectDirectory()` drops deny-all `index.php`/`.htaccess`/`web.config`;
  `retryMedia` back-filled URLs per slice (repeated full-table scans) → now
  accumulates remaps + back-fills once on the final slice; manual upload could
  steal a running import's lock → `SessionManager::isLocked()` gate;
  `stageOptional` silently dropped a failed optional file → now hard-fails.
- **SHOULD** — `/preflight` cached 60 s; `Snapshot::create()` skips on huge sites
  (`sd/edi/snapshot_max_rows`); daily cron (`sd_edi_manual_cleanup`) sweeps stale
  `.part`/`manual-*` artifacts; `RestorePointBanner`/`ImportLogPanel` fetches are
  unmount-safe; `retryMedia` returns a real error on importer-load failure.
- **Security verified safe:** XXE (WXR parser rejects DOCTYPE), every
  `unserialize(allowed_classes=false)`, path traversal (hex-only `sanitizeKey`),
  Snapshot SQL (no user input in table names), all REST/AJAX gated on
  `manage_options` + nonce.

---

## 11. Pending

### 11.1 Blocking (needs a real server — not available this session)
- **Manual QA of the whole v1.2.0 line** behind Cloudflare
  (`docs/qa/2026-07-09-v1.2.0-qa-checklist.md`) — plus the new features'
  runtime paths (nothing built today has run on live WP). Gates merge → `master`
  + tag `v1.2.0`. Nothing merged to `master`; no tag.
- **Full token-mutex fix** — deferred (softened to 2 s). Pitfall: the lock value is
  a bare `time()` + unconditional shutdown delete, so a naive early-release lets
  request A's shutdown delete request B's lock. Correct fix = unique token +
  conditional release; load-bearing, do during QA.

### 11.2 Manual UI/DB smoke tests (code/build/lint/unit-verified only)
Regenerate Thumbnails (±force) · Preflight gate · Retry-failed-media · **Snapshot
rollback** create→restore cycle (CI can't cover — DDL breaks WP_UnitTestCase txn) ·
**Manual import** upload → clone → retry → rollback (incl. a large chunked WXR).

### 11.3 Feature backlog (not built)
- **WP-CLI `import`/`reset`** — needs the phases decoupled from `$_POST`/`wp_send_json`.
- Selective import; retry re-retry of still-failing items; large-site snapshot
  chunking + restore-point expiry.

### 11.4 Housekeeping / known non-blockers
- **Merge plan:** fold-back order `wxr-chunking → wxr-state-split →
  regenerate-thumbnails → manual-import → master`, tag `v1.2.0`. The integration CI
  + `bin/install-wp-tests.sh` live from `wxr-state-split` onward, not on
  `wxr-chunking` — whichever merges first must carry them.
- **Multisite:** renumber `1.2.0 → 1.3.0` on `multi-site`; new features untested on
  multisite.
- **Phase 4 `alpha`:** 3 known bugs + merge decision, deferred.
- Pre-existing lint debt (not introduced today): a few eslint jsdoc/no-console/curly
  errors and the vendored `process_posts` phpcs complexity warning.

---

## 12. Post-session commits (after `592da51`)

Landed after the main write-up above; branch tip is now **`fe52c16`** (clean,
pushed, CI-green). Maintainer commits followed by one i18n fix:

| Commit | What |
|---|---|
| `0362147` | fix(log-ui): accordion + smooth-scroll on Import Log, matching System Status |
| `58b54d9` | **fix(rollback): snapshot *before* the database reset, not after** — snapshot creation moved into `Initialize::response()` so a "reset + snapshot" import captures the pre-reset state (relocated from `InstallDemo`). |
| `24c3c8d` | feat(preflight): collapse the readiness checks into a single summary bar (`PreflightPanel.jsx` redesign + `_cp-preflight.scss`) |
| `aefc5ef` | fix(messages): decode HTML entities in progress + log text |
| `f89dafa` | **fix(rollback): exclude snapshot tables from the database reset** (`Initialize.php` — the reset no longer drops the `{prefix}sd_edi_snap_*` shadows, so a restore point survives a reset) |
| `087c0ba` | fix(log-ui): never render blank on a failed log fetch |
| `fe52c16` | chore(i18n): localize the two preflight summary-bar strings (`preflightSummaryFail`, `preflightSummaryReady`) |

**Verify during QA:** the two rollback/reset interaction fixes (`58b54d9`, `f89dafa`)
— snapshot taken before reset, and reset preserving the snapshot tables — were not
in the original session's design and want a live-run check.

State at handoff: 98 unit tests + integration CI green (PHP 7.4/8.3); clean tree;
in sync with `origin/manual-import`. Nothing merged to `master`; no `v1.2.0` tag.

## 13. Rollback overhaul — "why does my rollback restore nothing?" (`89bdf9f`..`13febbc`)

Triggered by a report: *rollback restores no content; the snapshot table may not be
created.* Root-caused with a live MySQL 8.4 repro (the shadow-table SQL round-trip
itself is correct — 3 rows → wiped → 3 restored). Branch tip is now **`13febbc`**.

| Commit | What |
|---|---|
| `89bdf9f` | **fix(rollback): fresh snapshot per import, not a reused stale one.** Root cause: `Snapshot::create()` was gated on `! Snapshot::exists()`, and shadow tables are only dropped on rollback — so every import after the first *reused the first import's restore point*. If that first snapshot was taken on an empty/fresh site (the normal case while testing), every later rollback reverted to empty → "no content". Fix: gate on the **session** (`Snapshot::isForSession($sessionId)` backed by option `sd_edi_snapshot_session`), not table existence. `create()` now `drop()`s stale shadows first and records the session marker (even on too-large skip, so per-chunk callers don't recount). Both call sites (`Initialize`, `InstallDemo`) updated. |
| `72880d1` | **feat(rollback): snapshot the uploads media so rollback fully restores media files.** New `inc/Common/Utils/MediaSnapshot.php`, driven in lockstep through `Snapshot::create/restore/drop`. **Reset import** → rename `uploads/` aside instantly (no byte copy, no disk doubling), recreate empty, restore moves it back. **Non-reset / manual** → write a file manifest, rollback deletes only files the import added. Cross-filesystem uploads (custom `UPLOADS` disk) → rename fails → auto-fallback to manifest + logged warning. Symlinks skipped on walk + delete. Both strategies proven on a real temp FS. |
| `e265026` | **feat(rollback): keep-or-discard prompt on the success screen.** New REST `POST /discard-restore-point` → `Snapshot::drop()` (drops shadow tables *and* the moved-aside media copy; site untouched). `Success.jsx` shows a notice when a restore point exists: keep (do nothing) or **Discard restore point** → confirm → flips to "disk reclaimed", hides Roll Back. 6 new i18n keys. |
| `13febbc` | **feat(rollback): same discard action on the persistent `RestorePointBanner`** so a returning user who closed the modal can also reclaim disk. Reuses the discard i18n keys; buttons disable each other while running. |
| `f367363` | **fix(rollback): release the import lock after a restore.** Found during a full real demo-import→rollback E2E on `wpcheck.local`. `SessionManager::start()` sets the lock *before* the snapshot, so it lives in the options shadow; reverting options on rollback restored the completed import's lock, and the next import was wrongly rejected as "already in progress" (self-heals in ≤30 min via the lock transient TTL, or instantly via "Start Over", but still an annoyance). Fix: `SessionManager::forceRelease()` at the end of `Snapshot::restore()` — a rolled-back site is never mid-import. |
| `ebfe44d` | **fix(rollback): restore media BEFORE reverting the options table — the media-not-restoring bug.** Found by end-to-end Playwright test on a real Local WooCommerce site (`wpcheck.local`), instrumented with `error_log`. Root cause: `Snapshot::create()` snapshots `wp_options` into a shadow *before* `MediaSnapshot::create()` writes the `sd_edi_mediasnap` descriptor option — so the options shadow never contains it. `Snapshot::restore()` then reverted `wp_options` from that shadow *first*, **deleting `sd_edi_mediasnap`**, and only afterwards called `MediaSnapshot::restore()`, which read `false` and bailed → media never moved back, shadow uploads orphaned in `wp-content/sd-edi-restore/`. Isolated CLI tests passed only because the option stayed in that one process's in-memory cache (raw-SQL revert doesn't invalidate it); real multi-request rollbacks read a fresh DB and failed. Fix: call `MediaSnapshot::restore()` at the *top* of `Snapshot::restore()`, before the DB revert, so the descriptor is read while still present; also makes media restore succeed even when no DB shadows exist. Verified via UI→REST rollback: pristine original media restored, demo media dropped, nothing orphaned. |
| `0c0b4c9` | **feat(rollback): snapshot *every* prefixed table, not a fixed 9.** Was: hard-coded list (posts/postmeta/terms/…/options), so custom plugin tables (WooCommerce HPOS orders, form entries, bookings) were wiped by a reset but never backed up → lost on rollback. Now `Snapshot::tables()` discovers all `{prefix}*` tables via the same `SHOW TABLE STATUS` scan `databaseReset()` uses, minus an exclude list (`users`, `usermeta`, activity log, `sd_edi_snap_*` shadows, `actionscheduler*`), filterable via `sd/edi/snapshot_exclude_tables`. `restore()`/`drop()` now enumerate the **shadow** tables that actually exist and reverse-map to live names (imports can add/drop tables mid-run); `restore()` `CREATE TABLE IF NOT EXISTS` before refill so a dropped table still comes back. Reset-set == backup-set → complete restore. Verified on live MySQL: a custom `wp_wc_orders` table round-trips; excluded `wp_users` is not reverted (new users survive). |

**New API surface:** `Snapshot::create( $sessionId, $reset, $demoSlug )` (was arg-less),
`Snapshot::isForSession( $sessionId )`, `Snapshot::SESSION_OPTION`; `MediaSnapshot`
(`create/restore/discard`, option `sd_edi_mediasnap`); REST `/discard-restore-point`.

**Full E2E verified (Playwright, `wpcheck.local`, Local + WooCommerce/Elementor/Fluent Forms):**
a real "Main Home" demo import with reset + restore point, then Roll Back from the
success screen. Snapshot during import = **55 shadow tables** (full coverage) + moved-aside
uploads + `sd_edi_mediasnap`. After rollback every metric returned to baseline:
posts 10, pages 57, products 56, attachments 160; uploads reverted from `2025+2026`
to `2026`-only (import-added media dropped); 0 shadow tables, 0 restore options, 0
orphaned restore dirs. Media + DB + cleanup all correct through the real UI→REST path.

**Verify during QA:**
- Rollback on a site that **already has content** (empty site correctly restores to empty — that is not a bug).
- Media round-trip: reset import → rollback restores original image files; non-reset → demo media removed, originals intact.
- A kept (not-rolled-back) reset import leaves originals in `wp-content/sd-edi-restore/` until the next snapshot/rollback — expected disk cost; auto-cleaned on next import or discard.
- Discard from both the success screen and the banner frees disk and removes the rollback affordance.

**⚠ Build gotcha (not a code bug, but bit us during testing):** the service loader
(`Bootstrap::getServices`) discovers `App\*` classes via `$this->composer->getClassMap()`,
so the plugin **requires an optimized/authoritative autoloader** — a plain
`composer install`/`dump-autoload` (non-optimized) yields an empty App classmap and the
**entire admin menu silently disappears** (page 403s with "not allowed to access"). Always
build/deploy with `composer install --optimize-autoloader` (or `dump-autoload -o`). Worth
considering committing an optimized classmap or documenting this in the release checklist.

State at handoff: 98 unit tests pass, 0 phpcs errors, production build compiled, pot
regenerated. Assets are gitignored (built at release), so only source is committed.
Clean tree, in sync with `origin/manual-import`. Nothing merged to `master`; no tag.
