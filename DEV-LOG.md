# DEV-LOG

Running log of architectural decisions, non-obvious context, and rationale.
Most recent entries at the top.

## 2026-07-25 — Fix: disabled set_time_limit fatals the readiness report

**What:** `Preflight::attemptRaiseExecutionTime()` now checks `function_exists('set_time_limit')` before calling it (and `attemptRaiseMemory()` does the same for `ini_set`). When the function is unavailable the tuner returns an unchanged outcome, which the existing graders already report as a refusal.

**Why:** a function listed in PHP's `disable_functions` is removed from the function table entirely, so calling it raises **`Error: Call to undefined function`** — a fatal, not a warning, which the `@` suppression operator cannot catch. Inside a namespace the failed lookup is even reported under the namespaced name (`SigmaDevs\EasyDemoImporter\Common\Utils\set_time_limit()`), which makes it read like a missing method. Shared hosts disable `set_time_limit` routinely, so on those hosts `/preflight` returned **HTTP 500** and the Readiness step of the wizard broke outright — the readiness report was the thing breaking the page it reported on.

**Non-obvious context:**
- Introduced by the auto-tuner (`f981385`, merged in `ce56bf2`) and shipped on master. Not caught earlier because every prior test host allowed `set_time_limit`; it only surfaced once a `disable_functions = set_time_limit` php.ini was applied.
- `Actions::beforeImportActions()` already guarded this correctly with `strpos(ini_get('disable_functions'), 'set_time_limit')` — the pre-existing code had the right instinct and the new code simply failed to copy it. `function_exists()` is the more robust form (immune to spacing and substring false-positives in the ini string), so the new guards use that.
- The `@` operator was actively misleading here: it implied the failure mode was a warning. Removed, since with the guard in place there is nothing left to suppress.
- Not unit-testable — a disabled function cannot be simulated from PHPUnit. Verified live instead against a host with `disable_functions = set_time_limit`: `/preflight` returns 200, execution time reports the honest refusal, and a full 835-item import completed at `memory_limit = 48M` / `max_execution_time = 20`.

## 2026-07-25 — Fix: "Server Requirements Not Met" modal re-stacking

**What:** The requirements warning now stays dismissed. `AppDemoImporter.jsx` gained a `reqAcknowledged` flag: the effect that raises the modal is gated on `!reqAcknowledged`, and `handleCloseModal()` sets it when the user clicks "Continue Anyway".

**Why:** The raising effect runs on `[serverData, serverInfo]` and unconditionally called `setIsModalVisible(true)` whenever `hasErrors(serverInfo)` was true. It had no memory of the dismissal, so any later re-render that changed either dependency re-opened the warning — on top of an import wizard the user had already advanced. Observed live on a 128M host: after dismissing, the modal was back over the Configure step, and a second dismissal was needed.

**Also fixed here — two latent temporal-dead-zone bugs.** `serverInfo` and `demoData` were listed in `useEffect` dependency arrays (lines ~207 and ~240) but declared with `const` further down the component. A dependency array is evaluated *during render*, so both were still in their TDZ at that point. Babel's transpile masked it in the browser build — `const` became a hoisted `var`, so each dep silently read `undefined` on every render and never actually tracked its value — but untranspiled (Vitest/esbuild) it throws `ReferenceError: Cannot access 'X' before initialization`, which is how it surfaced. Both declarations moved above their effects. Net effect in production: those deps now genuinely track their values instead of being permanently `undefined`.

**Tests:** `tests/js/AppDemoImporter.requirements.test.jsx` — 4 tests driving the real component against a real test-local Zustand store, so the store update that used to re-raise the modal is reproduced rather than simulated. Verified to fail without the fix (2 of 4 fail) and pass with it.

