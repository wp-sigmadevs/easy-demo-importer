# Changelog

All notable changes to **Easy Demo Importer** are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
The canonical, WordPress.org-formatted changelog also lives in `readme.txt`.

## [2.0.0] - 2026-07-18

### Added
- Resumable, chunked WXR import — large/WooCommerce demos no longer fail with 524/503 gateway timeouts.
- Manual import — upload your own content, media, and settings as separate files or a single `.zip` bundle.
- One-click rollback — a restore point is created before import so you can revert in one click.
- Activity log — every import and thumbnail run is recorded with a live, per-item log.
- Regenerate Thumbnails tool (Appearance → Easy Thumbnails) — resumable, with force-all-sizes and one-at-a-time modes.
- Determinate progress bar that auto-resumes after connection drops or gateway timeouts.
- Interrupted imports resurface on reload with a Resume / Start Over prompt.
- LayerSlider import support.
- WP-CLI support — `wp edi demos`, `regenerate`, and `rollback`.
- Pre-import readiness checks gate Start Import until the server is ready.
- Retry individual failed media downloads from the result screen.
- Bundled media import — demo images load from the package instead of downloading.
- Full compatibility with WordPress 7.0.

### Security
- Completed the fix for the SVG-upload Stored XSS (CVE-2024-9071): closed an Author-role restriction bypass that remained exploitable through 1.1.6 via three defects — filename-based type reconstruction without a capability check, sanitization gated on the client-supplied MIME (so declaring an SVG as `image/png` skipped it), and the REST raw-body sideload route (`wp_handle_sideload_prefilter`) never being hooked. Reported by Artus KG.
- Hardened archive extraction (ZipSlip), plugin-installer downloads, and PHP file loading.

### Fixed
- Elementor global settings (container width, colors, fonts) are applied correctly after import.
- Improved Fluent Forms import, settings validation, demo-file download verification, and AJAX input sanitization.
- Prevented a JavaScript crash when network requests fail or time out.
- Imported navigation menus could appear empty — menu item counts are now reconciled after import, and a menu missing from the export file is created automatically so its items are no longer dropped.
- An import could report success while silently importing nothing when another plugin printed output during the AJAX request (for example, WooCommerce running `WC_Install` right after a database reset). The import response is now isolated so it stays valid JSON.
- The "waiting for the previous import" mutex state no longer hangs indefinitely; it is capped and surfaced in the UI, and the batch/regeneration continuations no longer flash a false "busy" wait or interrupt their own running import.
- Extracted demo files are now removed from the uploads folder after a successful import.
- The finalize stage now receives the same temporary resource boost as every other import phase, and the memory limit is only ever raised, never lowered.
- The activity-log table is recreated automatically if it is missing before it is queried.

### Performance
- Attachment-URL backfill is batched into nested `REPLACE` queries instead of a full-table scan per slice.
- Remote-media chunks checkpoint the cursor per attachment, so a dropped connection resumes without re-downloading.
- The restore-point media walk is lazy and short-circuits once the file-count cap is reached.

### Changed
- Improved Customizer, Widgets, and database search-replace handling; refactored slider import.
- A readiness step shows the "View full system status" link inline; improved uninstall cleanup.
- Per-item importer notices (skipped media, failed items, "already exists") are recorded in the activity log through a structured sink with explicit severities, instead of being parsed back out of printed output.
- Localized the mutex-wait and busy-import strings.

### Internal
- Regenerated the PHPStan baseline and annotated snapshot schema-change queries so the static-analysis and coding-standard gates pass clean.
- Cast `disk_free_space()` to int for `size_format()` in the disk-space readiness check.

## [1.1.6] - 2026-02-28

### Added
- Full compatibility with WordPress 6.9.
- Option to skip image regeneration during import to speed up the process.
- Smart scroll indicators on the demo preview image in the import modal.

