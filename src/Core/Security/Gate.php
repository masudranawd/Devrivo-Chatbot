<?php
/**
 * Security gate for public write endpoints.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Security;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a public request before it is processed, cheapest checks first:
 * body size (413), same-site Origin/Referer (403), signed session token (403)
 * and the per-client rate limit (429 with retry_after in the error data).
 *
 * Shared by the form endpoint now and the chat endpoint in Step 7. Public
 * endpoints use this instead of WordPress nonces, which break on cached pages.
 */
final class Gate {

	/**
	 * Largest accepted request body in bytes.
	 */
	public const MAX_BODY = 20 * KB_IN_BYTES;

	/**
	 * Checks a request.
	 *
	 * @param WP_REST_Request      $request Request.
	 * @param array<string, mixed> $context Context: endpoint (e.g. "form") and endpoint details.
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request, array $context ): bool|WP_Error {
		if ( strlen( $request->get_body() ) > self::MAX_BODY ) {
			return new WP_Error(
				'devrivo_chatbot_too_large',
				__( 'The request is too large.', 'devrivo-chatbot' ),
				array( 'status' => 413 )
			);
		}

		if ( ! self::same_site( $request ) ) {
			return new WP_Error(
				'devrivo_chatbot_bad_origin',
				__( 'Requests must come from this website.', 'devrivo-chatbot' ),
				array( 'status' => 403 )
			);
		}

		$token = SessionToken::verify( $request->get_param( 'token' ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		/**
		 * Filters the rate limit for a public endpoint.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, int>   $limits  Requests allowed per client ('max') per window in seconds ('window').
		 * @param array<string, mixed> $context Context: endpoint ("form") and details such as form_id.
		 */
		$limits = (array) apply_filters(
			'devrivo_chatbot_rate_limit',
			array(
				'max'    => 5,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			$context
		);

		$retry = RateLimiter::hit(
			(string) ( $context['endpoint'] ?? 'default' ),
			RateLimiter::client_key(),
			max( 1, (int) ( $limits['max'] ?? 5 ) ),
			max( 1, (int) ( $limits['window'] ?? 600 ) )
		);
		if ( $retry > 0 ) {
			return new WP_Error(
				'devrivo_chatbot_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'devrivo-chatbot' ),
				array(
					'status'      => 429,
					'retry_after' => $retry,
				)
			);
		}

		return true;
	}

	/**
	 * Whether the Origin (or, without one, the Referer) is this site.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function same_site( WP_REST_Request $request ): bool {
		$source = (string) $request->get_header( 'origin' );
		if ( '' === $source || 'null' === $source ) {
			$source = (string) $request->get_header( 'referer' );
		}

		return self::is_site_url( $source );
	}

	/**
	 * Whether a URL is on this site's host (http or https).
	 *
	 * @param string $url URL.
	 */
	public static function is_site_url( string $url ): bool {
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$home   = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) && is_string( $home )
			&& in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true )
			&& strtolower( $host ) === strtolower( $home );
	}
}
