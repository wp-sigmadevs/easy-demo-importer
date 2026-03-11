# Phase 4 — Power Import Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 5 power-import features to v1.5.0: selective item picking, dependency resolution, import rollback, auto URL fix, and pre-import conflict detection.

**Architecture:** Each subsystem is an independent PHP utility class. REST endpoints expose them to the React wizard. The wizard gains a new `SelectItemsStep` between Options and Confirm. `WizardContext` carries `selectedIds` + `snapshotId` across steps. `XmlChunker::extractChunk()` already accepts `allowed_ids`; `ImportXmlChunk` only needs to read and forward them. `SnapshotManager` captures watermarks before import starts; `UrlReplacer` runs once the XML chunks finish.

**Tech Stack:** PHP 8.1, WordPress REST API, React 18, Ant Design 5, React Router 6 hash router

---

## File Map

### New PHP Files
| File | Purpose |
|------|---------|
| `inc/Common/Utils/SnapshotManager.php` | Create/restore/expire import snapshots |
| `inc/Common/Utils/DependencyResolver.php` | Scan WXR for hard + soft deps |
| `inc/Common/Utils/UrlReplacer.php` | DB search-replace demo URL → site URL after import |
| `inc/App/Rest/DemoItems.php` | `GET /sd/edi/v1/demo-items` — list items per post type |
| `inc/App/Rest/ResolveDeps.php` | `POST /sd/edi/v1/resolve-deps` — classify hard/soft deps |
| `inc/App/Rest/Rollback.php` | `POST /sd/edi/v1/rollback/{id}` — undo an import |

### Modified PHP Files
| File | Change |
|------|--------|
| `inc/Config/Setup.php` | Add `wp_sd_edi_snapshots` table, bump `DB_VERSION` to `1.5.0` |
| `inc/App/Rest/RestEndpoints.php` | Register `DemoItems`, `ResolveDeps`, `Rollback` routes |
| `inc/App/Ajax/Backend/Initialize.php` | Call `SnapshotManager::create()` after session start; return `snapshotId` |
| `inc/App/Ajax/Backend/ImportXmlChunk.php` | Read `allowedIds[]` POST param; call `UrlReplacer::run()` on final chunk |

### New React Files
| File | Purpose |
|------|---------|
| `src/js/backend/wizard/steps/SelectItemsStep.jsx` | Tabbed item picker (new step between Options → Confirm) |

### Modified React Files
| File | Change |
|------|--------|
| `src/js/backend/wizard/WizardContext.jsx` | Add `selectedIds`, `setSelectedIds`, `snapshotId`, `setSnapshotId` |
| `src/js/backend/wizard/WizardLayout.jsx` | Add `select-items` between `options` and `confirm` in STEPS |
| `src/js/backend/App.jsx` | Add `/wizard/select-items` route |
| `src/js/backend/wizard/steps/ImportOptionsStep.jsx` | "Next" → `/wizard/select-items` if content ON, else `/wizard/confirm` |
| `src/js/backend/wizard/steps/ConfirmationStep.jsx` | Call `/resolve-deps`, show soft dep checkboxes; Back → select-items |
| `src/js/backend/wizard/steps/ImportingStep.jsx` | Pass `allowedIds[]` in all AJAX calls; capture `snapshotId` from init response |
| `src/js/backend/wizard/steps/CompleteStep.jsx` | Undo Import button + rollback flow |
| `src/js/backend/wizard/steps/RequirementsStep.jsx` | Conflict detection: hard blocks + soft warnings |

---

## Chunk 1: Backend Infrastructure

### Task 1: Snapshots DB Table

**Files:**
- Modify: `inc/Config/Setup.php`

- [ ] **Step 1: Add `createSnapshotsTable()` method to Setup.php**

  Add after `createImportTables()` in Setup.php. The new table stores one row per import, expires after 24 hours.

  Add to `activation()` and `maybeUpgradeDb()`:
  ```php
  self::createSnapshotsTable();
  ```

  Add the method:
  ```php
  private static function createSnapshotsTable(): void {
      global $wpdb;
      $wpdb->hide_errors();
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      $collate = $wpdb->has_cap( 'collation' ) ? $wpdb->get_charset_collate() : '';
      $table   = $wpdb->prefix . 'sd_edi_snapshots';
      dbDelta(
          "CREATE TABLE $table (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          session_id VARCHAR(36) NOT NULL,
          demo_slug VARCHAR(200) NOT NULL DEFAULT '',
          snapshot_data LONGTEXT NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          expires_at DATETIME NOT NULL,
          PRIMARY KEY (id),
          KEY session_id (session_id),
          KEY expires_at (expires_at)
      ) $collate;"
      );
  }
  ```

- [ ] **Step 2: Bump DB_VERSION to `'1.5.0'`**

  Change `private const DB_VERSION = '1.3.0';` → `'1.5.0'`

  Update `maybeUpgradeDb()` to also call `self::createSnapshotsTable()`.

- [ ] **Step 3: Verify**

  Deactivate + reactivate the plugin (or run `maybeUpgradeDb()` manually via WP-CLI):
  ```
  wp db query "SHOW TABLES LIKE '%sd_edi%';"
  ```
  Expected: `wp_sd_edi_snapshots` appears in output.

- [ ] **Step 4: Commit**
  ```bash
  git add inc/Config/Setup.php
  git commit -m "feat: add wp_sd_edi_snapshots table for rollback (Task 1)"
  ```

---

### Task 2: SnapshotManager.php

