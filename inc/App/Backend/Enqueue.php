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
				'ediLogo'                    => esc_url( $this->plugin->assetsUri() . '/images/sd-edi-logo.svg' ),
				'numberOfDemos'              => ! empty( sd_edi()->getDemoConfig()['demoData'] ) ? count( sd_edi()->getDemoConfig()['demoData'] ) : 0,
				'hasTabCategories'           => ! empty( sd_edi()->getDemoConfig()['demoData'] ) ? Helpers::searchArrayKey( sd_edi()->getDemoConfig(), 'category' ) : 'no',
				Helpers::nonceId()           => wp_create_nonce( Helpers::nonceText() ),
				'enableSupportButton'        => esc_html( apply_filters( 'sd/edi/support_button', 'yes' ) ),
				'searchPlaceholder'          => esc_html__( 'Search demos...', 'easy-demo-importer' ),
				'searchNoResults'            => esc_html__( 'Nothing found. Please try again.', 'easy-demo-importer' ),
				'removeTabsAndSearch'        => esc_html( apply_filters( 'sd/edi/remove_tab_and_search', false ) ),

				// Imports messages.
				'prepareImporting'           => esc_html__( 'Preparing to install demo data. Doing some cleanups first.', 'easy-demo-importer' ),
				'resetDatabase'              => esc_html__( 'Preparing to install demo data. Resetting database first.', 'easy-demo-importer' ),
				'importError'                => esc_html__( 'Something went wrong with the import.', 'easy-demo-importer' ),
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
				'excludeImagesTitle'         => esc_html__( 'Exclude Demo Images', 'easy-demo-importer' ),
				'excludeImagesHint'          => esc_html__( 'Select this option if demo import fails repeatedly. Excluding images will speed up the import process.', 'easy-demo-importer' ),
				'resetDatabaseTitle'         => esc_html__( 'Reset Existing Database', 'easy-demo-importer' ),
				'resetDatabaseWarning'       => esc_html__( 'Caution: ', 'easy-demo-importer' ),
				'resetDatabaseHint'          => esc_html__( 'Resetting the database will erase all of your content, including posts, pages, images, custom post types, taxonomies and settings. It is advised to reset the database for a full demo import.', 'easy-demo-importer' ),

				// Confirmation Modal.
				'confirmationModal'          => esc_html__( 'Are you sure you want to proceed?', 'easy-demo-importer' ),
				'resetMessage'               => esc_html__( 'Resetting the database will delete all your contents.', 'easy-demo-importer' ),
				'confirmationModalWithReset' => esc_html__( 'Are you sure you want to proceed? Resetting the database will delete all your contents, medias and settings.', 'easy-demo-importer' ),
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
				'copySuccess'                => esc_html__( 'System status data copied to clipboard', 'easy-demo-importer' ),
				'copyFailure'                => esc_html__( 'Unable to copy to clipboard. Try again.', 'easy-demo-importer' ),

				// Server Requirements.
				'reqHeader'                  => esc_html__( 'Server Requirements Error', 'easy-demo-importer' ),
				'reqDescription'             => esc_html__( 'Server Requirements for demo import have not been met. Kindly head to the server status page to review the details. Please note that if the minimum server requirements have not met, the demo import may be stuck or unsuccessful.', 'easy-demo-importer' ),
				'reqProceed'                 => esc_html__( 'Continue Anyway', 'easy-demo-importer' ),

				// Plugin Status.
				'notInstalled'               => esc_html__( 'Not installed', 'easy-demo-importer' ),
				'installedAndActive'         => esc_html__( 'Installed and active', 'easy-demo-importer' ),
				'installedNotActive'         => esc_html__( 'Installed but not active', 'easy-demo-importer' ),
				'installedNeedUpdate'        => esc_html__( 'Installed and active, update required', 'easy-demo-importer' ),
				'inactiveNeedUpdate'         => esc_html__( 'Installed but not active, update required', 'easy-demo-importer' ),
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
			'sd/edi/import_modal_texts',
			[
				'stepOne'     => [
					'introTopText'    => esc_html__( 'Before importing demo data, we recommend that you backup your site\'s data and files. You can use a popular backup plugin to ensure you have a copy of your site in case anything goes wrong during the import process.', 'easy-demo-importer' ),
					'introBottomText' => esc_html__( 'Please note that this demo import will install all the required plugins, import contents, media, settings, customizer data, widgets, and other necessary elements to replicate the demo site. Make sure to review your existing data and settings as they may be overwritten.', 'easy-demo-importer' ),
					'introExtraText'  => '',
				],
				'stepTwo'     => [
					'requiredPluginsIntro' => esc_html__( 'In order to replicate the exact appearance of the demo site, the import process will automatically install and activate the following plugins, provided they are not already installed or activated on your website. You may need to scroll through to see the full list:', 'easy-demo-importer' ),
				],
				'supportText' => esc_html__( 'If you have any problems, please don\'t hesitate to contact us. This helps us help you quickly and give you the right solutions.', 'easy-demo-importer' ),
				'docText'     => esc_html__( 'Start your journey by diving into our detailed FAQ documentation. It offers a comprehensive guide with step-by-step instructions, and helpful screenshots to assist you in successfully importing demo data', 'easy-demo-importer' ),
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
