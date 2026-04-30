<?php
/**
 * Backend Class: Enqueue
 *
 * This class enqueues required styles & scripts in the admin pages.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Traits\Singleton,
	Functions\Helpers,
	Abstracts\Enqueue as EnqueueBase
};
use SigmaDevs\EasyDemoImporter\Common\Utils\ContextResolver;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Class: Enqueue
 *
 * @package ThePluginName\App\Backend
 * @since 1.0.0
 */
class Enqueue extends EnqueueBase {
	/**
	 * Singleton Trait.
	 *
	 * @see Singleton
	 * @since 1.0.0
	 */
	use Singleton;

	/**
	 * Registers the class.
	 *
	 * This backend class is only being instantiated in the backend
	 * as requested in the Bootstrap class.
	 *
	 * @return void
	 * @since 1.0.0
	 *
	 * @see Bootstrap::registerServices
	 * @see Requester::isAdminBackend()
	 */
	public function register() {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'themes.php' === $pagenow && ( 'sd-easy-demo-importer' === $page || 'sd-edi-demo-importer-status' === $page ) ) {
			$this->assets();

			// Bail if no assets.
			if ( empty( $this->assets() ) ) {
				return;
			}

			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		}
	}

	/**
	 * Method to accumulate styles list.
	 *
	 * @return Enqueue
	 * @since 1.0.0
	 */
	protected function getStyles() {
		$styles = [];

		$styles[] = [
			'handle' => 'thickbox',
		];

		$styles[] = [
			'handle'    => 'sd-edi-admin-styles',
			'asset_uri' => esc_url( $this->plugin->assetsUri() . '/css/backend' . $this->suffix . '.css' ),
			'version'   => $this->plugin->version(),
		];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->enqueues['style'] = apply_filters( 'sd/edi/registered_admin_styles', $styles, 10, 1 );

		return $this;
	}

	/**
	 * Method to accumulate scripts list.
	 *
	 * @return Enqueue
	 * @since 1.0.0
	 */
	protected function getScripts() {
		$scripts = [];

		$scripts[] = [
			'handle'     => 'sd-edi-admin-script',
			'asset_uri'  => esc_url( $this->plugin->assetsUri() . '/js/backend.min.js' ),
			'dependency' => [ 'jquery' ],
			'in_footer'  => true,
			'version'    => $this->plugin->version(),
		];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$this->enqueues['script'] = apply_filters( 'sd/edi/registered_admin_scripts', $scripts, 10, 1 );

		return $this;
	}

	/**
	 * Method to enqueue scripts.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue() {
		$this
			->registerScripts()
			->enqueueScripts()
			->localize( $this->localizeData() );
	}

	/**
	 * Localized data.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function localizeData() {
		return [
			'handle' => 'sd-edi-admin-script',
			'object' => 'sdEdiAdminParams',
			'data'   => [
				// Essentials.
				'ajaxUrl'                    => esc_url( Helpers::ajaxUrl() ),
				'homeUrl'                    => esc_url( home_url( '/' ) ),
				'restApiUrl'                 => esc_url_raw( rest_url() ),
				'restNonce'                  => wp_create_nonce( 'wp_rest' ),

				// Multisite context.
				'isMultisite'                => ContextResolver::isMultisite(),
				'isNetworkContext'           => ContextResolver::isNetworkContext(),
				'currentBlogId'              => ContextResolver::currentBlogId(),
				'currentBlogLabel'           => ContextResolver::currentBlogLabel(),
				'currentBlogUrl'             => esc_url( home_url() ),
				'isSuperAdmin'               => function_exists( 'is_super_admin' ) && is_super_admin(),
				'canInstallPlugins'          => ContextResolver::canInstallPlugins(),
				'canUnfilteredUpload'        => ContextResolver::canUnfilteredUpload(),
				'subsiteBannerLabel'         => sprintf(
					/* translators: %s: subsite label like "subsite-2 (https://sub2.example.com)" */
					esc_html__( 'Importing into: %s', 'easy-demo-importer' ),
					ContextResolver::currentBlogLabel()
				),
				'networkContactSubject'      => esc_html__( 'Easy Demo Importer — required plugins missing', 'easy-demo-importer' ),
				'networkContactBody'         => esc_html__( "Hello,\n\nI need the following plugins installed network-wide for the demo importer to run:\n\n", 'easy-demo-importer' ),
				'networkRequiredPluginsMissing' => [],

				// Multisite UI strings.
				'i18nNetworkBlockTitle'      => esc_html__( 'Network Admin must install the following plugins network-wide:', 'easy-demo-importer' ),
				'i18nNetworkSuperTitle'      => esc_html__( 'Required plugins are missing on this network. As Super Admin you can install them network-wide:', 'easy-demo-importer' ),
				'i18nNotifyNetworkAdmin'     => esc_html__( 'Notify Network Admin', 'easy-demo-importer' ),
				'i18nRefresh'                => esc_html__( 'Refresh', 'easy-demo-importer' ),
				'i18nInstallAllOnNetwork'    => esc_html__( 'Install all on network', 'easy-demo-importer' ),
				'i18nInstalling'             => esc_html__( 'Installing…', 'easy-demo-importer' ),
				/* translators: 1: plugin name, 2: current count, 3: total count */
				'i18nInstallingProgress'     => esc_html__( 'Installing %1$s (%2$d/%3$d)…', 'easy-demo-importer' ),
				'i18nRestUnavailable'        => esc_html__( 'REST API not available.', 'easy-demo-importer' ),
				'i18nDomainEchoIntro'        => esc_html__( 'Type the host below to confirm:', 'easy-demo-importer' ),
				'i18nDomainEchoCancel'       => esc_html__( 'Cancel', 'easy-demo-importer' ),
				/* translators: %s: subsite host like "sub2.example.com" */
				'i18nDomainEchoConfirm'      => esc_html__( 'I understand — reset %s', 'easy-demo-importer' ),
				'i18nDomainEchoTitleFallback' => esc_html__( 'Confirm reset', 'easy-demo-importer' ),

				'ediLogo'                    => esc_url( $this->plugin->assetsUri() . '/images/sd-edi-logo.svg' ),
				'numberOfDemos'              => ! empty( sd_edi()->getDemoConfig()['demoData'] ) ? count( sd_edi()->getDemoConfig()['demoData'] ) : 0,
				'hasTabCategories'           => ! empty( sd_edi()->getDemoConfig()['demoData'] ) ? Helpers::searchArrayKey( sd_edi()->getDemoConfig(), 'category' ) : 'no',
				Helpers::nonceId()           => wp_create_nonce( Helpers::nonceText() ),
				'enableSupportButton'        => esc_html( apply_filters( 'sd/edi/support_button', 'yes' ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'searchPlaceholder'          => esc_html__( 'Search demos...', 'easy-demo-importer' ),
				'searchNoResults'            => esc_html__( 'No demos found. Try a different search term.', 'easy-demo-importer' ),
				'removeTabsAndSearch'        => esc_html( apply_filters( 'sd/edi/remove_tab_and_search', false ) ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

				// Imports messages.
				'prepareImporting'           => esc_html__( 'Preparing to install demo data. Doing some cleanups first.', 'easy-demo-importer' ),
				'resetDatabase'              => esc_html__( 'Preparing to install demo data. Resetting database first.', 'easy-demo-importer' ),
				'importSuccess'              => esc_html__( 'All done. Have fun!', 'easy-demo-importer' ),

				// Modal texts.
				'modalHeaderPrefix'          => esc_html__( 'Importing Demo:', 'easy-demo-importer' ),

				// Modal Step One.
				'beforeYouPreceed'           => esc_html__( 'Before You Proceed', 'easy-demo-importer' ),
				'stepOneIntro1'              => self::importModalTexts()['stepOne']['introTopText'],
				'stepOneIntro2'              => self::importModalTexts()['stepOne']['introBottomText'],
				'stepOneIntro3'              => self::importModalTexts()['stepOne']['introExtraText'],
				'stepTitles'                 => self::importModalTexts()['stepTitles'],

				// Modal Step Two.
				'requiredPluginsTitle'       => esc_html__( 'Required Plugins', 'easy-demo-importer' ),
				'configureImportTitle'       => esc_html__( 'Configure Your Import', 'easy-demo-importer' ),
				'requiredPluginsIntro'       => self::importModalTexts()['stepTwo']['requiredPluginsIntro'],
				'excludeImagesTitle'         => esc_html__( 'Skip Demo Images', 'easy-demo-importer' ),
				'excludeImagesHint'          => esc_html__( 'Exclude demo images to speed up the import. Recommended if the import fails repeatedly.', 'easy-demo-importer' ),
				'skipImageRegenerationTitle' => esc_html__( 'Skip Image Regeneration', 'easy-demo-importer' ),
				'skipImageRegenerationHint'  => esc_html__( 'Skips image regeneration during import for a faster experience. You can regenerate thumbnails later using a plugin like \'Regenerate Thumbnails\'.', 'easy-demo-importer' ),
				'resetDatabaseTitle'         => esc_html__( 'Reset Existing Database', 'easy-demo-importer' ),
				'resetDatabaseWarning'       => esc_html__( 'Caution: ', 'easy-demo-importer' ),
				'resetDatabaseHint'          => esc_html__( 'This will permanently erase all existing content — posts, pages, images, custom post types, taxonomies, and settings. A full database reset is recommended for the best demo import experience.', 'easy-demo-importer' ),

				// Confirmation Modal.
				'confirmationModal'          => esc_html__( 'Are you sure you want to continue?', 'easy-demo-importer' ),
				'resetMessage'               => esc_html__( 'This will permanently delete all your existing content.', 'easy-demo-importer' ),
				'confirmationModalWithReset' => esc_html__( 'This will permanently delete all your content, media, and settings. Are you sure you want to continue?', 'easy-demo-importer' ),
				'confirmYes'                 => esc_html__( 'Yes', 'easy-demo-importer' ),
				'confirmNo'                  => esc_html__( 'No', 'easy-demo-importer' ),

				// Server Page Button.
				'serverPageBtnText'          => esc_html__( 'System Status', 'easy-demo-importer' ),
				'serverPageUrl'              => sd_edi()->getData()['system_status_page'],
				'importPageBtnText'          => esc_html__( 'Back to Demo Importer', 'easy-demo-importer' ),
				'importPageUrl'              => admin_url( 'themes.php?page=sd-easy-demo-importer#/' ),
				'importPageLink'             => 'themes.php?page=sd-easy-demo-importer',

				// Button texts.
				'btnLivePreview'             => esc_html__( 'Live Preview', 'easy-demo-importer' ),
				'btnImport'                  => esc_html__( 'Import', 'easy-demo-importer' ),
				'btnCancel'                  => esc_html__( 'Cancel', 'easy-demo-importer' ),
				'btnContinue'                => esc_html__( 'Continue', 'easy-demo-importer' ),
				'btnPrevious'                => esc_html__( 'Previous', 'easy-demo-importer' ),
				'btnStartImport'             => esc_html__( 'Start Import', 'easy-demo-importer' ),
				'btnViewSite'                => esc_html__( 'View Site', 'easy-demo-importer' ),
				'btnClose'                   => esc_html__( 'Close', 'easy-demo-importer' ),
				'btnReloadRetry'             => esc_html__( 'Reload & Retry', 'easy-demo-importer' ),
				'btnResume'                  => esc_html__( 'Resume Import', 'easy-demo-importer' ),
				'btnStartOver'               => esc_html__( 'Start Over', 'easy-demo-importer' ),
				'resumingImport'             => esc_html__( 'Resuming import from where it left off...', 'easy-demo-importer' ),
				'importInterruptedTitle'     => esc_html__( 'Import was interrupted.', 'easy-demo-importer' ),
				'importInterruptedHint'      => esc_html__( 'Your previous import did not complete. Resume from where it left off, or start over to begin fresh.', 'easy-demo-importer' ),
				'clickEnlarge'               => esc_html__( 'Click to Enlarge', 'easy-demo-importer' ),
				'createATicket'              => esc_html__( 'Contact Us', 'easy-demo-importer' ),
				'viewDocumentation'          => esc_html__( 'View Documentation', 'easy-demo-importer' ),
				'needHelp'                   => esc_html__( 'Need help?', 'easy-demo-importer' ),
				'onlineDoc'                  => esc_html__( 'Documentation & FAQ', 'easy-demo-importer' ),
				'allDemoBtnText'             => esc_html__( 'All Demos', 'easy-demo-importer' ),

				// Support Modal.
				'supportTitle'               => esc_html__( 'Need Help?', 'easy-demo-importer' ),
				'docTitle'                   => esc_html__( 'Documentation & FAQs', 'easy-demo-importer' ),
				'supportDesc'                => self::importModalTexts()['supportText'],
				'docDesc'                    => self::importModalTexts()['docText'],
				'docUrl'                     => self::importModalTexts()['docUrl'],
				'ticketUrl'                  => self::importModalTexts()['ticketUrl'],
				'copySuccess'                => esc_html__( 'System status data copied to clipboard.', 'easy-demo-importer' ),
				'copyFailure'                => esc_html__( 'Could not copy to clipboard. Please try again.', 'easy-demo-importer' ),

				// Server Requirements.
				'reqHeader'                  => esc_html__( 'Server Requirements Not Met', 'easy-demo-importer' ),
				'reqDescription'             => esc_html__( 'Your server does not meet the minimum requirements for demo import. Please review the System Status page for details. Proceeding without meeting these requirements may result in a failed or incomplete import.', 'easy-demo-importer' ),
				'reqProceed'                 => esc_html__( 'Continue Anyway', 'easy-demo-importer' ),

				// Plugin Status.
				'notInstalled'               => esc_html__( 'Not installed', 'easy-demo-importer' ),
				'installedAndActive'         => esc_html__( 'Installed and active', 'easy-demo-importer' ),
				'installedNotActive'         => esc_html__( 'Installed but not active', 'easy-demo-importer' ),
				'installedNeedUpdate'        => esc_html__( 'Installed & active — update available', 'easy-demo-importer' ),
				'inactiveNeedUpdate'         => esc_html__( 'Installed but inactive — update available', 'easy-demo-importer' ),
			],
		];
	}

	/**
	 * Import modal texts.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	public static function importModalTexts() {
		return apply_filters(
			'sd/edi/import_modal_texts', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			[
				'stepOne'     => [
					'introTopText'    => esc_html__( 'We recommend backing up your site before proceeding. A backup plugin can help you restore your site if anything goes wrong during the import.', 'easy-demo-importer' ),
					'introBottomText' => esc_html__( 'This import will install all required plugins and bring in content, media, settings, customizer data, and widgets needed to replicate the demo site. Please review your existing content, as some data may be overwritten.', 'easy-demo-importer' ),
					'introExtraText'  => '',
				],
				'stepTwo'     => [
					'requiredPluginsIntro' => esc_html__( 'To replicate the demo site, the following plugins will be automatically installed and activated if not already present. Scroll down to see the full list.', 'easy-demo-importer' ),
				],
				'supportText' => esc_html__( 'Having trouble? Reach out to us and we\'ll help you get sorted quickly.', 'easy-demo-importer' ),
				'docText'     => esc_html__( 'Explore our documentation for step-by-step guides, helpful screenshots, and FAQs to walk you through the demo import process.', 'easy-demo-importer' ),
				'docUrl'      => 'https://docs.sigmadevs.com/easy-demo-importer/',
				'ticketUrl'   => 'https://docs.sigmadevs.com/easy-demo-importer/contact-us/',
				'stepTitles'  => [
					'step1' => esc_html__( 'Start', 'easy-demo-importer' ),
					'step2' => esc_html__( 'Configure', 'easy-demo-importer' ),
					'step3' => esc_html__( 'Imports', 'easy-demo-importer' ),
					'step4' => esc_html__( 'End', 'easy-demo-importer' ),
				],
			]
		);
	}
}