**Files:**
- Create: `inc/Common/Utils/SnapshotManager.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * Utility: SnapshotManager
   *
   * Creates and restores pre-import snapshots for rollback.
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\Common\Utils;
  use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class SnapshotManager {
      /**
       * Create a snapshot watermark before import starts.
       * Returns the snapshot row ID (used as rollback token).
       */
      public static function create( string $session_id, string $demo_slug ): int {
          global $wpdb;
          $max_post_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
          $max_term_id = (int) $wpdb->get_var( "SELECT MAX(term_id) FROM {$wpdb->terms}" );
          $options     = [];
          foreach ( [ 'sidebars_widgets', 'nav_menu_locations' ] as $key ) {
              $options[ $key ] = get_option( $key );
          }
          // Snapshot active theme mods.
          $theme_mods_key       = 'theme_mods_' . get_stylesheet();
          $options[ $theme_mods_key ] = get_option( $theme_mods_key );

          $snapshot = [
              'max_post_id'  => $max_post_id,
              'max_term_id'  => $max_term_id,
              'options'      => $options,
              'snapshot_time' => current_time( 'mysql' ),
          ];

          $table = $wpdb->prefix . 'sd_edi_snapshots';
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->insert(
              $table,
              [
                  'session_id'    => $session_id,
                  'demo_slug'     => $demo_slug,
                  'snapshot_data' => wp_json_encode( $snapshot ),
                  'created_at'    => current_time( 'mysql' ),
                  'expires_at'    => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
              ],
              [ '%s', '%s', '%s', '%s', '%s' ]
          );
          return (int) $wpdb->insert_id;
      }

      /**
       * Get a non-expired snapshot by ID.
       * Returns null if not found or expired.
       */
      public static function get( int $snapshot_id ): ?array {
          global $wpdb;
          $table = $wpdb->prefix . 'sd_edi_snapshots';
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $row = $wpdb->get_row(
              $wpdb->prepare(
                  "SELECT * FROM $table WHERE id = %d AND expires_at > %s",
                  $snapshot_id,
                  current_time( 'mysql' )
              ),
              ARRAY_A
          );
          return $row ?: null;
      }

      /**
       * Get the latest non-expired snapshot (for CompleteStep Undo button).
       */
      public static function getLatest(): ?array {
          global $wpdb;
          $table = $wpdb->prefix . 'sd_edi_snapshots';
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $row = $wpdb->get_row(
              $wpdb->prepare(
                  "SELECT * FROM $table WHERE expires_at > %s ORDER BY created_at DESC LIMIT 1",
                  current_time( 'mysql' )
              ),
              ARRAY_A
          );
          return $row ?: null;
      }

      /**
       * Restore from snapshot: delete posts/terms above watermarks, restore options.
       * Returns [ 'posts_deleted' => int, 'terms_deleted' => int ].
       */
      public static function restore( int $snapshot_id, string $session_id ): array {
          global $wpdb;
          $row = self::get( $snapshot_id );
          if ( ! $row ) {
              return [ 'error' => 'Snapshot not found or expired.' ];
          }
          $data        = json_decode( $row['snapshot_data'], true );
          $max_post_id = (int) ( $data['max_post_id'] ?? 0 );
          $max_term_id = (int) ( $data['max_term_id'] ?? 0 );
          $snap_time   = $data['snapshot_time'] ?? '';
          $options     = $data['options'] ?? [];

          // Delete posts created after snapshot.
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $posts = $wpdb->get_col(
              $wpdb->prepare(
                  "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_date >= %s",
                  $max_post_id,
                  $snap_time
              )
          );
          $posts_deleted = 0;
          foreach ( $posts as $post_id ) {
              wp_delete_post( (int) $post_id, true );
              ++$posts_deleted;
          }

          // Delete terms created after snapshot.
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
          $terms = $wpdb->get_col(
              $wpdb->prepare(
                  "SELECT term_id FROM {$wpdb->terms} WHERE term_id > %d",
                  $max_term_id
              )
          );
          $terms_deleted = 0;
          foreach ( $terms as $term_id ) {
              // Get taxonomy before deleting.
              // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
              $tt = $wpdb->get_var(
                  $wpdb->prepare(
                      "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
                      (int) $term_id
                  )
              );
              if ( $tt ) {
                  wp_delete_term( (int) $term_id, $tt );
                  ++$terms_deleted;
              }
          }

          // Restore options.
          foreach ( $options as $key => $value ) {
              update_option( $key, $value );
          }

          // Flush.
          wp_cache_flush();
          flush_rewrite_rules( false );

          // Delete snapshot row.
          $table = $wpdb->prefix . 'sd_edi_snapshots';
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->delete( $table, [ 'id' => $snapshot_id ], [ '%d' ] );

          ImportLogger::log(
              sprintf(
                  /* translators: 1: posts, 2: terms */
                  __( 'Rollback complete: %1$d posts and %2$d terms deleted; options restored.', 'easy-demo-importer' ),
                  $posts_deleted,
                  $terms_deleted
              ),
              'success',
              $session_id
          );

          return [
              'posts_deleted' => $posts_deleted,
              'terms_deleted' => $terms_deleted,
          ];
      }

      /**
       * Delete all expired snapshots. Run on admin_init or on plugin activation.
       */
      public static function purgeExpired(): void {
          global $wpdb;
          $table = $wpdb->prefix . 'sd_edi_snapshots';
          // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
          $wpdb->query(
              $wpdb->prepare(
                  "DELETE FROM $table WHERE expires_at < %s",
                  current_time( 'mysql' )
              )
          );
      }
  }
  ```

- [ ] **Step 2: Verify class loads**

  Add a temporary `add_action( 'init', function() { new \SigmaDevs\EasyDemoImporter\Common\Utils\SnapshotManager(); } );` in a test and check for PHP errors.

- [ ] **Step 3: Commit**
  ```bash
  git add inc/Common/Utils/SnapshotManager.php
  git commit -m "feat: add SnapshotManager for import rollback (Task 2)"
  ```

---

### Task 3: DependencyResolver.php

**Files:**
- Create: `inc/Common/Utils/DependencyResolver.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * Utility: DependencyResolver
   *
   * Scans selected WXR item IDs and classifies dependencies as hard (auto-add)
   * or soft (advisory/optional).
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\Common\Utils;
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class DependencyResolver {
      /**
       * Analyse selected post IDs and return hard + soft dependency arrays.
       *
       * Hard deps: parent pages, attachment parents — auto-included silently.
       * Soft deps: nav menus, sidebar widgets — shown as optional.
       *
       * @param string  $file_path    Absolute path to WXR file.
       * @param int[]   $selected_ids Post IDs the user has selected.
       * @return array{hard: int[], soft: list<array{id:int, label:string, type:string}>}
       */
      public static function resolve( string $file_path, array $selected_ids ): array {
          if ( ! file_exists( $file_path ) || empty( $selected_ids ) ) {
              return [ 'hard' => [], 'soft' => [] ];
          }

          // Build a map of all items: id → [post_type, post_parent, post_title].
          $all_items = self::indexItems( $file_path );

          $hard  = [];
          $soft  = [];
          $added = array_flip( $selected_ids );

          foreach ( $selected_ids as $id ) {
              $item = $all_items[ $id ] ?? null;
              if ( ! $item ) {
                  continue;
              }
              // Hard: parent page.
              $parent = (int) $item['post_parent'];
              if ( $parent > 0 && ! isset( $added[ $parent ] ) && isset( $all_items[ $parent ] ) ) {
                  $hard[]        = $parent;
                  $added[$parent] = true;
              }
          }

          // Soft: nav menus (post_type = nav_menu_item) not in selection.
          foreach ( $all_items as $id => $item ) {
              if ( 'nav_menu_item' === $item['post_type'] && ! isset( $added[ $id ] ) ) {
                  $soft[] = [
                      'id'    => $id,
                      'label' => $item['post_title'] ?: "Menu Item #$id",
                      'type'  => 'nav_menu_item',
                  ];
              }
          }

          return [
              'hard' => array_values( array_unique( $hard ) ),
              'soft' => array_slice( $soft, 0, 50 ), // Cap soft list at 50 for UX.
          ];
      }

      /**
       * Build an in-memory index: post_id → [post_type, post_parent, post_title].
       *
       * @param string $file_path
       * @return array<int, array{post_type: string, post_parent: int, post_title: string}>
       */
      private static function indexItems( string $file_path ): array {
          $index   = [];
          $reader  = new \XMLReader();
          if ( ! $reader->open( $file_path ) ) {
              return $index;
          }
          $in_item     = false;
          $post_id     = 0;
          $post_type   = '';
          $post_parent = 0;
          $post_title  = '';

          while ( $reader->read() ) {
              if ( \XMLReader::ELEMENT === $reader->nodeType ) {
                  $local = $reader->localName;
                  $ns    = $reader->namespaceURI;
                  if ( 'item' === $local && '' === $ns ) {
                      $in_item     = true;
                      $post_id     = 0; $post_type = ''; $post_parent = 0; $post_title = '';
                      continue;
                  }
                  if ( $in_item ) {
                      if ( 'title' === $local && '' === $ns ) {
                          $reader->read(); $post_title = trim( $reader->value );
                      } elseif ( false !== strpos( $ns, 'wordpress.org/export' ) ) {
                          if ( 'post_id' === $local )     { $reader->read(); $post_id     = (int) $reader->value; }
                          if ( 'post_type' === $local )   { $reader->read(); $post_type   = trim( $reader->value ); }
                          if ( 'post_parent' === $local ) { $reader->read(); $post_parent = (int) $reader->value; }
                      }
                  }
              }
              if ( \XMLReader::END_ELEMENT === $reader->nodeType && 'item' === $reader->localName && $in_item ) {
                  $index[ $post_id ] = [
                      'post_type'   => $post_type ?: 'post',
                      'post_parent' => $post_parent,
                      'post_title'  => $post_title,
                  ];
                  $in_item = false;
              }
          }
          $reader->close();
          return $index;
      }
  }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add inc/Common/Utils/DependencyResolver.php
  git commit -m "feat: add DependencyResolver for hard/soft dep scanning (Task 3)"
  ```

