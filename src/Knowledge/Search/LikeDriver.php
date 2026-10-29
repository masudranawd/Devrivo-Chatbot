<?php
/**
 * LIKE search driver (fallback without FULLTEXT).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Search;

use DevRivo\Chatbot\Knowledge\Index;

defined( 'ABSPATH' ) || exit;

/**
 * Any term may match. Terms under three characters and common English words
 * ("what", "are", "your", …) are ignored, so natural questions still find
 * pages. Rows are ranked by the number of distinct terms they contain; a term
 * in the title counts 3, a term only in the content counts 2.
 */
final class LikeDriver implements SearchDriverInterface {

	/**
	 * Common English words that never help a search.
	 */
	public const STOPWORDS = array(
		'about',
		'and',
		'any',
		'are',
		'can',
		'could',
		'did',
		'does',
		'for',
		'from',
		'get',
		'had',
		'has',
		'have',
		'her',
		'his',
		'how',
		'its',
		'not',
		'our',
		'ours',
		'she',
		'should',
		'that',
		'the',
		'their',
		'them',
		'then',
		'there',
		'these',
		'they',
		'this',
		'those',
		'was',
		'were',
		'what',
		'when',
		'where',
		'which',
		'who',
		'whom',
		'why',
		'will',
		'with',
		'would',
		'you',
		'your',
		'yours',
	);

	/**
	 * Matching rows, best first.
	 *
	 * @param string[] $terms      Search terms.
	 * @param string[] $post_types Only posts of these types; empty for everything.
	 * @param int      $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $terms, array $post_types, int $limit ): array {
		global $wpdb;

		$terms = self::useful( $terms );
		if ( array() === $terms ) {
			return array();
		}

		$score      = array();
		$score_args = array();
		$match      = array();
		$match_args = array();
		foreach ( $terms as $term ) {
			$like         = '%' . $wpdb->esc_like( $term ) . '%';
			$score[]      = '(CASE WHEN title LIKE %s THEN 3 WHEN content LIKE %s THEN 2 ELSE 0 END)';
			$score_args[] = $like;
			$score_args[] = $like;
			$match[]      = '(title LIKE %s OR content LIKE %s)';
			$match_args[] = $like;
			$match_args[] = $like;
		}

		$where = '(' . implode( ' OR ', $match ) . ')';
		$args  = array_merge( $score_args, array( Index::table() ), $match_args );
		if ( array() !== $post_types ) {
			$where .= ' AND source = %s AND post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$args   = array_merge( $args, array( 'post' ), $post_types );
		}
		$args[] = $limit;

		$select = implode( ' + ', $score );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $select and $where only add %s placeholders.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; visitor queries are never cached or stored.
			$wpdb->prepare(
				"SELECT id, source, object_id, source_key, title, content, ({$select}) AS score
				FROM %i WHERE {$where}
				ORDER BY score DESC, id ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Terms of three or more characters that aren't stopwords.
	 *
	 * @param string[] $terms Terms.
	 * @return string[]
	 */
	public static function useful( array $terms ): array {
		return array_values(
			array_filter(
				$terms,
				static fn( string $term ): bool => mb_strlen( $term ) >= 3 && ! in_array( $term, self::STOPWORDS, true )
			)
		);
	}
}
