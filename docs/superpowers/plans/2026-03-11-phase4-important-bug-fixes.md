# Phase 4 Important Bug Fixes — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 6 "important" Phase 4 bugs before merging alpha → master as v1.5.0.

**Architecture:** Touches SnapshotManager, Setup, Hooks, Rollback, DependencyResolver, DemoItems, RestEndpoints, UrlReplacer — all isolated, no cross-file dependencies between fixes.

**Tech Stack:** PHP 8.0+, WordPress, WP Cron, WP transients

---

## Bug 4 — Schedule purgeExpired() cron

**Files:**
- Modify: `inc/Config/Setup.php` (activation + deactivation)
- Modify: `inc/App/General/Hooks.php` (register cron callback)

- [ ] **Step 1: Register cron event on plugin activation**

In `Setup::activation()`, after the existing setup calls, add:
```php
if ( ! wp_next_scheduled( 'sd_edi_purge_snapshots' ) ) {
    wp_schedule_event( time(), 'daily', 'sd_edi_purge_snapshots' );
}
```

- [ ] **Step 2: Clear cron event on plugin deactivation**

In `Setup::deactivation()`, after the existing `flush_rewrite_rules()` call, add:
```php
wp_clear_scheduled_hook( 'sd_edi_purge_snapshots' );
```

- [ ] **Step 3: Register action callback in Hooks.php**

Add the SnapshotManager use statement and the action in `Hooks::actions()`:
```php
use SigmaDevs\EasyDemoImporter\Common\Utils\SnapshotManager;
// inside actions():
add_action( 'sd_edi_purge_snapshots', [ SnapshotManager::class, 'purgeExpired' ] );
```

- [ ] **Step 4: Commit**
```bash
git add inc/Config/Setup.php inc/App/General/Hooks.php
git commit -m "fix: schedule daily cron to purge expired snapshots"
```

---

## Bug 5 — Rollback ownership check

**Files:**
- Modify: `inc/Common/Utils/SnapshotManager.php` (store user_id in snapshot_data)
- Modify: `inc/App/Rest/Rollback.php` (check user_id before restoring)

- [ ] **Step 1: Store user_id in snapshot_data on create**

In `SnapshotManager::create()`, add `user_id` to the `$snapshot` array before encoding:
```php
$snapshot = [
    'user_id'       => get_current_user_id(),
    'max_post_id'   => $max_post_id,
    'max_term_id'   => $max_term_id,
    'options'       => $options,
    'snapshot_time' => current_time( 'mysql' ),
];
```

- [ ] **Step 2: Add ownership check in Rollback::rollback()**

After fetching `$row` and verifying it exists, decode `snapshot_data` and compare `user_id`:
```php
$snap_data = json_decode( $row['snapshot_data'], true );
$owner_id  = (int) ( $snap_data['user_id'] ?? 0 );

if ( $owner_id > 0 && $owner_id !== get_current_user_id() ) {
    return new WP_Error(
        'forbidden',
        __( 'You can only undo your own imports.', 'easy-demo-importer' ),
        [ 'status' => 403 ]
    );
}
```

- [ ] **Step 3: Commit**
```bash
git add inc/Common/Utils/SnapshotManager.php inc/App/Rest/Rollback.php
git commit -m "fix: add ownership check to rollback endpoint"
```

---

## Bug 6 — DependencyResolver: resolve all ancestor levels

**Files:**
- Modify: `inc/Common/Utils/DependencyResolver.php`

The current loop only resolves direct parents. Replace it with an iterative loop.

- [ ] **Step 1: Replace single-pass parent loop with iterative loop**

Replace the existing "Hard deps" block in `DependencyResolver::resolve()`:
```php
// Hard deps: parent pages not in selection (all ancestor levels).
$queue = $selected_ids;
while ( ! empty( $queue ) ) {
    $next_queue = [];
    foreach ( $queue as $id ) {
        $item   = $all_items[ $id ] ?? null;
        $parent = $item ? (int) $item['post_parent'] : 0;
        if ( $parent > 0 && ! isset( $added[ $parent ] ) && isset( $all_items[ $parent ] ) ) {
            $hard[]           = $parent;
            $added[ $parent ] = true;
            $next_queue[]     = $parent;
        }
    }
    $queue = $next_queue;
}
```