---

### Task 4: UrlReplacer.php

**Files:**
- Create: `inc/Common/Utils/UrlReplacer.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * Utility: UrlReplacer
   *
   * Reads <wp:base_site_url> from a WXR file and runs a DB search-replace
   * to swap the demo domain for the current site URL. Called once, after
   * all XML chunks have been imported.
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\Common\Utils;
  use SigmaDevs\EasyDemoImporter\Common\Utils\ImportLogger;
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class UrlReplacer {
      /**
       * Run URL replacement.
       * Opt-out: return false from `sd/edi/auto_url_fix` filter.
       *
       * @param string $xml_path   Absolute path to WXR file.
       * @param string $session_id Import session ID for logging.
       * @return int   Number of DB values updated, or 0 if skipped.
       */
      public static function run( string $xml_path, string $session_id ): int {
          if ( ! apply_filters( 'sd/edi/auto_url_fix', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
              return 0;
          }
          $demo_url = self::readBaseUrl( $xml_path );
          if ( ! $demo_url ) {
              return 0;
          }
          $site_url = untrailingslashit( get_site_url() );
          $demo_url = untrailingslashit( $demo_url );

          if ( $demo_url === $site_url ) {
              return 0;
          }

          $replaced = self::searchReplace( $demo_url, $site_url );

          // Flush Elementor cache if active.
          if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
              \Elementor\Plugin::$instance->files_manager->clear_cache();
          }

          ImportLogger::log(
              sprintf(
                  /* translators: 1: demo URL, 2: site URL, 3: count */
                  __( 'Auto URL fix: replaced "%1$s" → "%2$s" in %3$d DB values.', 'easy-demo-importer' ),
                  $demo_url,
                  $site_url,
                  $replaced
              ),
              $replaced > 0 ? 'success' : 'info',
              $session_id
          );

          return $replaced;
      }

      /**
       * Read <wp:base_site_url> from the WXR header (line-by-line, stops early).
       */
      private static function readBaseUrl( string $file_path ): string {
          if ( ! file_exists( $file_path ) ) {
              return '';
          }
          $handle = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
          if ( ! $handle ) {
              return '';
          }
          $url  = '';
          $read = 0;
          while ( ! feof( $handle ) && $read < 500 ) {
              $line = fgets( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
              ++$read;
              if ( false === $line ) {
                  break;
              }
              if ( preg_match( '#<wp:base_site_url>(.*?)</wp:base_site_url>#i', $line, $m ) ) {
                  $url = trim( $m[1] );
                  break;
              }
              if ( strpos( $line, '<item' ) !== false ) {
                  break; // Past header — not found.
              }
          }
          fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
          return $url;
      }

      /**
       * Run search-replace across serialized-safe DB columns.
       * Uses WP's built-in recursive unserializer for serialized values.
       * Returns total number of values updated.
       */
      private static function searchReplace( string $from, string $to ): int {
          global $wpdb;
          $count   = 0;

          // Variants to cover http/https and www prefixes.
          $pairs = [
              $from                                                         => $to,
              str_replace( 'https://', 'http://', $from )                  => str_replace( 'https://', 'http://', $to ),
              str_replace( [ 'https://', 'http://' ], 'https://www.', $from ) => $to,
          ];

          // Tables and columns to search.
          $targets = [
              $wpdb->posts    => [ 'post_content', 'post_excerpt', 'guid' ],
              $wpdb->postmeta => [ 'meta_value' ],
              $wpdb->options  => [ 'option_value' ],
          ];

          foreach ( $targets as $table => $columns ) {
              foreach ( $columns as $col ) {
                  foreach ( $pairs as $search => $replace ) {
                      if ( $search === $replace ) {
                          continue;
                      }
                      // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                      $rows = $wpdb->get_results(
                          $wpdb->prepare(
                              // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
                              "SELECT * FROM %1\$s WHERE %2\$s LIKE %s",
                              $table,
                              $col,
                              '%' . $wpdb->esc_like( $search ) . '%'
                          ),
                          ARRAY_A
                      );
                      foreach ( (array) $rows as $row ) {
                          $pk  = array_key_first( $row );
                          $old = $row[ $col ];
                          $new = self::replaceInValue( $old, $search, $replace );
                          if ( $new !== $old ) {
                              // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                              $wpdb->update( $table, [ $col => $new ], [ $pk => $row[ $pk ] ] );
                              ++$count;
                          }
                      }
                  }
              }
          }
          return $count;
      }

      /**
       * Replace within a potentially serialized string.
       */
      private static function replaceInValue( string $value, string $search, string $replace ): string {
          if ( is_serialized( $value ) ) {
              $unserialized = maybe_unserialize( $value );
              $json         = wp_json_encode( $unserialized );
              if ( $json ) {
                  $replaced = str_replace( $search, $replace, $json );
                  if ( $replaced !== $json ) {
                      $decoded = json_decode( $replaced, true );
                      if ( null !== $decoded ) {
                          return maybe_serialize( $decoded );
                      }
                  }
              }
              return $value; // Leave malformed serialized data unchanged.
          }
          return str_replace( $search, $replace, $value );
      }
  }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add inc/Common/Utils/UrlReplacer.php
  git commit -m "feat: add UrlReplacer for post-import domain swap (Task 4)"
  ```

---

## Chunk 2: REST Endpoints

### Task 5: DemoItems REST Endpoint

