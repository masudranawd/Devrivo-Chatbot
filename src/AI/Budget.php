<?php
/**
 * Monthly AI token budget.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Counts AI tokens and chat requests per calendar month (site timezone) in a
 * small option that is not autoloaded. Only numbers are stored: never
 * messages or replies.
 */
final class Budget {

	/**
	 * Usage option.
	 */
	public const OPTION = 'devrivo_chatbot_ai_usage';

	/**
	 * Usage this month (zero after the month changed).
	 *
	 * @return array{month: string, tokens: int, requests: int}
	 */
	public static function usage(): array {
		$stored = get_option( self::OPTION, array() );
		$month  = self::month();

		if ( ! is_array( $stored ) || ( $stored['month'] ?? '' ) !== $month ) {
			return array(
				'month'    => $month,
				'tokens'   => 0,
				'requests' => 0,
			);
		}

		return array(
			'month'    => $month,
			'tokens'   => max( 0, (int) ( $stored['tokens'] ?? 0 ) ),
			'requests' => max( 0, (int) ( $stored['requests'] ?? 0 ) ),
		);
	}

	/**
	 * Whether there are tokens left this month.
	 *
	 * @param int $limit Monthly token limit (0 = AI off).
	 */
	public static function can_spend( int $limit ): bool {
		return $limit > 0 && self::usage()['tokens'] < $limit;
	}

	/**
	 * Adds one chat request's tokens.
	 *
	 * @param int $tokens Total tokens of all rounds of the request.
	 */
	public static function record( int $tokens ): void {
		$usage = self::usage();

		$usage['tokens'] += max( 0, $tokens );
		++$usage['requests'];

		update_option( self::OPTION, $usage, false );
	}

	/**
	 * Current month in the site timezone (Y-m).
	 */
	public static function month(): string {
		return wp_date( 'Y-m' );
	}
}
