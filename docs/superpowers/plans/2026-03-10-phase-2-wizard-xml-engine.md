# Phase 2 — Wizard + XML Engine Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the modal-based import flow with a full-page wizard, add streaming chunked XML import via XMLReader, a live activity log, dry-run stats, conditional demo visibility, and post-import cache flush — targeting release `1.3.0`.

**Architecture:** The wizard runs as new React Router routes (`/wizard/*`) inside the existing HashRouter; wizard-scoped state lives in a new `WizardContext` (not the global Zustand store). XML import is chunked by a new `XmlChunker` PHP class that uses XMLReader to extract N `<item>` nodes from the WXR file, writes each chunk as a valid temporary WXR file, and passes it to the existing importer — so zero importer logic is rewritten. All new DB tables are created via `dbDelta()` in `Setup.php` and are versioned with `sd_edi_db_version`.

**Tech Stack:** PHP 7.4+ · WordPress AJAX + REST API · XMLReader · dbDelta · React 18 · React Router v6 (HashRouter) · Ant Design v5 · Zustand · Laravel Mix

**Branch:** `develop`

---

## File Map

### New PHP Files
| File | Responsibility |
|------|---------------|
| `inc/Common/Utils/XmlChunker.php` | XMLReader-based WXR parser: `getItems()` for stats, `extractChunk()` for chunked import |
| `inc/Common/Utils/ImportLogger.php` | Static `log()` helper writing to `wp_sd_edi_import_log`; auto-prune |
| `inc/App/Ajax/Backend/ImportXmlChunk.php` | AJAX handler `sd_edi_import_xml_chunk` — processes one chunk per call |
| `inc/App/Rest/DemoStatsEndpoint.php` | REST `GET /sd/edi/v1/demo-stats?demo={slug}` — dry-run item counts |
| `inc/App/Rest/ImportLogEndpoint.php` | REST `GET /sd/edi/v1/import-log?session_id={id}&since={ts}` — log polling |

### Modified PHP Files
| File | Change |
|------|--------|
| `inc/Config/Setup.php` | Add `createImportTables()` + `maybeUpgradeDb()` + `sd_edi_db_version` |
| `inc/App/Ajax/Backend/InstallDemo.php` | Replace full XML import with queue setup via `XmlChunker::getItems()` |
| `inc/App/Ajax/Backend/Finalize.php` | Add `flushCaches()` + `sd/edi/flush_caches` filter |
| `uninstall.php` | Drop new tables on plugin deletion |

### New JS Files
| File | Responsibility |
|------|---------------|
| `src/js/backend/wizard/WizardContext.jsx` | React context + `useWizard()` hook; holds selectedDemo, importOptions, dryRunStats, importProgress |
| `src/js/backend/wizard/WizardLayout.jsx` | Shared shell: step indicator, back/next nav, slide transition, step counter |
| `src/js/backend/wizard/steps/WelcomeStep.jsx` | Intro screen with feature highlights and time estimate |
| `src/js/backend/wizard/steps/RequirementsStep.jsx` | Server status check; blocks Next on hard fail |
| `src/js/backend/wizard/steps/PluginInstallerStep.jsx` | Plugin install/activate rows; adapted from existing Setup.jsx |
| `src/js/backend/wizard/steps/DemoSelectStep.jsx` | Full-page demo grid with conditional visibility |
| `src/js/backend/wizard/steps/ImportOptionsStep.jsx` | Content type toggles, media, DB reset |
| `src/js/backend/wizard/steps/ConfirmationStep.jsx` | Shows dry-run stats; Start Import button |
| `src/js/backend/wizard/steps/ImportingStep.jsx` | Chunk polling loop + ActivityFeed |
| `src/js/backend/wizard/steps/CompleteStep.jsx` | Health summary + quick links |
| `src/js/backend/components/ActivityFeed.jsx` | Polls import-log REST endpoint, auto-scrolls, level-coloured icons |

### Modified JS Files
| File | Change |
|------|--------|
| `src/js/backend.js` | Wrap `<App />` with Ant Design v5 `ConfigProvider` + design tokens |
| `src/js/backend/App.jsx` | Add `/wizard` nested routes with `WizardLayout` as parent |
| `src/js/backend/AppDemoImporter.jsx` | Card click → `navigate('/wizard/welcome')` instead of opening modal |

---

## Chunk 1: Backend Infrastructure

### Task 1: DB Tables

**Files:**
- Modify: `inc/Config/Setup.php`
- Modify: `uninstall.php`

- [ ] **Step 1: Add `createImportTables()` to `Setup.php`**

In `Setup.php`, after the existing `createTable()` call in `activation()`, add:

```php
self::createImportTables();
```

Then add these two methods:

```php
/**
 * Create Phase 2 import tables.
 *
 * @return void
 * @since 1.3.0
 */
private static function createImportTables() {
    global $wpdb;

    $wpdb->hide_errors();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';

    $log_table   = $wpdb->prefix . 'sd_edi_import_log';
    $queue_table = $wpdb->prefix . 'sd_edi_import_queue';

    dbDelta( "CREATE TABLE $log_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(36) NOT NULL,
        timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        level ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
        message TEXT NOT NULL,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY timestamp (timestamp)
    ) $collate;" );

    dbDelta( "CREATE TABLE $queue_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(36) NOT NULL,
        item_index INT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        post_type VARCHAR(20) NOT NULL DEFAULT '',
        post_title TEXT NOT NULL,
        status ENUM('pending','done','failed') NOT NULL DEFAULT 'pending',
        PRIMARY KEY (id),
        KEY session_status (session_id, status)
    ) $collate;" );

    update_option( 'sd_edi_db_version', '1.3.0' );
}

/**
 * Run DB upgrades if plugin was updated without reactivation.
 *
 * Hook: plugins_loaded
 *
 * @return void
 * @since 1.3.0
 */
public static function maybeUpgradeDb() {
    if ( get_option( 'sd_edi_db_version' ) !== '1.3.0' ) {
        self::createImportTables();
    }
}
```

- [ ] **Step 2: Register `maybeUpgradeDb` on `plugins_loaded`**

In `easy-demo-importer.php`, after the existing `register_activation_hook` call, add:

```php
add_action( 'plugins_loaded', [ 'SigmaDevs\EasyDemoImporter\Config\Setup', 'maybeUpgradeDb' ] );
```

- [ ] **Step 3: Drop new tables in `uninstall.php`**

Open `uninstall.php` and add after the existing DROP statements:

```php
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sd_edi_import_log" );    // phpcs:ignore
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sd_edi_import_queue" );  // phpcs:ignore
delete_option( 'sd_edi_db_version' );
```

- [ ] **Step 4: Verify tables are created**

Deactivate → Reactivate the plugin on a test WP install. Run:
```sql
SHOW TABLES LIKE 'wp_sd_edi_import%';
```
Expected: `wp_sd_edi_import_log`, `wp_sd_edi_import_queue` both present.

- [ ] **Step 5: Commit**
```bash
git add inc/Config/Setup.php uninstall.php easy-demo-importer.php
git commit -m "Add import_log and import_queue DB tables with version guard"
```

---

### Task 2: ImportLogger

**Files:**
- Create: `inc/Common/Utils/ImportLogger.php`

- [ ] **Step 1: Create `ImportLogger.php`**

```php
<?php
/**
 * Class: ImportLogger
 *
 * Writes structured log entries to wp_sd_edi_import_log.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportLogger
 *
 * @since 1.3.0
 */
class ImportLogger {

    /**
     * Valid log levels.
     *
     * @var string[]
     */
    const LEVELS = [ 'info', 'success', 'warning', 'error' ];

    /**
     * Auto-prune logs older than this many days.
     *
     * @var int
     */
    const PRUNE_DAYS = 7;

    /**
     * Write a log entry.
     *
     * @param string $message    Human-readable message.
     * @param string $level      One of: info, success, warning, error.
     * @param string $session_id UUID of the current import session.
     * @return void
     * @since 1.3.0
     */
    public static function log( string $message, string $level, string $session_id ): void {
        global $wpdb;

        if ( ! in_array( $level, self::LEVELS, true ) ) {
            $level = 'info';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $wpdb->prefix . 'sd_edi_import_log',
            [
                'session_id' => sanitize_text_field( $session_id ),
                'timestamp'  => current_time( 'mysql' ),
                'level'      => $level,
                'message'    => sanitize_text_field( $message ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Delete log rows older than PRUNE_DAYS days.
     *
     * Call at the start of each new import session.
     *
     * @return void
     * @since 1.3.0
     */
    public static function prune(): void {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::PRUNE_DAYS . ' days' ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}sd_edi_import_log WHERE timestamp < %s",
                $cutoff
            )
        );
    }

    /**
     * Fetch log entries for a session newer than a given timestamp.
     *
     * @param string $session_id UUID of the import session.
     * @param string $since      MySQL datetime string; only rows after this are returned.
     * @return array<int, array{id: int, timestamp: string, level: string, message: string}>
     * @since 1.3.0
     */
    public static function fetch( string $session_id, string $since = '' ): array {
        global $wpdb;

        if ( $since ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, timestamp, level, message
                       FROM {$wpdb->prefix}sd_edi_import_log
                      WHERE session_id = %s AND timestamp > %s
                      ORDER BY id ASC",
                    $session_id,
                    $since
                ),
                ARRAY_A
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, timestamp, level, message
                       FROM {$wpdb->prefix}sd_edi_import_log
                      WHERE session_id = %s
                      ORDER BY id ASC",
                    $session_id
                ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : [];
    }
}
```

- [ ] **Step 2: Verify class loads without errors**

Add a temporary call somewhere accessible (e.g., `functions.php`) and confirm no fatal:
```php
\SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger::log( 'Test', 'info', 'test-session' );
```
Check DB row exists. Remove temp call.

- [ ] **Step 3: Commit**
```bash
git add inc/Common/Utils/ImportLogger.php
git commit -m "Add ImportLogger utility for structured import activity logging"
```

---

### Task 3: XmlChunker — `getItems()`

**Files:**
- Create: `inc/Common/Utils/XmlChunker.php`

- [ ] **Step 1: Create `XmlChunker.php` with `getItems()`**