**Files:**
- Create: `inc/App/Rest/DemoItems.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * REST Endpoint: DemoItems
   *
   * GET /wp-json/sd/edi/v1/demo-items?demo=<slug>&post_type=<type>
   * Returns all items (or items of one type) from the demo WXR.
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\App\Rest;
  use WP_REST_Request;
  use WP_REST_Response;
  use WP_Error;
  use SigmaDevs\EasyDemoImporter\Common\{
      Abstracts\Base,
      Traits\Singleton,
      Functions\Helpers,
      Utils\XmlChunker
  };
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class DemoItems extends Base {
      use Singleton;

      public function register(): void {
          add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
      }

      public function registerRoutes(): void {
          register_rest_route(
              'sd/edi/v1',
              '/demo-items',
              [
                  'methods'             => 'GET',
                  'callback'            => [ $this, 'getItems' ],
                  'permission_callback' => [ $this, 'permissionCheck' ],
                  'args'                => [
                      'demo'      => [ 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ],
                      'post_type' => [ 'required' => false, 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ],
                  ],
              ]
          );
      }

      public function permissionCheck(): bool {
          return current_user_can( 'manage_options' );
      }

      public function getItems( WP_REST_Request $request ): WP_REST_Response|WP_Error {
          $demo_slug = $request->get_param( 'demo' );
          $post_type = $request->get_param( 'post_type' );
          $xml_path  = $this->resolveXmlPath( $demo_slug );

          if ( ! $xml_path ) {
              return new WP_Error( 'demo_not_found', __( 'Demo XML not found.', 'easy-demo-importer' ), [ 'status' => 404 ] );
          }

          $all   = XmlChunker::getItems( $xml_path );
          $items = [];

          foreach ( $all as $item ) {
              if ( $post_type && $item['post_type'] !== $post_type ) {
                  continue;
              }
              $items[] = [
                  'id'         => $item['post_id'],
                  'title'      => $item['post_title'] ?: "(#{$item['post_id']})",
                  'post_type'  => $item['post_type'],
              ];
          }

          // Group distinct post types for the tab list.
          $types = array_values( array_unique( array_column( $all, 'post_type' ) ) );
          sort( $types );

          return rest_ensure_response( [ 'items' => $items, 'types' => $types ] );
      }

      /**
       * Resolve absolute path to content.xml for a demo slug.
       * Reuses the same logic as ImporterAjax.
       */
      private function resolveXmlPath( string $demo_slug ): ?string {
          $uploads  = wp_get_upload_dir();
          $base_dir = $uploads['basedir'] . '/easy-demo-importer';
          $path     = trailingslashit( $base_dir . '/' . $demo_slug ) . 'content.xml';
          return file_exists( $path ) ? $path : null;
      }
  }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add inc/App/Rest/DemoItems.php
  git commit -m "feat: add DemoItems REST endpoint GET /demo-items (Task 5)"
  ```

---

### Task 6: ResolveDeps REST Endpoint

**Files:**
- Create: `inc/App/Rest/ResolveDeps.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * REST Endpoint: ResolveDeps
   *
   * POST /wp-json/sd/edi/v1/resolve-deps
   * Body: { demo: string, selected_ids: int[] }
   * Response: { hard: int[], soft: [{ id, label, type }] }
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\App\Rest;
  use WP_REST_Request;
  use WP_REST_Response;
  use WP_Error;
  use SigmaDevs\EasyDemoImporter\Common\{
      Abstracts\Base,
      Traits\Singleton,
      Utils\DependencyResolver
  };
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class ResolveDeps extends Base {
      use Singleton;

      public function register(): void {
          add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
      }

      public function registerRoutes(): void {
          register_rest_route(
              'sd/edi/v1',
              '/resolve-deps',
              [
                  'methods'             => 'POST',
                  'callback'            => [ $this, 'resolve' ],
                  'permission_callback' => fn() => current_user_can( 'manage_options' ),
              ]
          );
      }

      public function resolve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
          $body      = $request->get_json_params();
          $demo_slug = isset( $body['demo'] ) ? sanitize_text_field( $body['demo'] ) : '';
          $ids       = isset( $body['selected_ids'] ) && is_array( $body['selected_ids'] )
              ? array_map( 'absint', $body['selected_ids'] )
              : [];

          if ( ! $demo_slug ) {
              return new WP_Error( 'missing_demo', __( 'Missing demo slug.', 'easy-demo-importer' ), [ 'status' => 400 ] );
          }

          $uploads  = wp_get_upload_dir();
          $xml_path = $uploads['basedir'] . '/easy-demo-importer/' . $demo_slug . '/content.xml';

          if ( ! file_exists( $xml_path ) ) {
              return rest_ensure_response( [ 'hard' => [], 'soft' => [] ] );
          }

          $result = DependencyResolver::resolve( $xml_path, $ids );
          return rest_ensure_response( $result );
      }
  }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add inc/App/Rest/ResolveDeps.php
  git commit -m "feat: add ResolveDeps REST endpoint POST /resolve-deps (Task 6)"
  ```

---

### Task 7: Rollback REST Endpoint

**Files:**
- Create: `inc/App/Rest/Rollback.php`

- [ ] **Step 1: Create the class**

  ```php
  <?php
  /**
   * REST Endpoint: Rollback
   *
   * POST /wp-json/sd/edi/v1/rollback/{snapshot_id}
   * Restores a pre-import snapshot, undoing the import.
   *
   * @package SigmaDevs\EasyDemoImporter
   * @since   1.5.0
   */
  declare( strict_types=1 );
  namespace SigmaDevs\EasyDemoImporter\App\Rest;
  use WP_REST_Request;
  use WP_REST_Response;
  use WP_Error;
  use SigmaDevs\EasyDemoImporter\Common\{
      Abstracts\Base,
      Traits\Singleton,
      Utils\SnapshotManager
  };
  if ( ! defined( 'ABSPATH' ) ) { exit; }

  class Rollback extends Base {
      use Singleton;

      public function register(): void {
          add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
      }

      public function registerRoutes(): void {
          register_rest_route(
              'sd/edi/v1',
              '/rollback/(?P<id>\d+)',
              [
                  'methods'             => 'POST',
                  'callback'            => [ $this, 'rollback' ],
                  'permission_callback' => fn() => current_user_can( 'manage_options' ),
                  'args'                => [
                      'id' => [
                          'required'          => true,
                          'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                          'sanitize_callback' => 'absint',
                      ],
                  ],
              ]
          );
      }

      public function rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
          $snapshot_id = (int) $request->get_param( 'id' );
          $row         = SnapshotManager::get( $snapshot_id );

          if ( ! $row ) {
              return new WP_Error( 'not_found', __( 'Snapshot not found or has expired (24h limit).', 'easy-demo-importer' ), [ 'status' => 404 ] );
          }

          $result = SnapshotManager::restore( $snapshot_id, $row['session_id'] );

          if ( isset( $result['error'] ) ) {
              return new WP_Error( 'rollback_failed', $result['error'], [ 'status' => 500 ] );
          }

          return rest_ensure_response(
              [
                  'success'       => true,
                  'posts_deleted' => $result['posts_deleted'],
                  'terms_deleted' => $result['terms_deleted'],
              ]
          );
      }
  }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add inc/App/Rest/Rollback.php
  git commit -m "feat: add Rollback REST endpoint POST /rollback/{id} (Task 7)"
  ```

---

### Task 8: Register New REST Endpoints

**Files:**
- Modify: `inc/App/Rest/RestEndpoints.php`

- [ ] **Step 1: Add `use` imports for the three new classes**

  Add to existing `use` block in RestEndpoints.php:
  ```php
  use SigmaDevs\EasyDemoImporter\App\Rest\DemoItems;
  use SigmaDevs\EasyDemoImporter\App\Rest\ResolveDeps;
  use SigmaDevs\EasyDemoImporter\App\Rest\Rollback;
  ```

- [ ] **Step 2: Register them in the `register()` method**

  Inside `register()` (or wherever other endpoints are registered via `add_action( 'rest_api_init' ... )`), add:
  ```php
  DemoItems::instance()->register();
  ResolveDeps::instance()->register();
  Rollback::instance()->register();
  ```

