<?php
/**
 * REST Endpoint: ResolveDeps
 *
 * POST /wp-json/sd/edi/v1/resolve-deps
 * Body: { demo: string, selected_ids: int[] }
 * Response: { hard: int[], soft: [{ id, label, type }] }
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\App\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use SigmaDevs\EasyDemoImporter\Common\{
	Abstracts\Base,
	Traits\Singleton,
	Utils\DependencyResolver
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class ResolveDeps
 *
 * @since 1.5.0
 */
class ResolveDeps extends Base {

	use Singleton;

	/**
	 * Register the REST route.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'registerRoutes' ] );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function registerRoutes(): void {
		register_rest_route(
			'sd/edi/v1',
			'/resolve-deps',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resolve' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
			]
		);
	}

	/**
	 * Handle POST /resolve-deps request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.5.0
	 */
	public function resolve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body      = $request->get_json_params();
		$demo_slug = isset( $body['demo'] ) ? sanitize_text_field( $body['demo'] ) : '';
		$ids       = isset( $body['selected_ids'] ) && is_array( $body['selected_ids'] )
			? array_map( 'absint', $body['selected_ids'] )
			: [];

		if ( ! $demo_slug ) {
			return new WP_Error(
				'missing_demo',
				__( 'Missing demo slug.', 'easy-demo-importer' ),
				[ 'status' => 400 ]
			);
		}

		$uploads  = wp_get_upload_dir();
		$xml_path = $uploads['basedir'] . '/easy-demo-importer/' . $demo_slug . '/content.xml';

		if ( ! file_exists( $xml_path ) ) {
			return rest_ensure_response( [ 'hard' => [], 'soft' => [] ] );
		}

		$result = DependencyResolver::resolve( $xml_path, $ids );

		return rest_ensure_response( $result );
	}
}