```php
<?php
/**
 * Class: XmlChunker
 *
 * Streams a WXR file with XMLReader to extract item metadata
 * and produce temporary chunk files for the existing importer.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'This script cannot be accessed directly.' );
}

/**
 * Class XmlChunker
 *
 * @since 1.3.0
 */
class XmlChunker {

    /**
     * Default items per chunk.
     * Filterable via sd/edi/xml_chunk_size.
     *
     * @var int
     */
    const DEFAULT_CHUNK_SIZE = 20;

    /**
     * Stream a WXR file and return metadata for every <item> element.
     *
     * Returns a flat array of associative arrays, each with:
     *   - post_id    (int)    wp:post_id value, or 0 if absent
     *   - post_type  (string) wp:post_type value
     *   - post_title (string) title element value
     *
     * Used by: dry-run stats endpoint, Phase 4 item picker.
     *
     * @param string $file_path Absolute path to the WXR XML file.
     * @return array<int, array{post_id: int, post_type: string, post_title: string}>
     * @since 1.3.0
     */
    public static function getItems( string $file_path ): array {
        $items = [];

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return $items;
        }

        $reader = new \XMLReader();

        if ( ! $reader->open( $file_path ) ) {
            return $items;
        }

        $in_item    = false;
        $post_id    = 0;
        $post_type  = '';
        $post_title = '';

        while ( $reader->read() ) {
            if ( \XMLReader::ELEMENT === $reader->nodeType ) {
                if ( 'item' === $reader->localName && '' === $reader->namespaceURI ) {
                    $in_item    = true;
                    $post_id    = 0;
                    $post_type  = '';
                    $post_title = '';
                    continue;
                }

                if ( $in_item ) {
                    $local = $reader->localName;
                    $ns    = $reader->namespaceURI;

                    if ( 'title' === $local && '' === $ns ) {
                        $reader->read();
                        $post_title = trim( $reader->value );
                    } elseif ( 'post_id' === $local && false !== strpos( $ns, 'wordpress.org/export' ) ) {
                        $reader->read();
                        $post_id = (int) $reader->value;
                    } elseif ( 'post_type' === $local && false !== strpos( $ns, 'wordpress.org/export' ) ) {
                        $reader->read();
                        $post_type = trim( $reader->value );
                    }
                }
            }

            if ( \XMLReader::END_ELEMENT === $reader->nodeType && 'item' === $reader->localName ) {
                if ( $in_item ) {
                    $items[] = [
                        'post_id'    => $post_id,
                        'post_type'  => $post_type ?: 'post',
                        'post_title' => $post_title,
                    ];
                    $in_item = false;
                }
            }
        }

        $reader->close();

        return $items;
    }

    /**
     * Get the resolved chunk size (respects memory limit and filter).
     *
     * @return int
     * @since 1.3.0
     */
    public static function chunkSize(): int {
        $size = (int) apply_filters( 'sd/edi/xml_chunk_size', self::DEFAULT_CHUNK_SIZE ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        $memory_limit = (int) ini_get( 'memory_limit' );

        if ( $memory_limit > 0 && $memory_limit < 256 ) {
            $size = min( $size, 5 );
        }

        return max( 1, $size );
    }
}
```

- [ ] **Step 2: Verify `getItems()` on a real WXR file**

In a test environment, call:
```php
$items = \SigmaDevs\EasyDemoImporter\Common\Utils\XmlChunker::getItems( '/path/to/content.xml' );
var_dump( count( $items ), $items[0] );
```
Expected: array with post_id, post_type, post_title for each `<item>` in the XML.

- [ ] **Step 3: Commit**
```bash
git add inc/Common/Utils/XmlChunker.php
git commit -m "Add XmlChunker::getItems() — stream WXR metadata without DB writes"
```

---

### Task 4: XmlChunker — `extractChunk()`

**Files:**
- Modify: `inc/Common/Utils/XmlChunker.php`

- [ ] **Step 1: Add `extractChunk()` to `XmlChunker`**

Append this method to the `XmlChunker` class:

```php
/**
 * Extract N items from a WXR file starting at $offset.
 *
 * Strategy:
 *  1. First pass: capture the WXR header (everything before the first <item>)
 *     — this includes authors, categories, tags, terms, and WXR metadata.
 *     Terms are in every chunk so the importer can resolve parents.
 *  2. Second pass: stream items with XMLReader, skip $offset items,
 *     capture the next $limit items as raw XML strings.
 *  3. Combine: header XML + item XML strings + closing tags.
 *  4. Write to a temp file and return its path.
 *     Caller is responsible for deleting the temp file.
 *
 * @param string $file_path   Absolute path to the source WXR file.
 * @param int    $offset      Zero-based index of the first item to include.
 * @param int    $limit       Number of items to include. Default: chunkSize().
 * @param int[]  $allowed_ids If non-empty, only items whose wp:post_id is in
 *                            this list are counted toward $offset and $limit.
 *                            (Phase 4 selective import hook-in — pass [] for all.)
 * @return string|false Absolute path to the temp WXR file, or false on failure.
 * @since 1.3.0
 */
public static function extractChunk(
    string $file_path,
    int $offset,
    int $limit = 0,
    array $allowed_ids = []
): ?string {
    if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return null;
    }

    if ( $limit <= 0 ) {
        $limit = self::chunkSize();
    }

    // ── Pass 1: capture WXR header ────────────────────────────────────────────
    $header = self::extractHeader( $file_path );

    if ( null === $header ) {
        return null;
    }

    // ── Pass 2: collect item XML strings ─────────────────────────────────────
    $reader = new \XMLReader();

    if ( ! $reader->open( $file_path ) ) {
        return null;
    }

    $item_xmls  = [];
    $seen       = 0;
    $collected  = 0;

    while ( $reader->read() ) {
        if ( \XMLReader::ELEMENT !== $reader->nodeType || 'item' !== $reader->localName ) {
            continue;
        }

        // Check allowed_ids filter (Phase 4 hook-in).
        if ( ! empty( $allowed_ids ) ) {
            $xml   = $reader->readOuterXml();
            $doc   = new \DOMDocument();
            @$doc->loadXML( $xml );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $nodes = $doc->getElementsByTagNameNS( '*', 'post_id' );
            $id    = $nodes->length > 0 ? (int) $nodes->item( 0 )->textContent : 0;

            if ( ! in_array( $id, $allowed_ids, true ) ) {
                continue;
            }
        }

        $seen++;

        if ( $seen <= $offset ) {
            continue;
        }

        $item_xmls[] = $reader->readOuterXml();
        $collected++;

        if ( $collected >= $limit ) {
            break;
        }
    }

    $reader->close();

    if ( empty( $item_xmls ) ) {
        return null;
    }

    // ── Build temp WXR file ───────────────────────────────────────────────────
    $chunk_xml  = $header;
    $chunk_xml .= "\n" . implode( "\n", $item_xmls ) . "\n";
    $chunk_xml .= "  </channel>\n</rss>";

    $tmp_path = wp_tempnam( 'sd-edi-chunk-', get_temp_dir() );

    if ( false === file_put_contents( $tmp_path, $chunk_xml ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return null;
    }

    return $tmp_path;
}

/**
 * Extract the WXR header — everything before the first <item>.
 *
 * Includes: rss open tag, channel metadata, wp:author, wp:category,
 * wp:tag, wp:term elements. Excludes the closing </channel></rss>.
 *
 * @param string $file_path Absolute path to the WXR file.
 * @return string|null Header XML string, or null on failure.
 * @since 1.3.0
 */
private static function extractHeader( string $file_path ): ?string {
    $handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

    if ( ! $handle ) {
        return null;
    }

    $header   = '';
    $found    = false;

    while ( ! feof( $handle ) ) {
        $line = fgets( $handle );

        if ( false === $line ) {
            break;
        }

        // Stop accumulating once we hit the first <item> tag.
        if ( false !== strpos( $line, '<item>' ) || preg_match( '/<item\s/', $line ) ) {
            $found = true;
            break;
        }

        // Strip closing tags — we'll add them back after the items.
        if ( trim( $line ) === '</channel>' || trim( $line ) === '</rss>' ) {
            continue;
        }

        $header .= $line;
    }

    fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

    return $found ? $header : null;
}
```

- [ ] **Step 2: Verify chunk extraction on a real WXR file**

```php
$chunk = \SigmaDevs\EasyDemoImporter\Common\Utils\XmlChunker::extractChunk(
    '/path/to/content.xml',
    0,   // offset
    5    // first 5 items
);
// $chunk = path to temp file
$xml = file_get_contents( $chunk );
// Verify it's valid WXR: opens with <?xml, has <rss, has <channel, has 5 <item> nodes, closes correctly
$doc = new DOMDocument();
$doc->loadXML( $xml );
echo count( $doc->getElementsByTagName( 'item' ) ); // should be 5
unlink( $chunk );
```

- [ ] **Step 3: Commit**
```bash
git add inc/Common/Utils/XmlChunker.php
git commit -m "Add XmlChunker::extractChunk() — produce temp WXR chunk files for streaming import"
```

---

### Task 5: DemoStatsEndpoint

**Files:**
- Create: `inc/App/Rest/DemoStatsEndpoint.php`

> **Note:** New files in `inc/App/Rest/` are auto-registered by `Classes.php` — no changes needed there.

- [ ] **Step 1: Create `DemoStatsEndpoint.php`**

```php
<?php
/**
 * Rest Class: Demo Stats Endpoint.
 *
 * GET /sd/edi/v1/demo-stats?demo={slug}
 * Returns dry-run item counts parsed from the WXR file — no DB writes.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use WP_REST_Request;
use WP_REST_Response;
use SigmaDevs\EasyDemoImporter\Common\{
    Abstracts\Base,
    Traits\Singleton,
    Functions\Helpers,
    Utils\XmlChunker
};

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'This script cannot be accessed directly.' );
}

/**
 * Class DemoStatsEndpoint
 *
 * @since 1.3.0
 */
class DemoStatsEndpoint extends Base {
    use Singleton;

    /**
     * Register REST route.
     *
     * @return void
     * @since 1.3.0
     */
    public function register() {
        parent::register();

        add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
    }

    /**
     * Register route.
     *
     * @return void
     * @since 1.3.0
     */
    public function registerRoutes() {
        register_rest_route(
            'sd/edi/v1',
            '/demo-stats',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getStats' ],
                'permission_callback' => [ $this, 'permission' ],
                'args'                => [
                    'demo' => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check — requires import capability.
     *
     * @return bool
     * @since 1.3.0
     */
    public function permission(): bool {
        return current_user_can( 'import' );
    }

    /**
     * Return dry-run stats for the requested demo.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     * @since 1.3.0
     */
    public function getStats( WP_REST_Request $request ): WP_REST_Response {
        $demo_slug = $request->get_param( 'demo' ) ?? '';

        $cache_key = 'sd_edi_stats_' . md5( $demo_slug );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return new WP_REST_Response( $cached, 200 );
        }

        $config = sd_edi()->getDemoConfig();

        if ( empty( $config ) ) {
            return new WP_REST_Response( [ 'error' => 'Demo config not available.' ], 500 );
        }

        $xml_path = $this->resolveXmlPath( $config, $demo_slug );

        if ( ! $xml_path || ! file_exists( $xml_path ) ) {
            return new WP_REST_Response( [ 'error' => 'XML file not found. Run download step first.' ], 404 );
        }

        $items = XmlChunker::getItems( $xml_path );

        $stats = [
            'total'       => count( $items ),
            'by_type'     => [],
            'attachments' => 0,
        ];

        foreach ( $items as $item ) {
            $type = $item['post_type'];

            if ( 'attachment' === $type ) {
                $stats['attachments']++;
            }

            if ( ! isset( $stats['by_type'][ $type ] ) ) {
                $stats['by_type'][ $type ] = 0;
            }

            $stats['by_type'][ $type ]++;
        }

        set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

        return new WP_REST_Response( $stats, 200 );
    }

    /**
     * Resolve the local XML file path for a demo slug.
     *
     * The file is expected to be present in the importer's upload directory
     * after the download step has run.
     *
     * @param array  $config    Demo config array.
     * @param string $demo_slug Demo slug, or empty for single-zip setup.
     * @return string|null Absolute path, or null if unresolvable.
     * @since 1.3.0
     */
    private function resolveXmlPath( array $config, string $demo_slug ): ?string {
        $upload_dir = wp_get_upload_dir();
        $base       = $upload_dir['basedir'] . '/easy-demo-importer/';

        if ( ! empty( $config['multipleZip'] ) && $demo_slug ) {
            return $base . $demo_slug . '/content.xml';
        }

        return $base . 'content.xml';
    }
}
```

