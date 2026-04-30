## Easy Demo Importer

![Requires PHP_>_7.4](https://img.shields.io/badge/Requires-PHP_>_7.4-2d74d5)
![Tested up to PHP_8.4](https://img.shields.io/badge/Tested-Up_to_PHP_8.4-2d74d5)
![Tested up to WordPress 6.9](https://img.shields.io/badge/Tested-Up_to_WordPress_6.9-2d74d5)
![Stable_Tag 1.2.0](https://img.shields.io/badge/Stable_Tag-1.2.0-2d74d5)
![Multisite Yes](https://img.shields.io/badge/Multisite-Yes-2d74d5)
![License GPLv3 or later](https://img.shields.io/badge/License-GPLv3_or_later-2d74d5)

<hr />

The Easy Demo Importer is your go-to solution for effortlessly bringing your WordPress site to life. With its user-friendly interface and robust feature set, it streamlines the process of importing demo content, offering an unparalleled level of convenience for both users and developers.

👉 [wordpress.org Plugin Link](https://wordpress.org/plugins/easy-demo-importer/) | [Plugin Documentation](https://docs.sigmadevs.com/easy-demo-importer) 👈

### Features

-   One-Click Demo Import
-   Based on WordPress XML Importer
-   Complete Content Replication
-   User-Friendly Interface
-   Universal Theme Compatibility
-   Developer-Friendly Customization
-   High Customization for Single and Multipurpose Demo Imports
-   Modern React-Powered Pages
-   Database Reset Option Included
-   Media Import Control for Speed
-   Tabbed Categories & Search feature
-   Fluent Forms Import
-   Slider Revolution Import
-   Built-in Required Plugin Installer
-   Built-in System Status Checker
-   Automatic URL and Commenter Email Replacement
-   Elementor Taxonomy Data Fix
-   Developer Hooks for Custom Actions
-   Full WordPress Multisite Support (per-subsite or network-active)
-   Network Admin screen with cross-subsite status and JSON config override
-   Tiered plugin install: Network Admin install path for Super Admins, "ask Network Admin" guidance for subsite admins
-   Domain-echo confirmation on multisite database reset

### Requirement
- Composer [HERE](https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos)
- Nodejs [HERE](https://nodejs.org/en/download/)
- PHP >= 7.4 

### Installation
- Clone git repository
```shell script
git clone git@github.com:wp-sigmadevs/easy-demo-importer.git
cd easy-demo-importer
```
- Generate vendor autoload files
```shell script
composer install
```
- Optimize autoload files
```shell script
composer dumpautoload -o 
```
- Install Node packages
```shell script
npm install
```

### NPM Helper Commands
#### Compile resources in dev watch mode
```shell script
# NPM
npm run watch
```
```shell script
# Yarn
yarn watch
```
```shell script
# Bun
bun watch
```
#### Create package (in dist directory)
```shell script
# NPM
npm run package
```
```shell script
# Yarn
yarn package
```
```shell script
# Bun
bun package
```
#### Create .zip file (in dist directory)
```shell script
# NPM
npm run zip
```
```shell script
# Yarn
yarn zip
```
```shell script
# Bun
bun zip
```
