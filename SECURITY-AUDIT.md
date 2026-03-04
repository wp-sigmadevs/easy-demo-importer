# Security Audit Report — Easy Demo Importer

**Scope:** Full PHP/JS codebase audit across all AJAX handlers, REST endpoints, session management, file operations, and database interactions.
**Date:** 2026-03-03
**Version audited:** `1.2.0` (Phase 1, pre-release)
**Auditor:** Claude Code (Sonnet 4.6)
**Remediation date:** 2026-03-03 — H1, H2, H3, M1, M2, M4, L4, L5 fixed in this session.

---

## Executive Summary

The plugin has a **solid security foundation** — nonce verification is layered, all AJAX endpoints require authentication, user input is sanitised at every POST boundary, and session IDs are cryptographically random. The most critical issues stem from **trust placed in theme-controlled config values** being used in file operations and database writes without sufficient validation on the plugin side.

| Severity | Count |
|----------|-------|
| 🔴 High   | 3     |
| 🟠 Medium | 4     |
| 🟡 Low    | 5     |

---

## Findings

### 🔴 HIGH

---

#### H1 — ZipSlip in `ImportRevSlider::importSlider()`

**File:** `inc/App/Ajax/Backend/ImportRevSlider.php:99`

```php
$zip->extractTo( $unzipDir );   // no entry path validation
```

`ZipArchive::extractTo()` does not strip path traversal from entry filenames by default. A ZIP
with entries named `../../wp-config.php` can write outside `$unzipDir`. While the ZIP URL comes
from the theme config (not directly from user POST), the distribution server, a compromised theme,
or a hooked `sd/edi/importer/config` filter could deliver a crafted archive.

**Impact:** Arbitrary file write to any path the web server can reach; potential RCE via PHP file drop.

**Recommended fix:** Validate every entry before extraction:

```php
for ( $i = 0; $i < $zip->numFiles; $i++ ) {
    $name = $zip->getNameIndex( $i );
    $dest = realpath( $unzipDir ) . DIRECTORY_SEPARATOR . $name;
    if ( strpos( $dest, realpath( $unzipDir ) ) !== 0 ) {
        $zip->close();
        return; // reject the entire archive
    }
}
$zip->extractTo( $unzipDir );
```

---

#### H2 — Arbitrary WordPress Option Write in `ImportSettings`

**File:** `inc/App/Ajax/Backend/ImportSettings.php:84`

```php
update_option( $option, json_decode( $data, true ) );
```

`$option` is a string from the theme config's `settingsJson` array. The plugin performs no
validation. Anything hooking `sd/edi/importer/config` can specify option names such as
`admin_email`, `siteurl`, `default_role`, or `active_plugins`.

**Impact:** Privilege escalation (e.g. set `default_role` to `administrator`), site hijack
(change `admin_email`), or arbitrary settings corruption.

**Recommended fix:** Block known sensitive option names at minimum:

```php
$blocked = [ 'siteurl', 'home', 'admin_email', 'default_role', 'active_plugins' ];
if ( in_array( $option, $blocked, true ) ) {
    continue;
}
```

For a stronger approach, require all `settingsJson` keys to be prefixed with the theme slug.

---

#### H3 — SSRF via Unvalidated Download URL in `DownloadFiles`

**File:** `inc/App/Ajax/Backend/DownloadFiles.php:119`

```php
$response = wp_remote_get( $external_url, [ ... ] );
```

`$external_url` is the `demoZip` value from the theme config. No URL scheme, hostname, or
private-range check is applied. A malicious `sd/edi/importer/config` hook can point this to
`http://169.254.169.254/latest/meta-data/` (AWS IMDS), internal services, or `file://` paths.

**Impact:** SSRF to probe internal network; credential exfiltration from cloud metadata services.

**Recommended fix:**

```php
if ( ! wp_http_validate_url( $external_url ) ) {
    return [ 'success' => false, 'message' => 'Invalid demo URL.', 'hint' => '' ];
}
$parsed = wp_parse_url( $external_url );
if ( ! in_array( $parsed['scheme'] ?? '', [ 'http', 'https' ], true ) ) {
    return [ 'success' => false, 'message' => 'Invalid URL scheme.', 'hint' => '' ];
}
```

---

### 🟠 MEDIUM

---

#### M1 — `foreign_key_checks = 0` Never Reset After `databaseReset()`

**File:** `inc/App/Ajax/Backend/Initialize.php:226`

```php
$wpdb->query( 'SET foreign_key_checks = 0' );
$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %1$s', $tbl ) );
// ← SET foreign_key_checks = 1 is never called
```

