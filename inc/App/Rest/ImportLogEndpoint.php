<?php
/**
 * Rest Class: Import Log Endpoint.
 *
 * GET /sd/edi/v1/import-log?session_id={id}&since={timestamp}
 * Returns log entries for polling by ActivityFeed component.
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Utils\ImportLogger
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ImportLogEndpoint
 *
 * @since 1.3.0
 */
class ImportLogEndpoint extends Base {
	use Singleton;

	/**
	 * Register REST route.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 * @since 1.3.0
	 */
	public function registerRoutes() {
		register_rest_route(
			'sd/edi/v1',
			'/import-log',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'getLog' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'session_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'since'      => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					),
				),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return true|WP_Error
	 * @since 1.3.0
	 */
	public function permission() {
		if ( ! current_user_can( 'import' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'Sorry, you are not allowed to do that.', 'easy-demo-importer' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Return log entries.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 * @since 1.3.0
	 */
	public function getLog( WP_REST_Request $request ): WP_REST_Response {
		$session_id = $request->get_param( 'session_id' );
		$since      = $request->get_param( 'since' ) ?? '';

		if ( empty( $session_id ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		// Guard against oversized or non-UUID session IDs.
		if ( strlen( $session_id ) > 64 ) {
			return new WP_REST_Response( array(), 200 );
		}

		$entries = ImportLogger::fetch( $session_id, $since );

		return new WP_REST_Response( $entries, 200 );
	}
}