- [ ] **Step 2: Test the endpoint**

After activating, run:
```
GET /wp-json/sd/edi/v1/demo-stats?demo=your-demo-slug
```
Expected:
```json
{ "total": 87, "by_type": { "page": 23, "post": 8, "attachment": 134 }, "attachments": 134 }
```

- [ ] **Step 3: Commit**
```bash
git add inc/App/Rest/DemoStatsEndpoint.php
git commit -m "Add DemoStatsEndpoint — dry-run XML item counts via REST"
```

---

### Task 6: ImportLogEndpoint

**Files:**
- Create: `inc/App/Rest/ImportLogEndpoint.php`

- [ ] **Step 1: Create `ImportLogEndpoint.php`**

```php
<?php
/**
 * Rest Class: Import Log Endpoint.
 *
 * GET /sd/edi/v1/import-log?session_id={id}&since={timestamp}
 * Returns log entries for polling by ActivityFeed component.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use WP_REST_Request;
use WP_REST_Response;
use SigmaDevs\EasyDemoImporter\Common\{
    Abstracts\Base,
    Traits\Singleton,
    Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportLogEndpoint
 *
 * @since 1.3.0
 */
class ImportLogEndpoint extends Base {
    use Singleton;

    /**
     * Register REST route.
     *
     * @return void
     * @since 1.3.0
     */
    public function register() {
        parent::register();

        add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
    }

    /**
     * Register route.
     *
     * @return void
     * @since 1.3.0
     */
    public function registerRoutes() {
        register_rest_route(
            'sd/edi/v1',
            '/import-log',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'getLog' ],
                'permission_callback' => [ $this, 'permission' ],
                'args'                => [
                    'session_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'since' => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                        'default'           => '',
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check.
     *
     * @return bool
     * @since 1.3.0
     */
    public function permission(): bool {
        return current_user_can( 'import' );
    }

    /**
     * Return log entries.
     *
     * @param WP_REST_Request $request Incoming request.
     * @return WP_REST_Response
     * @since 1.3.0
     */
    public function getLog( WP_REST_Request $request ): WP_REST_Response {
        $session_id = $request->get_param( 'session_id' );
        $since      = $request->get_param( 'since' ) ?? '';

        if ( empty( $session_id ) ) {
            return new WP_REST_Response( [], 200 );
        }

        $entries = ImportLogger::fetch( $session_id, $since );

        return new WP_REST_Response( $entries, 200 );
    }
}
```

- [ ] **Step 2: Test endpoint**

Insert a test row manually:
```sql
INSERT INTO wp_sd_edi_import_log (session_id, level, message)
VALUES ('test-123', 'info', 'Test entry');
```
Then call:
```
GET /wp-json/sd/edi/v1/import-log?session_id=test-123
```
Expected: array with the row.

- [ ] **Step 3: Commit**
```bash
git add inc/App/Rest/ImportLogEndpoint.php
git commit -m "Add ImportLogEndpoint — REST polling for ActivityFeed component"
```

---

### Task 7: ImportXmlChunk AJAX Handler + InstallDemo modification

**Files:**
- Create: `inc/App/Ajax/Backend/ImportXmlChunk.php`
- Modify: `inc/App/Ajax/Backend/InstallDemo.php`

- [ ] **Step 1: Create `ImportXmlChunk.php`**

```php
<?php
/**
 * Ajax Class: ImportXmlChunk
 *
 * Processes one chunk of the WXR XML file using the existing WordPress importer.
 * Called repeatedly by the frontend polling loop until done === total.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Ajax\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
    Abstracts\ImporterAjax,
    Functions\Helpers,
    Functions\SessionManager,
    Utils\XmlChunker,
    Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportXmlChunk
 *
 * @since 1.3.0
 */
class ImportXmlChunk extends ImporterAjax {

    /**
     * Register AJAX action.
     *
     * @return void
     * @since 1.3.0
     */
    public function register() {
        parent::register();

        add_action( 'wp_ajax_sd_edi_import_xml_chunk', [ $this, 'response' ] );
    }

    /**
     * Handle one chunk import.
     *
     * POST params:
     *   sessionId  — active session UUID
     *   offset     — zero-based item index to start from
     *   xmlPath    — absolute path to the source WXR file (validated server-side)
     *
     * @return void
     * @since 1.3.0
     */
    public function response() {
        $this->handlePostSubmission();

        $offset   = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;  // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $xml_path = $this->uploadsDir['basedir'] . '/easy-demo-importer/';

        // Resolve XML path from config.
        if ( ! empty( $this->config['multipleZip'] ) && $this->demoSlug ) {
            $xml_path .= $this->demoSlug . '/content.xml';
        } else {
            $xml_path .= 'content.xml';
        }

        if ( ! file_exists( $xml_path ) ) {
            wp_send_json_error( [ 'errorMessage' => __( 'XML file not found.', 'easy-demo-importer' ) ], 404 );
        }

        // Total item count is stored in the session transient to avoid re-scanning.
        $session_key = 'sd_edi_xml_total_' . $this->sessionId;
        $total       = (int) get_transient( $session_key );

        if ( ! $total ) {
            $items = XmlChunker::getItems( $xml_path );
            $total = count( $items );
            set_transient( $session_key, $total, HOUR_IN_SECONDS );
        }

        $limit     = XmlChunker::chunkSize();
        $chunk_tmp = XmlChunker::extractChunk( $xml_path, $offset, $limit );

        if ( ! $chunk_tmp ) {
            // Offset past end — we're done.
            ImportLogger::log(
                __( 'XML content import complete.', 'easy-demo-importer' ),
                'success',
                $this->sessionId
            );

            wp_send_json_success( [
                'done'  => $total,
                'total' => $total,
            ] );
        }

        // Run existing importer on the chunk temp file.
        $this->importChunkFile( $chunk_tmp );

        @unlink( $chunk_tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

        $done = min( $offset + $limit, $total );

        ImportLogger::log(
            sprintf(
                /* translators: 1: items done, 2: total items */
                __( 'Imported items %1$d – %2$d of %3$d.', 'easy-demo-importer' ),
                $offset + 1,
                $done,
                $total
            ),
            'info',
            $this->sessionId
        );

        wp_send_json_success( [
            'done'   => $done,
            'total'  => $total,
            'offset' => $done, // next call uses this as the new offset
        ] );
    }

    /**
     * Pass a chunk WXR file to the existing importer.
     *
     * Temporarily suppresses image regeneration (regeneration happens in
     * the dedicated regen step in Phase 3).
     *
     * @param string $chunk_path Absolute path to the temp chunk WXR file.
     * @return void
     * @since 1.3.0
     */
    private function importChunkFile( string $chunk_path ): void {
        // Suppress WP's auto-regen — Phase 3 owns image regeneration.
        add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
        add_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );

        // Run the existing WordPress XML importer on the chunk.
        $importer_path = plugin_dir_path( SD_EDI_PLUGIN_FILE ) . 'lib/wordpress-importer/wordpress-importer.php';

        if ( file_exists( $importer_path ) ) {
            if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
                define( 'WP_LOAD_IMPORTERS', true );
            }

            require_once $importer_path;

            $importer = new \WP_Import();
            $importer->fetch_attachments = ! $this->excludeImages;

            ob_start();
            $importer->import( $chunk_path );
            ob_end_clean();
        }

        remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 9999 );
        remove_filter( 'wp_generate_attachment_metadata', '__return_empty_array', 9999 );
    }
}
```

- [ ] **Step 2: Modify `InstallDemo.php` — replace full XML import with queue setup**

In `InstallDemo.php`'s `response()` method, find the section where the existing XML importer is called. Replace that block with:

```php
// Queue the chunked import instead of running the full XML import here.
// The frontend will call sd_edi_import_xml_chunk repeatedly until done.
$xml_path = $this->uploadsDir['basedir'] . '/easy-demo-importer/';
$xml_path .= ( ! empty( $this->config['multipleZip'] ) && $this->demoSlug )
    ? $this->demoSlug . '/content.xml'
    : 'content.xml';

$items = \SigmaDevs\EasyDemoImporter\Common\Utils\XmlChunker::getItems( $xml_path );
$total = count( $items );

set_transient( 'sd_edi_xml_total_' . $this->sessionId, $total, HOUR_IN_SECONDS );

\SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger::log(
    sprintf(
        /* translators: %d: total number of items */
        __( 'XML content ready: %d items queued for import.', 'easy-demo-importer' ),
        $total
    ),
    'info',
    $this->sessionId
);

$this->prepareResponse(
    'sd_edi_import_xml_chunk',
    esc_html__( 'Importing XML content.', 'easy-demo-importer' ),
    sprintf(
        /* translators: %d: total items */
        esc_html__( '%d items queued. Starting chunked import…', 'easy-demo-importer' ),
        $total
    )
);
```

> **Note:** The `nextStep` key returned here tells the JS frontend to begin the chunk polling loop (task 20) rather than proceeding to the next linear AJAX step.

- [ ] **Step 3: Verify chunk AJAX works**

Via browser console (on a WP admin page with the plugin active):
```js
fetch(ajaxurl, {
  method: 'POST',
  body: new URLSearchParams({
    action: 'sd_edi_import_xml_chunk',
    nonce: sdEdiAdminParams.nonce,
    sessionId: 'your-session-id',
    offset: 0,
    demo: 'your-demo-slug'
  })
}).then(r => r.json()).then(console.log);
```
Expected: `{ success: true, data: { done: 20, total: 87, offset: 20 } }`

- [ ] **Step 4: Commit**
```bash
git add inc/App/Ajax/Backend/ImportXmlChunk.php inc/App/Ajax/Backend/InstallDemo.php
git commit -m "Add chunked XML import handler; InstallDemo now queues instead of full-imports"
```

---

### Task 8: Conditional Demo Visibility (PHP side)

**Files:**
- Modify: `inc/App/Rest/RestEndpoints.php`

The existing plugin list REST endpoint returns all configured plugins. We need the demo list endpoint to include a `requires` field per demo so the frontend can grey out incompatible demos.

- [ ] **Step 1: Add `requires` to demo data response**

In `RestEndpoints.php`, find the method that returns the demo config/import list (typically the `/sd/edi/v1/demo/list` or similar endpoint handler). Add a `requires_active` flag to each demo:

