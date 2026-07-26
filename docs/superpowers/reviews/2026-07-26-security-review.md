# Security Review — Easy Demo Importer

- **Date:** 2026-07-26
- **Scope:** Full plugin (whole-codebase), anchored to OWASP Top 10
- **Reviewed:** 55 PHP files under `inc/`, root PHP (`easy-demo-importer.php`, `uninstall.php`), the bundled WXR importer (`lib/wordpress-importer/class-wp-import.php`), the React admin bundle (`src/js`), and dependencies (Composer + npm).
- **Method:** Four parallel security agents by attack surface (access control, file/archive/SSRF, SQL/destructive-DB, XSS/SVG/secrets/misconfig); every candidate finding was re-verified by hand against the source before inclusion.
- **Version at review:** `2.0.1` (per plugin header / `package.json`).

## Verdict

**0 critical · 0 high · 1 medium · 5 low · 3 info.** No unauthenticated attack surface — every mutating or data-exposing entry point requires `manage_options` plus a valid nonce. **Safe to ship; schedule the fixes below**, prioritising the Medium SSRF hardening.

---

## Verified positives (calibration)

These were checked end-to-end and are **clean** — recorded so future changes don't regress them.

- **A01/A07 Access control — clean.** All 22 `wp_ajax_*` handlers and all 8 REST routes are double-gated:
  - Central AJAX gate `Helpers::verifyAjaxCall()` (`inc/Common/Functions/Helpers.php:84-104`) runs `check_ajax_referer('sd_edi_nonce_secret','sd_edi_nonce',false)` **then** `verifyUserRole()` → `current_user_can('manage_options')` (`Helpers.php:112-123`), each failing with `wp_send_json_error(...,403)` + `wp_die()`.
  - REST: every `register_rest_route` in `inc/App/Rest/RestEndpoints.php` sets `'permission_callback' => [$this,'permission']`, and `permission()` (`:437-449`) returns `current_user_can('manage_options')`. The two most sensitive — POST `/rollback` (`:171`, restores DB tables) and POST `/discard-restore-point` (`:207`, drops tables) — are gated.
  - Self-registered classes gate independently (they don't lean on the `$_POST['demo']` registration gate): `RegenerateThumbnails::handle()` (nonce `:90` + cap `:94`) and `ManualImport::handleUpload()` (nonce `:172` + cap `:176` + running-import lock `:182`).
  - No `wp_ajax_nopriv_*`, no `__return_true` permission callbacks anywhere.
- **A03 SQL — clean.** No request value is interpolated into SQL as data or identifier. Table names are trusted `$wpdb->prefix` + constants or `SHOW TABLES`/`SHOW TABLE STATUS` names re-scoped to the install prefix; values are bound via `prepare`, cast with `intval`/`absint`, or `esc_sql`'d. Destructive `TRUNCATE`/`DROP` (`Initialize.php:294-405`, `Snapshot.php:283-402`) target fixed prefix-scoped tables, explicitly excluding `options/users/usermeta/log/snapshot`, and are unreachable without the auth gate.
- **A08 Deserialization — clean.** Every `unserialize` uses `['allowed_classes' => false]`: `ImportState.php:222`, `DBSearchReplace.php:459`, `Customizer.php:52,61`. Widgets/Settings importers use `json_decode` (no PHP deserialization).
- **A02 Crypto — clean.** Import session IDs use `wp_generate_uuid4()` (`SessionManager.php:53`, CSPRNG-backed). All `md5()`/`uniqid()` uses are filename/option-key/log-group derivations, not security tokens. No hardcoded secrets in `inc/`, `src/`, or root PHP.
- **SVG sanitization — applied on all import paths.** Verified: upload prefilter (`Filters::sanitizeSVG`, `inc/Common/Functions/Filters.php:58`), WXR remote media (`fetch_remote_file`, `lib/wordpress-importer/class-wp-import.php:1539`), and bundled/manual media (`import_local_file`, `:1660`) all run `Filters::sanitizeSvgFile()` and `@unlink` the file on failure before it becomes an attachment.
- **Dependencies (PHP):** `composer audit` — no advisories. Only runtime dep is `enshrined/svg-sanitize ^0.22`.

---

## Findings

### MEDIUM — A10 SSRF: remote demo download not SSRF-hardened

- **File:** `inc/App/Ajax/Backend/DownloadFiles.php:168`
- **Evidence:**
  ```php
  $response = wp_remote_get(
      $external_url,
      [ 'timeout' => $timeout, 'sslverify' => $sslverify, 'stream' => true, 'filename' => $demoData ]
  );
  ```
- **Issue:** Uses `wp_remote_get`, not the SSRF-hardened `wp_safe_remote_get`. Pre-request validation is present — `wp_http_validate_url()` (`:121`), an http/https scheme allowlist (`:130`), and an *optional, empty-by-default* domain allowlist via `sd/edi/allowed_download_domains` (`:140`) — but two gaps remain:
  1. **Redirects are not re-validated.** `wp_remote_get` follows up to 5 redirects by default; the redirect *target* is never re-checked. A demo host issuing a `302` to an internal service or `http://169.254.169.254/…` is followed.
  2. **Link-local not blocked.** `wp_http_validate_url()` rejects loopback and RFC-1918 (10/8, 172.16/12, 192.168/16) but **not** `169.254.0.0/16` — the AWS/GCP/Azure metadata range — so even the first hop can reach cloud metadata.
- **Reachability / severity:** `$external_url` comes from the theme's `sd/edi/importer/config` filter (server-side, developer-supplied), and the action requires `manage_options`. So this is redirect-based SSRF via a malicious/compromised/open-redirecting demo host, not a direct low-privilege attack — **Medium**, not High.
- **Fix:**
  ```php
  $response = wp_safe_remote_get(
      $external_url,
      [
          'timeout'            => $timeout,
          'sslverify'          => $sslverify,
          'stream'             => true,
          'filename'           => $demoData,
          'reject_unsafe_urls' => true, // re-validates each redirect hop
          'redirection'        => 0,    // or a small N if demo CDNs need redirects
      ]
  );
  ```
  Additionally reject `169.254.0.0/16` explicitly before the request (resolve the host and compare), since neither `wp_http_validate_url()` nor `wp_safe_remote_get()` blocks link-local by default.

---

### LOW — A01 ZipSlip: user-uploaded ZIP extraction lacks realpath confinement

- **File:** `inc/App/Manual/ManualImport.php:559` (`unzip()`), used by `routeBundle()` (`:354`), `expandSettingsZip()` (`:427`), `extractImages()` (`:503`).
- **Evidence:**
  ```php
  private function unzip( string $zip, string $dest ) {
      require_once ABSPATH . 'wp-admin/includes/file.php';
      $this->fs();
      return unzip_file( $zip, $dest ); // WP core — no post-extraction path check
  }
  ```
- **Issue:** This handles the **user-uploaded** bundle/images/settings ZIPs and relies entirely on WP core `unzip_file()` for ZipSlip safety. The sibling remote-download path already does a post-extraction `realpath()` sweep and rejects anything escaping the target dir (`DownloadFiles.php:263-286`) — the higher-trust-risk user-upload path is the one **missing** that guard.
- **Mitigation:** Requires `manage_options` (`:176`) + `is_uploaded_file` (`:249`) + a 512 MB cap (`:244,263`); modern WP core `unzip_file()` also rejects `../` internally. Defense-in-depth gap, not a proven exploit — but the plugin should not depend solely on core, especially when its other path doesn't.
- **Fix:** Extract the DownloadFiles realpath sweep into a shared helper and run it after every `unzip_file()` in ManualImport:
  ```php
  private function assertExtractedWithin( string $dir ) {
      $real = realpath( $dir );
      if ( false === $real ) { return; }
      $it = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
          RecursiveIteratorIterator::SELF_FIRST
      );
      foreach ( $it as $item ) {
          $rp = realpath( $item->getPathname() );
          if ( false !== $rp && 0 !== strpos( $rp, $real ) ) {
              $this->fs()->delete( $dir, true );
              throw new \RuntimeException( 'Archive contained unsafe file paths.' );
          }
      }
  }
  ```

---

### LOW — A03 Stored-XSS window: raw SVG kept in web-reachable staging

- **File:** `inc/App/Manual/ManualImport.php:88` (`svg` in `imageExtensions()`), `pruneNonMedia()` (`:523`).
- **Issue:** SVGs from `images.zip`/bundle are kept **raw** in `wp-content/uploads/easy-demo-importer/manual-<key>/uploads/`. `pruneNonMedia()` deletes only *non*-media extensions, so an SVG survives. The staging dir is guarded by `Setup::protectDirectory()` (index.php + .htaccess + web.config), but the code's own comment (`:507-511`) notes .htaccess is ignored by nginx — leaving a smuggled `<script>`-bearing SVG briefly web-executable.
- **Mitigation (important):** The **final** Media Library import is safe — `import_local_file()` (`lib/wordpress-importer/class-wp-import.php:1660`) runs `wp_check_filetype()` and, for `image/svg+xml`, `Filters::sanitizeSvgFile()`, dropping unsafe SVGs. Stored XSS in the Media Library is prevented. Residual risk is only the pre-import staging window on nginx, admin-gated. (An earlier "manual SVG entirely unsanitized" concern was investigated and found to be a **false positive** — the sanitize gate at `:1660` covers this path.)
- **Fix:** Sanitize SVGs during `pruneNonMedia()` (run `Filters::sanitizeSvgFile()` on each kept `.svg`), or stage uploads outside the web root.

---

### LOW — A03 Latent XSS: `dangerouslySetInnerHTML` on filter-supplied strings

- **File:** `src/js/backend/components/Modal/steps/Begin.jsx:95,102,109`
- **Evidence:**
  ```jsx
  <p dangerouslySetInnerHTML={{ __html: sdEdiAdminParams.stepOneIntro1 }} />
  ```
- **Issue:** Source values come from `Enqueue::importModalTexts()` (`inc/App/Backend/Enqueue.php:378-380`), which are `esc_html__()` translation strings overridable only via the `sd/edi/import_modal_texts` PHP filter.
- **Reachability:** Developer/theme-controlled, not attacker-reachable; defaults are entity-encoded. Risk exists only if a theme pipes untrusted HTML through that filter.
- **Fix:** Render as plain `{text}`, or if inline HTML is intended, run the values through a server-side `wp_kses` allowlist before localizing.

---

### LOW — A05/A08 Arbitrary option write: settings import uses a blocklist, not an allowlist

- **File:** `inc/App/Ajax/Backend/ImportSettings.php:132-144`
- **Evidence:**
  ```php
  foreach ( $map as $option => $value ) {
      if ( '' === $option || in_array( $option, $blocked_options, true )
          || 'user_roles' === substr( $option, -10 ) ) { continue; }
      update_option( $option, $value );
  }
  ```
- **Issue:** An imported `settings.json` can `update_option()` any key not on the blocklist.
- **Mitigation:** The blocklist (`:81-115`) covers `siteurl`, `home`, `active_plugins`, `default_role`, all salts/keys, `mailserver_pass`, plus a `*user_roles` capability-map guard; values are `json_decode` output (arrays/scalars only — no object injection); requires `manage_options` + a hostile file the admin *chose* to import. Residual risk is limited to writing/creating other autoloaded options.
- **Fix (defense-in-depth):** Prefer an allowlist of importable option keys; if keeping the blocklist, skip creating brand-new autoloaded keys and cap per-value size.

---

### LOW — Vulnerable dependencies (admin bundle only)

- **Evidence:** `npm audit --omit=dev` → 6 advisories (3 high, 3 moderate):
  - `lodash` — code injection via `_.template`, prototype pollution in `_.unset`/`_.omit`.
  - `react-router` / `react-router-dom` — open redirect via `//`/backslash, SSR `deserializeErrors` constructor injection.
  - `form-data` — CRLF injection.
  - `react-router-dom` and `lodash` **do** ship in `assets/js/backend.min.js` (the app uses `HashRouter`).
- **Reachability:** The vulnerable sinks (`_.template` with untrusted input, SSR-only `deserializeErrors`, `//`-prefixed open redirect) are not reachable in an authenticated admin HashRouter SPA. Low real-world exposure. **PHP/Composer runtime is clean.**
- **Fix:** `npm audit fix` (bump `react-router-dom` and `lodash`) and rebuild the bundle; re-run `npm audit` to confirm.

---

## INFO (non-vulnerabilities, noted for consistency)

- **`inc/App/Backend/Enqueue.php:202`** — `serverPageUrl` is localized without `esc_url()` (unlike its siblings). The value is an internal `admin_url()`-derived string used with `window.open(...,'_self')`; not exploitable. Wrap in `esc_url()` for consistency.
- **`inc/Common/Models/DBSearchReplace.php:419-441`** — homegrown `mysql_escape_mimic()` (inherited from Better Search Replace) is byte-level, not charset/connection-aware. It only ever escapes DB-sourced content, so no injection today; a charset-aware `$wpdb->prepare('%s', …)` on the values would be strictly safer.
- **Multisite capability granularity** — every gate uses `manage_options`, which on multisite is a per-site-admin cap (not Super Admin). Destructive actions (`Initialize::databaseReset()` at `Initialize.php:311`, REST rollback/discard) would be reachable by any site admin. Correct/conventional for single-site; multisite hardening is tracked on a separate branch.

---

## Recommendation

**Safe to ship.** No critical/high findings and no unauthenticated surface — the nonce + `manage_options` pattern is applied uniformly. Suggested order of remediation:

1. **Medium SSRF** (`DownloadFiles.php:168`) — swap to `wp_safe_remote_get` + `reject_unsafe_urls`/`redirection`, and block `169.254.0.0/16`. One-line-ish, highest value.
2. **ZipSlip parity** (`ManualImport.php`) — shared realpath sweep after each `unzip_file()`.
3. **`npm audit fix`** + rebuild the admin bundle.
4. Optional low/info: SVG staging sanitize, `Begin.jsx` HTML handling, ImportSettings allowlist, `esc_url()` on `serverPageUrl`.
