<?php
/**
 * Trait: Requester.
 *
 * The requester trait to determine what we request,
 * which classes we instantiate in the Bootstrap class.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Traits;

// Do not allow directly accessing this file.
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Trait: Requester.
 *
 * @since  1.0.0
 */
trait Requester {
	/**
	 * What type of request is this?
	 *
	 * @param string $type admin, cron or frontend.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function request( string $type ) {
		switch ( $type ) {
			case 'frontend':
				return $this->isFrontend();
			case 'backend':
				return $this->isAdminBackend();
			case 'cron':
				return $this->isCron();
			default:
				wp_die(
					sprintf(
					/* translators: %s: request function */
						esc_html__( 'Unknown request type: %s.', 'easy-demo-importer' ),
						esc_html( $type )
					),
					esc_html__( 'Classes are not being correctly requested.', 'easy-demo-importer' ),
					__FILE__
				);
		}
	}

	/**
	 * Is it frontend?
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function isFrontend() {
		return ! $this->isAdminBackend() && ! $this->isCron();
	}

	/**
	 * Is it admin?
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function isAdminBackend() {
		return is_user_logged_in() && is_admin();
	}

	/**
	 * Is it cron?
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function isCron() {
		return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || defined( 'DOING_CRON' );
	}
}