```php
// For each demo in the config, check its 'requires' key.
foreach ( $demoData as $slug => &$demo ) {
    $requires = $demo['requires'] ?? [];
    $missing  = [];

    foreach ( $requires as $plugin_file ) {
        if ( ! is_plugin_active( $plugin_file ) ) {
            $missing[] = $plugin_file;
        }
    }

    $demo['requires_met']    = empty( $missing );
    $demo['requires_missing'] = $missing;
}
unset( $demo );
```

- [ ] **Step 2: Document the `requires` config key for theme authors**

Add a comment in the sample config file (`samples/sample-config.php`):

```php
// Optional: grey out this demo if required plugins are not active.
'requires' => [
    'woocommerce/woocommerce.php',
    'elementor/elementor.php',
],
```

- [ ] **Step 3: Commit**
```bash
git add inc/App/Rest/RestEndpoints.php samples/sample-config.php
git commit -m "Add requires_met flag to demo list endpoint for conditional visibility"
```

---

### Task 9: Post-Import Cache Flush

**Files:**
- Modify: `inc/App/Ajax/Backend/Finalize.php`

- [ ] **Step 1: Add `flushCaches()` to `Finalize.php`**

In the `response()` method of `Finalize.php`, before the final `prepareResponse()` call, add:

```php
$this->flushCaches();
```

Then add the method:

```php
/**
 * Flush known caching plugins and Elementor after import.
 *
 * @return void
 * @since 1.3.0
 */
private function flushCaches(): void {
    $flushed = [];

    $handlers = apply_filters( 'sd/edi/flush_caches', [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        'wp_super_cache'   => function() { if ( function_exists( 'wp_cache_clear_cache' ) ) { wp_cache_clear_cache(); return true; } return false; },
        'w3_total_cache'   => function() { if ( function_exists( 'w3tc_flush_all' ) ) { w3tc_flush_all(); return true; } return false; },
        'litespeed_cache'  => function() { do_action( 'litespeed_purge_all' ); return true; },
        'wp_rocket'        => function() { if ( function_exists( 'rocket_clean_domain' ) ) { rocket_clean_domain(); return true; } return false; },
        'elementor_css'    => function() {
            if ( class_exists( '\Elementor\Plugin' ) ) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                return true;
            }
            return false;
        },
        'woocommerce'      => function() { if ( function_exists( 'wc_delete_product_transients' ) ) { wc_delete_product_transients(); return true; } return false; },
        'wp_object_cache'  => function() { wp_cache_flush(); return true; },
    ] );

    foreach ( $handlers as $name => $handler ) {
        if ( $handler() ) {
            $flushed[] = $name;
        }
    }

    if ( ! empty( $flushed ) && ! empty( $this->sessionId ) ) {
        ImportLogger::log(
            sprintf(
                /* translators: %s: comma-separated list of cache systems */
                __( 'Caches cleared: %s', 'easy-demo-importer' ),
                implode( ', ', $flushed )
            ),
            'success',
            $this->sessionId
        );
    }
}
```

Add the import at the top of `Finalize.php`:
```php
use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;
```

- [ ] **Step 2: Verify no fatal on sites without any cache plugin**

Run the finalize step on a clean WP install with no cache plugins. Confirm no errors. Check that `wp_object_cache` is always flushed.

- [ ] **Step 3: Commit**
```bash
git add inc/App/Ajax/Backend/Finalize.php
git commit -m "Flush caching plugins and Elementor CSS after import via sd/edi/flush_caches filter"
```

---

### Task 10: Instrument Existing Steps with ImportLogger

**Files:**
- Modify: `inc/App/Ajax/Backend/Initialize.php`
- Modify: `inc/App/Ajax/Backend/InstallPlugins.php`
- Modify: `inc/App/Ajax/Backend/ImportMenus.php`
- Modify: `inc/App/Ajax/Backend/ImportWidgets.php`
- Modify: `inc/App/Ajax/Backend/CustomizerImport.php`
- Modify: `inc/Common/Functions/SessionManager.php`

- [ ] **Step 1: Add prune call to `SessionManager::start()`**

In `SessionManager.php`, inside `start()` after `static::cleanup()`:

```php
\SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger::prune();
```

- [ ] **Step 2: Add log calls to key import steps**

Pattern — add after successful operation in each handler's `response()`:

```php
// In Initialize.php — after successful download/unzip:
ImportLogger::log( __( 'Demo files downloaded and extracted.', 'easy-demo-importer' ), 'success', $this->sessionId );

// In InstallPlugins.php — after each plugin installed:
ImportLogger::log(
    sprintf( __( 'Plugin installed: %s', 'easy-demo-importer' ), $slug ),
    'success',
    $this->sessionId
);

// In ImportMenus.php — after menus assigned:
ImportLogger::log( __( 'Navigation menus imported and assigned.', 'easy-demo-importer' ), 'success', $this->sessionId );

// In ImportWidgets.php — after widgets done:
ImportLogger::log( __( 'Widgets imported.', 'easy-demo-importer' ), 'success', $this->sessionId );

// In CustomizerImport.php — after customizer imported:
ImportLogger::log( __( 'Customizer settings applied.', 'easy-demo-importer' ), 'success', $this->sessionId );
```

Add the `use` import to each file:
```php
use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;
```

- [ ] **Step 3: Verify log entries appear during a full import run**

Run a complete import and check `wp_sd_edi_import_log` for expected rows with the correct `session_id`.

- [ ] **Step 4: Commit**
```bash
git add inc/App/Ajax/Backend/Initialize.php inc/App/Ajax/Backend/InstallPlugins.php \
        inc/App/Ajax/Backend/ImportMenus.php inc/App/Ajax/Backend/ImportWidgets.php \
        inc/App/Ajax/Backend/CustomizerImport.php inc/Common/Functions/SessionManager.php
git commit -m "Instrument import steps with ImportLogger activity entries"
```

---

## Chunk 2: Wizard Frontend

> **Prerequisites:** Chunk 1 must be merged and available. The REST endpoints (`/demo-stats`, `/import-log`) and AJAX handler (`sd_edi_import_xml_chunk`) must be reachable before testing wizard steps.

---

### Task 11: ConfigProvider Design Tokens

**Files:**
- Modify: `src/js/backend.js`

- [ ] **Step 1: Wrap `<App />` with `ConfigProvider`**

```jsx
import App from './backend/App';
import ReactDOM from 'react-dom/client';
import { ConfigProvider } from 'antd';

const ediTheme = {
    token: {
        colorPrimary:   '#6366f1',
        colorSuccess:   '#10b981',
        colorWarning:   '#f59e0b',
        colorError:     '#ef4444',
        borderRadius:    10,
        borderRadiusLG:  14,
        fontFamily:     '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
        boxShadow:      '0 4px 24px rgba(0,0,0,0.08)',
    },
    components: {
        Button:   { borderRadius: 8, controlHeight: 40 },
        Switch:   { colorPrimary: '#6366f1' },
        Modal:    { borderRadiusLG: 16 },
        Progress: { colorInfo: '#6366f1' },
        Steps:    { colorPrimary: '#6366f1' },
        Tag:      { borderRadius: 6 },
    },
};

const container = document.getElementById( 'sd-edi-demo-import-container' );

if ( container ) {
    const root = ReactDOM.createRoot( container );
    root.render(
        <ConfigProvider theme={ediTheme}>
            <App />
        </ConfigProvider>
    );
}
```

- [ ] **Step 2: Build and verify**

```bash
npm run dev
```
Open the plugin admin page. Confirm buttons/switches now use indigo (`#6366f1`) and the border radius changed. No console errors.

- [ ] **Step 3: Commit**
```bash
git add src/js/backend.js
git commit -m "Wrap app in Ant Design v5 ConfigProvider with brand design tokens"
```

---

### Task 12: WizardContext

**Files:**
- Create: `src/js/backend/wizard/WizardContext.jsx`

- [ ] **Step 1: Create `WizardContext.jsx`**

```jsx
import { createContext, useContext, useState, useCallback } from 'react';

const WizardContext = createContext( null );

const DEFAULT_OPTIONS = {
    content:        true,
    media:          true,
    customizer:     true,
    widgets:        true,
    menus:          true,
    pluginSettings: true,
    resetDb:        false,
};

/**
 * WizardProvider — wraps all /wizard/* routes.
 * Holds state that lives for the duration of one import wizard session.
 */
export const WizardProvider = ( { children } ) => {
    const [ selectedDemo,  setSelectedDemo  ] = useState( null );
    const [ importOptions, setImportOptions ] = useState( DEFAULT_OPTIONS );
    const [ dryRunStats,   setDryRunStats   ] = useState( null );
    const [ importProgress, setImportProgress ] = useState( { done: 0, total: 0, currentTitle: '' } );
    const [ direction, setDirection ] = useState( 'forward' );

    const updateOption = useCallback( ( key, value ) => {
        setImportOptions( ( prev ) => ( { ...prev, [ key ]: value } ) );
    }, [] );

    const resetWizard = useCallback( () => {
        setSelectedDemo( null );
        setImportOptions( DEFAULT_OPTIONS );
        setDryRunStats( null );
        setImportProgress( { done: 0, total: 0, currentTitle: '' } );
        setDirection( 'forward' );
    }, [] );

    return (
        <WizardContext.Provider value={ {
            selectedDemo,  setSelectedDemo,
            importOptions, updateOption,
            dryRunStats,   setDryRunStats,
            importProgress, setImportProgress,
            direction,     setDirection,
            resetWizard,
        } }>
            { children }
        </WizardContext.Provider>
    );
};

/**
 * useWizard — consume wizard context from any step component.
 *
 * @throws {Error} If used outside WizardProvider.
 */
export const useWizard = () => {
    const ctx = useContext( WizardContext );

    if ( ! ctx ) {
        throw new Error( 'useWizard must be used inside WizardProvider' );
    }

    return ctx;
};
```

- [ ] **Step 2: Commit**
```bash
git add src/js/backend/wizard/WizardContext.jsx
git commit -m "Add WizardContext for wizard-scoped state management"
```

---

### Task 13: WizardLayout

**Files:**
- Create: `src/js/backend/wizard/WizardLayout.jsx`
- Create: `src/js/backend/wizard/wizard.css`

- [ ] **Step 1: Create `wizard.css`**

```css
/* Wizard step slide transitions */
.edi-wizard-step-enter-forward {
    animation: ediSlideInRight 0.28s ease-out both;
}
.edi-wizard-step-enter-backward {
    animation: ediSlideInLeft 0.28s ease-out both;
}

@keyframes ediSlideInRight {
    from { transform: translateX(40px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
@keyframes ediSlideInLeft {
    from { transform: translateX(-40px); opacity: 0; }
    to   { transform: translateX(0);     opacity: 1; }
}

/* Wizard shell */
.edi-wizard-shell {
    max-width: 860px;
    margin: 24px auto;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 24px rgba(0,0,0,0.08);
    overflow: hidden;
}

.edi-wizard-header {
    padding: 28px 36px 20px;
    border-bottom: 1px solid #f0f0f0;
    background: #fafafa;
}

.edi-wizard-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}

.edi-wizard-meta h1 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
    color: #1a1a2e;
}

.edi-wizard-counter {
    font-size: 12px;
    font-weight: 600;
    color: #6366f1;
    background: #eef2ff;
    padding: 4px 12px;
    border-radius: 20px;
    letter-spacing: 0.3px;
}

.edi-wizard-body {
    padding: 36px;
    min-height: 420px;
}

.edi-wizard-footer {
    padding: 16px 36px;
    border-top: 1px solid #f0f0f0;
    background: #fafafa;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
```

