<?php
/**
 * Fixed-window rate limiter.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Security;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per client in transients.
 *
 * Client keys are keyed hashes (of the session ID or the IP address), kept only
 * in the transient name for the window's length; IP addresses are never stored.
 * Proxy headers such as X-Forwarded-For are ignored by default because visitors
 * can forge them; a site that trusts its proxy can opt in with the
 * devrivo_chatbot_client_ip filter.
 */
final class RateLimiter {

	/**
	 * The client's IP address: REMOTE_ADDR unless a site filters it.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function client_ip( WP_REST_Request $request ): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP address used for rate limiting.
		 *
		 * The default is REMOTE_ADDR. Forwarded headers are never trusted by
		 * default because anyone can send them. Only a site whose traffic
		 * always passes through a proxy it trusts should use one, e.g. behind
		 * Cloudflare (with the server firewalled to Cloudflare's IP ranges):
		 *
		 *     add_filter( 'devrivo_chatbot_client_ip', function ( $ip, $request ) {
		 *         $cf = $request->get_header( 'cf_connecting_ip' );
		 *         return $cf ? $cf : $ip;
		 *     }, 10, 2 );
		 *
		 * Values that are not valid IP addresses are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param string          $ip      Client IP address (REMOTE_ADDR).
		 * @param WP_REST_Request $request Request.
		 */
		$ip = filter_var( apply_filters( 'devrivo_chatbot_client_ip', $remote, $request ), FILTER_VALIDATE_IP );

		return false !== $ip ? $ip : $remote;
	}

	/**
	 * Hashed key for a client identifier (IP address or session ID).
	 *
	 * @param string $id Identifier.
	 */
	public static function key( string $id ): string {
		return hash_hmac( 'sha256', $id, wp_salt( 'nonce' ) );
	}

	/**
	 * Seconds until a client may try again, without recording a hit.
	 *
	 * @param string $bucket Limit name (e.g. "form:ip").
	 * @param string $client Client key.
	 * @param int    $max    Hits allowed per window.
	 * @param int    $window Window in seconds.
	 * @return int 0 when a hit is allowed.
	 */
	public static function retry_after( string $bucket, string $client, int $max, int $window ): int {
		$data = self::load( $bucket, $client, $window );

		return $data['count'] >= $max ? $data['remaining'] : 0;
	}

	/**
	 * Records a hit.
	 *
	 * @param string $bucket Limit name (e.g. "form:ip").
	 * @param string $client Client key.
	 * @param int    $max    Hits allowed per window.
	 * @param int    $window Window in seconds.
	 * @return int 0 when allowed, otherwise seconds until the client may try again.
	 */
	public static function hit( string $bucket, string $client, int $max, int $window ): int {
		$data = self::load( $bucket, $client, $window );
		if ( $data['count'] >= $max ) {
			return $data['remaining'];
		}

		set_transient(
			self::transient( $bucket, $client ),
			array(
				'start' => $data['start'],
				'count' => $data['count'] + 1,
			),
			$data['remaining']
		);

		return 0;
	}

	/**
	 * Current window for a client.
	 *
	 * @param string $bucket Limit name.
	 * @param string $client Client key.
	 * @param int    $window Window in seconds.
	 * @return array{start: int, count: int, remaining: int}
	 */
	private static function load( string $bucket, string $client, int $window ): array {
		$now  = time();
		$data = get_transient( self::transient( $bucket, $client ) );

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || $now - (int) $data['start'] >= $window ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}

		return array(
			'start'     => (int) $data['start'],
			'count'     => (int) $data['count'],
			'remaining' => max( 1, (int) $data['start'] + $window - $now ),
		);
	}

	/**
	 * Transient name for a bucket and client.
	 *
	 * @param string $bucket Limit name.
	 * @param string $client Client key.
	 */
	private static function transient( string $bucket, string $client ): string {
		return 'devrivo_chatbot_rl_' . substr( hash( 'sha256', $bucket . '|' . $client ), 0, 32 );
	}
}