FK checks are disabled inside the truncation loop but never restored. For the remainder of that
MySQL connection all subsequent DB operations in the same import request (plugin activation, post
insertion, taxonomy linking) run without referential integrity.

**Impact:** Data corruption — orphaned meta rows, broken taxonomy mappings, missed constraint
errors.

**Recommended fix:** Wrap the truncation block:

```php
$wpdb->query( 'SET foreign_key_checks = 0' );
foreach ( $customTables as $tbl ) { ... }
$wpdb->query( 'SET foreign_key_checks = 1' );
```

---

#### M2 — Capability Inconsistency: AJAX uses `import`, REST uses `manage_options`

**Files:** `inc/Common/Functions/Helpers.php:95` · `inc/App/Rest/RestEndpoints.php:208`

All 12 AJAX handlers require `current_user_can('import')`. REST endpoints require
`current_user_can('manage_options')`. The `import` capability can be granted to Editor-role
users. An Editor can therefore trigger a full demo import — including the "Reset Database"
option which wipes all posts, pages, and media — even though the REST info endpoints require
admin-level access.

**Impact:** An Editor can perform a full database wipe via the import pipeline.

**Recommended fix:** Raise the AJAX capability check to `manage_options`, or introduce a
dedicated `sd_edi_import` capability and document it explicitly.

---

#### M3 — `cancelSession()` Has No User Ownership Check

**File:** `inc/App/Ajax/Backend/Initialize.php:70–83`

```php
public function cancelSession() {
    Helpers::verifyAjaxCall();    // nonce + import cap only
    $session_id = sanitize_text_field( ... $_POST['sessionId'] ... );
    SessionManager::release( $session_id );   // no current-user check
}
```

Any user with the `import` capability can cancel another user's in-progress import if they
obtain the session UUID. The UUID is returned in every AJAX response and is stored in
`wp_options` (readable by admins via `get_option()`).

**Impact:** Authenticated import-capable user can terminate another admin's running import.

**Recommended fix:** Store `user_id` in session data and assert ownership:

```php
$session_data = SessionManager::get();
if ( $session_data && (int) $session_data['user_id'] !== get_current_user_id() ) {
    wp_send_json_error( [ 'errorMessage' => 'Session does not belong to you.' ], 403 );
}
```

---

#### M4 — Unsanitised Form Title/Status in `ImportFluentForms`

**File:** `inc/App/Ajax/Backend/ImportFluentForms.php:119–126`

```php
$form = [
    'title'  => Arr::get( $formItem, 'title' ),           // raw string
    'status' => Arr::get( $formItem, 'status', 'published' ),  // unchecked
    ...
];
$formId = Form::insertGetId( $form );
```

`title` and `status` from the demo archive JSON are passed directly to FluentForm's ORM without
sanitisation. If FluentForm's layer does not escape output, a crafted demo package can inject
stored XSS visible to editors reviewing imported forms.

**Impact:** Stored XSS in Fluent Forms admin UI.

**Recommended fix:**

```php
'title'  => sanitize_text_field( Arr::get( $formItem, 'title' ) ),
'status' => in_array( Arr::get( $formItem, 'status' ), [ 'published', 'unpublished', 'draft' ], true )
               ? Arr::get( $formItem, 'status' )
               : 'published',
```

---

### 🟡 LOW / INFORMATIONAL

---

#### L1 — Nonce Value Not Sanitised Before Verification

**File:** `inc/Common/Functions/Helpers.php:75`

`check_ajax_referer()` reads `$_REQUEST` internally without explicit sanitisation of the nonce
value. The WP Plugin Handbook recommends `sanitize_text_field(wp_unslash($_POST['nonce']))` before
passing to `wp_verify_nonce` because the function can be extended by plugins.

Not a known exploit vector but is the established WP coding standard.

---

#### L2 — Server Status REST Endpoint Leaks Detailed Infrastructure Info

**File:** `inc/App/Rest/RestEndpoints.php` — `GET /sd/edi/v1/server/status`

The endpoint returns OS type, server software, MySQL version, PHP version, all active and
inactive plugin names/versions/authors, memory limits, cURL version, GD version, and permalink
structure. Currently gated by `manage_options`, but a misconfigured authentication plugin could
expose this to lower-privilege users.

**Recommendation:** Consider removing inactive plugins from the response, or add a separate
filter to disable the endpoint in production.

---

#### L3 — `$should_skip` / `$update_later` Scope Leak in `DBSearchReplace::srdb()`

**File:** `inc/Common/Models/DBSearchReplace.php:262, 268`

```php
if ( isset( $should_skip ) && true === $should_skip ) { ...
if ( isset( $update_later ) && true === $update_later ) { ...
```

These variables are declared inside `foreach ($columns)` but not reset at the start of each
outer `foreach ($data as $row)` iteration. A flag set for one row's column can persist and
incorrectly skip a column in the next row.