- [ ] **Step 3: Verify endpoints exist**

  ```
  wp eval "echo rest_url('sd/edi/v1/demo-items');"
  # Or visit /wp-json/sd/edi/v1 in browser and check routes listed
  ```

- [ ] **Step 4: Commit**
  ```bash
  git add inc/App/Rest/RestEndpoints.php
  git commit -m "feat: register DemoItems, ResolveDeps, Rollback REST routes (Task 8)"
  ```

---

## Chunk 3: Import Integration

### Task 9: Snapshot Creation in Initialize.php

**Files:**
- Modify: `inc/App/Ajax/Backend/Initialize.php`

- [ ] **Step 1: Add `use` import for SnapshotManager**

  Add to the existing `use` block:
  ```php
  Utils\SnapshotManager,
  ```

- [ ] **Step 2: Call `SnapshotManager::create()` and return `snapshotId`**

  In `response()`, after `SessionManager::start()` and before `$this->databaseReset()`, add:
  ```php
  $demo_slug   = $this->demo ?? '';
  $snapshot_id = SnapshotManager::create( $this->sessionId, $demo_slug );
  ```

  Then in the final `$this->prepareResponse(...)` call, add `snapshotId` in the extra data array (last parameter):
  ```php
  $this->prepareResponse(
      'sd_edi_install_plugins',
      esc_html__( 'Let us install the required plugins.', 'easy-demo-importer' ),
      ( $this->reset ) ? esc_html__( 'Database reset completed.', 'easy-demo-importer' ) : esc_html__( 'Minor cleanups done.', 'easy-demo-importer' ),
      false,
      '',
      '',
      [ 'snapshotId' => $snapshot_id ]
  );
  ```

  > **Note:** Check `prepareResponse()` signature in `ImporterAjax.php` to confirm the 7th parameter is `$data array`. If the signature only has 6 params, add `$data = []` to it and merge into the response.

- [ ] **Step 3: Verify**

  Run an import; after init step, check DB:
  ```
  wp db query "SELECT * FROM wp_sd_edi_snapshots ORDER BY id DESC LIMIT 1;"
  ```
  Expected: One row with the session_id and expires_at = created_at + 24h.

- [ ] **Step 4: Commit**
  ```bash
  git add inc/App/Ajax/Backend/Initialize.php
  git commit -m "feat: create snapshot on import init, return snapshotId (Task 9)"
  ```

---

### Task 10: ImportXmlChunk.php — allowedIds + URL Fix

**Files:**
- Modify: `inc/App/Ajax/Backend/ImportXmlChunk.php`

- [ ] **Step 1: Read `allowedIds` from POST and pass to XmlChunker**

  In `response()`, after reading `$offset`, add:
  ```php
  // phpcs:ignore WordPress.Security.NonceVerification.Missing
  $raw_ids     = isset( $_POST['allowedIds'] ) && is_array( $_POST['allowedIds'] )
      ? array_map( 'absint', $_POST['allowedIds'] )
      : [];
  $allowed_ids = array_filter( $raw_ids );
  ```

  Change the `XmlChunker::extractChunk()` call to:
  ```php
  $chunk_tmp = XmlChunker::extractChunk( $xml_path, $offset, $limit, $allowed_ids );
  ```

  When `$allowed_ids` is non-empty, use its count as the total instead of the XML scan:
  ```php
  if ( ! empty( $allowed_ids ) ) {
      $total = count( $allowed_ids );
  } else {
      // existing transient-cached count
  }
  ```

  > Adjust so the existing transient logic only runs when `$allowed_ids` is empty.

- [ ] **Step 2: Trigger UrlReplacer on final chunk**

  Add `use SigmaDevs\EasyDemoImporter\Common\Utils\UrlReplacer;` to the use block.

  In the "extraction complete" branch (where it logs "XML chunk extraction complete"), add:
  ```php
  UrlReplacer::run( $xml_path, $this->sessionId );
  ```

- [ ] **Step 3: Verify in browser console**

  - With no selection → chunk AJAX calls behave identically to before.
  - Check import log for "Auto URL fix" entry after XML import completes.

- [ ] **Step 4: Commit**
  ```bash
  git add inc/App/Ajax/Backend/ImportXmlChunk.php
  git commit -m "feat: pass allowedIds to XmlChunker; run UrlReplacer on completion (Task 10)"
  ```

---

### Task 11: Check ImporterAjax::prepareResponse Signature

**Files:**
- Possibly modify: `inc/Common/Abstracts/ImporterAjax.php`

- [ ] **Step 1: Read `prepareResponse()` and confirm it accepts a `$data` array param**

  Open `inc/Common/Abstracts/ImporterAjax.php`, find `prepareResponse()`. If the last parameter is not `array $data = []`, add it and merge it into the response JSON before `wp_send_json()`.

  Expected signature:
  ```php
  protected function prepareResponse(
      string $nextPhase,
      string $message,
      string $successMessage,
      bool   $hasError       = false,
      string $errorMessage   = '',
      string $errorHint      = '',
      array  $data           = []
  ): void {
  ```

  The response array should include `...$data` spread or `array_merge( $response, $data )`.

- [ ] **Step 2: Commit if changed**
  ```bash
  git add inc/Common/Abstracts/ImporterAjax.php
  git commit -m "feat: add \$data param to prepareResponse for extensibility (Task 11)"
  ```

---

## Chunk 4: React — Selective Import

### Task 12: WizardContext — selectedIds + snapshotId

**Files:**
- Modify: `src/js/backend/wizard/WizardContext.jsx`

- [ ] **Step 1: Add `selectedIds`, `setSelectedIds`, `snapshotId`, `setSnapshotId` state**

  In `WizardProvider`:
  ```jsx
  const [ selectedIds,   setSelectedIds   ] = useState( [] );
  const [ snapshotId,    setSnapshotId    ] = useState( null );
  ```

  Expose in context value:
  ```jsx
  selectedIds, setSelectedIds,
  snapshotId,  setSnapshotId,
  ```

  In `resetWizard`, reset them:
  ```jsx
  setSelectedIds( [] );
  setSnapshotId( null );
  ```

- [ ] **Step 2: Verify**

  Confirm no console errors by refreshing the wizard page.

- [ ] **Step 3: Commit**
  ```bash
  git add src/js/backend/wizard/WizardContext.jsx
  git commit -m "feat: add selectedIds and snapshotId to WizardContext (Task 12)"
  ```

---

### Task 13: WizardLayout + App.jsx — add select-items step

**Files:**
- Modify: `src/js/backend/wizard/WizardLayout.jsx`
- Modify: `src/js/backend/App.jsx`

- [ ] **Step 1: WizardLayout — add `select-items` to STEPS between `options` and `confirm`**

  ```js
  const STEPS = [
      { key: 'welcome',      title: 'Welcome'      },
      { key: 'requirements', title: 'Requirements' },
      { key: 'plugins',      title: 'Plugins'      },
      { key: 'demos',        title: 'Select Demo'  },
      { key: 'options',      title: 'Options'      },
      { key: 'select-items', title: 'Items'        },
      { key: 'confirm',      title: 'Confirm'      },
      { key: 'importing',    title: 'Importing'    },
      { key: 'regen',        title: 'Images'       },
      { key: 'complete',     title: 'Done'         },
  ];
  ```

