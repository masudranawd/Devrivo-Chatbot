<?php
/**
 * Checks AI output before it reaches visitors.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\AI;

use DevRivo\Chatbot\Core\Security\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Plain text only, links only to this site, length caps, and sources and
 * actions only from this request.
 */
final class OutputCheck {

	/**
	 * Most characters in a reply.
	 */
	public const MAX_TEXT = 1500;

	/**
	 * Most sources per reply.
	 */
	public const MAX_SOURCES = 3;

	/**
	 * Cleans reply text: no HTML, markdown links become their text, links to
	 * other sites are removed, length capped at a word boundary.
	 *
	 * @param string $text Model text.
	 */
	public static function text( string $text ): string {
		// Markdown links: keep the label, plus the URL only when it is on this site.
		$text = (string) preg_replace_callback(
			'/\[([^\]\n]{1,200})\]\(([^)\s]{1,2000})\)/u',
			static fn( array $m ): string => self::is_site( $m[2] ) ? $m[1] . ' (' . $m[2] . ')' : $m[1],
			$text
		);

		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$text = (string) preg_replace_callback(
			'#\b(?:https?://|www\.)[^\s<>"\'()]+#iu',
			static function ( array $m ): string {
				$url = rtrim( $m[0], '.,;:!?' );
				$end = substr( $m[0], strlen( $url ) );
				return self::is_site( $url ) ? $url . $end : $end;
			},
			$text
		);

		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/ *\n */u', "\n", $text );
		$text = trim( (string) preg_replace( "/\n{3,}/u", "\n\n", $text ) );

		if ( mb_strlen( $text ) > self::MAX_TEXT ) {
			$cut   = mb_substr( $text, 0, self::MAX_TEXT - 1 );
			$space = mb_strrpos( $cut, ' ' );
			$text  = rtrim( false !== $space && $space > self::MAX_TEXT / 2 ? mb_substr( $cut, 0, $space ) : $cut ) . '…';
		}

		return $text;
	}

	/**
	 * Sources: this site's http(s) URLs only, unique, at most MAX_SOURCES.
	 *
	 * @param array<int, mixed> $sources Candidate sources { title, url }.
	 * @return list<array{title: string, url: string}>
	 */
	public static function sources( array $sources ): array {
		$clean = array();
		foreach ( $sources as $source ) {
			$url = is_array( $source ) ? (string) ( $source['url'] ?? '' ) : '';
			if ( '' === $url || ! Gate::is_site_url( $url ) || isset( $clean[ $url ] ) ) {
				continue;
			}
			$clean[ $url ] = array(
				'title' => mb_substr( trim( wp_strip_all_tags( (string) ( $source['title'] ?? '' ) ) ), 0, 200 ),
				'url'   => esc_url_raw( $url ),
			);
			if ( count( $clean ) >= self::MAX_SOURCES ) {
				break;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Action IDs that are allowed, unique, at most ToolRegistry::MAX_ACTIONS.
	 *
	 * @param array<int, mixed> $ids     Candidate IDs.
	 * @param string[]          $allowed Public action IDs.
	 * @return list<string>
	 */
	public static function actions( array $ids, array $allowed ): array {
		$clean = array_values( array_unique( array_filter( $ids, static fn( $id ): bool => is_string( $id ) && in_array( $id, $allowed, true ) ) ) );

		return array_slice( $clean, 0, ToolRegistry::MAX_ACTIONS );
	}

	/**
	 * Whether a URL (or www. address) is on this site.
	 *
	 * @param string $url URL.
	 */
	private static function is_site( string $url ): bool {
		return Gate::is_site_url( str_starts_with( strtolower( $url ), 'www.' ) ? 'https://' . $url : $url );
	}
}