- [ ] **Step 2: Create `WizardLayout.jsx`**

```jsx
import { Steps } from 'antd';
import { Outlet, useNavigate, useLocation } from 'react-router-dom';
import { useEffect, useRef } from 'react';
import { WizardProvider, useWizard } from './WizardContext';
import './wizard.css';

/* global sdEdiAdminParams */

const STEPS = [
    { key: 'welcome',      title: 'Welcome'      },
    { key: 'requirements', title: 'Requirements' },
    { key: 'plugins',      title: 'Plugins'      },
    { key: 'demos',        title: 'Select Demo'  },
    { key: 'options',      title: 'Options'      },
    { key: 'confirm',      title: 'Confirm'      },
    { key: 'importing',    title: 'Importing'    },
    { key: 'complete',     title: 'Done'         },
];

/**
 * Inner layout — needs WizardProvider to already be in the tree.
 */
const WizardShell = () => {
    const navigate    = useNavigate();
    const location    = useLocation();
    const { direction, setDirection } = useWizard();
    const prevStepRef = useRef( -1 );

    const currentKey  = location.pathname.split( '/' ).pop();
    const currentIdx  = STEPS.findIndex( ( s ) => s.key === currentKey );
    const stepCounter = currentIdx >= 0 ? `Step ${ currentIdx + 1 } of ${ STEPS.length }` : '';

    // Set slide direction on navigation.
    useEffect( () => {
        if ( prevStepRef.current >= 0 ) {
            setDirection( currentIdx >= prevStepRef.current ? 'forward' : 'backward' );
        }
        prevStepRef.current = currentIdx;
    }, [ currentIdx, setDirection ] );

    const antSteps = STEPS.map( ( s, i ) => ( {
        title:  s.title,
        status: i < currentIdx ? 'finish' : i === currentIdx ? 'process' : 'wait',
    } ) );

    const handleBack = () => {
        if ( currentIdx > 0 ) {
            navigate( `/wizard/${ STEPS[ currentIdx - 1 ].key }` );
        } else {
            navigate( '/' );
        }
    };

    return (
        <div className="edi-wizard-shell">
            <div className="edi-wizard-header">
                <div className="edi-wizard-meta">
                    <h1>{ sdEdiAdminParams.pluginName || 'Demo Importer' }</h1>
                    { stepCounter && (
                        <span className="edi-wizard-counter">{ stepCounter }</span>
                    ) }
                </div>
                <Steps
                    current={ currentIdx }
                    items={ antSteps }
                    size="small"
                    labelPlacement="vertical"
                />
            </div>

            <div
                className={ `edi-wizard-body edi-wizard-step-enter-${ direction }` }
                key={ currentKey }
            >
                <Outlet />
            </div>

            <div className="edi-wizard-footer">
                <span
                    id="edi-wizard-back-slot"
                    style={ { display: 'contents' } }
                />
                <span
                    id="edi-wizard-next-slot"
                    style={ { display: 'contents' } }
                />
            </div>
        </div>
    );
};

/**
 * WizardLayout — route element that provides WizardContext and the shell.
 * Used as the parent route for all /wizard/* routes.
 */
const WizardLayout = () => (
    <WizardProvider>
        <WizardShell />
    </WizardProvider>
);

export default WizardLayout;
```

> **Note:** Back and Next buttons are rendered by each step into the footer slots via `ReactDOM.createPortal`. This keeps navigation logic inside each step (where validation lives) while keeping the footer in the layout.

- [ ] **Step 3: Commit**
```bash
git add src/js/backend/wizard/WizardLayout.jsx src/js/backend/wizard/WizardContext.jsx \
        src/js/backend/wizard/wizard.css
git commit -m "Add WizardLayout shell with Ant Design Steps indicator and slide transitions"
```

---

### Task 14: Add Wizard Routes to App.jsx

**Files:**
- Modify: `src/js/backend/App.jsx`

- [ ] **Step 1: Add `/wizard` nested routes**

```jsx
import WizardLayout      from './wizard/WizardLayout';
import WelcomeStep       from './wizard/steps/WelcomeStep';
import RequirementsStep  from './wizard/steps/RequirementsStep';
import PluginInstallerStep from './wizard/steps/PluginInstallerStep';
import DemoSelectStep    from './wizard/steps/DemoSelectStep';
import ImportOptionsStep from './wizard/steps/ImportOptionsStep';
import ConfirmationStep  from './wizard/steps/ConfirmationStep';
import ImportingStep     from './wizard/steps/ImportingStep';
import CompleteStep      from './wizard/steps/CompleteStep';
```

Add to the `routes` array (alongside existing `/` and `/system_status_page`):

```jsx
{
    path: '/wizard',
    element: (
        <ErrorBoundary>
            <LayoutWithEffects>
                <WizardLayout />
            </LayoutWithEffects>
        </ErrorBoundary>
    ),
    children: [
        { index: true,            element: <Navigate to="/wizard/welcome" replace /> },
        { path: 'welcome',        element: <WelcomeStep /> },
        { path: 'requirements',   element: <RequirementsStep /> },
        { path: 'plugins',        element: <PluginInstallerStep /> },
        { path: 'demos',          element: <DemoSelectStep /> },
        { path: 'options',        element: <ImportOptionsStep /> },
        { path: 'confirm',        element: <ConfirmationStep /> },
        { path: 'importing',      element: <ImportingStep /> },
        { path: 'complete',       element: <CompleteStep /> },
    ],
},
```

- [ ] **Step 2: Update card click in `AppDemoImporter.jsx`**

Find the `DemoCard` onClick handler and replace the modal open logic:

```jsx
// Before: setModalVisible(true); setModalData(demo);
// After:
import { useNavigate } from 'react-router-dom';
// Inside component:
const navigate = useNavigate();
// In the onClick:
const handleDemoSelect = ( demo ) => {
    // Store selected demo in sessionStorage so wizard can read it on load.
    sessionStorage.setItem( 'sd_edi_selected_demo', JSON.stringify( demo ) );
    navigate( '/wizard/welcome' );
};
```

- [ ] **Step 3: Build and verify routing works**

```bash
npm run dev
```
Navigate to `/#/wizard/welcome` in the browser. Confirm WizardLayout renders (even with empty step content). No console errors.

- [ ] **Step 4: Commit**
```bash
git add src/js/backend/App.jsx src/js/backend/AppDemoImporter.jsx
git commit -m "Add /wizard/* routes and wire demo card click to wizard navigation"
```

---

### Task 15: WelcomeStep + RequirementsStep

**Files:**
- Create: `src/js/backend/wizard/steps/WelcomeStep.jsx`
- Create: `src/js/backend/wizard/steps/RequirementsStep.jsx`

- [ ] **Step 1: Create `WelcomeStep.jsx`**

```jsx
import { Button, Tag, Space } from 'antd';
import { useNavigate } from 'react-router-dom';
import {
    ThunderboltOutlined, SafetyOutlined,
    FileSearchOutlined, CheckCircleOutlined,
} from '@ant-design/icons';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const FEATURES = [
    { icon: <ThunderboltOutlined />, label: 'Chunked streaming XML import — no timeouts' },
    { icon: <FileSearchOutlined />,  label: 'Live activity log during import' },
    { icon: <SafetyOutlined />,      label: 'Session-based resumable imports' },
    { icon: <CheckCircleOutlined />, label: 'Auto cache flush when done' },
];

const WelcomeStep = () => {
    const navigate = useNavigate();

    const footer = document.getElementById( 'edi-wizard-next-slot' );

    return (
        <>
            <div style={ { maxWidth: 540 } }>
                <h2 style={ { fontSize: 22, fontWeight: 700, marginBottom: 8 } }>
                    Welcome to the Demo Importer
                </h2>
                <p style={ { color: '#595959', marginBottom: 28, fontSize: 15 } }>
                    Import a complete demo in a few guided steps.
                    The process takes roughly <strong>2–5 minutes</strong> depending on server speed and demo size.
                </p>

                <Space direction="vertical" size={ 12 } style={ { width: '100%' } }>
                    { FEATURES.map( ( f, i ) => (
                        <div key={ i } style={ {
                            display: 'flex', alignItems: 'center', gap: 10,
                            padding: '10px 14px', background: '#f9fafb',
                            borderRadius: 8, border: '1px solid #f0f0f0',
                        } }>
                            <span style={ { color: '#6366f1', fontSize: 16 } }>{ f.icon }</span>
                            <span style={ { fontSize: 14, color: '#262626' } }>{ f.label }</span>
                        </div>
                    ) ) }
                </Space>
            </div>

            { footer && ReactDOM.createPortal(
                <Button
                    type="primary"
                    size="large"
                    onClick={ () => navigate( '/wizard/requirements' ) }
                    icon={ <ThunderboltOutlined /> }
                >
                    Get Started
                </Button>,
                footer
            ) }
        </>
    );
};

export default WelcomeStep;
```

- [ ] **Step 2: Create `RequirementsStep.jsx`**

```jsx
import { Button, Alert, Spin, Tag } from 'antd';
import {
    CheckCircleOutlined, WarningOutlined, CloseCircleOutlined,
} from '@ant-design/icons';
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { Api } from '../../utils/Api';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const STATUS_ICON = {
    pass:    <CheckCircleOutlined style={ { color: '#10b981' } } />,
    warn:    <WarningOutlined     style={ { color: '#f59e0b' } } />,
    fail:    <CloseCircleOutlined style={ { color: '#ef4444' } } />,
};

const RequirementsStep = () => {
    const navigate = useNavigate();
    const [ checks,   setChecks   ] = useState( [] );
    const [ loading,  setLoading  ] = useState( true );
    const [ hasBlock, setHasBlock ] = useState( false );

    useEffect( () => {
        Api.get( '/sd/edi/v1/server/status' )
            .then( ( res ) => {
                const rows  = res.data?.data ?? [];
                const items = rows.map( ( r ) => ( {
                    label:  r.label,
                    value:  r.value,
                    status: r.status, // 'pass' | 'warn' | 'fail'
                    hint:   r.hint ?? '',
                } ) );

                const blocked = items.some( ( i ) => i.status === 'fail' );
                setChecks( items );
                setHasBlock( blocked );
            } )
            .catch( () => setChecks( [] ) )
            .finally( () => setLoading( false ) );
    }, [] );

    const footer = document.getElementById( 'edi-wizard-next-slot' );
    const back   = document.getElementById( 'edi-wizard-back-slot' );

    return (
        <>
            <h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 16 } }>
                Server Requirements
            </h2>

            { loading && <Spin size="large" style={ { display: 'block', margin: '40px auto' } } /> }

            { ! loading && (
                <>
                    { hasBlock && (
                        <Alert
                            type="error"
                            message="One or more requirements are not met. Fix the issues below before continuing."
                            style={ { marginBottom: 20 } }
                            showIcon
                        />
                    ) }

                    <div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
                        { checks.map( ( c, i ) => (
                            <div key={ i } style={ {
                                display: 'flex', justifyContent: 'space-between',
                                alignItems: 'center', padding: '10px 14px',
                                background: '#fafafa', borderRadius: 8,
                                border: '1px solid #f0f0f0',
                            } }>
                                <span style={ { color: '#262626', fontSize: 14 } }>
                                    { STATUS_ICON[ c.status ] }{ ' ' }
                                    { c.label }
                                    { c.hint && (
                                        <span style={ { color: '#8c8c8c', fontSize: 12, marginLeft: 8 } }>
                                            — { c.hint }
                                        </span>
                                    ) }
                                </span>
                                <Tag color={
                                    c.status === 'pass' ? 'success' :
                                    c.status === 'warn' ? 'warning' : 'error'
                                }>
                                    { c.value }
                                </Tag>
                            </div>
                        ) ) }
                    </div>
                </>
            ) }

            { back && ReactDOM.createPortal(
                <Button onClick={ () => navigate( '/wizard/welcome' ) }>Back</Button>,
                back
            ) }

            { footer && ReactDOM.createPortal(
                <Button
                    type="primary"
                    disabled={ loading || hasBlock }
                    onClick={ () => navigate( '/wizard/plugins' ) }
                >
                    Next
                </Button>,
                footer
            ) }
        </>
    );
};

export default RequirementsStep;
```