- [ ] **Step 2: App.jsx — add the import and route**

  Add import:
  ```js
  import SelectItemsStep from './wizard/steps/SelectItemsStep';
  ```

  Add route in the `/wizard` children array (between `options` and `confirm`):
  ```js
  { path: 'select-items', element: <SelectItemsStep /> },
  ```

- [ ] **Step 3: Commit**
  ```bash
  git add src/js/backend/wizard/WizardLayout.jsx src/js/backend/App.jsx
  git commit -m "feat: add select-items route and step indicator (Task 13)"
  ```

---

### Task 14: SelectItemsStep.jsx

**Files:**
- Create: `src/js/backend/wizard/steps/SelectItemsStep.jsx`

- [ ] **Step 1: Create the component**

  ```jsx
  import { Button, Tabs, Checkbox, Input, Space, Spin, Alert, Badge } from 'antd';
  import { SearchOutlined } from '@ant-design/icons';
  import { useNavigate } from 'react-router-dom';
  import React, { useState, useEffect, useCallback, useRef } from 'react';
  import ReactDOM from 'react-dom';
  import { useWizard } from '../WizardContext';
  import { Api } from '../../utils/Api';

  const SelectItemsStep = () => {
      const navigate = useNavigate();
      const { selectedDemo, importOptions, selectedIds, setSelectedIds } = useWizard();
      const [footer, setFooter] = useState( null );
      const [back,   setBack  ] = useState( null );

      const [types,    setTypes  ] = useState( [] );
      const [items,    setItems  ] = useState( {} ); // { post_type: [{ id, title }] }
      const [checked,  setChecked] = useState( {} ); // { post_type: Set<int> }
      const [search,   setSearch ] = useState( {} ); // { post_type: string }
      const [loading,  setLoading] = useState( {} ); // { post_type: bool }
      const [fetched,  setFetched] = useState( {} ); // { post_type: bool }
      const [error,    setError  ] = useState( null );
      const [activeTab, setActiveTab] = useState( null );

      useEffect( () => {
          const nextEl = document.getElementById( 'edi-wizard-next-slot' );
          const backEl = document.getElementById( 'edi-wizard-back-slot' );
          if ( nextEl ) setFooter( nextEl );
          if ( backEl ) setBack( backEl );
      }, [] );

      // Fetch list of types on mount.
      useEffect( () => {
          if ( ! selectedDemo ) { navigate( '/wizard/demos' ); return; }
          Api.get( `/sd/edi/v1/demo-items?demo=${ selectedDemo.slug }` )
              .then( ( res ) => {
                  const postTypes = res.data.types || [];
                  setTypes( postTypes );
                  if ( postTypes.length > 0 ) setActiveTab( postTypes[0] );
              } )
              .catch( () => setError( 'Could not load demo items.' ) );
      }, [ selectedDemo ] );

      // Lazy-load items for a tab when first clicked.
      const loadTab = useCallback( ( postType ) => {
          if ( fetched[ postType ] ) return;
          setLoading( ( prev ) => ( { ...prev, [ postType ]: true } ) );
          Api.get( `/sd/edi/v1/demo-items?demo=${ selectedDemo.slug }&post_type=${ postType }` )
              .then( ( res ) => {
                  const tabItems = res.data.items || [];
                  setItems( ( prev ) => ( { ...prev, [ postType ]: tabItems } ) );
                  // Default: all selected.
                  setChecked( ( prev ) => ( {
                      ...prev,
                      [ postType ]: new Set( tabItems.map( ( i ) => i.id ) ),
                  } ) );
                  setFetched( ( prev ) => ( { ...prev, [ postType ]: true } ) );
              } )
              .catch( () => {} )
              .finally( () => setLoading( ( prev ) => ( { ...prev, [ postType ]: false } ) ) );
      }, [ fetched, selectedDemo ] );

      useEffect( () => {
          if ( activeTab ) loadTab( activeTab );
      }, [ activeTab, loadTab ] );

      const handleNext = () => {
          // Flatten checked sets into a flat id array.
          const ids = [];
          for ( const set of Object.values( checked ) ) {
              set.forEach( ( id ) => ids.push( id ) );
          }
          setSelectedIds( ids );
          navigate( '/wizard/confirm' );
      };

      const toggleItem = ( postType, id ) => {
          setChecked( ( prev ) => {
              const s = new Set( prev[ postType ] || [] );
              s.has( id ) ? s.delete( id ) : s.add( id );
              return { ...prev, [ postType ]: s };
          } );
      };

      const selectAll = ( postType ) => {
          const all = ( items[ postType ] || [] ).map( ( i ) => i.id );
          setChecked( ( prev ) => ( { ...prev, [ postType ]: new Set( all ) } ) );
      };

      const deselectAll = ( postType ) => {
          setChecked( ( prev ) => ( { ...prev, [ postType ]: new Set() } ) );
      };

      const tabItems = types.map( ( type ) => {
          const typeItems = items[ type ] || [];
          const q         = ( search[ type ] || '' ).toLowerCase();
          const filtered  = q ? typeItems.filter( ( i ) => i.title.toLowerCase().includes( q ) ) : typeItems;
          const sel       = checked[ type ] || new Set();
          const selCount  = typeItems.filter( ( i ) => sel.has( i.id ) ).length;

          return {
              key:   type,
              label: (
                  <span>{ type } <Badge count={ selCount } size="small" color="#1677ff" /></span>
              ),
              children: (
                  <div style={ { padding: '12px 0' } }>
                      <div style={ { display: 'flex', gap: 8, marginBottom: 12, alignItems: 'center' } }>
                          <Input
                              placeholder={ `Search ${ type }…` }
                              prefix={ <SearchOutlined /> }
                              value={ search[ type ] || '' }
                              onChange={ ( e ) => setSearch( ( p ) => ( { ...p, [ type ]: e.target.value } ) ) }
                              style={ { flex: 1 } }
                              allowClear
                          />
                          <Button size="small" onClick={ () => selectAll( type ) }>All</Button>
                          <Button size="small" onClick={ () => deselectAll( type ) }>None</Button>
                      </div>

                      { loading[ type ] ? (
                          <div style={ { textAlign: 'center', padding: 32 } }><Spin /></div>
                      ) : (
                          <div style={ {
                              maxHeight: 340, overflowY: 'auto',
                              display: 'flex', flexDirection: 'column', gap: 4,
                          } }>
                              { filtered.map( ( item ) => (
                                  <div
                                      key={ item.id }
                                      style={ {
                                          display: 'flex', alignItems: 'center', gap: 8,
                                          padding: '6px 10px', borderRadius: 6,
                                          background: sel.has( item.id ) ? '#f0f5ff' : '#fafafa',
                                          border: `1px solid ${ sel.has( item.id ) ? '#adc6ff' : '#f0f0f0' }`,
                                          cursor: 'pointer',
                                      } }
                                      onClick={ () => toggleItem( type, item.id ) }
                                  >
                                      <Checkbox checked={ sel.has( item.id ) } onChange={ () => toggleItem( type, item.id ) } />
                                      <span style={ { fontSize: 13 } }>{ item.title }</span>
                                  </div>
                              ) ) }
                              { filtered.length === 0 && (
                                  <div style={ { textAlign: 'center', color: '#8c8c8c', padding: 24 } }>No items match.</div>
                              ) }
                          </div>
                      ) }

                      <div style={ { marginTop: 10, color: '#8c8c8c', fontSize: 12 } }>
                          { selCount } of { typeItems.length } { type } selected
                      </div>
                  </div>
              ),
          };
      } );

      return (
          <>
              <h2 style={ { fontSize: 18, fontWeight: 700, marginBottom: 6 } }>Select Items to Import</h2>
              <p style={ { color: '#8c8c8c', marginBottom: 20, fontSize: 14 } }>
                  Choose which posts, pages, and other content to include. All items are selected by default.
              </p>

              { error && <Alert type="error" message={ error } style={ { marginBottom: 16 } } /> }

              { types.length === 0 && ! error ? (
                  <div style={ { textAlign: 'center', padding: 40 } }><Spin size="large" /></div>
              ) : (
                  <Tabs
                      activeKey={ activeTab }
                      onChange={ ( key ) => { setActiveTab( key ); loadTab( key ); } }
                      items={ tabItems }
                  />
              ) }

              { back && ReactDOM.createPortal(
                  <Button onClick={ () => navigate( '/wizard/options' ) }>Back</Button>,
                  back
              ) }

              { footer && ReactDOM.createPortal(
                  <Button type="primary" onClick={ handleNext } disabled={ types.length === 0 }>
                      Continue
                  </Button>,
                  footer
              ) }
          </>
      );
  };

  export default SelectItemsStep;
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add src/js/backend/wizard/steps/SelectItemsStep.jsx
  git commit -m "feat: add SelectItemsStep tabbed item picker (Task 14)"
  ```

