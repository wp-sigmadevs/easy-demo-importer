# DEV-LOG

Running log of architectural decisions, non-obvious context, and rationale.
Most recent entries at the top.

## 2026-07-23 — Streamed demo download + HTTP Range resume

**What:** `DownloadFiles::downloadDemoFiles()` now streams the demo archive to disk (`'stream' => true`) instead of buffering the whole zip with `wp_remote_retrieve_body()`, and resumes an interrupted download via a `.part` accumulator + `Range: bytes=N-` header rather than restarting from zero. On `fix/stream-demo-download`, two commits (`4898333`, `344a384`).

**Why:** The old path loaded the entire archive into a PHP string before writing it, so a large WooCommerce demo could exceed the host `memory_limit` and fatal before a single post was imported — a real crash on low-memory shared hosts, not a theoretical one. This is deliberately a free-core bug fix, not a Pro feature: the plugin already solved import timeouts architecturally via chunking, and gating a memory-crash fix would be indefensible.

**Non-obvious context:**
- `wp_remote_get()`'s stream target is opened **truncating**, not appending, so resume can't stream directly onto the `.part` file. A resume streams the ranged response into a side `.chunk`, then appends it stream-to-stream via `stream_copy_to_stream()`. `WP_Filesystem::get_contents()` was avoided in the append because it would re-buffer the whole chunk in memory, defeating the entire point of streaming.
- Completeness is inferred from a clean 2xx: one request fetches all remaining bytes, so a clean 206/200 = done → promote `.part` → `demoData` → unzip. A mid-stream drop returns `WP_Error`, which now **preserves** the partial (folding in any side chunk) so the next retry continues. There is intentionally no partial-progress protocol back to the JS client — adding one would mean touching `response()` and the wizard, out of scope for a crash fix.
- Branch response codes handled explicitly: 206 (append), 200-with-offset (server ignored Range → chunk is authoritative, replaces stale part), 416 (part already complete), other non-2xx (discard both, clean restart).

**Known follow-ups (not done):** a permanently-failing download leaves one `.part`/`.chunk` in the demo upload dir with no sweeper (unlike `ManualImport`'s cleanup cron); `appendChunk`'s `@since 2.1.0` assumes the next release version.

**Rejected:** (1) Selling this as a Pro "bulletproof import" feature — rejected, it's a bug fix. (2) A Pro feature that raises `max_execution_time`/`upload_max_filesize` at runtime — rejected as non-functional: those are `PHP_INI_PERDIR` (ini_set no-op) and FPM/nginx gateway timeouts are unreachable from PHP. (3) Appending via `WP_Filesystem::get_contents()` — rejected for re-buffering the chunk into memory.