### Fixed
- PHP 8.4 compatibility improvements for script loading.
- Prevented fatal errors when cleaning upload directories that cannot be read.
- Added an empty check on demo data config to prevent warnings when `demoData` is absent.

### Changed
- Improved wording of some user-facing messages.
- Updated the `enshrined/svg-sanitize` dependency.

## [1.1.5] - 2025-07-22

### Added
- Full compatibility with WordPress 6.8.
- Nav menu item custom meta-data is now preserved during import.
- Importer now supports non-unique post-types (e.g. grouped fields).

### Fixed
- PHP 8.4 compatibility improvements for the XML parser.
- Resolved CSS issues across various admin views.
- Corrected Elementor taxonomy mapping issues in default WordPress widgets.
- Fixed a typo in the system status report.

### Changed
- Introduced new action hooks for advanced developer customization.
- Temporarily disable big image scaling during the import process.
- Enhanced CSS transitions for smoother visual behavior.
- Refactored post-processing logic in the importer.
- Upgraded the core import library and all dependency libraries to their latest versions.
- UI enhancements to align with updated external libraries and frameworks.

## [1.1.4] - 2024-11-16

### Added
- Full compatibility with WordPress 6.7.
- A way to set the Posts page (blog) as the front page.

### Fixed
- Resolved the PHP "Uncaught TypeError" error in SVG detection.

### Performance
- Removed the download-server connection check to reduce REST API calls.

## [1.1.3] - 2024-10-03

### Added
- SVG file sanitization to prevent malicious content uploads.
- File size validation for SVG uploads, with customizable size limits.

### Fixed
- Correct MIME type detection for SVG files in WordPress.
- Resolved an issue where unsafe SVG files bypassed security checks.

### Changed
- Improved error messaging for file upload validation.

## [1.1.2] - 2024-08-11

### Added
- Full compatibility with WordPress 6.6.
- Integrated React Router for smoother and faster page transitions.

### Fixed
- Resolved an issue where HTML entities were incorrectly displayed.
- Addressed UI issues on the Server Status page.

### Changed
- Enhanced the database reset script.
- Updated dependency libraries and aligned the UI with them.

## [1.1.1] - 2024-04-21

### Added
- Support for RTL languages.
- Compatibility with WordPress 6.5 and PHP 8.3.
- Temporary PHP parameters boost during import.

### Fixed
- Addressed some sanitization issues.
- Rectified a PHP warning regarding an undefined index.
- Resolved several responsive and CSS issues.

### Changed
- General codebase improvements.
- Added/renamed various action and filter hooks.

## [1.1.0] - 2024-02-20

### Added
- Support for importing Slider Revolution slides.
- Tabbed categories in the demo import interface.
- Search functionality for quicker access to specific demos.
- Step titles in the demo import process and an extra intro paragraph in the import modal.
- 'PHP Max Input Time' in the System Status screen.

### Fixed
- Import modal compatibility issues with high-resolution devices.
- Elementor taxonomy data mapping for repeater controls.
- Improved the required-plugin update feature.

### Changed
- Code refactoring for performance and maintainability.
- Added/renamed various action and filter hooks for customization.

## [1.0.2] - 2023-12-10

### Added
- A feature to update required plugins if an update is available.

### Fixed
- Resolved an issue with importing product attributes.

### Changed
- Elementor taxonomy data mapping for improved compatibility with multiple and repeater controls.

## [1.0.1] - 2023-11-26

### Fixed
- Resolved compatibility issues with PHP 7.4.

## [1.0.0]

- Initial release.

[2.0.0]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.6...2.0.0
[1.1.6]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.5...1.1.6
[1.1.5]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.4...1.1.5
[1.1.4]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.3...1.1.4
[1.1.3]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.2...1.1.3
[1.1.2]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.1...1.1.2
[1.1.1]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.1.0...1.1.1
[1.1.0]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.0.2...1.1.0
[1.0.2]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/wp-sigmadevs/easy-demo-importer/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/wp-sigmadevs/easy-demo-importer/releases/tag/1.0.0