---

### Task 15: ImportOptionsStep — conditional navigation

**Files:**
- Modify: `src/js/backend/wizard/steps/ImportOptionsStep.jsx`

- [ ] **Step 1: Change "Review & Confirm" to navigate based on content toggle**

  The step currently always navigates to `/wizard/confirm`. Change to go to `/wizard/select-items` when content is ON, else go straight to `/wizard/confirm`.

  ```jsx
  const { importOptions, updateOption } = useWizard();
  ```

  Change the Next button `onClick`:
  ```jsx
  onClick={ () => navigate( importOptions.content ? '/wizard/select-items' : '/wizard/confirm' ) }
  ```

- [ ] **Step 2: Commit**
  ```bash
  git add src/js/backend/wizard/steps/ImportOptionsStep.jsx
  git commit -m "feat: route Options→SelectItems when content ON, else→Confirm (Task 15)"
  ```

---

### Task 16: ImportingStep — pass selectedIds to AJAX calls

**Files:**
- Modify: `src/js/backend/wizard/steps/ImportingStep.jsx`

- [ ] **Step 1: Read `selectedIds` and `setSnapshotId` from context**

  ```jsx
  const { importOptions, selectedIds, setSnapshotId } = useWizard();
  ```

- [ ] **Step 2: Pass `selectedIds` in the init AJAX call and capture `snapshotId`**

  In the `sd_edi_install_demo` call, no change needed (snapshot is auto-created server-side).

  Capture `snapshotId` from the init response and store it:
  ```jsx
  // After the init AJAX call succeeds and returns data:
  if ( data?.snapshotId ) {
      setSnapshotId( data.snapshotId );
  }
  ```

- [ ] **Step 3: Pass `allowedIds[]` in every `sd_edi_import_xml_chunk` call**

  Find the AJAX call for `sd_edi_import_xml_chunk` and add:
  ```js
  ...(selectedIds.length > 0 ? { allowedIds: selectedIds } : {}),
  ```

  Example:
  ```js
  const data = await ajaxPost( 'sd_edi_import_xml_chunk', {
      offset,
      ...(selectedIds.length > 0 ? { allowedIds: selectedIds } : {}),
  } );
  ```

- [ ] **Step 4: Commit**
  ```bash
  git add src/js/backend/wizard/steps/ImportingStep.jsx
  git commit -m "feat: pass allowedIds to chunk calls; capture snapshotId from init (Task 16)"
  ```

---

## Chunk 5: UI Enhancements

### Task 17: ConfirmationStep — soft deps + back nav

**Files:**
- Modify: `src/js/backend/wizard/steps/ConfirmationStep.jsx`

- [ ] **Step 1: Add state for soft deps and call `/resolve-deps`**

  ```jsx
  const { selectedDemo, dryRunStats, setDryRunStats, importOptions, selectedIds } = useWizard();
  const [softDeps,     setSoftDeps    ] = useState( [] );
  const [softChecked,  setSoftChecked ] = useState( {} ); // { id: bool }
  ```

  In a `useEffect`, after `dryRunStats` loads and if `selectedIds.length > 0`, call resolve-deps:
  ```jsx
  useEffect( () => {
      if ( ! selectedDemo || selectedIds.length === 0 ) return;
      Api.post( '/sd/edi/v1/resolve-deps', {
          demo:         selectedDemo.slug,
          selected_ids: selectedIds,
      } )
      .then( ( res ) => {
          setSoftDeps( res.data.soft || [] );
          const init = {};
          ( res.data.soft || [] ).forEach( ( d ) => { init[ d.id ] = false; } );
          setSoftChecked( init );
      } )
      .catch( () => {} );
  }, [ selectedDemo, selectedIds ] );
  ```

- [ ] **Step 2: Render soft deps section**

  After the stats section, add:
  ```jsx
  { softDeps.length > 0 && (
      <div style={ { marginTop: 16, background: '#fffbe6', border: '1px solid #ffe58f', borderRadius: 8, padding: '12px 16px' } }>
          <div style={ { fontWeight: 600, marginBottom: 8 } }>Also include related content? (optional)</div>
          { softDeps.slice( 0, 20 ).map( ( dep ) => (
              <div key={ dep.id } style={ { marginBottom: 4 } }>
                  <input
                      type="checkbox"
                      checked={ !! softChecked[ dep.id ] }
                      onChange={ ( e ) => setSoftChecked( ( p ) => ( { ...p, [ dep.id ]: e.target.checked } ) ) }
                      style={ { marginRight: 6 } }
                  />
                  { dep.label } <span style={ { color: '#8c8c8c', fontSize: 11 } }>({ dep.type })</span>
              </div>
          ) ) }
      </div>
  ) }
  ```

- [ ] **Step 3: Fix Back button navigation**

  Change Back button `onClick` to:
  ```jsx
  onClick={ () => navigate( importOptions.content ? '/wizard/select-items' : '/wizard/options' ) }
  ```

- [ ] **Step 4: Commit**
  ```bash
  git add src/js/backend/wizard/steps/ConfirmationStep.jsx
  git commit -m "feat: add soft dep section and fix back nav in ConfirmationStep (Task 17)"
  ```

---

### Task 18: CompleteStep — Undo Import Button

**Files:**
- Modify: `src/js/backend/wizard/steps/CompleteStep.jsx`

