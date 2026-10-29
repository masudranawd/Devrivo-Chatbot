<?php
/**
 * Signed session tokens for public endpoints.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Security;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless tokens the widget fetches before it submits anything.
 *
 * Format: base64url( JSON { sid, iat, exp } ) . '.' . base64url( HMAC-SHA256 ).
 * The key is derived from wp_salt( 'nonce' ), so nothing is stored and page
 * caching doesn't break it (unlike WordPress nonces in cached HTML). Tokens
 * expire after two hours and are rejected when used less than three seconds
 * after they were issued (bots fill forms instantly).
 */
final class SessionToken {

	/**
	 * Lifetime in seconds.
	 */
	public const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Minimum seconds between issuing and using a token.
	 */
	public const MIN_AGE = 3;

	/**
	 * Allowed clock difference for tokens "from the future" (seconds).
	 */
	private const LEEWAY = 60;

	/**
	 * Issues a token.
	 *
	 * @param int|null $now Current time (tests).
	 * @return array{token: string, expires: int}
	 */
	public static function issue( ?int $now = null ): array {
		$now     = $now ?? time();
		$payload = array(
			'sid' => bin2hex( random_bytes( 16 ) ),
			'iat' => $now,
			'exp' => $now + self::TTL,
		);
		$body    = self::encode( (string) wp_json_encode( $payload ) );

		return array(
			'token'   => $body . '.' . self::sign( $body ),
			'expires' => $payload['exp'],
		);
	}

	/**
	 * Verifies a token.
	 *
	 * @param mixed    $token   Token from the request.
	 * @param int|null $now     Current time (tests).
	 * @param int      $min_age Minimum seconds since the token was issued (forms 3, chat 1).
	 * @return array{sid: string, iat: int, exp: int}|WP_Error Payload, or a 403 error.
	 */
	public static function verify( mixed $token, ?int $now = null, int $min_age = self::MIN_AGE ): array|WP_Error {
		$now   = $now ?? time();
		$parts = is_string( $token ) && strlen( $token ) <= 512 ? explode( '.', $token ) : array();

		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return self::invalid();
		}

		$payload = json_decode( (string) self::decode( $parts[0] ), true );
		if (
			! is_array( $payload )
			|| ! is_string( $payload['sid'] ?? null )
			|| ! is_int( $payload['iat'] ?? null )
			|| ! is_int( $payload['exp'] ?? null )
			|| $now > $payload['exp']
			|| $payload['iat'] > $now + self::LEEWAY
		) {
			return self::invalid();
		}

		if ( $now - $payload['iat'] < $min_age ) {
			return new WP_Error(
				'devrivo_chatbot_too_fast',
				__( 'Please take a moment before sending.', 'devrivo-chatbot' ),
				array( 'status' => 403 )
			);
		}

		return array(
			'sid' => $payload['sid'],
			'iat' => $payload['iat'],
			'exp' => $payload['exp'],
		);
	}

	/**
	 * HMAC of a token body.
	 *
	 * @param string $body Encoded payload.
	 */
	private static function sign( string $body ): string {
		$key = hash_hmac( 'sha256', 'devrivo-chatbot-session', wp_salt( 'nonce' ), true );

		return self::encode( hash_hmac( 'sha256', $body, $key, true ) );
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $data Bytes.
	 */
	private static function encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding, not obfuscation.
	}

	/**
	 * Decodes base64url.
	 *
	 * @param string $data Encoded data.
	 * @return string|false
	 */
	private static function decode( string $data ): string|false {
		return base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding, not obfuscation.
	}

	/**
	 * Error for a missing, tampered, malformed or expired token.
	 */
	private static function invalid(): WP_Error {
		return new WP_Error(
			'devrivo_chatbot_invalid_token',
			__( 'Your session has expired. Please try again.', 'devrivo-chatbot' ),
			array( 'status' => 403 )
		);
	}
}