- [ ] **Step 2: Commit**
```bash
git add inc/Common/Utils/DependencyResolver.php
git commit -m "fix: resolve all ancestor levels in DependencyResolver (not just direct parents)"
```

---

## Bug 7 — DemoItems: add transient caching

**Files:**
- Modify: `inc/App/Rest/DemoItems.php`

- [ ] **Step 1: Cache the full items array with a transient**

In `DemoItems::getItems()`, wrap the `XmlChunker::getItems()` call:
```php
$cache_key = 'sd_edi_demo_items_' . md5( $xml_path );
$all       = get_transient( $cache_key );

if ( false === $all ) {
    $all = XmlChunker::getItems( $xml_path );
    set_transient( $cache_key, $all, 5 * MINUTE_IN_SECONDS );
}
```

- [ ] **Step 2: Commit**
```bash
git add inc/App/Rest/DemoItems.php
git commit -m "fix: cache XmlChunker::getItems() result in DemoItems endpoint"
```

---

## Bug 8 — Add post_count to server status

**Files:**
- Modify: `inc/App/Rest/RestEndpoints.php`

The frontend (`RequirementsStep.jsx`) reads `sysInfoRaw?.post_count?.value` from `system_info.fields`. The field is missing server-side.

- [ ] **Step 1: Add post_count field to systemInfoFields()**

At the end of `systemInfoFields()`, before `return $fields`, add:
```php
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$post_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','trash')"
);

$fields['post_count'] = [
    'label' => esc_html__( 'Existing Post Count', 'easy-demo-importer' ),
    'value' => $post_count,
];
```

Note: `$wpdb` is already available via `global $wpdb` at the top of `systemInfoFields()`.

- [ ] **Step 2: Commit**
```bash
git add inc/App/Rest/RestEndpoints.php
git commit -m "fix: add post_count field to server status so DB-reset warning fires correctly"
```

---

## Bug 9 — UrlReplacer: explicit PK column instead of array_key_first()

**Files:**
- Modify: `inc/Common/Utils/UrlReplacer.php`

`array_key_first($row)` assumes the first column is the PK, which is fragile. Use a static map instead.

- [ ] **Step 1: Add a PK map and use it in searchReplace()**

Add a `$pk_map` before the `$targets` loop and use it inside:
```php
// Known primary key columns per table.
$pk_map = [
    $wpdb->posts    => 'ID',
    $wpdb->postmeta => 'meta_id',
    $wpdb->options  => 'option_id',
];

foreach ( $targets as $table => $columns ) {
    $pk_col = $pk_map[ $table ] ?? array_key_first( array_keys( $rows[0] ?? [] ) );
    foreach ( $columns as $col ) {
        foreach ( $pairs as $search => $replace ) {
            // ... existing query ...
            foreach ( $rows as $row ) {
                $pk  = $pk_col;  // use $pk_col, not array_key_first( $row )
                $old = $row[ $col ];
                $new = self::replaceInValue( $old, $search, $replace );
                if ( $new !== $old ) {
                    $wpdb->update( $table, [ $col => $new ], [ $pk => $row[ $pk ] ] );
                    ++$count;
                }
            }
        }
    }
}
```

The `$pk_map` fallback (`array_key_first`) is only reached for unknown tables (currently impossible since `$targets` is a fixed list), but keeps the code defensively correct.

- [ ] **Step 2: Commit**
```bash
git add inc/Common/Utils/UrlReplacer.php
git commit -m "fix: use explicit per-table PK column in UrlReplacer instead of array_key_first()"
```

---

## Final

- [ ] Verify git log shows all 6 fix commits on alpha branch