**Non-obvious context:**
- Found while live-testing the limits reporting under reduced PHP resources — the low `memory_limit` is what makes the pre-existing `systemRequirements` gate (256M/300s) fire in the first place, which is why this had gone unnoticed.
- Added `@testing-library/react` + `@testing-library/dom` as devDependencies (the project had no component-test tooling), and `tests/js/**/*.test.jsx` to the Vitest `include` so tests may contain JSX.
- `tests/js/setup.js` gained a `window.matchMedia` stub — jsdom has none, and antd's responsive `Grid` subscribes to it on mount.
- The Vitest config does not set `globals: true`, so Testing Library cannot auto-register its `afterEach` cleanup. Without an explicit `cleanup()` antd's body portals leak into the next test and assertions read a stale modal. This cost a debugging cycle.
- The test stubs `ModaRequirements` and asserts on the `isVisible` prop rather than the rendered antd Modal: a closed antd Modal stays mounted and only hides when its leave animation ends, which never happens in jsdom (no `transitionend`). Asserting our own prop keeps the test about our logic instead of antd's motion.
- The warning is deliberately still raised once per page load; only *re-raising after acknowledgement* is suppressed. Acknowledgement is per page load (component state), not persisted — a reload legitimately re-warns.
- After close, antd keeps the modal node mounted but `display:none`. A DOM query for its buttons therefore still finds them; visibility must be asserted via `getClientRects()` / computed style, not node presence. (This tripped up the first verification pass.)
- Also verified by live browser run against a genuinely constrained host — the warning appears once, one dismissal sticks, and it never re-stacks across the full wizard to Start Import.
## 2026-07-25 — Flag plugin-adjusted limits in the readiness list

**What:** Readiness rows whose value the plugin raised itself now carry a small blue "Adjusted" pill beside the label, with a tooltip explaining the server default is unchanged. `Preflight::check()` gained an `adjusted` flag, set by `memoryCheck()`/`executionTimeCheck()` when the tune outcome reports `raised`.

**Why:** the tune outcome only existed inside the message prose ("256M — raised for this import (was 128M)"), so a row the plugin had modified rendered identically to every other passing check — same green tick, same grey subtext — and the change went unnoticed.

**Non-obvious context:**
- The flag is deliberately separate from the message: the UI needs something machine-readable to style on, and re-parsing the localized sentence would be fragile.
- `adjusted` defaults to `false` in `check()`, so every other builder is unaffected — asserted directly in `test_untuned_checks_are_never_flagged_adjusted`.
- Only `raised` sets it. A refusal or an already-sufficient limit means the plugin changed nothing, so there is nothing to flag.
- Treatment chosen (of four offered) to be purely additive: the pass/warn/fail icon language shared with the Required Plugins list beside it stays intact, and no existing row changes appearance. `PreflightPanel` renders only in the Readiness modal step — the System Status page is untouched.
- Pre-existing, not introduced here: `_cp-preflight.scss:68` trips stylelint's `CssSyntaxError: Unclosed string` on an apostrophe in a `//` comment ("list's"). Also note the `stylelint` npm script globs `src/sass/**`, but the directory is `src/scss/**` — so stylelint currently never runs against these files.

## 2026-07-25 — Wire honest limits reporting into import start

**What:** `InstallDemo::logEffectiveLimits()` runs right after `do_action('sd/edi/before_import')` and records, once per session, the *effective* (post-raise) `memory_limit` / `max_execution_time` into the activity log via a new pure grader `Preflight::limitsLogEntry()` — an `info` line when both meet the floor, a `warning` naming the host-enforced value when either was refused/capped.

**Why:** `beforeImportActions()` already raises both limits, but `ini_set()`/`set_time_limit()` are silent no-ops on locked hosts — a refusal was previously invisible until a mid-import fatal. This surfaces the truth at import start, in the log the user is already watching.

**Non-obvious context:**
- The existing `Actions::raiseMemoryLimit()` was left untouched — its only-ever-raise semantics and the authoritative `sd/edi/temp_boost_memory_limit` (350M) / `sd/edi/temp_boost_max_execution_time` (300) filters are superior to Preflight's fixed-target attemptRaise and must not be regressed. We report the outcome of *its* raise, we don't replace it.
- `before_import` fires on **every** chunk request (prepare/batch/finalize), so the log call is guarded by a per-session transient (`sd_edi_limits_logged_{sessionId}`, 1h) → one line per import, not per chunk.
- Placed in `InstallDemo` (not `Actions`) because that's where `sessionId`/`demoSlug` and the `ImportLogger` sink are in scope; `beforeImportActions()` is an arg-less static hook callback with neither.
- `limitsLogEntry()` is pure (params in, `['level','message']` out) → unit-tested without the environment; the side-effecting read/guard/log stays in `InstallDemo`.

