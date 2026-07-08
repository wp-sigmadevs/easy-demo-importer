# Plan: Resumable / Chunked WXR Import

- **Date:** 2026-07-08
- **Branch:** `wxr-chunking` (branched from `master`, **independent of `multi-site`**)
- **Target release:** v1.1.7 — ships without multisite. Multisite (`multi-site`, v1.2.0) is a separate track; nothing here depends on it.
- **Status:** 🟡 Plan written, no code yet.

---

## 1. Problem

WooCommerce demo imports fail with **HTTP 524** (Cloudflare's fixed 100 s edge timeout) and **503** (origin PHP-FPM / nginx worker kill), *even when `max_execution_time` is raised* — because those are **wall-clock gateway limits**, not PHP limits.

Root cause: the content-import phase runs the **entire WXR in one synchronous AJAX request**.

- `inc/App/Ajax/Backend/InstallDemo.php:215` → `$wp_import->import( $xmlFilePath )` imports every post, product, term, comment, and (unless excluded) downloads every attachment in **one pass**.
- The bundled importer (`lib/wordpress-importer/class-wp-import.php`) is **not resumable** — no cursor, no batch boundary.
- WooCommerce latest increased per-item cost (HPOS sync, product/attribute lookup tables), pushing a WC demo that used to *just* fit under 100 s over the edge.

Options #1–#4 from the diagnosis (bypass Cloudflare, raise FPM timeouts, cut per-item WC cost, exclude images) only **raise the ceiling**. This plan is the **permanent fix (#5)**: make the import immune to any gateway wall-clock limit by processing it in resumable, time-boxed chunks.

---

## 2. Goal & Non-Goals

**Goal:** No single import HTTP request exceeds a safe wall-clock budget (~45–60 s), regardless of demo size or per-item cost. Total import time becomes irrelevant to gateways.

**Non-goals (this release):**
- Multisite / per-blog anything — out of scope, lives on `multi-site`.
- Streaming XML parser (constant-memory parse) — deferred; parse-once-and-persist is enough for v1.1.7.
- The WC per-item cost reduction (#3) — complementary but shipped separately so this branch stays focused. Noted in §9.

---

## 3. Why it's hard: cross-request importer state

The single hard problem is that `SD_EDI_WP_Import` holds **in-memory state that spans the whole import** and is lost between stateless AJAX requests:

| State (protected prop) | Purpose | Must survive across chunks? |
|---|---|---|
| `$this->posts` / `terms` / `categories` / `tags` / authors | parsed WXR data | Yes (or re-parse each chunk — too slow) |
| `$this->processed_posts` (old→new ID) | ID remap for parents/menus/featured images | **Yes** |
| `$this->processed_menu_items`, `menu_item_orphans` | menu hierarchy backfill | **Yes** |
| `$this->post_orphans` | child→parent backfill | **Yes** |
| `$this->url_remap` | in-content URL rewrite | **Yes** |
| `$this->featured_images` | `_thumbnail_id` remap | **Yes** |
| `$this->processed_terms` / `author_mapping` | term & author remap | **Yes** |

`import()` (line 187) runs: `import_start` (parse) → `process_categories`/`tags`/`terms` → `process_posts` (the dominant loop, incl. `process_attachment`/`fetch_remote_file`) → `import_end` (`backfill_parents`, `backfill_attachment_urls`, `remap_featured_images`). `import_end` **requires all posts imported first**.

The plugin's own `getNewID`/`createEntry` ID table (`wp_sd_edi_taxonomy_import`) is separate and does **not** replace the importer's internal maps.

---

## 4. Design

### 4.1 Split `import()` into three resumable stages

New subclass `SD_EDI_WP_Import_Chunked extends SD_EDI_WP_Import` (in `lib/wordpress-importer/` or `inc/Common/Importer/`) that exposes the parent's `import()` as:

1. **`prepare($file)`** — run once.
   - `import_start($file)` (parse WXR into memory).
   - Import the cheap, foundational data that everything else depends on: authors, `process_categories`, `process_tags`, `process_terms`.
   - Serialize the parsed `$this->posts` array + the state table (§3) to a **session store** (see 4.2).
   - Return `total` = count of posts to process.

2. **`processBatch($offset, $timeBudget)`** — run repeatedly.
   - Load state from the store; hydrate protected props.
   - Loop `process_posts` **one post at a time starting at `$offset`**, until either the time budget (`microtime` elapsed ≥ budget) or a max batch count is hit.
   - Merge updated maps back; persist state; return `{processed, total, nextOffset, done}`.

3. **`finalize()`** — run once at the end.
   - Load state; run `backfill_parents()`, `backfill_attachment_urls()`, `remap_featured_images()`, then `import_end()` tail (defer-counting off, cache invalidation on, `do_action('import_end')`).
   - Delete the session store.

`process_posts` currently loops `foreach ( $this->posts as $post )` (line 597). Refactor to accept an offset/limit (or iterate a slice) without altering per-post logic — minimize divergence from the vendored importer so future upstream syncs stay tractable.

### 4.2 Session store

Persist per-import state in a **single serialized blob** keyed by the existing `$this->sessionId`:

- Location: `{demo upload dir}/.sd-edi-import-state.json` (already have `demoUploadDir()`), **or** a dedicated `autoload='no'` option `sd_edi_import_state_{sessionId}`. Prefer the file — parsed post arrays can be large and don't belong in `wp_options`.
- Contents: parsed `posts`, all §3 maps, `offset`, `total`, `flags` (excludeImages, skipImageRegeneration).
- Written at end of `prepare` and each `processBatch`; deleted in `finalize` and on `cancelSession`.
- Guarded by the **existing** `sd_edi_xml_import_mutex` (InstallDemo.php:83) + 30-min stale-lock release + `register_shutdown_function` release — reuse as-is.

### 4.3 Time-boxing, not count-boxing

Attachment downloads dominate and vary 100×. Use an **elapsed-time budget** per request:

```
$budget = apply_filters( 'sd/edi/import_chunk_seconds', 45 );
```

45 s leaves generous headroom under Cloudflare's 100 s and typical FPM `request_terminate_timeout`. Stop the batch loop when `microtime(true) - $start >= $budget`, always finishing the current post so no partial-post corruption.

### 4.4 AJAX orchestration — new phase chain

Replace the single `sd_edi_import_xml` body with a chain that reuses the existing `nextPhase` / `retry` / `retryAfter` response protocol (already consumed by `src/js/backend/utils/Api.js` and `ModalComponent.jsx`):

| Phase (AJAX action) | Does | Returns |
|---|---|---|
| `sd_edi_import_xml` | acquire mutex → `prepare()` | `nextPhase: sd_edi_import_xml_batch`, `offset:0`, `total:N`, progress msg |
| `sd_edi_import_xml_batch` | `processBatch(offset, 45s)` | if not done → `retry:true`/`nextPhase: sd_edi_import_xml_batch`, `offset:next`, `progress:{processed,total}`; if done → `nextPhase: sd_edi_import_xml_finalize` |
| `sd_edi_import_xml_finalize` | `finalize()` → cleanup | `nextPhase: sd_edi_import_customizer` (existing next step) |

`offset`/`total` are echoed back by the client each call (same way `demo`/`sessionId` already round-trip). Server trusts the store's own offset as source of truth; client `offset` is advisory for display.

### 4.5 Frontend (React wizard)

- `Api.js` / `ModalComponent.jsx` already loop on `nextPhase` and honor `retryAfter`. Extend to:
  - Carry `offset` forward across `sd_edi_import_xml_batch` calls.
  - Render a **real progress bar** from `progress.processed / progress.total` instead of an indeterminate spinner.
  - On network error / 524 / 503 mid-batch: **wait `retryAfter` and re-issue the same batch** (server is idempotent via cursor + `post_exists` GUID skip + mutex). This converts a gateway timeout from a hard failure into an automatic resume.

### 4.6 Backward-compat & fallback

- Filter `sd/edi/enable_chunked_import` (default `true`). When `false`, or when `prepare()` throws, fall back to the **current single-shot** `$wp_import->import()` path — zero behavior change for small demos / non-proxied hosts.
- Keep `excludeImages` and `skipImageRegeneration` honored identically inside `processBatch`.

---

## 5. Stages & Tasks

### Stage A — Resumable importer core (PHP)
- A1. Add `SD_EDI_WP_Import_Chunked` subclass with `prepare()`, `processBatch()`, `finalize()`.
- A2. Refactor `process_posts()` to support offset/limit slicing without changing per-post logic.
- A3. State store: serialize/hydrate all §3 maps + parsed posts; read/write/delete helpers.
- A4. Time-box loop with `sd/edi/import_chunk_seconds` filter.

### Stage B — AJAX orchestration (PHP)
- B1. Split `InstallDemo::response()` into `prepare` phase; add `importBatch()` + `finalize()` handlers.
- B2. Register `wp_ajax_sd_edi_import_xml_batch` and `wp_ajax_sd_edi_import_xml_finalize`.
- B3. Wire cursor + progress into the existing response protocol; reuse mutex/shutdown release.
- B4. `cancelSession` + stale-lock path must also delete the session store.

### Stage C — Frontend (React)
- C1. Thread `offset`/`total` through the phase loop in `Api.js`/`ModalComponent.jsx`.
- C2. Determinate progress bar from `progress.processed/total`.
- C3. Auto-resume on 524/503/network error (re-issue current batch after `retryAfter`).

### Stage D — Safety & QA
- D1. `sd/edi/enable_chunked_import` fallback flag + single-shot fallback on `prepare()` failure.
- D2. Bump `easy-demo-importer.php` 1.1.6 → **1.1.7**.
- D3. QA matrix (§7).

---

## 6. Files

**New**
- `inc/Common/Importer/ChunkedImport.php` (or `lib/wordpress-importer/class-wp-import-chunked.php`) — the subclass.
- `inc/Common/Importer/ImportState.php` — session-store read/write/delete.

**Modified (PHP)**
- `inc/App/Ajax/Backend/InstallDemo.php` — phase split, batch/finalize handlers, store cleanup.
- `lib/wordpress-importer/class-wp-import.php` — `process_posts()` offset/limit refactor (kept minimal; document the divergence for future upstream syncs).
- `easy-demo-importer.php` — version bump.

**Modified (JS)**
- `src/js/backend/utils/Api.js` — cursor threading, resume-on-timeout.
- `src/js/backend/components/Modal/ModalComponent.jsx` — determinate progress + resume UX.

---

## 7. QA matrix (single-site only — no multisite here)

| # | Scenario | Expect |
|---|---|---|
| 1 | Large WooCommerce demo (300+ products, images on) behind Cloudflare | Completes; no 524/503; progress bar advances; each request < 60 s |
| 2 | Same demo, `excludeImages` on | Completes faster; no attachment downloads |
| 3 | Same demo, `skipImageRegeneration` on | Completes; no intermediate sizes |
| 4 | Kill a batch mid-way (simulate 524: close tab / drop connection), reopen, resume | Resumes at cursor; no duplicated posts (GUID skip + mutex); menus/parents/featured images correct at end |
| 5 | `sd/edi/enable_chunked_import` = false | Falls back to single-shot; unchanged behavior |
| 6 | Small demo (few posts) | One or two batches; no regression vs current |
| 7 | Parent/child posts + nav menus + featured images span a batch boundary | `finalize()` backfills correctly (parents, menu hierarchy, `_thumbnail_id`) |
| 8 | Reset + re-import (existing `reset` flow) | Clean slate; `clearNavMenus` still runs; store recreated |
| 9 | Cancel mid-import (`sd_edi_cancel_session`) | Store + mutex deleted; no orphan state |

*(No PHPUnit harness in this codebase — QA is manual per project convention.)*

---

## 8. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Cross-batch state corruption (parents/menus/featured images) | Import all terms/authors/categories in `prepare`; process posts in file order; defer ALL backfill/remap to `finalize`. QA #7 targets this. |
| Serialized `posts` blob is huge (memory/disk) on giant demos | Store on disk (not `wp_options`); v2 candidate = streaming parser to avoid holding all posts. |
| Divergence from vendored WP importer complicates future syncs | Keep `process_posts` change to offset/limit only; document the diff; subclass everything else. |
| `wp_defer_term_counting`/`comment_counting` are per-request | Set defer in each batch; recount once in `finalize`. |
| Idempotency on resumed batch | Rely on importer's `post_exists()` GUID skip + persisted cursor + mutex; QA #4. |
| Client doesn't resume on 524 | Stage C3 auto-resume; server is safe to re-hit the same batch. |

---

## 9. Complementary (separate PRs, not this branch)
- **#3 WC per-item cost reduction:** in `sd/edi/before_import`, suspend HPOS sync, WC lookup-table regeneration, and term/comment counting; restore in `sd/edi/after_import`. Multiplies the benefit of chunking on WooCommerce demos. Ship as its own small PR.
- **Ops guidance** (docs): Cloudflare page-rule / FPM `request_terminate_timeout` notes for self-hosters.

---

## 10. Release

1. Land Stages A–D on `wxr-chunking`.
2. Run §7 QA matrix on a WooCommerce demo behind Cloudflare.
3. Merge to `master`, tag **v1.1.7**. Independent of `multi-site` — can ship before it.
4. Rebase `multi-site` on the new `master` afterward (multisite's InstallDemo already shares the mutex scaffolding, so conflict surface is small).
