=== Easy Demo Importer - A Modern One Click Demo Import Solution ===
Contributors: sigmadevs
Donate link:
Tags: demo importer, one click demo importer, theme demo importer, content, import, demo, data, widgets, settings, redux, theme options
Requires at least: 5.5
Tested up to: 6.3
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html
GitHub Repository: https://github.com/wp-sigmadevs/easy-demo-importer

A one-click, user-friendly WordPress plugin for effortlessly importing theme demos and customizing your website in no time.

== Description ==

Are you tired of the complex and time-consuming process of setting up your WordPress website? Look no further. The Easy Demo Importer plugin is your solution to effortlessly import demo data and have your website up and running in no time.

👉 [Official GitHub repository](https://github.com/wp-sigmadevs/easy-demo-importer) | Plugin Documentation (Coming Soon). 👈

== Key Features ==

* **One-Click Demo Import:** With just one click, you can import beautifully designed demo content to kickstart your website.
* **Based on WordPress XML Importer:** Built on the reliable WordPress XML Importer, our plugin ensures a robust import process.
* **Full Site or Single Site Import:** Demo import can be configured to import a whole demo or individual demos from a multipurpose theme.
* **Complete Content Import:** Import Customizer settings, widgets, menus, options data, and more, ensuring your website looks and functions just like the demo.
* **User-Friendly Interface:** Our intuitive interface makes it easy for users of all skill levels to import demo data.
* **Universal Theme Compatibility:** Can be configured to work seamlessly with a wide range of WordPress themes, ensuring broad compatibility.
* **Developer Hooks:** offers a wide range of hooks that give theme developers full control to perform advanced custom actions. These hooks allow for precise adjustments and customizations in the import process.
* **Database Reset Option Included:** Theme users can decide whether to reset the database during import. It provides a clean slate for a fresh start.
* **Media Import Control for Speed:** Our plugin offers flexibility by letting users choose whether to include media during demo imports. Disabling media import can speed up the setup for those who don't need media files.
* **Plugin Settings and Theme Options:** Can be configured to import any plugin settings and theme options, ensuring a cohesive website setup.
* **Fluent Forms Import:** Automatically imports Fluent Forms, retaining your forms' integrity.
* **Modern React-Powered Pages:** Enjoy modern, React-powered pages for a seamless user experience.
* **Built-in Required Plugins Installer:** Simplify the installation process of necessary plugins right from the start.
* **System Status Checker:** Our built-in system status checker acts as a helpful pre-import checklist, alerting you to any potential issues that need addressing.
* **Automatic URL and Commenter Email Replacement:** Designed for developers, our plugin has a versatile built-in tool for updating URLs and email addresses in your imported content.
* **Elementor Taxonomy Data Fix:** Resolve Elementor data import issues with our automatic taxonomy data fix.

Experience the ultimate convenience of importing demo data with the Theme Demo Importer plugin. Make your website setup a breeze and unleash the full potential of your WordPress theme. Whether you're a user or developer, our feature-rich and user-friendly plugin is designed to simplify your website creation journey.

> **Important Note:** Please be aware that this plugin does not provide a feature to import authors from the demo import file to existing users in your WordPress site. When you import demo content, all content will be attributed to the current user account.

For any bugs or suggestions, please email us: service.sigmadevs@gmail.com

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

Theme authors can configure the demo imports by hooking into the `sd/edi/importer/config` filter. A sample configuration can be found in the *plugin directory -> samples* folder along with a sample zip file.

= How can I see the demo importer in action? =

A sample configuration is provided in the *plugin directory -> samples* folder along with a sample zip file. It is configured to work with the default **Twenty Twenty-One** theme. You need to require the `sample-config.php` file in the theme to see the importer in action (it will even work in localhost).

= Where is the demo import page? =

If your theme is correctly configured, you can find the demo import page with all the demos in *Dashboard -> Appearance -> Easy Demo Importer*.

= How can I export plugin settings to configure with the demo import? =

Theme developers can use the [WP Options Importer](https://wordpress.org/plugins/options-importer) plugin to downloading the data from the options table. Then, use some preferable tools to decode and convert this data into a JSON format and include them in the demo zip file.

= I can't import demo. It is saying there are errors. What can I do? =

Commonly, errors encountered during the demo import process are often associated with the relatively smaller `max_execution_time` or server timeout settings. A practical first step is to inspect the built-in **Easy System Status** page, which can provide insights into any problematic server parameters. Once these issues are fixed, your demo import should work smoothly.

= Does this plugin support multi-language? =

Yes, Easy Demo Importer fully supports multi-language.

= Where can I report bugs or contribute to the project? =

Bugs can be reported either in our support forum or preferably on the [GitHub repository](https://github.com/wp-sigmadevs/easy-demo-importer/issues).

= Need Any Help? =

For any bug, support or suggestion please email us [here](mailto:service.sigmadevs@gmail.com).

== Screenshots ==

== Changelog ==

= 1.0.0 =
* Stable version release
