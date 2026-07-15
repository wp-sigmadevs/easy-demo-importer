# Multi-Lens Code Review — `manual-import` branch (v2.0.0)

**Date:** 2026-07-15
**Target:** `git diff master...HEAD` — shipped source only (`inc/`, `lib/`, `src/js/`); 126 files, ~20.5k insertions.
**Method:** three independent reviewers (Security, Performance, DX/Correctness) over the same diff; findings appearing in 2+ lenses are treated as highest-confidence.

**Verdict:** No MUST-FIX on any happy path. The design is careful — every web entry point is gated by `manage_options` + nonce; DB/filesystem ops are chunked, capped, and use `$wpdb->prepare()` for values and ZipSlip-safe extraction. The real issues are **conditional edge cases converging on two subsystems**: the bundled-media/attachment-import path and the `MediaSnapshot` fallback.

---

## Cross-Lens Findings (highest confidence)

### CL-1 · Bundled-media / attachment-import path — *Security + Correctness*
**Files:** `lib/wordpress-importer/class-wp-import.php` (`import_local_file()` ~1408–1455, attachment guard ~1120–1125); `inc/Common/Importer/ChunkedImport.php:237–311`

- **Security (SHOULD FIX):** `import_local_file()` copies the bundled source into `wp-content/uploads` via `wp_unique_filename(..., wp_basename($source))`, **preserving the original extension**, and returns before any validation. The later guard (`wp_check_filetype($upload['file']); if ($info)`) is effectively dead code — `wp_check_filetype()` returns a non-empty array even for disallowed types, so nothing is rejected and the file is already on disk.
  - **Attack:** a demo `bundle`/`images.zip` ships `uploads/2024/01/evil.php`, and the WXR references `…/uploads/2024/01/evil.php`. `resolve_bundled_media()` maps it → `import_local_file()` writes `wp-content/uploads/2024/01/evil.php` → **RCE** on request.
  - Admin + nonce gated, so not privilege escalation. The exposure is **supply-chain** (the demo/bundle author is not the importing site owner) and it bypasses WP core's refusal to write arbitrary types into uploads.
  - **Fix:** enforce a media-type allowlist (e.g. `wp_check_filetype_and_ext` / an extension allowlist matching `imageExtensions()`) **before** the copy in `import_local_file()`; reject anything else.

- **Correctness (SHOULD FIX):** attachment inserts are not deduped by URL/guid, and `persist()` runs only at the *end* of `processBatch` (line 304). A request killed after inserting attachments but before persist replays the slice on retry. `post_exists()` dedups normal posts (correct) but **not** attachments. Batches are time-boxed to ~10s, but one tar-pitted image can hold `wp_safe_remote_get` up to the 40s attachment timeout, overrunning a low FPM `request_terminate_timeout` and forcing a replay → **duplicate Media Library entries**. Also cosmetic: `bundled_media_imported`/`failed_attachments` re-increment/re-append on replay (`failed_attachments` grows unbounded).
  - **Fix:** persist the cursor per `$step`, or dedup attachments by URL/guid before insert.

> ⚠️ This is the exact `bundled_media_dir` path extended this cycle (commit `106d15d`, manual-import media import). Both findings apply directly to that change.

### CL-2 · `MediaSnapshot` manifest / move-fallback — *Performance + Correctness*
**Files:** `inc/Common/Utils/MediaSnapshot.php:122–141` (move fallback), `:169–198` (`captureByManifest`), `:245` (`restore`), `:291–312` (`files`); `inc/App/Ajax/Backend/Initialize.php:348–350` (`clearUploads`)

