<?php
/**
 * Fixed-window rate limiter.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Counts requests per client in transients.
 *
 * The client key is a keyed hash of REMOTE_ADDR, kept only in the transient
 * name for the window's length; IP addresses are never stored. Proxy headers
 * such as X-Forwarded-For are ignored because visitors can forge them.
 */
final class RateLimiter {

	/**
	 * Hashed key for the current client.
	 */
	public static function client_key(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
	}

	/**
	 * Records a hit.
	 *
	 * @param string $bucket Limit name (e.g. "form").
	 * @param string $client Client key.
	 * @param int    $max    Hits allowed per window.
	 * @param int    $window Window in seconds.
	 * @return int 0 when allowed, otherwise seconds until the client may try again.
	 */
	public static function hit( string $bucket, string $client, int $max, int $window ): int {
		$key  = 'devrivo_chatbot_rl_' . substr( hash( 'sha256', $bucket . '|' . $client ), 0, 32 );
		$now  = time();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || ! isset( $data['start'], $data['count'] ) || $now - (int) $data['start'] >= $window ) {
			$data = array(
				'start' => $now,
				'count' => 0,
			);
		}

		$remaining = max( 1, (int) $data['start'] + $window - $now );
		if ( (int) $data['count'] >= $max ) {
			return $remaining;
		}

		++$data['count'];
		set_transient( $key, $data, $remaining );

		return 0;
	}
}
