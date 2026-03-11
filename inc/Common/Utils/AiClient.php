<?php
/**
 * Utility: AiClient
 *
 * Sends HMAC-signed requests to the Cloudflare Worker AI proxy and extracts
 * typed responses. All AI feature sub-projects (B–F) consume this class.
 *
 * Feature flag: return false from `sd/edi/ai_enabled` to disable all AI calls.
 * Constants (defined in easy-demo-importer.php, override in wp-config.php):
 *   SD_EDI_AI_PROXY_URL      — Cloudflare Worker endpoint URL (default '').
 *   SD_EDI_AI_SHARED_SECRET  — HMAC secret; empty = AI disabled (default '').
 *
 * @package SigmaDevs\EasyDemoImporter
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace SigmaDevs\EasyDemoImporter\Common\Utils;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'This script cannot be accessed directly.' );
}

/**
 * Class AiClient
 *
 * @since 1.6.0
 */
class AiClient {

	/**
	 * Call Gemini Flash for text generation.
	 *
	 * Returns ['text' => '...'] on success, or WP_Error on failure.
	 * $options is accepted but reserved for future use (silently ignored in v1.6.0).
	 *
	 * @param string $prompt  Prompt text to send.
	 * @param array  $options Reserved for future use.
	 * @return array|WP_Error
	 * @since 1.6.0
	 */
	public static function generate( string $prompt, array $options = [] ): array|WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = [ 'prompt' => $prompt ];
		$result  = self::request( 'generate', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

		if ( '' === $text ) {
			return new WP_Error( 'ai_error', __( 'Empty response from Gemini.', 'easy-demo-importer' ) );
		}

		return [ 'text' => $text ];
	}

	/**
	 * Generate an embedding vector via text-embedding-004.
	 *
	 * Returns ['embedding' => [0.1, 0.2, ...]] on success, or WP_Error on failure.
	 *
	 * @param string $text Text to embed.
	 * @return array|WP_Error
	 * @since 1.6.0
	 */
	public static function embed( string $text ): array|WP_Error {
		$guard = self::guard();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$payload = [ 'text' => $text ];
		$result  = self::request( 'embed', $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$values = $result['embedding']['values'] ?? null;

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'ai_error', __( 'Empty embedding from Gemini.', 'easy-demo-importer' ) );
		}

		return [ 'embedding' => $values ];
	}

	/**
	 * Feature flag + secret guard.
	 *
	 * @return true|WP_Error
	 * @since 1.6.0
	 */
	private static function guard(): true|WP_Error {
		if ( ! apply_filters( 'sd/edi/ai_enabled', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return new WP_Error( 'ai_disabled', __( 'AI features are disabled.', 'easy-demo-importer' ) );
		}

		if ( SD_EDI_AI_SHARED_SECRET === '' ) {
			return new WP_Error( 'ai_misconfigured', __( 'AI proxy is not configured.', 'easy-demo-importer' ) );
		}

		if ( SD_EDI_AI_PROXY_URL === '' ) {
			return new WP_Error( 'ai_misconfigured', __( 'AI proxy URL is not configured.', 'easy-demo-importer' ) );
		}

		return true;
	}

	/**
	 * Sign and send a request to the Worker proxy.
	 *
	 * Payload invariant: all payloads must be flat (no nested objects). ksort() is
	 * sufficient because the Worker's canonicalJson() only sorts top-level keys for
	 * flat payloads, and both sides must produce identical canonical JSON.
	 *
	 * @param string $action  Named action: 'generate' or 'embed'.
	 * @param array  $payload Flat key-value payload (no nested objects).
	 * @return array|WP_Error Decoded `result` value from Worker response, or WP_Error.
	 * @since 1.6.0
	 */
	private static function request( string $action, array $payload ): array|WP_Error {
		$timestamp = time();

		ksort( $payload );
		$canonical = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $canonical ) {
			return new WP_Error( 'ai_invalid_request', __( 'Could not encode request payload.', 'easy-demo-importer' ) );
		}

		$message = "{$action}:{$timestamp}:{$canonical}";
		$sig     = hash_hmac( 'sha256', $message, SD_EDI_AI_SHARED_SECRET );

		$body = wp_json_encode(
			[
				'action'    => $action,
				'timestamp' => $timestamp,
				'payload'   => $payload,
				'sig'       => $sig,
			]
		);

		if ( false === $body ) {
			return new WP_Error( 'ai_invalid_request', __( 'Could not encode request body.', 'easy-demo-importer' ) );
		}

		$response = wp_remote_post(
			SD_EDI_AI_PROXY_URL,
			[
				'timeout' => 10,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => $body,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'ai_unavailable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			return self::mapHttpError( $status, $raw );
		}

		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'ai_invalid_response', __( 'Could not decode AI proxy response.', 'easy-demo-importer' ) );
		}

		return $decoded['result'] ?? [];
	}

	/**
	 * Map a non-200 HTTP status code to a WP_Error.
	 *
	 * Error code reference:
	 *   ai_invalid_request  — 400 (bad payload), 413 (body too large), 415 (wrong Content-Type)
	 *   ai_unavailable      — 401 (expired timestamp), 5xx (Worker/Gemini unavailable)
	 *   ai_auth_failed      — 403 (bad HMAC)
	 *   ai_error            — 502 (Gemini API returned an error)
	 *
	 * @param int    $status HTTP status code.
	 * @param string $raw    Raw response body (may contain Worker error JSON).
	 * @return WP_Error
	 * @since 1.6.0
	 */
	private static function mapHttpError( int $status, string $raw ): WP_Error {
		$worker_msg = '';
		$decoded    = json_decode( $raw, true );

		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			$worker_msg = (string) $decoded['error'];
		}

		switch ( $status ) {
			case 400:
			case 413:
			case 415:
				return new WP_Error( 'ai_invalid_request', $worker_msg ?: __( 'Invalid AI request.', 'easy-demo-importer' ) );
			case 401:
				return new WP_Error( 'ai_unavailable', $worker_msg ?: __( 'AI request expired.', 'easy-demo-importer' ) );
			case 403:
				return new WP_Error( 'ai_auth_failed', $worker_msg ?: __( 'AI authentication failed.', 'easy-demo-importer' ) );
			case 502:
				return new WP_Error( 'ai_error', $worker_msg ?: __( 'Gemini API error.', 'easy-demo-importer' ) );
			default:
				return new WP_Error( 'ai_unavailable', $worker_msg ?: __( 'AI proxy unavailable.', 'easy-demo-importer' ) );
		}
	}
}
