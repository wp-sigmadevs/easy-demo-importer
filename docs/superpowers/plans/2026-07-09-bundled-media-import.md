# Plan: Bundled-media import (Cloudflare-proof, faster attachments)

- **Date:** 2026-07-09
- **Branch:** `wxr-chunking` (or a fresh branch off it)
- **Status:** 🟡 Plan written.
- **Related:** attachment fetch timeout already capped at 25s (`sd/edi/importer/attachment_timeout`) so remote fetches fail fast; this plan removes the remote fetch entirely when media is bundled.

## 1. Problem & goal

Remote attachment downloads fail whenever the demo-image host sits behind a Cloudflare (or similar) bot challenge — no server-side User-Agent/header trick beats a managed challenge (confirmed: OCDI doesn't either). Remote fetching is also the single biggest chunk of import wall-clock.

**Goal:** let a theme author ship the demo's media *inside the demo package* and have the importer install those files **from local disk** instead of fetching them over HTTP — making image import Cloudflare-proof and dramatically faster, while gracefully falling back to remote fetch for any file that isn't bundled.

## 2. Package format

The existing demo zip already extracts into `{demoUploadDir}/{demoDir}/` (via `DownloadFiles::downloadDemoFiles()` → `unzip_file()`). Add one optional folder to that zip:

```
demo.zip
├── content.xml
├── customizer.dat
├── …
└── uploads/                ← NEW (standard WP uploads layout)
    ├── 2024/01/hero.jpg
    ├── 2024/01/hero-300x200.jpg   (optional; see §4.3)
    └── 2024/02/…
```

- Auto-detected: if `{demoDir}/uploads/` exists after extraction, bundled-media mode is on. No config change required.
- Optional explicit `bundledMedia` key in the demo config (`demoData[slug]['bundledMedia'] => true/false`) to force-enable/disable, default auto.

## 3. Resolution: WXR attachment URL → local bundled file

Each WXR attachment carries a URL (e.g. `https://source.test/wp-content/uploads/2024/01/hero.jpg`) and `$this->base_url`. To find the bundled file:

1. **Primary:** strip `$this->base_url` (and a trailing `/wp-content/uploads` or `/uploads` segment) from the URL to get the uploads-relative path `2024/01/hero.jpg`; look up `{bundledDir}/2024/01/hero.jpg`.
2. **Fallback:** if the URL host differs from `base_url` (WXRs sometimes hardcode a CDN host), match on the `uploads/(\d{4}/\d{2}/.+)$` tail of the URL path.
3. **Last resort:** match by basename within `{bundledDir}` (guarded — only if unique) — off by default, filterable.

Resolver returns an absolute local path or `null`. Everything filterable via `sd/edi/importer/bundled_media_path` (`$path`, `$url`, `$post`).

## 4. Interception point

### 4.1 `fetch_remote_file()`
Add a short-circuit at the very top of `SD_EDI_WP_Import::fetch_remote_file( $url, $post )`:

```
$local = $this->resolve_bundled_media( $url );      // null when not bundled / disabled
if ( $local ) {
    return $this->import_local_file( $local, $post ); // copy into uploads, return the same
                                                      // shape wp_upload_bits() would
}
// …existing remote-fetch path (now with the 25s timeout) as fallback…
```

`import_local_file()` mirrors the success contract the caller expects (a `['file','url','type']`-style array like the remote path builds), by copying the bundled file into WP's uploads dir via `wp_upload_bits()` / `WP_Filesystem->copy()` and running `wp_check_filetype`. No HTTP, no timeout, no Cloudflare.

Keep the change minimal and documented — same discipline as the existing UA divergence, for future upstream syncs.

### 4.2 Where the bundled dir comes from (chunked-safe)
`ChunkedImport` needs to know `{bundledDir}` across batches:
- Add a public `$bundled_media_dir = ''` property to `SD_EDI_WP_Import`, add it to `ChunkedImport::STATE_PROPS` so it persists/hydrates across batch requests.
- `InstallDemo::response()` (prepare stage) sets `$importer->bundled_media_dir = {demoDir}/uploads` when that folder exists (and bundled mode isn't disabled), before `prepare()`.

### 4.3 Intermediate image sizes — a dedicated, resumable regeneration phase

Today thumbnails are generated **inline** during attachment import (WP's normal `wp_generate_attachment_metadata` on each attachment). That is the wrong place for a chunked importer: it makes every attachment far heavier, competes with the tight batch budget, and — for bundled originals — has to run anyway. Move it out into its **own pipeline phase after content import**, built on the same resumable machinery as the WXR batch loop.

**During content import:** *always* suppress inline size generation (the existing
`intermediate_image_sizes_advanced` / `wp_generate_attachment_metadata` → `__return_empty_array`
filters), so attachments land as **originals only** — fast, keeps batches well under the gateway
limit. This applies whether media is bundled or remote.

**New phase `sd_edi_regenerate_images`** — inserted between content import and customizer:

| Phase | Does |
|---|---|
| `sd_edi_import_xml_finalize` | ends content import (originals only) |
| **`sd_edi_regenerate_images`** (new) | time-boxed, resumable thumbnail generation |
| `sd_edi_import_customizer` | (unchanged) |

- Operates only on the **attachments imported this run** — collect their new IDs during content
  import (the importer already maps attachment post IDs in `processed_posts`) and persist the list,
  so a site's pre-existing media is never touched.
- Processes them in slices with a cursor (last processed ID), time-boxed (~40s), persisted via the
  existing `ImportState`; re-fires through the same `retry` / `progress:{processed,total}` protocol
  as the batch phase → determinate progress bar, auto-resume on 524.
- Per attachment: `wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, get_attached_file( $id ) ) )`.
- Logs a summary via `ImportLogger` ("regenerated N of M attachments").

**`skipImageRegeneration`** becomes "skip this phase" — the pipeline jumps straight to customizer,
leaving originals only. Clean, explicit, and no inline cost either way.

**Bundle-all (optional):** if an author ships the `-WxH` variants too, the regen phase can register
the existing files instead of regenerating. Deferred follow-up; the default is bundle-originals +
regenerate.

## 5. Interaction with existing options
- `excludeImages`: still honored — when images are excluded, neither remote nor bundled files are imported.
- `skipImageRegeneration`: unchanged (applies to bundled originals too).
- Activity log: log a per-run summary — "N media imported from bundle, M fetched remotely, K failed" (uses the new `ImportLogger`).
- Timeout fix (25s) remains the fallback safety net for non-bundled files.

## 6. Files
**Modified**
- `lib/wordpress-importer/class-wp-import.php` — `fetch_remote_file()` short-circuit; `$bundled_media_dir` property; `resolve_bundled_media()` + `import_local_file()` helpers.
- `inc/Common/Importer/ChunkedImport.php` — add `bundled_media_dir` to `STATE_PROPS`.
- `inc/App/Ajax/Backend/InstallDemo.php` — detect `{demoDir}/uploads`, set on the importer, log the mode.
- (optional) `inc/Common/Functions/Helpers.php` — a `bundledMedia` config accessor.

**No new REST/JS** — this is import-engine only; the log page + result screen already surface the outcome.

## 7. Tasks
- A. Resolver + local-import helpers in the importer (pure-ish, unit-testable on path mapping).
- B. `fetch_remote_file` short-circuit + `$bundled_media_dir` property + `STATE_PROPS`.
- C. `InstallDemo` detection/wiring + activity-log summary. Suppress inline size generation during content import (originals only, always).
- D. **New `sd_edi_regenerate_images` AJAX phase** (`inc/App/Ajax/Backend/RegenerateImages.php`): collect imported attachment IDs during content import + persist; resumable time-boxed regen loop reusing `ImportState` + the retry/progress protocol; wire it between `import_xml_finalize` and `import_customizer`; honor `skipImageRegeneration` (skip phase).
- E. Unit tests: `resolve_bundled_media()` URL→path mapping (primary/fallback/miss); regen cursor/slice logic (pure part).
- F. Docs: demo-package format note for theme authors.

**Note:** Task D (dedicated regeneration phase) is valuable independently of bundling — it lightens the content-import batches for *every* import, bundled or remote. It can ship first, on its own.

## 8. QA (manual)
| # | Case | Expect |
|---|------|--------|
| 1 | Demo zip with `uploads/`, images on | All images imported from disk; **zero** outbound image requests; fast |
| 2 | Same demo behind a Cloudflare-challenged source | Still succeeds (no remote fetch happens) |
| 3 | Partial bundle (some files missing) | Missing ones fall back to remote fetch (25s cap); rest local |
| 4 | No `uploads/` in zip | Behaves exactly as today (remote fetch) |
| 5 | `excludeImages` on | No media imported, bundled or remote |
| 6 | URL host ≠ base_url (CDN) | Fallback tail-match resolves the bundled file |
| 7 | Large WC demo | Import completes well under gateway limits; thumbnails present |

## 9. Out of scope (later)
- Bundling pre-generated thumbnail sizes (bundle-all mode) beyond the trivial case.
- A packaging tool/CLI to build the `uploads/` folder from a source site.
- Deduping identical media across multi-demo packages.
