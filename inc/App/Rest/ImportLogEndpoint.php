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
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
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
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getLog' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'session_id' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'since'      => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 * @since 1.3.0
	 */
	public function permission(): bool {
		return current_user_can( 'import' );
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
			return new WP_REST_Response( [], 200 );
		}

		$entries = ImportLogger::fetch( $session_id, $since );

		return new WP_REST_Response( $entries, 200 );
	}
}
