# Plan: Manual demo import (OCDI-style "import your own files")

- **Date:** 2026-07-12
- **Branch:** `manual-import` (off `regenerate-thumbnails`)
- **Status:** 🟢 **Phase A + most of B built & CI-green** (content + media + customizer
  + widgets). Remaining: framework/redux settings and chunked large-file upload.
  See §0 Progress.
- **Goal:** Let a user import **without** the theme providing demo config, by
  uploading their own files — mirroring One Click Demo Import's manual tab — while
  reusing this plugin's resumable/recovery machinery (chunked import, image
  regeneration, retry-failed-media, preflight, snapshot rollback).

---

## 0. Progress (2026-07-12)

**Built on branch `manual-import`** — `1ee56b1` (backend), `c945444` (frontend +
threading). Unit + integration CI green (PHP 7.4–8.4 unit; 7.4 + 8.3 real-WP
integration). 98 unit tests.

Delivered **more than the planned Phase A** — customizer + widgets came along for
free because they read fixed filenames (`customizer.dat`, `widget.wie`) and reuse
cleanly via the config stub:

- `inc/Common/Utils/ManualContext.php` — manual working-dir + config-stub resolver
  + hex-only key sanitiser (path-traversal-safe; unit-tested, incl. a traversal
  case). Reserved slug `__manual__`.
- `inc/App/Manual/ManualImport.php` — `wp_ajax_sd_edi_manual_upload`: `manage_options`
  + nonce + size cap + `is_uploaded_file`; validates content (WXR sniff), customizer
  (`.dat`), widgets (`.wie/.json` JSON-validated); stages to `manual-{key}/` as
  `content.xml` / `customizer.dat` / `widget.wie`; starts the session; returns
  `{manualKey, sessionId}`.
- `inc/Common/Abstracts/ImporterAjax.php` — manual branch: when `manual=true`, use
  `ManualContext::configStub()` instead of `getDemoConfig()` (bypasses the empty
  -config gate). `manual` + `manualKey` threaded through every phase response.
- `inc/App/Ajax/Backend/InstallDemo.php`, `src/js/backend/utils/Api.js` — round-trip
  `manual` / `manualKey` across phases.
- `src/js/backend/components/Modal/ManualImportModal.jsx` — upload form → upload →
  `doAxios(nextPhase='sd_edi_import_xml', manual=true)`, reusing the `Imports` and
  `Success` steps. Snapshot toggle default ON.
- `AppDemoImporter.jsx` — "Manual Import" button opens the modal.
- `Success.jsx` — forwards `manual`/`manualKey` so **retry-failed-media works for
  config-less manual imports**.

**Reuse confirmed working end-to-end via the config stub:** content → image
regeneration → customizer → widgets → finalize, with settings/sliders/fluent-forms
skipping. Inherits resumable import, retry-failed-media, and snapshot rollback.

**Security posture (verified):** `.dat` object-injection already blocked by
`unserialize(…, allowed_classes=false)` in the existing `Customizer` model; WXR
sniffed before import; manual key is hex-only.

**Still pending (see §6 Phase B/C):**
- Framework/redux-style theme **settings** files (config-driven — the stub can't
  know the option keys). Customizer covers the common case.
- **Chunked large-file upload** for big WXRs (single POST hits
  `upload_max_filesize`).
- **Manual QA:** no live WP here — needs a real upload → clone → retry → rollback
  pass. Multisite untested.

---

## 1. What it is

A "Manual Import" surface with up to three file uploads:

| Upload | Format | Imports |
|---|---|---|
| **Content** (required) | WXR `.xml` | posts, pages, products, media refs, terms, menus |
| **Widgets** (optional) | `.wie` / `.json` | widget assignments |
| **Customizer** (optional) | `.dat` (serialized PHP) | theme mods / customizer settings |

Then "Import." Unlike OCDI, our manual import inherits resumability (524-proof),
image regeneration, retry-failed-media, the preflight gate and snapshot rollback.

---

## 2. Why it's valuable

- Makes the plugin usable with **any** theme, even one shipping no demo config.
- Turns all of this session's resumable/recovery work into a general-purpose
  "import any WXR, safely" tool.
- Content-only MVP is independently shippable and covers ~80% of the value.

---

## 3. Challenges (must be designed around)

