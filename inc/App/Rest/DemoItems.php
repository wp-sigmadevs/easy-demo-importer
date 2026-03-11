<?php
/**
 * REST Endpoint: DemoItems
 *
 * GET /wp-json/sd/edi/v1/demo-items?demo=<slug>&post_type=<type>
 * Returns items from the demo WXR, optionally filtered by post_type.
 * Also returns the distinct list of post types for tab building.
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
	Utils\XmlChunker
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class DemoItems
 *
 * @since 1.5.0
 */
class DemoItems extends Base {

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
			'/demo-items',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getItems' ],
				'permission_callback' => [ $this, 'permissionCheck' ],
				'args'                => [
					'demo'      => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'post_type' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
						'default'           => '',
					],
				],
			]
		);
	}

	/**
	 * Permission check — require manage_options.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function permissionCheck(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle GET /demo-items request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.5.0
	 */
	public function getItems( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$demo_slug = $request->get_param( 'demo' );
		$post_type = $request->get_param( 'post_type' );
		$xml_path  = $this->resolveXmlPath( $demo_slug );

		if ( ! $xml_path ) {
			return new WP_Error(
				'demo_not_found',
				__( 'Demo XML not found.', 'easy-demo-importer' ),
				[ 'status' => 404 ]
			);
		}

		$cache_key = 'sd_edi_demo_items_' . md5( $xml_path );
		$all       = get_transient( $cache_key );

		if ( false === $all ) {
			$all = XmlChunker::getItems( $xml_path );
			set_transient( $cache_key, $all, 5 * MINUTE_IN_SECONDS );
		}

		$items = [];

		foreach ( $all as $item ) {
			if ( $post_type && $item['post_type'] !== $post_type ) {
				continue;
			}

			$items[] = [
				'id'        => $item['post_id'],
				'title'     => $item['post_title'] ?: "(#{$item['post_id']})",
				'post_type' => $item['post_type'],
			];
		}

		// Distinct list of post types (for tab building in SelectItemsStep).
		$types = array_values( array_unique( array_column( $all, 'post_type' ) ) );
		sort( $types );

		return rest_ensure_response( [ 'items' => $items, 'types' => $types ] );
	}

	/**
	 * Resolve the absolute path to the demo's content.xml.
	 *
	 * @param string $demo_slug Demo slug.
	 * @return string|null Absolute path, or null if not found.
	 * @since 1.5.0
	 */
	private function resolveXmlPath( string $demo_slug ): ?string {
		$uploads = wp_get_upload_dir();
		$path    = $uploads['basedir'] . '/easy-demo-importer/' . $demo_slug . '/content.xml';

		return file_exists( $path ) ? $path : null;
	}
}