- [ ] **Step 1: Add rollback state and fetch logic**

  ```jsx
  import React, { useState, useEffect } from 'react';
  import { Button, Result, Space, Modal, Spin } from 'antd';
  import { RollbackOutlined } from '@ant-design/icons';
  import { Api } from '../../utils/Api';
  ```

  In component:
  ```jsx
  const { resetWizard, snapshotId } = useWizard();
  const [rolling,   setRolling  ] = useState( false );
  const [undone,    setUndone   ] = useState( false );
  const [rollError, setRollError] = useState( null );

  const handleUndo = () => {
      Modal.confirm( {
          title:   'Undo this import?',
          content: 'This will delete all posts, pages, and media imported during this session, and restore your previous settings. This cannot be undone.',
          okText:    'Yes, undo import',
          okType:    'danger',
          cancelText: 'Cancel',
          onOk: async () => {
              setRolling( true );
              try {
                  await Api.post( `/sd/edi/v1/rollback/${ snapshotId }`, {} );
                  setUndone( true );
              } catch ( err ) {
                  setRollError( err?.response?.data?.message || 'Rollback failed. Please try again.' );
              } finally {
                  setRolling( false );
              }
          },
      } );
  };
  ```

- [ ] **Step 2: Render Undo button only when snapshotId exists and not already undone**

  In the `extra` Space component, add before the last button:
  ```jsx
  { snapshotId && ! undone && (
      <Button
          icon={ rolling ? <Spin size="small" /> : <RollbackOutlined /> }
          danger
          onClick={ handleUndo }
          disabled={ rolling }
      >
          Undo Import
      </Button>
  ) }
  { undone && (
      <Button disabled icon={ <RollbackOutlined /> }>Import Undone</Button>
  ) }
  ```

  If `rollError`, show a small error note:
  ```jsx
  { rollError && <div style={ { color: '#cf1322', marginTop: 8 } }>{ rollError }</div> }
  ```

- [ ] **Step 3: Commit**
  ```bash
  git add src/js/backend/wizard/steps/CompleteStep.jsx
  git commit -m "feat: add Undo Import button with rollback flow in CompleteStep (Task 18)"
  ```

---

### Task 19: RequirementsStep — Conflict Detection

**Files:**
- Modify: `src/js/backend/wizard/steps/RequirementsStep.jsx`

- [ ] **Step 1: Read current RequirementsStep to understand existing check structure**

  The step already fetches `/sd/edi/v1/server/status`. Add a second fetch for demo-specific conflict checks using the `selectedDemo` slug from WizardContext.

- [ ] **Step 2: Add conflict check logic**

  ```jsx
  const { selectedDemo, importOptions } = useWizard();
  const [conflicts, setConflicts] = useState( { hard: [], soft: [] } );
  ```

  Add a second `useEffect` for conflict checks after the server status loads. Derive them from the existing status data (already fetched) without a new endpoint — check in the frontend:

  ```jsx
  useEffect( () => {
      if ( ! serverStatus ) return;
      const hard = [];
      const soft = [];

      const memoryMb = serverStatus?.memory_limit_mb ?? 0;
      if ( memoryMb > 0 && memoryMb < 128 ) {
          hard.push( `PHP memory limit is ${ memoryMb }MB. Minimum 128MB required.` );
      }
      if ( memoryMb >= 128 && memoryMb < 256 ) {
          soft.push( `Memory is tight (${ memoryMb }MB). Consider increasing to 256MB+ for large demos.` );
      }

      const execTime = serverStatus?.max_execution_time ?? 0;
      if ( execTime > 0 && execTime < 300 ) {
          soft.push( `Execution time limit is ${ execTime }s. Import may time out on large demos. Recommended: 300s+.` );
      }

      if ( importOptions?.resetDb ) {
          const postCount = serverStatus?.post_count ?? 0;
          if ( postCount > 10 ) {
              soft.push( `⚠ Reset DB is ON. This will permanently delete your ${ postCount } existing posts. Only use on staging.` );
          }
      }

      setConflicts( { hard, soft } );
  }, [ serverStatus, importOptions ] );
  ```

- [ ] **Step 3: Render hard blocks and soft warnings in the UI**

  After the existing check list, add:
  ```jsx
  { conflicts.hard.map( ( msg, i ) => (
      <Alert key={ i } type="error" showIcon message="Hard Block" description={ msg } style={ { marginBottom: 8 } } />
  ) ) }
  { conflicts.soft.map( ( msg, i ) => (
      <Alert key={ i } type="warning" showIcon message={ msg } style={ { marginBottom: 8 } } />
  ) ) }
  ```

  Disable the Next/Continue button if `conflicts.hard.length > 0`:
  ```jsx
  disabled={ conflicts.hard.length > 0 }
  ```

- [ ] **Step 4: Verify**

  - On a server with 64MB memory, hard block should appear and block progress.
  - On a server with 192MB memory, soft warning should appear but Continue is enabled.

- [ ] **Step 5: Commit**
  ```bash
  git add src/js/backend/wizard/steps/RequirementsStep.jsx
  git commit -m "feat: add conflict detection hard blocks + soft warnings to RequirementsStep (Task 19)"
  ```

---

## Chunk 6: Build + Final Wiring

### Task 20: Build JS Bundle

**Files:**
- `assets/js/backend.min.js` (compiled output)

- [ ] **Step 1: Build**
  ```bash
  cd /path/to/plugin && npm run prod
  ```

- [ ] **Step 2: Verify bundle created without errors**

  Check terminal output — should end with `webpack compiled successfully`.

- [ ] **Step 3: Smoke test in browser**

  - Navigate wizard to Options step → toggle Content ON → Next should go to `/wizard/select-items`.
  - Tabs should load for each post type.
  - After Continue → ConfirmationStep shows soft deps section if any.
  - Complete step shows Undo Import button.
  - RequirementsStep shows conflict warnings if memory < 256MB.

- [ ] **Step 4: Commit**
  ```bash
  git add assets/js/backend.min.js
  git commit -m "build: compile JS bundle for Phase 4 Power Import (Task 20)"
  ```

---

### Task 21: Version Bump

**Files:**
- `easy-demo-importer.php`
- `readme.txt`

- [ ] **Step 1: Update plugin version to 1.5.0 in both files**

  In `easy-demo-importer.php`:
  - `* Version: 1.4.0` → `* Version: 1.5.0`
  - `define( 'SD_EDI_PLUGIN_VERSION', '1.4.0' )` → `'1.5.0'`

  In `readme.txt`: Update `Stable tag` and add changelog entry.

- [ ] **Step 2: Commit**
  ```bash
  git add easy-demo-importer.php readme.txt
  git commit -m "chore: bump version to 1.5.0 for Phase 4 Power Import (Task 21)"
  ```

---

## Summary

| Chunk | Tasks | Deliverable |
|-------|-------|-------------|
| 1 Backend infrastructure | 1–4 | Snapshots table, SnapshotManager, DependencyResolver, UrlReplacer |
| 2 REST endpoints | 5–8 | DemoItems, ResolveDeps, Rollback endpoints live |
| 3 Import integration | 9–11 | Snapshot on init, allowedIds forwarded, URL fix on completion |
| 4 React selective import | 12–16 | SelectItemsStep live, WizardContext extended, Options routing |
| 5 UI enhancements | 17–19 | Soft deps in Confirm, Undo button in Complete, conflict detection in Requirements |
| 6 Build + release | 20–21 | Compiled bundle, version 1.5.0 |
