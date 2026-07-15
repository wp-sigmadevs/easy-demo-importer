# Plan: React Import-Log page + post-import log view

- **Date:** 2026-07-09
- **Branch:** `wxr-chunking`
- **Depends on:** the `ImportLogger` + `/import/log` REST endpoint already on this branch.
- **Status:** 🟡 Plan written.

## 1. Goal

Replace the plain PHP Import-Log admin page with a React page (matching the System Status page), showing all import activity grouped by run, and surface the current run's log inline on the modal's result screen after every import — success or failure.

## 2. Architecture (matches the existing System Status page exactly)

The backend is a **single SPA**: `src/js/backend.js` mounts `<App/>` (`src/js/backend/App.jsx`) on `#sd-edi-demo-import-container`, using `createHashRouter`. The System Status page is the `#/system_status_page` route rendering `AppServer`. So:

- **Add a hash route** `#/import_log` → **`AppLog`** in `App.jsx` (third route beside `/` and `/system_status_page`).
- Point the existing "Import Log" submenu at the SPA page + `#/import_log` (same mechanism the status menu uses), instead of the standalone PHP page.
- Data via `useSharedDataStore` → `GET /sd/edi/v1/import/log` (already exists), rendered with antd `Collapse` + `Timeline`.

No new JS bundle/entry, no new container div — one SPA, one more route.

## 3. Data model — `demo_slug` column

Grouping "by run" needs a demo name + pass/fail per session. Add one column:

- `ImportLogger`: add `demo_slug` (varchar(191), default '') to the `CREATE TABLE`; bump `DB_VERSION '1' → '2'` so `maybeInstall()` migrates via `dbDelta`.
- `log()/info()/success()/warning()/error()`: accept an optional `$demo_slug` and store it. Callers in `InstallDemo` + `Finalize` pass `$this->demoSlug`.
- Run **status** = any `error` entry in the session → `failed`, else if a terminal success entry exists → `success`, else `info`/in-progress. Run **label** = `demo_slug` + earliest `logged_at`.

Add an explicit terminal entry: `Finalize` logs `ImportLogger::success('Import finished.', ...)` so a run has a clear success marker.

## 4. REST — group by run

`RestEndpoints::importLog()` gains a `group` param:

- `?session_id=<id>` → flat entries for one run (used by the modal result screen). Unchanged shape + `demo_slug`.
- `?group=1` (page default) → `ImportLogger::getRuns($limit)` returns:
  ```
  [{ session_id, demo_slug, started_at, status, count, entries:[{logged_at, level, message}] }, …]
  ```
  newest run first. Keep `permission_callback => permission` (manage_options).

`ImportLogger::getRuns()` groups the flat rows by `session_id` in PHP (single query, group client-agnostic), derives status + label per group.

## 5. Frontend

- **`src/js/backend/AppLog.jsx`** (new) — mirrors `AppServer`: `Header` + loading `Skeleton` + `ErrorMessage`; antd `Collapse`, one panel per run (header: demo · time · status `Tag`), body an antd `Timeline` of level-colored entries. Empty state when no runs.
- **`useSharedDataStore`** — add `logData`, `fetchLogData(url)` slice (mirror `serverData`/`fetchServerData`).
- **`App.jsx`** — add the `/import_log` route + menu-highlight branch in `LayoutWithEffects`.
- **Result screen (`components/Modal/steps/Success.jsx`)** — when step 4 mounts, fire one `GET /import/log?session_id=<activeSessionId>`; render a collapsible "Import details (N)" block below the message + a "View full log ↗" link to `#/import_log`. Same for success and failure. `activeSessionId` already tracked in `ModalComponent`.

## 6. PHP wiring

- `Pages.php` — repoint the `sd-edi-import-log` submenu to the SPA page + `#/import_log` (match how status menu is wired), or keep the page slug but have its callback render the SPA container. Prefer the hash-route approach for parity with status.
- `Callbacks::renderImportLogPage` — remove (or leave as a no-op) once the route replaces it.
- Localize `logPageLink` / hash into `sdEdiAdminParams` for menu highlighting + the "View full log" link.

## 7. Fold in the two logger fixes (already flagged)
- `ImportLogger` get/prune: add `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` to the `phpcs:ignore` lines.
- Timezone: store `logged_at` as GMT (`current_time('mysql', true)`) so it matches `prune()`'s UTC cutoff; render with site offset client-side if needed.

## 8. Tasks
- A. PHP: `demo_slug` column + `DB_VERSION` bump + `getRuns()` + logger fixes; pass `demoSlug` at call sites; `Finalize` success entry.
- B. REST: `group` param + `getRuns` wiring.
- C. JS: store slice → `AppLog.jsx` → route + menu wiring.
- D. JS: Success.jsx inline log + "View full log" link.
- E. PHP: menu repoint + localize link; retire PHP render.
- F. Build (mix) + unit tests for `ImportLogger::getRuns` grouping/status derivation; run suite.

## 9. QA (manual, single-site)
| # | Case | Expect |
|---|------|--------|
| 1 | Fresh install, open Import Log | Empty state, no PHP notices |
| 2 | Run a successful import | Result screen shows inline log; admin page shows the run, green Success badge, entries in order |
| 3 | Force a failure (bad WXR) | Result screen shows inline log incl. the error; admin page badge = Failed |
| 4 | Multiple runs | Newest run on top; each expands independently |
| 5 | Pre-existing install (table at v1) | `maybeInstall()` adds `demo_slug`; no errors |
| 6 | Non-UTC site timezone | prune window correct; times display sanely |

## 10. Out of scope (deferred — per requirements)
Search/level filter, export/download, clear-log button, pagination.
