# DEV-LOG

Running log of architectural decisions, non-obvious context, and rationale.
Most recent entries at the top.

## 2026-07-23 — Post-import cache flush

**What:** Added `Actions::flushCaches()` to the `afterImportActions()` chain — purges the WP object cache plus the common page-cache plugins (W3TC, WP Super Cache, WP Rocket, SG Optimizer, Autoptimize, WP Fastest Cache, and action-driven LiteSpeed/Cache Enabler/Hummingbird), and fires `sd/edi/flush_caches` for custom layers.

**Why:** Caching plugins serve stale HTML after an import, so a fresh site can look broken (old pages, missing styles) until a cache the user doesn't know exists is cleared — per `IMPROVEMENTS.md` #11, the single largest "looks wrong after import" support category, and nothing in the codebase flushed anything except Elementor's own cache (`elementorActions()`).

**Non-obvious context:**
- Every purge is best-effort and guarded (`function_exists`/`class_exists`/action hooks are no-ops when the plugin is absent), so it is safe on any site.
- `flushCaches()` returns **void**, not `static` like its sibling chain methods, deliberately: it is the terminal call in the chain (return value unused) and a `new static()` there would add to the file's baselined `new static()` count and trip the PHPStan gate. Verified via stash-compare that the change introduces zero new errors beyond the pre-existing baseline drift on this file.
- The Autoptimize guard uses `class_exists()` only — `method_exists()` on a class PHPStan can't resolve always evaluates to false (dead-code error), and the runtime guard is sufficient.

**Rejected:** logging each flush to the activity log — `afterImportActions()` is a static hook without the importer's `report()` sink in scope, and a silent best-effort flush matches its siblings (`updatePermalinks`, `elementorActions`).

## 2026-07-23 — Streamed demo download (memory-crash fix)

**What:** `DownloadFiles::downloadDemoFiles()` now streams the demo archive to disk (`'stream' => true`, `'filename' => $demoData`) instead of buffering the whole zip with `wp_remote_retrieve_body()`, and deletes the partial file on the error and non-200 paths. Commit `4898333` on `fix/stream-demo-download`.

**Why:** The old path loaded the entire archive into a PHP string before writing it, so a large WooCommerce demo could exceed the host `memory_limit` and fatal before a single post was imported — a real crash on low-memory shared hosts, not a theoretical one. This is deliberately a free-core bug fix, not a Pro feature: the plugin already solved import timeouts architecturally via chunking, and gating a memory-crash fix would be indefensible.

**Non-obvious context:** with `'stream' => true` the body is written to `$demoData` directly and `wp_remote_retrieve_body()` is empty, so no `put_contents()` is needed. A streamed request can leave a partial file on failure, hence the explicit `delete()` on both the `WP_Error` and non-200 branches (the streamed file on a non-200 holds the error body, not the zip).

**HTTP Range resume — built then reverted.** A follow-on set of commits (`344a384`/`343da79`/`b1190be`) added `.part`-accumulator + `Range: bytes=N-` resume so an interrupted download wouldn't restart from zero. A `/code-review` pass found five real correctness gaps: the `WP_Error` path can't distinguish a dropped 206 from a Range-ignoring 200 (corrupts the accumulator); same-URL content mutation splices mismatched halves (no `If-Range`/ETag); raw `fopen` append breaks under a non-Direct `WP_Filesystem` method; 416 promotes an oversized `.part` unvalidated; and an unchecked `stream_copy_to_stream` can silently truncate. The streaming crash-fix drew zero findings. Decision: keep the certain, clean crash-fix; revert resume and rebuild it later as its own branch with a completeness/size check, `If-Range` validation, and a live test harness against real flaky-server behavior (edge cases unit tests can't reach).

**Rejected:** (1) Selling this as a Pro "bulletproof import" feature — it's a bug fix. (2) A Pro feature that raises `max_execution_time`/`upload_max_filesize` at runtime — non-functional: those are `PHP_INI_PERDIR` (ini_set no-op) and FPM/nginx gateway timeouts are unreachable from PHP.
