<?php
/**
 * Rest Class: Demo Stats Endpoint.
 *
 * GET /sd/edi/v1/demo-stats?demo={slug}
 * Returns dry-run item counts parsed from the WXR file — no DB writes.
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
	Utils\XmlChunker
};

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class DemoStatsEndpoint
 *
 * @since 1.3.0
 */
class DemoStatsEndpoint extends Base {
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
			'/demo-stats',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'getStats' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'demo' => [
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission check — requires import capability.
	 *
	 * @return true|WP_Error
	 * @since 1.3.0
	 */
	public function permission() {
		if ( ! current_user_can( 'import' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'Sorry, you are not allowed to do that.', 'easy-demo-importer' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Return dry-run stats for the requested demo.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 * @since 1.3.0
	 */
	public function getStats( WP_REST_Request $request ): WP_REST_Response {
		$demo_slug = $request->get_param( 'demo' ) ?? '';

		$cache_key = 'sd_edi_stats_' . md5( $demo_slug );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$config = sd_edi()->getDemoConfig();

		if ( empty( $config ) ) {
			return new WP_REST_Response( [ 'error' => 'Demo config not available.' ], 500 );
		}

		$xml_path = $this->resolveXmlPath( $config, $demo_slug );

		if ( ! $xml_path || ! file_exists( $xml_path ) ) {
			return new WP_REST_Response( [ 'error' => 'XML file not found. Run download step first.' ], 404 );
		}

		$items = XmlChunker::getItems( $xml_path );

		$stats = [
			'total'       => count( $items ),
			'by_type'     => [],
			'attachments' => 0,
		];

		foreach ( $items as $item ) {
			$type = $item['post_type'];

			if ( 'attachment' === $type ) {
				$stats['attachments']++;
			}

			if ( ! isset( $stats['by_type'][ $type ] ) ) {
				$stats['by_type'][ $type ] = 0;
			}

			$stats['by_type'][ $type ]++;
		}

		set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

		return new WP_REST_Response( $stats, 200 );
	}

	/**
	 * Resolve the local XML file path for a demo slug.
	 *
	 * @param array  $config    Demo config array.
	 * @param string $demo_slug Demo slug, or empty for single-zip setup.
	 * @return string|null Absolute path, or null if unresolvable.
	 * @since 1.3.0
	 */
	private function resolveXmlPath( array $config, string $demo_slug ): ?string {
		$upload_dir = wp_get_upload_dir();
		$base       = $upload_dir['basedir'] . '/easy-demo-importer/';

		if ( ! empty( $config['multipleZip'] ) && $demo_slug ) {
			return $base . $demo_slug . '/content.xml';
		}

		return $base . 'content.xml';
	}
}