Not a security issue, but a data correctness bug that can leave rows partially unreplaced.

---

#### L4 — No `basename()` on Theme-Config File Path Fragments

**Files:** `inc/App/Ajax/Backend/ImportSettings.php:76` · `ImportFluentForms.php:98` · `ImportRevSlider.php:94`

```php
$optionFile = $this->demoUploadDir( $this->demoDir() ) . '/' . $option . '.json';
```

`$option`, `$form`, and `$slider` values from the theme config are concatenated into file paths
without stripping directory separators. A value like `subdir/../../etc/passwd` resolves outside
the demo directory. This is a theme-config trust issue (not a user POST issue), but defensive
path normalisation costs nothing.

**Recommended fix:** `$option = basename( $option );` before path concatenation in all three
handlers.

---

#### L5 — `unzip_file()` in `DownloadFiles` Also Lacks Entry Path Validation

**File:** `inc/App/Ajax/Backend/DownloadFiles.php:161`

```php
$unzip_result = unzip_file( $demoData, $this->demoUploadDir() );
```

WP's `unzip_file()` uses ZipArchive or PclZip depending on the environment and does not
guarantee ZipSlip protection across all WordPress versions. The same concern as H1 applies to
the primary demo ZIP.

**Recommendation:** After `unzip_file()`, verify that all extracted files reside under
`$this->demoUploadDir()` using `realpath()` checks, and delete the directory if any violation
is found.

---

## Positive Findings — What Is Done Well

| Area | Detail |
|------|--------|
| **Login gate** | All 12 AJAX actions use `wp_ajax_` only. Unauthenticated users cannot reach any handler. |
| **Double nonce verification** | Nonce checked at Bootstrap registration time (`check_admin_referer`) AND again at handler execution time (`check_ajax_referer`). |
| **Demo slug validation** | `getDemoSlug()` rejects any `$_POST['demo']` value not present in `config['demoData']` keys. No arbitrary key injection. |
| **POST sanitisation** | `sanitize_text_field(wp_unslash(...))` applied consistently to all POST values in the base class. |
| **Session IDs** | `wp_generate_uuid4()` — 128-bit CSPRNG. Unpredictable and non-enumerable. |
| **Session TTL** | WP transient auto-expiry (30 min default, filterable). Stale locks clear automatically. |
| **Symlink guard in `clearUploads()`** | Symlinks explicitly skipped to prevent deleting files outside the uploads directory. |
| **`demoDir()` path isolation** | `pathinfo(basename($demoZip), PATHINFO_FILENAME)` correctly strips URL path components. |
| **REST permissions** | All REST endpoints require `manage_options`. |
| **Config guard** | Empty `getDemoConfig()` returns HTTP 500 before any import logic runs. |
| **AJAX early-exit** | `wp_send_json_error()` + explicit `wp_die()` at every exit point. No silent fall-through. |
| **Structured DB writes** | `$wpdb->insert()`, `$wpdb->update()`, `$wpdb->prepare()` used for all structured writes. |
| **Session ownership on `release()`** | `SessionManager::release()` only clears the lock if the stored session ID still matches. |

---

## Remediation Roadmap

| ID | Severity | Effort | Status | Fixed In |
|----|----------|--------|--------|----------|
| H1 — ZipSlip (RevSlider ZIP) | 🔴 High | Low | ✅ Fixed | v1.2.0 |
| H2 — Arbitrary option write | 🔴 High | Low | ✅ Fixed | v1.2.0 |
| H3 — SSRF (download URL) | 🔴 High | Low | ✅ Fixed | v1.2.0 |
| M1 — FK checks not restored | 🟠 Medium | Trivial | ✅ Fixed | v1.2.0 |
| M2 — Capability inconsistency | 🟠 Medium | Low | ✅ Fixed | v1.2.0 |
| L4 — `basename()` on file paths | 🟡 Low | Trivial | ✅ Fixed | v1.2.0 |
| L5 — `unzip_file()` ZipSlip | 🟡 Low | Low | ✅ Fixed | v1.2.0 |
| M4 — FluentForms XSS | 🟠 Medium | Low | ✅ Fixed | v1.2.0 |
| M3 — Session ownership | 🟠 Medium | Low | 🔲 Pending | v1.3.0 |
| L1 — Nonce sanitisation | 🟡 Low | Trivial | 🔲 Pending | Next coding-standards pass |
| L2 — Server info disclosure | 🟡 Low | Low | 🔲 Pending | v1.3.0 |
| L3 — `$should_skip` scope | 🟡 Low | Low | 🔲 Pending | `DBSearchReplace` refactor |