- [ ] **Step 3: Build, navigate to requirements step, verify**

`/#/wizard/requirements` should load, hit the server status REST endpoint, and render coloured rows. `Next` is disabled if any `fail` row exists.

- [ ] **Step 4: Commit**
```bash
git add src/js/backend/wizard/steps/WelcomeStep.jsx \
        src/js/backend/wizard/steps/RequirementsStep.jsx
git commit -m "Add WelcomeStep and RequirementsStep wizard steps"
```

---

### Task 16: PluginInstallerStep

**Files:**
- Create: `src/js/backend/wizard/steps/PluginInstallerStep.jsx`

The existing `Setup.jsx` modal step handles plugin listing and install/activate. Adapt its plugin-list section into a full-page step.

- [ ] **Step 1: Create `PluginInstallerStep.jsx`**

```jsx
import { Button, Spin } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useEffect } from 'react';
import PluginList from '../../components/PluginList';
import useSharedDataStore from '../../utils/sharedDataStore';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const PluginInstallerStep = () => {
    const navigate = useNavigate();
    const { pluginList, fetchPluginList, loading, setLoading } = useSharedDataStore();

    useEffect( () => {
        setLoading( true );
        fetchPluginList( '/sd/edi/v1/plugin/list' );
    }, [] );

    const demoPluginData = pluginList.success ? pluginList.data : [];
    const pluginArray    = Object.entries( demoPluginData ).map( ( [ key, value ] ) => ( { key, ...value } ) );
    const allActive      = pluginArray.every( ( p ) => p.active );

    const back   = document.getElementById( 'edi-wizard-back-slot' );
    const footer = document.getElementById( 'edi-wizard-next-slot' );

    return (
        <>
            <h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Required Plugins</h2>
            <p style={ { color: '#8c8c8c', marginBottom: 20, fontSize: 14 } }>
                The following plugins are needed for this demo. Install and activate them before proceeding.
            </p>

            { loading
                ? <Spin size="large" style={ { display: 'block', margin: '40px auto' } } />
                : <PluginList pluginData={ pluginArray } />
            }

            { back && ReactDOM.createPortal(
                <Button onClick={ () => navigate( '/wizard/requirements' ) }>Back</Button>,
                back
            ) }

            { footer && ReactDOM.createPortal(
                <Button
                    type="primary"
                    disabled={ loading }
                    onClick={ () => navigate( '/wizard/demos' ) }
                >
                    { allActive ? 'Next' : 'Skip for now' }
                </Button>,
                footer
            ) }
        </>
    );
};

export default PluginInstallerStep;
```

- [ ] **Step 2: Build and verify**

Navigate to `/#/wizard/plugins`. Plugin list should render. Next/Skip button present.

- [ ] **Step 3: Commit**
```bash
git add src/js/backend/wizard/steps/PluginInstallerStep.jsx
git commit -m "Add PluginInstallerStep wizard step"
```

---

### Task 17: DemoSelectStep

**Files:**
- Create: `src/js/backend/wizard/steps/DemoSelectStep.jsx`

- [ ] **Step 1: Create `DemoSelectStep.jsx`**

Reuse the existing demo grid logic from `AppDemoImporter.jsx`. Key difference: clicking a card sets `selectedDemo` in WizardContext and navigates forward.

```jsx
import { Button, Input, Tabs, Empty, Tooltip, Tag } from 'antd';
import { LockOutlined, SearchOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useState, useEffect } from 'react';
import { useWizard } from '../WizardContext';
import DemoCard from '../../components/DemoCard';
import GridSkeleton from '../../components/GridSkeleton';
import useSharedDataStore from '../../utils/sharedDataStore';
import ReactDOM from 'react-dom';

/* global sdEdiAdminParams */

const DemoSelectStep = () => {
    const navigate = useNavigate();
    const { setSelectedDemo } = useWizard();
    const { importList, fetchImportList, loading } = useSharedDataStore();
    const [ search, setSearch ] = useState( '' );
    const [ activeTab, setActiveTab ] = useState( 'all' );

    useEffect( () => {
        fetchImportList( sdEdiAdminParams.restApiUrl + 'sd/edi/v1/demo/list' );
    }, [] );

    const demos      = importList?.data ?? {};
    const demoArray  = Object.entries( demos ).map( ( [ slug, data ] ) => ( { slug, ...data } ) );
    const categories = [ 'all', ...new Set( demoArray.flatMap( ( d ) => d.categories ?? [] ) ) ];

    const filtered = demoArray.filter( ( d ) => {
        const matchTab    = activeTab === 'all' || ( d.categories ?? [] ).includes( activeTab );
        const matchSearch = ! search || d.name?.toLowerCase().includes( search.toLowerCase() );
        return matchTab && matchSearch;
    } );

    const handleSelect = ( demo ) => {
        if ( demo.requires_met === false ) return;
        setSelectedDemo( demo );
        sessionStorage.setItem( 'sd_edi_selected_demo', JSON.stringify( demo ) );
        navigate( '/wizard/options' );
    };

    const back = document.getElementById( 'edi-wizard-back-slot' );

    return (
        <>
            <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 } }>
                <h2 style={ { fontSize: 18, fontWeight: 700, margin: 0 } }>Choose a Demo</h2>
                <Input
                    prefix={ <SearchOutlined /> }
                    placeholder="Search demos…"
                    style={ { width: 220 } }
                    value={ search }
                    onChange={ ( e ) => setSearch( e.target.value ) }
                    allowClear
                />
            </div>

            <Tabs
                activeKey={ activeTab }
                onChange={ setActiveTab }
                items={ categories.map( ( c ) => ( { key: c, label: c === 'all' ? 'All' : c } ) ) }
                style={ { marginBottom: 16 } }
            />

            { loading
                ? <GridSkeleton />
                : filtered.length === 0
                    ? <Empty description="No demos match your search." />
                    : (
                        <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(220px, 1fr))', gap: 16 } }>
                            { filtered.map( ( demo ) => {
                                const locked = demo.requires_met === false;
                                return (
                                    <Tooltip
                                        key={ demo.slug }
                                        title={ locked
                                            ? `Requires: ${ ( demo.requires_missing ?? [] ).join( ', ' ) }`
                                            : '' }
                                    >
                                        <div
                                            style={ { opacity: locked ? 0.5 : 1, cursor: locked ? 'not-allowed' : 'pointer', position: 'relative' } }
                                            onClick={ () => handleSelect( demo ) }
                                        >
                                            { locked && (
                                                <Tag
                                                    icon={ <LockOutlined /> }
                                                    color="default"
                                                    style={ { position: 'absolute', top: 8, right: 8, zIndex: 2 } }
                                                >
                                                    Requirements not met
                                                </Tag>
                                            ) }
                                            <DemoCard data={ demo } disableClick={ locked } />
                                        </div>
                                    </Tooltip>
                                );
                            } ) }
                        </div>
                    )
            }

            { back && ReactDOM.createPortal(
                <Button onClick={ () => navigate( '/wizard/plugins' ) }>Back</Button>,
                back
            ) }
        </>
    );
};

export default DemoSelectStep;
```

- [ ] **Step 2: Verify locked demos are greyed out**

Configure a demo with `'requires' => ['woocommerce/woocommerce.php']` in the sample config. Navigate to `/#/wizard/demos`. The demo card should be greyed out with a Lock tag and tooltip.

- [ ] **Step 3: Commit**
```bash
git add src/js/backend/wizard/steps/DemoSelectStep.jsx
git commit -m "Add DemoSelectStep with conditional demo visibility"
```

---

### Task 18: ImportOptionsStep

**Files:**
- Create: `src/js/backend/wizard/steps/ImportOptionsStep.jsx`

- [ ] **Step 1: Create `ImportOptionsStep.jsx`**

