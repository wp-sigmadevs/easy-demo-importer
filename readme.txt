=== Easy Demo Importer - A Modern One-Click Demo Import Solution ===
Contributors: sigmadevs
Donate link:
Tags: demo importer, one click demo importer, theme demo importer, WordPress demo importer, content import plugin
Requires at least: 5.5
Tested up to: 6.8
Stable tag: 1.1.5
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
GitHub Repository: https://github.com/wp-sigmadevs/easy-demo-importer

A one-click, user-friendly WordPress plugin for effortlessly importing theme demos and customizing your website in no time.

== Description ==

Are you tired of the complex and time-consuming process of setting up your WordPress website? Look no further. The Easy Demo Importer plugin is your solution to effortlessly import demo data and have your website up and running in no time.

👉 [Official GitHub Repository](https://github.com/wp-sigmadevs/easy-demo-importer) | [Plugin Documentation](https://docs.sigmadevs.com/easy-demo-importer). 👈

> **Notes for users:** Please note that this plugin isn't a ready-to-use solution out of the box. The demo data import feature needs to be set up and configured by the theme authors. If you run into any difficulties or need help, please contact the theme author for assistance with the setup and integration. You can find a step-by-step guide on how to import demo data properly in the [User Documentation](https://docs.sigmadevs.com/easy-demo-importer/user-docs/).
>
> **Notes for theme authors:** If you encounter any issues or need assistance with integrating the demo importer into your theme, don't hesitate to seek support. You can either post your queries in the support forum or use the [plugin documentation contact page](https://docs.sigmadevs.com/easy-demo-importer/contact-us/). A detailed theme integration guide is available in the [Developer Documentation](https://docs.sigmadevs.com/easy-demo-importer/developer-docs/).

== Key Features ==

* **One-Click Demo Import:** With just one click, you can import beautifully designed demo content to kickstart your website.
* **Based on WordPress XML Importer:** Built on the reliable WordPress XML Importer, our plugin ensures a robust import process.
* **Full Site or Single Site Import:** Demo importer can be configured to import a whole demo or individual demos from a multipurpose theme.
* **Complete Content Import:** Imports all contents and Customizer settings, including widgets, menus, options data, Redux Framework data, Slider Revolution slides, and more, ensuring your website mirrors the demo in both appearance and functionality.
* **User-Friendly Interface:** Our intuitive interface makes it easy for users of all skill levels to import demo data.
* **Universal Theme Compatibility:** Can be configured to work seamlessly with a wide range of WordPress themes, ensuring broad compatibility.
* **Developer Hooks:** offers a wide range of hooks that give theme developers full control to perform advanced custom actions. These hooks allow for precise adjustments and customizations in the import process.
* **Database Reset Option Included:** Theme users can decide whether to reset the database during import. It provides a clean slate for a fresh start.
* **Media Import Control for Speed:** Our plugin offers flexibility by letting users choose whether to include media during demo imports. Disabling media import can speed up the setup for those who don't need media files.
* **Plugin Settings and Theme Options:** Can be configured to import any plugin settings and theme options, ensuring a cohesive website setup.
* **Tabbed Categories & Search feature:** Includes a convenient tabbed interface that categorizes demos into various categories with a powerful search feature.
* **Fluent Forms Import:** Can be configured to automatically import Fluent Forms, retaining your forms' integrity.
* **Slider Revolution Import:** Can be configured to automatically import Slider Revolution slides, ensuring the slides' functionalities.
* **Modern React-Powered Pages:** Enjoy modern, React-powered admin pages for a seamless user experience.
* **Built-in Required Plugins Installer:** Features a built-in Required Plugins Installer that can be configured for hassle-free import process.
* **System Status Checker:** Our built-in system status checker acts as a helpful pre-import checklist, alerting you to any potential issues that need addressing.
* **Automatic URL and Commenter Email Replacement:** Designed for developers, our plugin has a versatile built-in tool for updating URLs and email addresses in your imported content.
* **Elementor Taxonomy Data Fix:** Resolve Elementor widgets data import issues with our automatic taxonomy data fix.

Experience the ultimate convenience of importing demo data with the Easy Demo Importer plugin. Make your website set up a breeze and unleash the full potential of your WordPress theme. Whether you're a user or developer, our feature-rich and user-friendly plugin is designed to simplify your website creation journey.

> **Important Note:** Please be aware that this plugin does not provide a feature to import authors from the demo import file in your WordPress site. When you import demo content, all content will be attributed to the current user account.

For any bugs or suggestions, please email us at: service.sigmadevs@gmail.com

= Requirements =
* **WordPress version:** >= 5.5
* **PHP version:** >= 7.4

== Installation ==

= Using The WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Search for 'Easy Demo Importer'
3. Click 'Install Now'
4. Activate the plugin on the Plugin dashboard

= Uploading in WordPress Dashboard =

1. Navigate to the 'Add New' in the plugins dashboard
2. Navigate to the 'Upload' area
3. Select `easy-demo-importer.zip` from your computer
4. Click 'Install Now'
5. Activate the plugin in the Plugin dashboard

= Using FTP =

1. Download `easy-demo-importer.zip`
2. Extract the `easy-demo-importer` directory to your computer
3. Upload the `easy-demo-importer` directory to the `/wp-content/plugins/` directory
4. Activate the plugin in the Plugin dashboard

== Frequently Asked Questions ==

= How to configure demo import? =

Theme authors can can set up demo imports easily by using the `sd/edi/importer/config` filter. A sample configuration can be found in the "samples" folder within the plugin directory.

Please note that three mandatory files need to be exported from the theme demo: **XML File, Customizer File, and Widget File**. The XML file (.xml) needs to be renamed as `content.xml`, the Customizer file (.dat) as `customizer.dat`, and the widget file (.wie) as `widget.wie`, after that these files will be automatically recognized by the demo importer.

For step-by-step instructions on integrating your theme with the Easy Demo Importer, please check out the **[Developer Docs](https://docs.sigmadevs.com/easy-demo-importer/developer-docs/)** in the Easy Demo Importer Documentation.

= How can I see the demo importer in action? =

A sample configuration is provided in the *plugin directory -> samples* folder. It is configured to work with the default **Twenty Twenty-One** theme.

To see the importer in action, simply require the `sample-config.php` file within your theme's `functions.php`. It will even work in the localhost.

The link of a **sample .zip file** is provided in the plugin documentation. Kindly look for it in the information note of the **[Configuring the Demo Import with PHP](https://docs.sigmadevs.com/easy-demo-importer/php-configuration/#sd-toc-configuring-the-demo-import-with-php)** section.

= Where is the demo import page? =

If your theme is correctly configured, you can find the demo import page with all the demos in *Dashboard -> Appearance -> Easy Demo Importer*.

= How can I export any required plugin settings to include in the demo import? =

Please refer to the **[Exporting Settings as JSON](https://docs.sigmadevs.com/easy-demo-importer/theme-integration/#sd-toc-exporting-settings-as-json)** section in the Easy Demo Importer plugin documentation for detailed instructions on exporting various settings as JSON files.

= I can't import the demo. It is saying there are errors. What can I do? =

Commonly, errors encountered during the demo import process are often associated with the relatively smaller `max_execution_time` or server timeout settings. A practical first step is to inspect the built-in **Appearance -> Easy System Status** page, which can provide insights into any problematic server parameters. Once these issues are fixed, your demo import should work smoothly.

For a comprehensive guide on debugging import issues, please refer to the **[Troubleshooting](https://docs.sigmadevs.com/easy-demo-importer/troubleshooting/)** within the Easy Demo Importer plugin documentation. This page provides detailed steps to troubleshoot and resolve common import-related issues.

= Does this plugin support multi-language? =

Certainly, Easy Demo Importer fully supports multi-language functionality.

= Does this plugin support RTL? =

Absolutely! Easy Demo Importer provides full support for RTL languages.

= Where can I report bugs or contribute to the project? =

Bugs can be reported either in our support forum or preferably on the [GitHub repository](https://github.com/wp-sigmadevs/easy-demo-importer/issues), also on the [contact page](https://docs.sigmadevs.com/easy-demo-importer/contact-us/).

= Need Any Help? =

For any inquiries, bug reports, or suggestions, please submit your request [here](https://docs.sigmadevs.com/easy-demo-importer/contact-us/).

== Screenshots ==

1. Example of multiple demos that a user can choose from.
2. Example of how import step introduction popup looks.
3. Example of how required plugins and configure import popup looks.
4. Example of how the demo import step popup looks.
5. Example of the completed demo import popup.
6. Example of how the system status page looks.

== Changelog ==

= 1.1.5 (22-July-2025) =
* Add: Full compatibility with WordPress 6.8.
* Add: Nav menu item custom meta-data is now preserved during import.
* Add: Importer now supports non-unique post-types (e.g., grouped fields).
* Fix: PHP 8.4 compatibility improvements for the XML parser.
* Fix: Resolved CSS issues across various admin views.
* Fix: Corrected Elementor taxonomy mapping issues in default WordPress widgets.
* Fix: Fixed a typo in the system status report.
* Dev: Introduced new action hooks for advanced developer customization.
* Tweak: Temporarily disable big image scaling during the import process.
* Tweak: Enhanced CSS transitions for smoother visual behavior.
* Tweak: Refactored post-processing logic in the importer.
* Update: Upgraded the core Import library to the latest stable version.
* Update: Updated all dependency libraries to their latest versions.
* Update: UI enhancements to align with updated external libraries and frameworks.

= 1.1.4 (16-November-2024) =
* Add: Full compatibility with WordPress 6.7.
* Fix: Resolved the PHP "Uncaught TypeError" error in SVG detection.
* Performance: Removed the download server connection check to reduce REST API calls.
* Enhancement: Introduced a way to set the Posts page (blog) as the front page.

= 1.1.3 (03-October-2024) =
* Add: SVG file sanitization to prevent malicious content uploads.
* Add: File size validation for SVG uploads, with customizable size limits.
* Fix: Correct MIME type detection for SVG files in WordPress.
* Fix: Resolved issue where unsafe SVG files bypassed security checks.
* Tweak: Improved error messaging for file upload validation.

= 1.1.2 (11-August-2024) =
* Add: Full compatibility with WordPress 6.6.
* Add: Integrated React Router for smoother and faster page transitions.
* Fix: Resolved an issue where HTML entities were incorrectly displayed.
* Fix: Addressed UI issues on the Server Status page.
* Tweak: Enhanced the database reset script.
* Update: Updated dependency libraries to their latest versions.
* Update: UI adjustments to align with the updated versions of the libraries.

= 1.1.1 (21-April-2024) =
* Add: Included Support for RTL language.
* Add: Compatibility with WordPress 6.5 and PHP 8.3.
* Add: Implemented temporary PHP parameters boost during import.
* Fix: Addressed some sanitization issues.
* Fix: Rectified a PHP warning regarding undefined index.
* Fix: Resolved several responsive issues.
* Fix: Corrected various CSS issues.
* Tweak: Made improvements to the codebase.
* Update: Added/renamed various action and filter hooks.

= 1.1.0 (20-February-2024) =
* Feature: Added support for importing Slider Revolution Sliders.
* Feature: Introduced tabbed categories in the demo import interface.
* Feature: Implemented a search functionality for quicker access to specific demos.
* Add: Included step titles in the demo import process.
* Add: Enhanced the import modal intro step with an additional paragraph.
* Fix: Addressed import modal compatibility issues with high-resolution devices.
* Fix: Resolved Elementor taxonomy data mapping issues for repeater controls.
* Fix: Improved functionality of the required plugin update feature.
* Tweak: Conducted code refactoring to optimize performance and maintainability.
* Update: Added/renamed various action and filter hooks for enhanced customization.
* Update: Included 'PHP Max Input Time' parameter in the System Status Screen for better diagnostics.

= 1.0.2 (10-December-2023) =
* Feature: Added a feature to update required plugins if available.
* Fix: Resolved an issue with importing product attributes.
* Update: Elementor taxonomy data mapping for improved compatibility with multiple and repeater controls.

= 1.0.1 (26-November-2023) =
* Fix: Resolved compatibility issues with PHP 7.4.

= 1.0.0 =
* Initial release
