<?php
/**
 * REST Endpoint: Rollback
 *
 * POST /wp-json/sd/edi/v1/rollback/{snapshot_id}
 * Restores a pre-import snapshot, undoing the import.
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
	Utils\SnapshotManager
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class Rollback
 *
 * @since 1.5.0
 */
class Rollback extends Base {

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
			'/rollback/(?P<id>\d+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => fn() => current_user_can( 'manage_options' ),
				'args'                => [
					'id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	/**
	 * Handle POST /rollback/{id} request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 * @since 1.5.0
	 */
	public function rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$snapshot_id = (int) $request->get_param( 'id' );
		$row         = SnapshotManager::get( $snapshot_id );

		if ( ! $row ) {
			return new WP_Error(
				'not_found',
				__( 'Snapshot not found or has expired (24h limit).', 'easy-demo-importer' ),
				[ 'status' => 404 ]
			);
		}

		$snap_data = json_decode( $row['snapshot_data'], true );
		$owner_id  = (int) ( $snap_data['user_id'] ?? 0 );

		if ( $owner_id > 0 && $owner_id !== get_current_user_id() ) {
			return new WP_Error(
				'forbidden',
				__( 'You can only undo your own imports.', 'easy-demo-importer' ),
				[ 'status' => 403 ]
			);
		}

		$result = SnapshotManager::restore( $snapshot_id, $row['session_id'] );

		if ( isset( $result['error'] ) ) {
			return new WP_Error(
				'rollback_failed',
				$result['error'],
				[ 'status' => 500 ]
			);
		}

		return rest_ensure_response(
			[
				'success'       => true,
				'posts_deleted' => $result['posts_deleted'],
				'terms_deleted' => $result['terms_deleted'],
			]
		);
	}
}
