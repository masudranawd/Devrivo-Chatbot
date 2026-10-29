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
 * and the per-session and per-IP rate limits (429 with retry_after in the
 * error data).
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
	 * @param array<string, mixed> $context Context: endpoint ("form", "chat"), endpoint details, and
	 *                                      min_age (seconds since the token was issued; default 3).
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

		$token = SessionToken::verify( $request->get_param( 'token' ), null, (int) ( $context['min_age'] ?? SessionToken::MIN_AGE ) );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$limits = self::limits( $context );

		$endpoint = (string) ( $context['endpoint'] ?? 'default' );
		// Chat has its own, higher limits; forms use "session" and "ip".
		$prefix  = 'chat' === $endpoint ? 'chat_' : '';
		$clients = array(
			'session' => $token['sid'],
			'ip'      => RateLimiter::client_ip( $request ),
		);
		$checks  = array();
		foreach ( $clients as $name => $id ) {
			$checks[] = array(
				$endpoint . ':' . $name,
				RateLimiter::key( $id ),
				$limits[ $prefix . $name ]['max'],
				$limits[ $prefix . $name ]['window'],
			);
		}

		// Check both first, so a request blocked by one limit doesn't use up the other.
		$retry = 0;
		foreach ( $checks as $check ) {
			$retry = max( $retry, RateLimiter::retry_after( ...$check ) );
		}
		if ( 0 === $retry ) {
			foreach ( $checks as $check ) {
				$retry = max( $retry, RateLimiter::hit( ...$check ) );
			}
		}
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
	 * Rate limits for a public endpoint, filtered and cleaned.
	 *
	 * @param array<string, mixed> $context Context: endpoint ("form", "search", "chat") and endpoint details.
	 * @return array<string, array{max: int, window: int}> session, ip, search, chat_session and chat_ip limits.
	 */
	public static function limits( array $context ): array {
		$defaults = array(
			'session'      => array(
				'max'    => 5,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			'ip'           => array(
				'max'    => 30,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			'search'       => array(
				'max'    => 60,
				'window' => MINUTE_IN_SECONDS,
			),
			'chat_session' => array(
				'max'    => 20,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
			'chat_ip'      => array(
				'max'    => 60,
				'window' => 10 * MINUTE_IN_SECONDS,
			),
		);

		/**
		 * Filters the rate limits for public endpoints.
		 *
		 * Form submits must pass both 'session' (per session token, so visitors
		 * sharing an IP address — offices, mobile networks, sites behind a
		 * proxy — don't block each other) and the looser 'ip' (per client IP,
		 * which also catches clients that fetch a new token for every request).
		 * Search uses 'search', per client IP. Chat messages must pass both
		 * 'chat_session' and 'chat_ip'.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array<string, int>> $limits  'session', 'ip', 'search', 'chat_session' and 'chat_ip', each with 'max' requests per 'window' seconds.
		 * @param array<string, mixed>              $context Context: endpoint ("form", "search", "chat") and details such as form_id.
		 */
		$filtered = (array) apply_filters( 'devrivo_chatbot_rate_limit', $defaults, $context );

		$limits = array();
		foreach ( $defaults as $name => $default ) {
			$limit           = (array) ( $filtered[ $name ] ?? array() ) + $default;
			$limits[ $name ] = array(
				'max'    => max( 1, (int) $limit['max'] ),
				'window' => max( 1, (int) $limit['window'] ),
			);
		}

		return $limits;
	}

	/**
	 * The page a visitor was on: only this site's URLs, without query string
	 * or fragment (they can contain personal data).
	 *
	 * @param mixed $url Submitted URL.
	 */
	public static function page_url( mixed $url ): string {
		if ( ! is_string( $url ) || strlen( $url ) > 2000 || ! self::is_site_url( $url ) ) {
			return '';
		}

		return esc_url_raw( (string) preg_replace( '/[?#].*$/s', '', $url ) );
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
	 * Hosts are compared case-insensitively and without a leading "www.", and
	 * both the home and the WordPress address count as this site.
	 *
	 * @param string $url URL.
	 */
	public static function is_site_url( string $url ): bool {
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( ! is_string( $host ) || ! in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$own = array();
		foreach ( array( home_url(), site_url() ) as $site ) {
			$site_host = wp_parse_url( $site, PHP_URL_HOST );
			if ( is_string( $site_host ) ) {
				$own[] = self::bare_host( $site_host );
			}
		}

		return in_array( self::bare_host( $host ), $own, true );
	}

	/**
	 * Lower-case host without a leading "www.".
	 *
	 * @param string $host Host.
	 */
	private static function bare_host( string $host ): string {
		$host = strtolower( $host );

		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}
}