- **Correctness (SHOULD FIX):** with snapshot on, `reset=true`, and a custom `UPLOADS` path on a **different device**, `@rename($base,$shadow)` fails → falls back to `captureByManifest`, which records filenames only (no copy). `Initialize` then `clearUploads()` deletes every pre-existing upload. On rollback, manifest mode only deletes files *not* in the manifest — it has nothing to restore from, so **pre-existing media is permanently lost**. The warning logged at `:134–138` ("the database will still roll back, and new media added by this import will be removed") wrongly implies pre-existing media is safe.
- **Performance (SHOULD FIX):** the manifest walk of the entire uploads tree has **no size/time cap** (the DB half *is* guarded by `tooLargeToSnapshot()`'s 500k-row cap). On a 100k+ file library → multi-second blocking walk inside the import AJAX request, and `restore()` walks it again — the exact wall-clock risk chunking exists to avoid.
- **Converging fix:** on move-fallback for a **reset** import, refuse the media restore point (and warn accurately). Optionally add a file-count cap that falls back to skipping the media restore point.

### CL-3 · Manual chunk-upload lifecycle (JS) — *Correctness + Performance*
**Files:** `src/js/backend/components/Modal/ManualImportModal.jsx:137–174`; `src/js/backend/utils/Api.js:192/237/304`

- The recursive `send(i)` **rejects the entire multi-hundred-MB upload on one transient chunk error**, with no resume — undercutting the point of chunking.
- No `AbortController`: closing the modal mid-upload leaves the fetch chain and `setUploadPct` running; inter-phase/retry `setTimeout`s in `Api.js` aren't cancelled on unmount either.
- **Fix:** retry a failed chunk with backoff; abort in-flight requests + clear timers on close.

---

## Security

**Verdict:** No unauthenticated or cross-privilege exploitable vulnerability.

**SHOULD FIX**
1. **CL-1** — media-type allowlist before `import_local_file()` copy (RCE via malicious bundle).
2. **SVG/ICO/BMP in bundled images + unfiltered `images.zip` extraction.** `ManualImport.php:88` allows `svg`,`ico`,`bmp`; `extractImages()` (`:499–506`) `unzip_file`s the entire `images.zip` — every entry including `.php`/`.html` — into staging protected only by `.htaccess`/`web.config` (`Setup::protectDirectory:179–183`), which **nginx ignores** (transient web-executable window until the 6h cleanup cron). SVGs that reach the Media Library (via CL-1) are served from uploads → **stored XSS**; WP core blocks SVG upload for this reason. **Fix:** drop `svg` from the bundled set (or sanitize), and skip non-media entries during extraction.

**Protections verified (no action)**
- **Auth/CSRF:** REST `permission_callback` → `RestEndpoints.php:434` (`manage_options`); AJAX phases → `Helpers::verifyAjaxCall()` (nonce + `manage_options`, `Helpers.php:83–97`); `ManualImport::handleUpload()` nonce+cap (`:172–178`); `RegenerateThumbnails` nonce+cap (`:90–94`). Nonce only enqueued on the `manage_options`-only admin page.
- **SQLi:** only `$wpdb->prefix`-derived table identifiers are interpolated; all data values use `prepare()`; `IN()` lists are `intval`-mapped.
- **Path traversal:** manual keys reduced to `[a-f0-9]` (`ManualContext.php:82`); upload `target` whitelisted (`ManualImport.php:239`); slider ZipArchive has explicit `..`/absolute reject loop (`ImporterAjax.php:448–457`); bundle/settings/images use WP `unzip_file` (ZipSlip-safe); `BundledMedia::candidates()` rejects `..`.
- **Deserialization:** `ImportState` uses `unserialize(..., ['allowed_classes'=>false])` (`:222`); manual settings are `json_decode`.
- **SSRF:** attachment fetch uses `wp_safe_remote_get` (`class-wp-import.php:1225`).
- **Option write (manual settings.json):** blocklist covers siteurl/home/active_plugins/default_role/template/stylesheet/keys/salts + `*user_roles` suffix guard (`ImportSettings.php:75–140`).

---

## Performance

**Verdict:** No MUST FIX. Every high-risk path is chunked, time-boxed, capped, or cached.

**SHOULD FIX** (all one-time-per-import or repeated-cheap)
1. **CL-2** — uncapped media-manifest walk.
2. `Snapshot::tooLargeToSnapshot()` (`Snapshot.php:340–363`) — exact `COUNT(*)` per table (InnoDB index scan on multi-million-row `postmeta`); `tables()` already fetched the `SHOW TABLE STATUS` `Rows` estimate and throws it away — use that for the guard.
3. `Snapshot::create()` (`:233–272`) — `tables()` (a `SHOW TABLE STATUS` computing row estimates for every table) runs ~3× per create (`drop()`, `tooLargeToSnapshot()`, main `foreach`). Memoize per request.
4. `RegenerateThumbnails::totalImages()` (`:109, 309–316`) — `COUNT(* … post_mime_type LIKE 'image/%')` recomputed on **every batch request**; the total is fixed during a run — round-trip it like the cursor or cache in a short transient. (`imageIdsAfter()` correctly uses keyset pagination `ID > after` — good.)

**Low (mention only)**
- `Api.js:192/237/304` — inter-phase/retry `setTimeout`s not cancelled on modal unmount.
- `ImportLogPanel.jsx:220–228` — `runs` rebuilt each render, effect dep `[runs]` re-runs every render, but body is idempotently guarded. Negligible.

**Verified mitigations (not findings)**
- ChunkedImport/ImportState: per-chunk post files loaded one slice at a time, 10s budget; deferred term counting recounted only for touched terms via a single `IN()`; immutable parse blob written once.
- ImportLogger: coarse phase-level inserts; `session_id`+`logged_at` indexed; `get()` LIMIT-bounded; `getRuns()` transient-cached keyed on `MAX(id)`; 7-day prune.
- WXR fetch: attachment timeout cut to 40s; bundled-media short-circuit (≤2 `preg_match` + `is_file`, no HTTP).
- REST `serverStatus` (5min) / `preflight` (1min) transient-cached; snapshot create gated once-per-session.
- `AppRegenerate.jsx`: 40ms drain interval cleared on unmount and completion; recursion guarded by `runningRef`; list windowed to `MAX_ROWS=120`.

---

## DX / Correctness

**Verdict:** Design is unusually careful. No guaranteed-corruption-on-happy-path bug.

**SHOULD FIX**
1. **CL-2** — reset + separate-filesystem uploads → rollback silently can't restore media + misleading log.
2. **Retry-failed-media can duplicate attachments** (`InstallDemo.php:644–666` + `Success.jsx:148–186`). Each `process_attachment` inserts a new attachment; `FailedMedia` is cleared only on `done`. If a slice recovers records then the request is killed before returning JSON, Retry restarts at cursor 0 → re-imports recovered records (no `post_exists` dedup) → duplicates. **Fix:** remove each record from persisted `FailedMedia` (or persist cursor) as it recovers.
3. **CL-1** — chunked batch retry re-downloads attachments → duplicate media.
4. **CL-3** — manual chunk upload has no per-chunk retry and no cancellation.

**Test gap:** No PHPUnit harness ships, yet the highest-risk new paths (snapshot/rollback, manual finalize routing, chunked resume) are untested. `BundledMedia::candidates()` and `ImportLogger::groupRows`/`markInterruptedRuns` are pure (no WP bootstrap) — add unit tests there first (uploads-relative extraction, CDN `YYYY/MM` fallback, `..` rejection; status precedence and interrupted flagging). They encode exactly the edge cases above.

**Confirmed correct / intentional (no action)**
- Rollback restores media **first** so it reads `sd_edi_mediasnap` before the options table is reverted (`Snapshot.php:280–331`).
- Reset excludes `options`/`users`/`usermeta`/log/`sd_edi_snap_*` from truncation (`Initialize.php:265,295,307`) — snapshot marker, mutex, media descriptor survive a reset (no double-snapshot / restore-point wipe).
- Snapshot taken exactly once (`isForSession` guard no-ops re-checks and per-chunk calls).
- `acquireMutex`: atomic `INSERT IGNORE` on the unique `option_name` key + 30-min stale reclaim + shutdown-handler release (`InstallDemo.php:914–960`).
- `import_local_file` guards `stat()` before `chmod` (a failed stat can't strip permissions).
- `import_start()` override throws instead of `die()`, enabling the single-shot fallback.
- `regenState` uses kind `'regen'` — no collision with content `'import'` state.
- `uploadId` is `[a-f0-9]{20}` client-side, matched by `ManualContext::sanitizeKey` server-side.

**Out of scope (pre-existing, not in this diff):** `Initialize.php:325` uses `$wpdb->prepare('TRUNCATE TABLE %1$s', $tbl)` — violates the "never pass table names via `%1$s`" rule, but predates this branch.

---

## Summary

| | Count |
|---|---|
| MUST FIX | **0** |
| SHOULD FIX | **10** (Security 2 · Performance 4 · Correctness 4) |
| Low / notes | 2 JS |
| Test gap | 1 |
| Cross-lens | **3** (CL-1, CL-2, CL-3) |

**Recommended order:**
1. **CL-1** — media-type allowlist in `import_local_file()` (highest severity: RCE + dup; and it's in code changed this cycle).
2. **CL-2** — refuse media restore point on separate-FS reset (fixes data-loss edge + perf, single change).
3. **Security #2** — drop `svg` from bundled images / skip non-media entries.
4. Retry-dedup items (Correctness #2, CL-1 correctness half).
5. Perf memoization (Snapshot `tables()`, thumbnail count) and the JS upload retry/abort.
