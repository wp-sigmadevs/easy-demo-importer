## Easy Demo Importer

![Requires PHP_>_7.4](https://img.shields.io/badge/Requires-PHP_>_7.4-2d74d5)
![Tested up to PHP_8.4](https://img.shields.io/badge/Tested-Up_to_PHP_8.4-2d74d5)
![Tested up to WordPress 7.0](https://img.shields.io/badge/Tested-Up_to_WordPress_7.0-2d74d5)
![Stable_Tag 2.0.0](https://img.shields.io/badge/Stable_Tag-2.0.0-2d74d5)
![License GPLv3 or later](https://img.shields.io/badge/License-GPLv3_or_later-2d74d5)
[![Unit Tests](https://github.com/wp-sigmadevs/easy-demo-importer/actions/workflows/unit-tests.yml/badge.svg)](https://github.com/wp-sigmadevs/easy-demo-importer/actions/workflows/unit-tests.yml)

<hr />

The Easy Demo Importer is your go-to solution for effortlessly bringing your WordPress site to life. With its user-friendly interface and robust feature set, it streamlines the process of importing demo content, offering an unparalleled level of convenience for both users and developers.

👉 [wordpress.org Plugin Link](https://wordpress.org/plugins/easy-demo-importer/) | [Plugin Documentation](https://docs.sigmadevs.com/easy-demo-importer) | [Changelog](CHANGELOG.md) 👈

### What's new in 2.0.0

- **Resumable, timeout-proof import** — large and WooCommerce demos import in time-boxed batches that survive gateway timeouts and auto-resume if interrupted.
- **Manual import** — bring in your own content, media, and settings as separate files or a single `.zip` bundle, no theme configuration required.
- **One-click rollback** — a restore point is created before every import so you can revert your site in one click.
- **Activity log** — every import and thumbnail run is recorded with a live, per-item log for easy troubleshooting.
- **Regenerate Thumbnails tool** — a dedicated, resumable tool with force-all-sizes and one-at-a-time modes.
- **WP-CLI support** — `wp edi demos`, `regenerate`, and `rollback`.
- **Pre-import readiness checks** and **bundled media import**.
- Completed the fix for the SVG-upload Stored XSS (**CVE-2024-9071**).

See the full [CHANGELOG](CHANGELOG.md) for details.

### Features

- One-Click Demo Import
- Based on the WordPress XML Importer
- Resumable, Timeout-Proof Import for large & WooCommerce demos
- Manual Import (your own files or a single `.zip`)
- One-Click Rollback / Restore Points
- Activity Log with a live, per-item view
- Dedicated Thumbnail Regeneration tool
- WP-CLI commands (`demos`, `regenerate`, `rollback`)
- Complete Content Replication
- Universal Theme Compatibility
- Full Site or Single Site Import for multipurpose themes
- Modern React-Powered Admin Pages
- Database Reset Option Included
- Media Import Control for Speed
- Bundled Media Import
- Tabbed Categories & Search
- Fluent Forms & Slider Revolution Import
- Built-in Required Plugin Installer
- Built-in System Status Checker & Pre-Import Readiness Report
- Automatic URL and Commenter Email Replacement
- Elementor Taxonomy Data Fix
- Developer Hooks for Custom Actions

### Requirements

- PHP >= 7.4
- WordPress >= 5.5
- [Composer](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos)
- [Node.js](https://nodejs.org/en/download/)

### Development Setup

Clone the repository:

```shell
git clone git@github.com:wp-sigmadevs/easy-demo-importer.git
cd easy-demo-importer
```

Install PHP dependencies and generate an **optimized** autoloader (the plugin
discovers its services from the classmap, so the `-o` flag is required — a plain
`composer install` leaves the classmap empty and no admin pages load):

```shell
composer install -o
```

Install Node packages:

```shell
npm install
```

### Build Commands

```shell
npm run watch        # compile assets in dev watch mode
npm run production   # production build
npm run package      # build a production package in dist/ (assets + no-dev autoloader)
npm run zip          # build and create the distributable .zip in dist/
```

### Quality Checks

```shell
composer test        # PHPUnit unit suite
composer phpstan     # static analysis (PHPStan)
composer phpcs       # coding standards (WordPress Coding Standards)
npm run eslint       # JavaScript lint
npm run stylelint    # SCSS lint
```

Integration tests (`tests/Integration`) require a WordPress test-suite install and
a throwaway database — see [`tests/README.md`](tests/README.md).

### License

[GPLv3 or later](https://www.gnu.org/licenses/gpl-3.0.html).
