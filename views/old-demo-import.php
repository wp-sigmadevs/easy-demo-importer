<?php
/**
 * Demo Import Page.
 *
 * @package RT\DemoImport
 */

/**
 * Template variables:
 *
 * @var $args   array
 */

use RT\DemoImporter\Helpers\Fns;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

$themeConfig = $args['themeConfig'];
?>

<div class="wrap rtdi-demo-importer-wrapper">
	<div class="rtdi-header">
		<h2><?php echo esc_html__( 'Radius Demo Importer', 'radius-demo-importer' ); ?></h2>
	</div>

	<div class="rtdi-content">
		<div class="rtdi-container">
			<?php
			if ( is_array( $themeConfig ) && ! empty( $themeConfig ) ) {
				$demoType     = [];
				$pageBuilders = [];
				$demoData     = ! empty( $themeConfig['demoData'] ) ? $themeConfig['demoData'] : [];

				foreach ( $demoData as $demoKey => $demoValue ) {
					if ( isset( $demoValue['demoType'] ) && is_array( $demoValue['demoType'] ) ) {
						foreach ( $demoValue['demoType'] as $key => $type ) {
							$demoType[ $key ] = $type;
						}
					}
				}

				foreach ( $demoData as $demoKey => $demoValue ) {
					if ( isset( $demoValue['pageBuilder'] ) && is_array( $demoValue['pageBuilder'] ) ) {
						foreach ( $demoValue['pageBuilder'] as $key => $pageBuilder ) {
							$pageBuilders[ $key ] = $pageBuilder;
						}
					}
				}

				asort( $demoType );
				asort( $pageBuilders );

				if ( ! empty( $demoType ) || ! empty( $pageBuilders ) ) {
					?>
					<div class="rtdi-row">
						<div class="rtdi-tab-filter rtdi-clearfix rtdi-col-xs-12">
							<?php
							if ( ! empty( $demoType ) ) {
								?>
								<div class="rtdi-tab-group rtdi-tag-group" data-filter-group="tag">
									<div class="rtdi-tab" data-filter="*">
										<?php esc_html_e( 'All', 'radius-demo-importer' ); ?>
									</div>
									<?php
									foreach ( $demoType as $key => $value ) {
										?>
										<div class="rtdi-tab" data-filter=".<?php echo esc_attr( $key ); ?>">
											<?php echo esc_html( $value ); ?>
										</div>
										<?php
									}
									?>
								</div>
								<?php
							}

							if ( ! empty( $pageBuilders ) ) {
								?>
								<div class="rtdi-tab-group rtdi-pageBuilder-group" data-filter-group="pageBuilder">
									<div class="rtdi-tab" data-filter="*">
										<?php esc_html_e( 'All', 'radius-demo-importer' ); ?>
									</div>
									<?php
									foreach ( $pageBuilders as $key => $value ) {
										?>
										<div class="rtdi-tab" data-filter=".<?php echo esc_attr( $key ); ?>">
											<?php echo esc_html( $value ); ?>
										</div>
										<?php
									}
									?>
								</div>
								<?php
							}
							?>
						</div>
					</div>
					<?php
				}
				?>

				<div class="rtdi-demo-cards rtdi-row theme-browser">
					<?php
					foreach ( $demoData as $demoKey => $demoValue ) {
						$demoType     = '';
						$pageBuilders = '';
						$class        = '';

						if ( isset( $demoValue['tags'] ) ) {
							$demoType = implode( ' ', array_keys( $demoValue['tags'] ) );
						}

						if ( isset( $demoValue['pageBuilder'] ) ) {
							$pageBuilders = implode( ' ', array_keys( $demoValue['pageBuilder'] ) );
						}

						$classes = $demoType . ' ' . $pageBuilders;

						$type = ! empty( $demoValue['type'] ) ? $demoValue['type'] : 'free';
						?>
						<div id="<?php echo esc_attr( $demoKey ); ?>"
						     class="rtdi-demo-card theme rtdi-col-sm-12 rtdi-col-md-6 rtdi-col-lg-24 <?php echo esc_attr( $classes ); ?>">
							<?php
							if ( 'pro' === $type ) {
								?>
								<div class="rtdi-ribbon"><span>Premium</span></div>
								<?php
							}
							?>
							<div class="theme-screenshot">
								<img src="<?php echo esc_url( $demoValue['previewImage'] ); ?> " alt="Preview Image">
							</div>
							<button href="<?php echo esc_url( $demoValue['previewUrl'] ); ?>" target="_blank"
							        class="more-details">
								<?php echo esc_html__( 'Preview', 'radius-demo-importer' ); ?>
							</button>

							<div class="rtdi-demo-actions theme-id-container">
								<h2 class="theme-name"><?php echo esc_html( $demoValue['name'] ); ?></h2>

								<div class="rtdi-demo-buttons">
									<?php
									if ( 'pro' === $type ) {
										$buyUrl = ! empty( $demoValue['buy_url'] ) ? $demoValue['buy_url'] : '#';
										?>
										<a target="_blank" href="<?php echo esc_url( $buyUrl ); ?>"
										   class="button button-primary">
											<?php echo esc_html__( 'Buy Now', 'radius-demo-importer' ); ?>
										</a>
									<?php } else { ?>
										<a href="#rtdi-modal-<?php echo esc_attr( $demoKey ); ?>"
										   class="rtdi-modal-button button button-primary">
											<?php echo esc_html__( 'Install', 'radius-demo-importer' ); ?>
										</a>
										<?php
									}
									?>
								</div>

							</div>
						</div>
						<?php
					}
					?>
				</div>
				<?php
			} else {
				?>
				<div class="rtdi-demo-wrap">
					<div class="no-demo-found">
						<?php
						esc_html_e( 'We apologize for any inconvenience, but it appears that the configuration file for the demo importer is either missing or you are using an unsupported theme. As a result, the installation of the demo content cannot proceed any further at this time. Thank you for your understanding.', 'radius-demo-importer' );
						?>
					</div>
				</div>
				<?php
			}
			?>
		</div>
	</div>

	<div class="rtdi-modal-content">
		<?php
		if ( is_array( $themeConfig ) && ! empty( $themeConfig ) ) {
			$demoData = ! empty( $themeConfig['demoData'] ) ? $themeConfig['demoData'] : [];
			foreach ( $demoData as $demoKey => $demoValue ) {
				?>
				<div id="rtdi-modal-<?php echo esc_attr( $demoKey ); ?>" class="rtdi-modal" style="display: none;">

					<div class="rtdi-modal-header">
						<h2>
							<?php
							printf(
							/* translators: Demo Name */
								esc_html__( 'Import %s Demo', 'radius-demo-importer' ),
								esc_html( $demoValue['name'] )
							);
							?>
						</h2>
						<div class="rtdi-modal-back"><span class="dashicons dashicons-no-alt"></span></div>
					</div>

					<div class="rtdi-modal-wrap">
						<p>
							<?php
							echo esc_html__( 'We recommend you backup your website content before attempting to import the demo so that you can recover your website if something goes wrong.', 'radius-demo-importer' );
							?>
						</p>

						<p><?php echo esc_html__( 'This process will install all the required plugins, import contents and setup customizer and theme options.', 'radius-demo-importer' ); ?></p>

						<?php
						if ( $themeConfig['multipleZip'] ) {
							$requiredPlugins = ! empty( $demoValue['plugins'] ) ? $demoValue['plugins'] : [];
						} else {
							$requiredPlugins = ! empty( $themeConfig['plugins'] ) ? $themeConfig['plugins'] : [];
						}

						if ( ! empty( $requiredPlugins ) ) {
							?>
							<div class="rtdi-modal-recommended-plugins">
								<h4><?php esc_html_e( 'Required Plugins', 'radius-demo-importer' ); ?></h4>
								<p><?php esc_html_e( 'For your website to look exactly like the demo,the import process will install and activate the following plugin if they are not installed or activated.', 'radius-demo-importer' ); ?></p>
								<?php
								if ( is_array( $requiredPlugins ) ) {
									?>
									<ul class="rtdi-plugin-status">
										<?php
										foreach ( $requiredPlugins as $plugin ) {
											$name   = ! empty( $plugin['name'] ) ? $plugin['name'] : '';
											$status = Fns::pluginActivationStatus( $plugin['filePath'] );
											if ( 'active' === $status ) {
												$pluginClass = '<span class="dashicons dashicons-yes-alt"></span>';
											} elseif ( 'inactive' === $status ) {
												$pluginClass = '<span class="dashicons dashicons-warning"></span>';
											} else {
												$pluginClass = '<span class="dashicons dashicons-dismiss"></span>';
											}
											?>
											<li class="rtdi-<?php echo esc_attr( $status ); ?>">
												<?php
												echo wp_kses_post( $pluginClass ) . ' ' . esc_html( $name ) . ' - <i>' . esc_html( Fns::getPluginStatus( $status ) ) . '</i>';
												?>
											</li>
											<?php
										}
										?>
									</ul>
									<?php
								} else {
									?>
									<ul>
										<li><?php esc_html_e( 'No Required Plugins Found.', 'radius-demo-importer' ); ?></li>
									</ul>
									<?php
								}
								?>
							</div>
							<?php
						}
						?>

						<div class="rtdi-exclude-image-checkbox">
							<h4><?php esc_html_e( 'Exclude Images', 'radius-demo-importer' ); ?></h4>
							<p><?php esc_html_e( 'Check this option if importing demo fails multiple times. Excluding image will make the demo import process super quick.', 'radius-demo-importer' ); ?></p>
							<label>
								<input id="checkbox-exclude-image-<?php echo esc_attr( $demoKey ); ?>" type="checkbox"
								       value='1'/>
								<?php echo esc_html__( 'Yes, Exclude Images', 'radius-demo-importer' ); ?>
							</label>
						</div>

						<div class="rtdi-reset-checkbox">
							<h4><?php esc_html_e( 'Reset Website', 'radius-demo-importer' ); ?></h4>
							<p><?php esc_html_e( 'Reseting the website will delete all your post, pages, custom post types, categories, taxonomies, images and all other customizer and theme option settings.', 'radius-demo-importer' ); ?></p>
							<p><?php esc_html_e( 'It is always recommended to reset the database for a complete demo import.', 'radius-demo-importer' ); ?></p>
							<label class="rtdi-reset-website-checkbox">
								<input id="checkbox-reset-<?php echo esc_attr( $demoKey ); ?>" type="checkbox"
								       value='1' checked="checked"/>
								<?php echo esc_html__( 'Reset Website - Check this box only if you are sure to reset the website.', 'radius-demo-importer' ); ?>
							</label>
						</div>

						<a href="javascript:void(0)" data-demo-slug="<?php echo esc_attr( $demoKey ); ?>"
						   class="button button-primary rtdi-import-demo"><?php esc_html_e( 'Import Demo', 'radius-demo-importer' ); ?></a>
						<a href="javascript:void(0)"
						   class="button rtdi-modal-cancel"><?php esc_html_e( 'Cancel', 'radius-demo-importer' ); ?></a>
					</div>
				</div>
				<?php
			}
		}
		?>
	</div>
	<div id="rtdi-import-progress" style="display: none">
		<h2 class="rtdi-import-progress-header"><?php echo esc_html__( 'Demo Import Progress', 'radius-demo-importer' ); ?></h2>

		<div class="rtdi-import-progress-wrap">
			<div class="rtdi-import-loader">
				<div class="rtdi-loader-content">
					<div class="rtdi-loader-content-inside">
						<div class="rtdi-loader-rotater"></div>
						<div class="rtdi-loader-line-point"></div>
					</div>
				</div>
			</div>
			<div class="rtdi-import-progress-message"></div>
		</div>
	</div>
</div>