```jsx
import { Button, Switch, Alert, Divider } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useWizard } from '../WizardContext';
import ReactDOM from 'react-dom';

const OPTION_GROUPS = [
    {
        label: 'Content',
        options: [
            { key: 'content',        label: 'Posts & Pages',         desc: 'All WXR content items' },
            { key: 'media',          label: 'Media & Images',        desc: 'Download remote attachments' },
            { key: 'menus',          label: 'Navigation Menus',      desc: 'Menus and menu item assignments' },
        ],
    },
    {
        label: 'Settings',
        options: [
            { key: 'customizer',     label: 'Customizer Settings',   desc: 'Colors, fonts, layout settings' },
            { key: 'widgets',        label: 'Widgets',               desc: 'Sidebar and footer widgets' },
            { key: 'pluginSettings', label: 'Plugin Settings',       desc: 'Theme options and plugin config' },
        ],
    },
    {
        label: 'Database',
        options: [
            { key: 'resetDb',        label: 'Reset Database Before Import',
              desc: 'Deletes existing posts, terms, and options. Use on a fresh install.', danger: true },
        ],
    },
];

const ImportOptionsStep = () => {
    const navigate = useNavigate();
    const { importOptions, updateOption } = useWizard();

    const back   = document.getElementById( 'edi-wizard-back-slot' );
    const footer = document.getElementById( 'edi-wizard-next-slot' );

    return (
        <>
            <h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Import Options</h2>
            <p style={ { color: '#8c8c8c', marginBottom: 24, fontSize: 14 } }>
                Choose what to include in the import. All options are enabled by default.
            </p>

            { OPTION_GROUPS.map( ( group ) => (
                <div key={ group.label } style={ { marginBottom: 24 } }>
                    <div style={ { fontSize: 12, fontWeight: 700, color: '#8c8c8c', textTransform: 'uppercase', letterSpacing: '0.6px', marginBottom: 10 } }>
                        { group.label }
                    </div>
                    <div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
                        { group.options.map( ( opt ) => (
                            <div key={ opt.key } style={ {
                                display: 'flex', justifyContent: 'space-between', alignItems: 'center',
                                padding: '12px 14px', background: '#fafafa',
                                borderRadius: 8, border: `1px solid ${ opt.danger && importOptions[ opt.key ] ? '#ffa39e' : '#f0f0f0' }`,
                            } }>
                                <div>
                                    <div style={ { fontSize: 14, fontWeight: 500, color: opt.danger ? '#cf1322' : '#262626' } }>
                                        { opt.label }
                                    </div>
                                    <div style={ { fontSize: 12, color: '#8c8c8c' } }>{ opt.desc }</div>
                                </div>
                                <Switch
                                    checked={ importOptions[ opt.key ] }
                                    onChange={ ( v ) => updateOption( opt.key, v ) }
                                />
                            </div>
                        ) ) }
                    </div>
                </div>
            ) ) }

            { importOptions.resetDb && (
                <Alert
                    type="warning"
                    showIcon
                    message="Database reset is enabled. All existing posts, pages, and settings will be permanently deleted before import."
                    style={ { marginTop: 8 } }
                />
            ) }

            { back && ReactDOM.createPortal(
                <Button onClick={ () => navigate( '/wizard/demos' ) }>Back</Button>,
                back
            ) }

            { footer && ReactDOM.createPortal(
                <Button type="primary" onClick={ () => navigate( '/wizard/confirm' ) }>
                    Review & Confirm
                </Button>,
                footer
            ) }
        </>
    );
};

export default ImportOptionsStep;
```

- [ ] **Step 2: Commit**
```bash
git add src/js/backend/wizard/steps/ImportOptionsStep.jsx
git commit -m "Add ImportOptionsStep with content/settings/database toggles"
```

---

### Task 19: ConfirmationStep

**Files:**
- Create: `src/js/backend/wizard/steps/ConfirmationStep.jsx`

- [ ] **Step 1: Create `ConfirmationStep.jsx`**

```jsx
import { Button, Statistic, Row, Col, Spin, Alert } from 'antd';
import {
    FileTextOutlined, PictureOutlined, AppstoreOutlined,
    MenuOutlined, ThunderboltOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useEffect } from 'react';
import { useWizard } from '../WizardContext';
import { Api } from '../../utils/Api';
import ReactDOM from 'react-dom';

const ConfirmationStep = () => {
    const navigate = useNavigate();
    const { selectedDemo, dryRunStats, setDryRunStats, importOptions } = useWizard();

    useEffect( () => {
        if ( ! selectedDemo || dryRunStats ) return;

        Api.get( `/sd/edi/v1/demo-stats?demo=${ selectedDemo.slug ?? '' }` )
            .then( ( res ) => setDryRunStats( res.data ) )
            .catch( () => setDryRunStats( { error: true } ) );
    }, [ selectedDemo ] );

    const back   = document.getElementById( 'edi-wizard-back-slot' );
    const footer = document.getElementById( 'edi-wizard-next-slot' );

    const byType     = dryRunStats?.by_type ?? {};
    const pages      = byType.page ?? 0;
    const posts      = byType.post ?? 0;
    const products   = byType.product ?? 0;
    const attachments = dryRunStats?.attachments ?? 0;
    const total      = dryRunStats?.total ?? 0;

    return (
        <>
            <h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Ready to Import</h2>
            <p style={ { color: '#8c8c8c', marginBottom: 24, fontSize: 14 } }>
                Review what will be imported, then click <strong>Start Import</strong>.
            </p>

            { ! dryRunStats && (
                <div style={ { textAlign: 'center', padding: '40px 0' } }>
                    <Spin size="large" />
                    <p style={ { marginTop: 16, color: '#8c8c8c' } }>Analysing demo content…</p>
                </div>
            ) }

            { dryRunStats?.error && (
                <Alert
                    type="warning"
                    showIcon
                    message="Could not analyse demo content. The import will still run."
                    style={ { marginBottom: 20 } }
                />
            ) }

            { dryRunStats && ! dryRunStats.error && (
                <>
                    <div style={ { background: '#f9fafb', border: '1px solid #f0f0f0', borderRadius: 10, padding: '20px 24px', marginBottom: 24 } }>
                        <Row gutter={ 24 }>
                            { pages > 0      && <Col><Statistic title="Pages"       value={ pages }       prefix={ <FileTextOutlined /> } /></Col> }
                            { posts > 0      && <Col><Statistic title="Posts"       value={ posts }       prefix={ <FileTextOutlined /> } /></Col> }
                            { products > 0   && <Col><Statistic title="Products"    value={ products }    prefix={ <AppstoreOutlined /> } /></Col> }
                            { attachments > 0 && <Col><Statistic title="Images"     value={ attachments } prefix={ <PictureOutlined /> }  /></Col> }
                            { total > 0      && <Col><Statistic title="Total Items" value={ total }                                       /></Col> }
                        </Row>
                    </div>

                    <div style={ { fontSize: 13, color: '#595959', marginBottom: 8 } }>
                        <strong>Import will include:</strong>{ ' ' }
                        { Object.entries( importOptions )
                            .filter( ( [ , v ] ) => v )
                            .map( ( [ k ] ) => k )
                            .join( ', ' )
                        }
                    </div>
                </>
            ) }

            { back && ReactDOM.createPortal(
                <Button onClick={ () => navigate( '/wizard/options' ) }>Back</Button>,
                back
            ) }

            { footer && ReactDOM.createPortal(
                <Button
                    type="primary"
                    icon={ <ThunderboltOutlined /> }
                    disabled={ ! dryRunStats }
                    onClick={ () => navigate( '/wizard/importing' ) }
                >
                    Start Import
                </Button>,
                footer
            ) }
        </>
    );
};

export default ConfirmationStep;
```

- [ ] **Step 2: Commit**
```bash
git add src/js/backend/wizard/steps/ConfirmationStep.jsx
git commit -m "Add ConfirmationStep with dry-run stats display"
```

---

### Task 20: ActivityFeed Component

**Files:**
- Create: `src/js/backend/components/ActivityFeed.jsx`

- [ ] **Step 1: Create `ActivityFeed.jsx`**

```jsx
import { useEffect, useRef, useState } from 'react';
import { Empty, Tag } from 'antd';
import {
    InfoCircleOutlined, CheckCircleOutlined,
    WarningOutlined, CloseCircleOutlined,
    LoadingOutlined,
} from '@ant-design/icons';
import { Api } from '../utils/Api';

const LEVEL_CONFIG = {
    info:    { icon: <InfoCircleOutlined />,    color: '#1677ff', bg: '#e6f4ff' },
    success: { icon: <CheckCircleOutlined />,   color: '#52c41a', bg: '#f6ffed' },
    warning: { icon: <WarningOutlined />,       color: '#faad14', bg: '#fffbe6' },
    error:   { icon: <CloseCircleOutlined />,   color: '#ff4d4f', bg: '#fff2f0' },
};

/**
 * ActivityFeed — polls /sd/edi/v1/import-log while active is true.
 *
 * @param {object} props
 * @param {string} props.sessionId  Active import session UUID.
 * @param {boolean} props.active    Set to false to stop polling.
 */
const ActivityFeed = ( { sessionId, active = true } ) => {
    const [ entries, setEntries ] = useState( [] );
    const scrollRef = useRef( null );
    const sinceRef  = useRef( '' );

    useEffect( () => {
        if ( ! sessionId ) return;

        const poll = () => {
            Api.get( `/sd/edi/v1/import-log`, {
                params: { session_id: sessionId, since: sinceRef.current },
            } )
            .then( ( res ) => {
                const rows = Array.isArray( res.data ) ? res.data : [];
                if ( rows.length ) {
                    sinceRef.current = rows[ rows.length - 1 ].timestamp;
                    setEntries( ( prev ) => [ ...prev, ...rows ] );
                }
            } )
            .catch( () => {} );
        };

        poll();

        if ( ! active ) return;

        const interval = setInterval( poll, 2000 );
        return () => clearInterval( interval );
    }, [ sessionId, active ] );

    // Auto-scroll to bottom on new entries.
    useEffect( () => {
        if ( scrollRef.current ) {
            scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
        }
    }, [ entries ] );

    return (
        <div style={ {
            border: '1px solid #f0f0f0', borderRadius: 8,
            background: '#fafafa', overflow: 'hidden',
        } }>
            <div style={ {
                padding: '10px 16px', borderBottom: '1px solid #f0f0f0',
                display: 'flex', alignItems: 'center', gap: 8,
                fontSize: 13, fontWeight: 600, color: '#595959',
            } }>
                { active && <LoadingOutlined style={ { color: '#6366f1' } } /> }
                Activity Log
            </div>

            <div
                ref={ scrollRef }
                style={ { maxHeight: 280, overflowY: 'auto', padding: entries.length ? '12px 16px' : 0 } }
            >
                { entries.length === 0
                    ? <Empty image={ Empty.PRESENTED_IMAGE_SIMPLE } description="Waiting for activity…" style={ { margin: '20px 0' } } />
                    : entries.map( ( e ) => {
                        const cfg = LEVEL_CONFIG[ e.level ] ?? LEVEL_CONFIG.info;
                        const time = new Date( e.timestamp ).toLocaleTimeString( 'en-US', {
                            hour: '2-digit', minute: '2-digit', second: '2-digit',
                        } );
                        return (
                            <div key={ e.id } style={ {
                                display: 'flex', alignItems: 'flex-start', gap: 10,
                                marginBottom: 8, padding: '8px 10px', borderRadius: 6,
                                background: cfg.bg, border: `1px solid ${ cfg.color }22`,
                            } }>
                                <span style={ { color: cfg.color, fontSize: 14, marginTop: 1 } }>
                                    { cfg.icon }
                                </span>
                                <span style={ { flex: 1, fontSize: 13, color: '#262626', lineHeight: 1.5 } }>
                                    { e.message }
                                </span>
                                <span style={ { fontSize: 11, color: '#bfbfbf', whiteSpace: 'nowrap' } }>
                                    { time }
                                </span>
                            </div>
                        );
                    } )
                }
            </div>
        </div>
    );
};

export default ActivityFeed;
```

- [ ] **Step 2: Commit**
```bash
git add src/js/backend/components/ActivityFeed.jsx
git commit -m "Add ActivityFeed component with 2s polling and auto-scroll"
```

---

### Task 21: ImportingStep

**Files:**
- Create: `src/js/backend/wizard/steps/ImportingStep.jsx`

This step drives the chunk polling loop and shows the ActivityFeed.

- [ ] **Step 1: Create `ImportingStep.jsx`**