## 2026-07-25 — Honest limits auto-tuner (Preflight)

**What:** `Preflight::report()` now attempts to raise `memory_limit` (to `RECOMMENDED_MEMORY`) and `max_execution_time` (to `RECOMMENDED_EXECUTION_TIME`) via `attemptRaiseMemory()` / `attemptRaiseExecutionTime()`, then **re-reads** the effective value. The System Status memory/execution-time rows now say what actually happened — "raised for this import (was 128M)", "the host would not raise it", or "raised … but still below the recommended" — instead of only reporting the raw ini value.

**Why:** `ini_set('memory_limit', …)` and `set_time_limit()` are silently no-ops on many managed/locked hosts. Reporting the *requested* value (or claiming a raise) would be a lie the user later pays for mid-import. The tuner tells the truth: requested X, host granted Y. This is also the free-core groundwork that makes a future Pro "Background Import" pitch credible — we can prove the host caps limits before selling the workaround.

**Non-obvious context:**
- The side-effecting `attemptRaise*` methods are thin: mutate + re-read. All grading is in the pure `memoryTuneOutcome()` / `execTuneOutcome()` graders (unit-tested without the environment), returning `raised` / `reached` / `refused` flags.
- `memoryCheck()` / `executionTimeCheck()` gained an optional trailing `?array $tune = null`, so the existing unit-test call sites (no tune arg) behave exactly as before — no test churn, message unchanged when null.
- Memory uses `-1` for unlimited, execution time uses `0` — the two graders don't share a sentinel, hence two methods rather than one generic.
- The tune runs inside the cached `/preflight` GET, so it only proves the host *allows* raising; it does **not** raise limits for the actual import request. Wiring an `attemptRaise*` call at import start (in the AJAX bootstrap) is the natural follow-up — deliberately left out of this branch to keep it scoped to the status report.
- `ini_set` needs a `phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed`; `set_time_limit` is `@`-silenced (with a `NoSilencedErrors` ignore) because a host with it in `disable_functions` would otherwise emit a warning.
- Pre-existing (not introduced here): `Preflight.php:186` trips `Generic.Commenting.DocComment.ShortNotCapital` (doc starts with lowercase `max_execution_time`). Left untouched — out of scope.

## 2026-07-23 — Conditional demo visibility (`requires` block)

**What:** Demos can declare an optional `requires` block (`php` min version, `extensions`, must-be-active `plugins`). `DemoRequirements::evaluate()` grades it server-side; `buildList()` attaches `requirementsMet` + `missingRequirements` per demo; `DemoCard.jsx` greys the card and disables Import with a tooltip listing what's missing.

**Why:** Per `IMPROVEMENTS.md` #16 — showing a demo whose prerequisites can't be met produces a guaranteed mid-import failure and a support ticket. This is the same failure-prevention class as the cache flush, so it ships **free**, not Pro.

**Non-obvious context:**
- `requires` is deliberately **separate** from the existing `plugins` array. `plugins` are auto-installed during import; `requires` is only for prerequisites the importer *cannot* resolve (PHP version, extensions, premium/bundled plugins that must already be active). Folding them together would grey out every demo that lists plugins.
- Evaluation is server-side because the client can't see active plugins / PHP build. The result is injected into the existing `buildList` REST payload — no new endpoint.
- Named `DemoRequirements`, not `Requirements`, to avoid confusion with the pre-existing `Config\Requirements` (which gates whether the *plugin itself* can run). Different concept, different layer.
- Legacy configs omit the field; `DemoCard` treats `requirementsMet !== false` as met, so demos without a `requires` block are unaffected.
- The `.pot` was left untouched: a hook regenerated it wholesale (~2000 lines — it was long stale), confirming it's a release-time artifact, not per-commit maintained. New strings get picked up at the next release regen.

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
