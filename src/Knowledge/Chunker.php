<?php
/**
 * Text chunker.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Splits plain text into chunks of about SIZE characters, at paragraph, then
 * sentence, then word boundaries. Each chunk after the first starts with up
 * to OVERLAP characters from the end of the previous one, so a match that
 * spans a boundary is still found.
 */
final class Chunker {

	/**
	 * Target chunk size in characters.
	 */
	public const SIZE = 1200;

	/**
	 * Characters repeated from the previous chunk.
	 */
	public const OVERLAP = 150;

	/**
	 * Most chunks per item.
	 */
	public const MAX_CHUNKS = 50;

	/**
	 * Most characters per item (the rest is ignored).
	 */
	public const MAX_CHARS = 60000;

	/**
	 * Splits text.
	 *
	 * @param string $text Plain text; paragraphs separated by blank lines.
	 * @return string[]
	 */
	public static function split( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		if ( mb_strlen( $text ) > self::MAX_CHARS ) {
			$text = mb_substr( $text, 0, self::MAX_CHARS );
		}

		$chunks  = array();
		$current = '';

		foreach ( self::pieces( $text ) as $piece ) {
			$joined = '' === $current ? $piece : $current . "\n\n" . $piece;
			if ( '' !== $current && mb_strlen( $joined ) > self::SIZE ) {
				$chunks[] = $current;
				if ( count( $chunks ) >= self::MAX_CHUNKS ) {
					return $chunks;
				}
				$current = self::tail( $current ) . $piece;
				continue;
			}
			$current = $joined;
		}

		if ( '' !== trim( $current ) && count( $chunks ) < self::MAX_CHUNKS ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Paragraphs, with paragraphs longer than SIZE split into sentences and
	 * sentences longer than SIZE split at words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function pieces( string $text ): array {
		$pieces = array();
		$limit  = self::SIZE - self::OVERLAP;

		foreach ( self::split_by( '/\n\s*\n/u', $text ) as $paragraph ) {
			$paragraph = trim( $paragraph );
			if ( '' === $paragraph ) {
				continue;
			}
			if ( mb_strlen( $paragraph ) <= $limit ) {
				$pieces[] = $paragraph;
				continue;
			}

			$sentence_group = '';
			foreach ( self::split_by( '/(?<=[.!?。！？])\s+/u', $paragraph ) as $sentence ) {
				foreach ( self::words( $sentence, $limit ) as $part ) {
					$joined = '' === $sentence_group ? $part : $sentence_group . ' ' . $part;
					if ( '' !== $sentence_group && mb_strlen( $joined ) > $limit ) {
						$pieces[]       = $sentence_group;
						$sentence_group = $part;
					} else {
						$sentence_group = $joined;
					}
				}
			}
			if ( '' !== $sentence_group ) {
				$pieces[] = $sentence_group;
			}
		}

		return $pieces;
	}

	/**
	 * Splits with a pattern.
	 *
	 * @param string $pattern Pattern.
	 * @param string $text    Text.
	 * @return string[]
	 */
	private static function split_by( string $pattern, string $text ): array {
		$parts = preg_split( $pattern, $text );

		return false === $parts ? array( $text ) : $parts;
	}

	/**
	 * A sentence cut at word boundaries into parts of at most $limit characters.
	 *
	 * @param string $sentence Sentence.
	 * @param int    $limit    Maximum length.
	 * @return string[]
	 */
	private static function words( string $sentence, int $limit ): array {
		$parts = array();

		while ( mb_strlen( $sentence ) > $limit ) {
			$cut = mb_strrpos( mb_substr( $sentence, 0, $limit ), ' ' );
			$cut = false === $cut || $cut < $limit / 2 ? $limit : $cut;

			$parts[]  = trim( mb_substr( $sentence, 0, $cut ) );
			$sentence = trim( mb_substr( $sentence, $cut ) );
		}
		if ( '' !== $sentence ) {
			$parts[] = $sentence;
		}

		return $parts;
	}

	/**
	 * The last OVERLAP characters of a chunk, starting at a word, plus a space.
	 *
	 * @param string $chunk Chunk.
	 */
	private static function tail( string $chunk ): string {
		if ( mb_strlen( $chunk ) <= self::OVERLAP ) {
			return $chunk . ' ';
		}

		$tail  = mb_substr( $chunk, -self::OVERLAP );
		$space = mb_strpos( $tail, ' ' );

		return trim( false === $space ? $tail : mb_substr( $tail, $space + 1 ) ) . ' ';
	}
}
