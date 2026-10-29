<?php
/**
 * Knowledge search.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

use DevRivo\Chatbot\Knowledge\Search\FulltextDriver;
use DevRivo\Chatbot\Knowledge\Search\LikeDriver;
use DevRivo\Chatbot\Knowledge\Search\SearchDriverInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a visitor's question into search terms, runs the index driver and
 * builds public results: the best chunk per item, items still published at
 * query time, at most MAX_RESULTS.
 *
 * Queries are never stored, cached or logged.
 */
final class Search {

	/**
	 * Most results returned.
	 */
	public const MAX_RESULTS = 5;

	/**
	 * Query length limits (characters).
	 */
	public const MIN_LENGTH = 2;
	public const MAX_LENGTH = 100;

	/**
	 * Excerpt length (characters).
	 */
	private const EXCERPT = 200;

	/**
	 * Rows fetched before grouping by item.
	 */
	private const CANDIDATES = 50;

	/**
	 * Constructor.
	 *
	 * @param Knowledge                  $knowledge Knowledge.
	 * @param SearchDriverInterface|null $driver    Driver (tests); default from the stored driver option.
	 */
	public function __construct( private Knowledge $knowledge, private ?SearchDriverInterface $driver = null ) {}

	/**
	 * Searches the index.
	 *
	 * @param string   $query      Visitor's question.
	 * @param string[] $post_types Only posts of these indexed types (no FAQs); empty for everything.
	 * @return array<int, array{type: string, title: string, url: string, excerpt: string, score: float}>
	 */
	public function run( string $query, array $post_types = array() ): array {
		$terms = self::terms( $query );
		$rows  = array() === $terms ? array() : $this->rows( $terms, $post_types );

		$sources = $this->knowledge->sources();
		$seen    = array();
		$results = array();

		foreach ( $rows as $row ) {
			// Rows come best first, so the first row per item is its best chunk.
			$item = $row['source'] . ':' . $row['object_id'] . ':' . $row['source_key'];
			if ( isset( $seen[ $item ] ) ) {
				continue;
			}
			$seen[ $item ] = true;

			$source = $sources[ (string) $row['source'] ] ?? null;
			$public = null === $source ? null : $source->result( $row );
			if ( null === $public ) {
				continue;
			}

			$results[] = array(
				'type'    => $public['type'],
				'title'   => $public['title'],
				'url'     => $public['url'],
				'excerpt' => $public['excerpt'] ?? self::excerpt( (string) $row['content'], $terms ),
				'score'   => round( (float) $row['score'], 4 ),
			);
			if ( count( $results ) >= self::MAX_RESULTS ) {
				break;
			}
		}

		/**
		 * Filters the results of a knowledge search.
		 *
		 * The response is public (sent to any visitor): never add personal or
		 * private data.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, array<string, mixed>> $results Results: type (page|faq), title, url, excerpt, score.
		 * @param string                           $query   Visitor's question (never stored).
		 */
		return array_values( (array) apply_filters( 'devrivo_chatbot_search_results', $results, $query ) );
	}

	/**
	 * Search terms: lowercase words of letters and digits, 2+ characters,
	 * unique, at most 10. Everything else (operators, punctuation) is dropped.
	 *
	 * @param string $query Query.
	 * @return string[]
	 */
	public static function terms( string $query ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $query ), -1, PREG_SPLIT_NO_EMPTY );
		$terms = array();

		foreach ( false === $words ? array() : $words as $word ) {
			if ( mb_strlen( $word ) >= 2 && ! in_array( $word, $terms, true ) ) {
				$terms[] = $word;
			}
		}

		return array_slice( $terms, 0, 10 );
	}

	/**
	 * About EXCERPT characters of plain text around the first match.
	 *
	 * @param string   $content Chunk.
	 * @param string[] $terms   Terms.
	 */
	public static function excerpt( string $content, array $terms ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $content ) );
		if ( mb_strlen( $text ) <= self::EXCERPT ) {
			return $text;
		}

		$first = null;
		foreach ( $terms as $term ) {
			$position = mb_stripos( $text, $term );
			if ( false !== $position && ( null === $first || $position < $first ) ) {
				$first = $position;
			}
		}

		$start = max( 0, (int) $first - 60 );
		if ( $start > 0 ) {
			$space = mb_strpos( $text, ' ', $start );
			$start = false === $space || $space > (int) $first ? $start : $space + 1;
		}
		$start = min( $start, max( 0, mb_strlen( $text ) - self::EXCERPT ) );

		$excerpt = mb_substr( $text, $start, self::EXCERPT );
		$cut     = $start + self::EXCERPT < mb_strlen( $text );
		if ( $cut ) {
			$space   = mb_strrpos( $excerpt, ' ' );
			$excerpt = false === $space ? $excerpt : mb_substr( $excerpt, 0, $space );
		}

		return ( $start > 0 ? '…' : '' ) . trim( $excerpt ) . ( $cut ? '…' : '' );
	}

	/**
	 * Matching rows from the driver; FULLTEXT falls back to LIKE when it
	 * can't run the search.
	 *
	 * @param string[] $terms      Terms.
	 * @param string[] $post_types Post types.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $terms, array $post_types ): array {
		$driver = $this->driver ?? ( 'fulltext' === Index::driver() ? new FulltextDriver() : new LikeDriver() );
		$rows   = $driver->search( $terms, $post_types, self::CANDIDATES );

		if ( null === $rows && ! $driver instanceof LikeDriver ) {
			$rows = ( new LikeDriver() )->search( $terms, $post_types, self::CANDIDATES );
		}

		return $rows ?? array();
	}
}