### 3.1 The pipeline is config/demo-keyed (architectural — the biggest one)
Every phase assumes a theme-configured demo:
- `inc/Common/Abstracts/ImporterAjax.php::handlePostSubmission()` **hard-blocks**
  when `sd_edi()->getDemoConfig()` is empty (`wp_send_json_error` "Demo
  configuration is missing"). Manual import has no config.
- `ImporterAjax::getDemoSlug()` validates the posted slug **against** config's
  `demoData`; `demoDir()` / `demoUploadDir()` derive the working dir from the
  config's `demoZip` basename.
- `inc/Config/Classes.php` registers `App\Ajax\Backend` only when `$_POST['demo']`
  is present (`onRequest => 'import'`).
- `InstallDemo::response()` reads `{demoUploadDir}/{demoDir}/content.xml`.

→ Need a **synthetic "manual" identity + working directory** that bypasses config
/slug/zip resolution, threaded through the phases the manual flow uses.

### 3.2 Security of user-uploaded files (the real risk — treat as Rule 0)
The predefined flow trusts theme-package files. Manual import takes untrusted
uploads:
- **Customizer `.dat` is serialized PHP** → object-injection / RCE-class risk.
  MUST `unserialize( $data, [ 'allowed_classes' => false ] )` and validate it is a
  flat map of scalars/arrays before applying. (This is why the MVP defers `.dat`.)
- **WXR `.xml`** → XML parsing exposes **XXE**. MUST disable external entities
  (`libxml_set_external_entity_loader` / avoid `LIBXML_NOENT`) and confirm the file
  is actually a WXR (`<wp:wxr_version>` present) before importing.
- **Widgets `.wie`/`.json`** → JSON; validate structure, reject on decode failure.
- Cross-cutting: `manage_options` capability, nonce, size caps, extension + content
  sniff (not just MIME), store under a non-executable uploads subdir, unique names.

### 3.3 Media / attachments
- Manual WXR references images on the **source site** → remote fetch only (no
  bundled-media package) → fully exposed to the Cloudflare/availability problem.
  **Retry-failed-media becomes essential**; surface it prominently.
- URL remap keys off the WXR's `base_url` (arbitrary for a manual file) — already
  handled by the importer, but worth confirming on odd inputs.

### 3.4 Requirements can't be pre-checked
No config means preflight can't list required plugins. Content for missing post
types/taxonomies (e.g. products with WooCommerce inactive) imports as orphaned /
skipped. The user owns this; surface a warning.

### 3.5 Large-file upload
WXR can be large and hits the exact `upload_max_filesize` / `post_max_size` limits
preflight flags. MVP: enforce + clearly message the limit; chunked upload is a
follow-up.

### 3.6 UX surface
New "Manual Import" tab in a demo-grid-centric SPA; upload inputs + progress; loud
"imports into your CURRENT site" warning paired with the snapshot toggle.

### 3.7 Customizer/widgets phase reuse
`CustomizerImport` / `ImportWidgets` are `$_POST`-driven and read the demo dir —
same synthetic-path treatment (the coupling that also blocks full `wp edi import`).

---

## 4. Design decisions

1. **Synthetic manual demo.** Introduce a reserved slug (e.g. `__manual__`) and a
   fixed working dir `{uploads}/easy-demo-importer/manual-{sessionHash}/`. A
   `ManualContext` helper resolves the working dir + a minimal in-memory "config"
   so the existing phases operate unchanged.
2. **Config gate bypass.** In `ImporterAjax::handlePostSubmission()`, when the
   request is flagged manual (`$_POST['manual'] === 'true'`), skip the empty-config
   error and the slug-against-config validation, and set a manual config stub
   (`multipleZip => false`, no `plugins`, `demoData` absent). `demoDir()` returns
   the manual dir.
3. **Separate upload endpoint, then reuse the pipeline.** A new REST or AJAX
   endpoint accepts the upload(s), validates + stages them as `content.xml`
   (and later `customizer.dat`, widget file) into the manual dir, returns a
   session id. The wizard then runs the **existing** `sd_edi_import_xml` →
   `…batch` → `…finalize` → `regenerate_images` phases against the manual dir.
4. **Snapshot on by default for manual.** Manual imports into a live site are the
   risky case — default the restore-point toggle ON for manual.
5. **MVP = content only.** Defer `.dat` (RCE hazard) and widgets until the content
   path is solid; this also sidesteps §3.7.

---

## 5. Reuse map

| Need | Reuse |
|---|---|
| Content import | `SD_EDI_WP_Import` / `ChunkedImport` (unchanged) |
| Resumability, 524-proofing | existing batch/finalize phases |
| Image regeneration | `ThumbnailRegenerator` + `sd_edi_regenerate_images` phase |
| Failed-media recovery | `FailedMedia` + retry endpoint (already built) |
| Restore point | `Snapshot` (already built) — default ON for manual |
| Preflight | `Preflight::report()` — extend to skip required-plugins when manual |
| Working-dir helpers | `demoUploadDir()`, `demoDir()` via the manual stub |

---

## 6. Implementation phases

### Phase A — Content-only MVP (recommended first, independently shippable)
1. `inc/Common/Utils/ManualContext.php` — reserved slug, manual working-dir path,
   minimal config stub, cleanup.
2. `inc/Common/Abstracts/ImporterAjax.php` — read a `manual` flag; when set, bypass
   the empty-config guard + slug validation and use the manual stub/dir.
3. **Upload handler** (`inc/App/Ajax/Backend/ManualUpload.php` or a REST endpoint):
   - `manage_options` + nonce + size cap.
   - Validate the WXR: extension `.xml`, sniff for `<?xml` + `<wp:wxr_version`,
     harden libxml against XXE.
   - Stage as `{manualDir}/content.xml`; start a session; return session id.
4. Wire the wizard's manual flow to kick off `sd_edi_import_xml` with the manual
   flag + session (reuse `doAxios`).
5. UI: "Manual Import" tab/page with a single WXR upload, the snapshot toggle
   (default ON), exclude-images / skip-regeneration options, and a loud warning.
6. Tests: `ManualContext` path/stub logic (unit); WXR validation (unit, pure).

### Phase B — Widgets + Customizer (after A is solid)
7. Extend the upload handler for `.wie`/`.json` (widgets) and `.dat` (customizer)
   with the §3.2 hardening (JSON validate; **`allowed_classes => false`** + shape
   validation for `.dat`).
8. Run the existing `ImportWidgets` / `CustomizerImport` phases against the manual
   dir (requires the §3.7 synthetic-path treatment — the same decoupling needed
   for `wp edi import`).

### Phase C — Polish
9. Chunked/large-file upload for big WXRs.
10. `wp edi import --content=<file>` CLI once the phases are decoupled (ties into
    the deferred CLI import).

---

## 7. Security checklist (gate before shipping any upload)
- [ ] `manage_options` + nonce on every upload/import request.
- [ ] Size cap (configurable) + clear over-limit message.
- [ ] WXR: extension + content sniff; libxml external entities disabled; confirm
      `wp:wxr_version` before import.
- [ ] Widgets: JSON decode with error handling; structure validation.
- [ ] Customizer `.dat`: `unserialize(…, ['allowed_classes' => false])` + validate
      it's a flat scalar/array map; never apply raw.
- [ ] Uploads stored under `{uploads}/easy-demo-importer/manual-*/` (non-exec),
      unique names, cleaned up after import / on cancel.
- [ ] Reject path traversal in any staged filename.

## 8. Testing
- Unit: `ManualContext` (paths, stub, cleanup), WXR validator (valid / not-WXR /
  malformed / XXE payload rejected), `.dat` validator (allowed_classes, shape).
- Integration (real WP): upload a small WXR fixture → run the manual pipeline →
  assert posts imported + working dir cleaned. (The existing
  `ChunkedImportIntegrationTest` fixture `sample.wxr` can seed this.)
- Manual QA: large WXR near the size limit; a WXR whose images 404 → confirm
  retry-failed-media recovers; snapshot rollback of a manual import.

## 9. Open questions
- Reserved manual slug + whether to allow multiple concurrent manual sessions.
- Where the "Manual Import" lives — a tab on the importer page vs a Tools submenu.
- Whether to also accept a bundled `uploads/` media zip for manual imports
  (Cloudflare-proof media, reuses the bundled-media resolver) — strong follow-up.
- Default state of the reset-DB option for manual (probably OFF; snapshot ON).

## 10. Recommendation
Build **Phase A (content-only)** first: highest value, most reuse, and it avoids
the serialized-`.dat` RCE hazard and the customizer/widgets coupling until those
are hardened/decoupled deliberately.
