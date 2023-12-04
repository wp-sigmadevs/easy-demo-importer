<?php
/**
 * Backend Class: Deactivate Notice
 *
 * This class gives a notice for deactivation after
 * successful demo import.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Backend;

use SigmaDevs\EasyDemoImporter\Common\{
	Utils\Notice,
	Utils\Errors,
	Abstracts\Base,
	Traits\Singleton
};

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Backend Class: Deactivate Notice
 *
 * @since 1.0.0
 */
class DeactivateNotice extends Base {
	/**
	 * Singleton trait.
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
		if ( ! apply_filters( 'sd/edi/admin/deactivation_notice', false ) ) {
			return;
		}

		$this
			->deactivateNotice()
			->deactivateActions();
	}

	/**
	 * Deactivation notice,
	 *
	 * @return DeactivateNotice
	 * @since 1.0.0
	 */
	public function deactivateNotice() {
		$importSuccess          = get_option( 'sd_edi_import_success' );
		$ignoreDeactivateNotice = get_option( 'sd_edi_plugin_deactivate_notice' );

		if ( ! $importSuccess || ! current_user_can( 'deactivate_plugin' ) || ( 'true' === $ignoreDeactivateNotice && current_user_can( 'deactivate_plugin' ) ) ) {
            // phpcs:ignore Universal.CodeAnalysis.ConstructorDestructorReturn.ReturnValueFound
			return $this;
		}

		Notice::trigger( $this->noticeMarkup(), 'success', true );

		// phpcs:ignore Universal.CodeAnalysis.ConstructorDestructorReturn.ReturnValueFound
		return $this;
	}

	/**
	 * Deactivation actions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function deactivateActions() {
		add_action( 'admin_init', [ $this, 'deactivatePlugin' ], 0 );
		add_action( 'admin_init', [ $this, 'ignoreNotice' ], 0 );
	}

	/**
	 * Notice Markup.
	 *
	 * @return false|string
	 * @since 1.0.0
	 */
	private function noticeMarkup() {
		ob_start();
		?>
		<h4 style="text-decoration: underline;">Easy Demo Importer - Notice</h4>
		<p style="margin-bottom: 20px;">
			<?php
			printf(
				/* translators: %s: Plugin name */
				esc_html__(
					'It seems you\'ve imported the theme demo data successfully. So, the purpose of %1$s plugin is fulfilled, and it has no more use. %2$sIf you\'re satisfied with the imported theme demo data, you can safely deactivate it by clicking the %3$s button.',
					'easy-demo-importer'
				),
				'<b>' . esc_html( $this->plugin->name() ) . '</b>',
				'<br />',
				'<b>' . esc_html__( 'Deactivate Plugin', 'easy-demo-importer' ) . '</b>'
			);
			?>
		</p>

		<?php
		$link = wp_nonce_url(
			add_query_arg( 'deactivate-easy-demo-importer', 'true' ),
			'deactivate_sd_edi_plugin',
			'_deactivate_sd_edi_plugin_nonce'
		);
		?>
		<p class="links">
			<a href="<?php echo esc_url( $link ); ?>"
				class="btn button-primary">
				<span><?php esc_html_e( 'Deactivate Plugin', 'easy-demo-importer' ); ?></span>
			</a>
			<a class="btn button-secondary"
				href="?nag_sd_edi_plugin_deactivate_notice=0"><?php esc_html_e( 'Dismiss This Notice', 'easy-demo-importer' ); ?></a>
		</p>

		<?php
		return ob_get_clean();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public function deactivatePlugin() {
		// Deactivate the plugin.
		if ( isset( $_GET['deactivate-easy-demo-importer'] ) && isset( $_GET['_deactivate_sd_edi_plugin_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['_deactivate_sd_edi_plugin_nonce'] ) );

			if ( ! wp_verify_nonce( $nonce, 'deactivate_sd_edi_plugin' ) ) {
				wp_die( esc_html__( 'Action failed. Please refresh the page and retry.', 'easy-demo-importer' ) );
			}

			Errors::deactivate();
		}
	}

	/**
	 * Remove the plugin deactivate notice permanently.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function ignoreNotice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['nag_sd_edi_plugin_deactivate_notice'] ) && '0' === sanitize_text_field( wp_unslash( $_GET['nag_sd_edi_plugin_deactivate_notice'] ) ) ) {
			update_option( 'sd_edi_plugin_deactivate_notice', 'true' );
			wp_safe_redirect( admin_url( 'plugins.php' ) );
		}
	}
}