```jsx
import { Progress, Alert } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useState, useEffect, useRef } from 'react';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';
import { Api } from '../../utils/Api';

/* global sdEdiAdminParams, ajaxurl */

const AJAX_STEPS = [
    { action: 'sd_edi_initialize',    label: 'Downloading demo files…'       },
    { action: 'sd_edi_install_plugins', label: 'Installing plugins…'         },
    { action: 'sd_edi_install_demo',  label: 'Preparing XML content…'        },
    // sd_edi_import_xml_chunk runs in a loop (see runChunkLoop)
    { action: 'sd_edi_import_menus',  label: 'Importing navigation menus…'   },
    { action: 'sd_edi_import_widgets', label: 'Importing widgets…'           },
    { action: 'sd_edi_customizer_import', label: 'Applying customizer…'      },
    { action: 'sd_edi_import_settings', label: 'Importing theme settings…'   },
    { action: 'sd_edi_import_fluent_forms', label: 'Importing forms…'        },
    { action: 'sd_edi_activate_plugins', label: 'Activating plugins…'        },
    { action: 'sd_edi_finalize',      label: 'Finalising and flushing caches…' },
];

const ImportingStep = () => {
    const navigate    = useNavigate();
    const { importOptions, selectedDemo } = useWizard();
    const { activeSessionId, setActiveSessionId, resetStore } = useSharedDataStore();

    const [ stepLabel,   setStepLabel   ] = useState( 'Starting…' );
    const [ xmlProgress, setXmlProgress ] = useState( { done: 0, total: 0 } );
    const [ overallPct,  setOverallPct  ] = useState( 0 );
    const [ error,       setError       ] = useState( null );
    const [ done,        setDone        ] = useState( false );
    const [ sessionId,   setSessionId   ] = useState( activeSessionId || '' );

    const abortRef = useRef( false );

    // ── AJAX helper ─────────────────────────────────────────────────────────
    const ajaxPost = async ( action, extra = {} ) => {
        const body = new URLSearchParams( {
            action,
            nonce:     sdEdiAdminParams.nonce,
            sessionId: sessionId,
            demo:      selectedDemo?.slug ?? '',
            excludeImages:           importOptions.media          ? '' : '1',
            reset:                   importOptions.resetDb        ? 'true' : 'false',
            skipImageRegeneration:   'true', // Phase 3 owns regen
            ...extra,
        } );

        const res  = await fetch( ajaxurl, { method: 'POST', body } );
        const json = await res.json();

        if ( ! json.success ) {
            throw new Error( json.data?.errorMessage ?? 'Unknown error' );
        }

        return json.data;
    };

    // ── XML chunk polling loop ───────────────────────────────────────────────
    const runChunkLoop = async ( totalItems ) => {
        let offset = 0;

        while ( offset < totalItems && ! abortRef.current ) {
            setStepLabel( `Importing content… ${ offset } / ${ totalItems }` );

            const data = await ajaxPost( 'sd_edi_import_xml_chunk', { offset } );

            setXmlProgress( { done: data.done, total: data.total } );
            setOverallPct( Math.round( ( data.done / data.total ) * 60 ) + 20 ); // 20–80%

            offset = data.offset ?? data.done;

            if ( data.done >= data.total ) break;
        }
    };

    // ── Main import sequence ─────────────────────────────────────────────────
    useEffect( () => {
        let sid = sessionId;

        const run = async () => {
            try {
                // Step 1 — Initialize (also starts session).
                setStepLabel( 'Downloading demo files…' );
                setOverallPct( 5 );
                const initData = await ajaxPost( 'sd_edi_initialize' );
                sid = initData.sessionId ?? sid;
                setSessionId( sid );
                setActiveSessionId( sid );

                // Step 2 — Plugins.
                setStepLabel( 'Installing plugins…' );
                setOverallPct( 10 );
                await ajaxPost( 'sd_edi_install_plugins' );

                // Step 3 — Queue XML.
                setStepLabel( 'Analysing demo content…' );
                setOverallPct( 18 );
                const queueData = await ajaxPost( 'sd_edi_install_demo' );
                const totalItems = queueData?.total ?? 0;

                // Step 4 — Chunk loop (20% → 80% of progress bar).
                await runChunkLoop( totalItems );

                // Steps 5–10 — Remaining linear steps.
                const remaining = [
                    { action: 'sd_edi_import_menus',        label: 'Importing menus…',      pct: 82 },
                    { action: 'sd_edi_import_widgets',       label: 'Importing widgets…',    pct: 85 },
                    { action: 'sd_edi_customizer_import',    label: 'Applying customizer…',  pct: 88 },
                    { action: 'sd_edi_import_settings',      label: 'Importing settings…',   pct: 91 },
                    { action: 'sd_edi_import_fluent_forms',  label: 'Importing forms…',      pct: 93 },
                    { action: 'sd_edi_activate_plugins',     label: 'Activating plugins…',   pct: 95 },
                    { action: 'sd_edi_finalize',             label: 'Finalising…',            pct: 99 },
                ];

                for ( const step of remaining ) {
                    if ( abortRef.current ) break;
                    setStepLabel( step.label );
                    setOverallPct( step.pct );
                    await ajaxPost( step.action );
                }

                setOverallPct( 100 );
                setDone( true );
                setTimeout( () => navigate( '/wizard/complete' ), 800 );

            } catch ( err ) {
                setError( err.message );
            }
        };

        run();

        return () => { abortRef.current = true; };
    }, [] );

    const pct = xmlProgress.total > 0
        ? Math.round( ( xmlProgress.done / xmlProgress.total ) * 100 )
        : null;

    return (
        <div>
            <div style={ {
                background: '#fffbe6', border: '1px solid #ffe58f',
                borderRadius: 8, padding: '10px 14px',
                fontSize: 13, color: '#ad6800', marginBottom: 20,
                display: 'flex', alignItems: 'center', gap: 8,
            } }>
                <WarningOutlined />
                Do not close this tab while the import is running.
            </div>

            <div style={ { marginBottom: 24 } }>
                <div style={ { fontSize: 14, color: '#595959', marginBottom: 8 } }>{ stepLabel }</div>
                <Progress
                    percent={ overallPct }
                    status={ error ? 'exception' : done ? 'success' : 'active' }
                    strokeColor={ { '0%': '#6366f1', '100%': '#818cf8' } }
                />

                { pct !== null && (
                    <div style={ { fontSize: 12, color: '#8c8c8c', marginTop: 4 } }>
                        XML content: { xmlProgress.done } / { xmlProgress.total } items ({ pct }%)
                    </div>
                ) }
            </div>

            { error && (
                <Alert
                    type="error"
                    showIcon
                    message="Import Error"
                    description={ error }
                    style={ { marginBottom: 20 } }
                />
            ) }

            <ActivityFeed sessionId={ sessionId } active={ ! done && ! error } />
        </div>
    );
};

export default ImportingStep;
```

- [ ] **Step 2: Build and run a full import through the wizard**

Start from `/#/wizard/welcome`, select a demo, choose options, confirm, and let the importing step run to completion. Verify:
- Overall progress bar advances
- XML chunk progress shows per-item counts
- ActivityFeed populates with log entries
- On completion, wizard auto-navigates to `/wizard/complete`

- [ ] **Step 3: Commit**
```bash
git add src/js/backend/wizard/steps/ImportingStep.jsx
git commit -m "Add ImportingStep with chunk polling loop, progress bar, and ActivityFeed"
```

---

### Task 22: CompleteStep

**Files:**
- Create: `src/js/backend/wizard/steps/CompleteStep.jsx`

- [ ] **Step 1: Create `CompleteStep.jsx`**

```jsx
import { Button, Result, Space } from 'antd';
import {
    EyeOutlined, SettingOutlined, BookOutlined, ReloadOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useWizard } from '../WizardContext';
import useSharedDataStore from '../../utils/sharedDataStore';
import ActivityFeed from '../../components/ActivityFeed';

/* global sdEdiAdminParams */

const CompleteStep = () => {
    const navigate = useNavigate();
    const { resetWizard }    = useWizard();
    const { activeSessionId, resetStore } = useSharedDataStore();

    const handleImportAnother = () => {
        resetWizard();
        resetStore();
        navigate( '/wizard/welcome' );
    };

    return (
        <div>
            <Result
                status="success"
                title="Import Complete!"
                subTitle="Your demo content has been imported successfully. Caches have been flushed."
                extra={
                    <Space wrap>
                        <Button
                            type="primary"
                            icon={ <EyeOutlined /> }
                            href={ sdEdiAdminParams.siteUrl }
                            target="_blank"
                        >
                            View Site
                        </Button>
                        <Button
                            icon={ <SettingOutlined /> }
                            href={ sdEdiAdminParams.customizeUrl }
                            target="_blank"
                        >
                            Customize
                        </Button>
                        <Button
                            icon={ <BookOutlined /> }
                            href="https://docs.sigmadevs.com/easy-demo-importer"
                            target="_blank"
                        >
                            Documentation
                        </Button>
                        <Button
                            icon={ <ReloadOutlined /> }
                            onClick={ handleImportAnother }
                        >
                            Import Another Demo
                        </Button>
                    </Space>
                }
                style={ { marginBottom: 24 } }
            />

            { activeSessionId && (
                <ActivityFeed sessionId={ activeSessionId } active={ false } />
            ) }
        </div>
    );
};

export default CompleteStep;
```

- [ ] **Step 2: Add `customizeUrl` and `siteUrl` to the PHP localized vars**

In `inc/App/Backend/Enqueue.php`, find the `wp_localize_script` call and add:

```php
'siteUrl'      => get_site_url(),
'customizeUrl' => admin_url( 'customize.php' ),
```

- [ ] **Step 3: Full end-to-end smoke test**

1. Navigate to `/#/wizard/welcome`
2. Complete all 8 steps
3. Verify CompleteStep shows success result with quick links
4. ActivityFeed shows the complete import log (read-only, polling stopped)
5. "Import Another Demo" resets state and goes back to welcome

- [ ] **Step 4: Build production assets**
```bash
npm run production
```
Confirm no build errors. Check bundle size hasn't grown unreasonably.

- [ ] **Step 5: Commit**
```bash
git add src/js/backend/wizard/steps/CompleteStep.jsx inc/App/Backend/Enqueue.php
git commit -m "Add CompleteStep with import summary, quick links, and full activity log"
```

---

## Final Steps

- [ ] **Bump version to `1.3.0`** in `easy-demo-importer.php`, `readme.txt` (`Stable tag`), `package.json`
- [ ] **Write `1.3.0` changelog entry** in `readme.txt`
- [ ] **Run PHPStan** — `vendor/bin/phpstan analyse inc/ --level=5` — fix any new errors
- [ ] **Run PHPCS** — `vendor/bin/phpcs inc/ --standard=WordPress` — fix errors, commit
- [ ] **Run Rector** — `vendor/bin/rector process inc/ --dry-run` — confirm zero changes needed
- [ ] **Build production** — `npm run production`
- [ ] **Final smoke test** on WP 6.9 + PHP 8.4 (wp-env or staging)

```bash
git add .
git commit -m "Bump version to 1.3.0 and write changelog"
```

---

*Plan authored: 2026-03-10 | Phase 2 of Easy Demo Importer roadmap*
