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
 * Every term must appear in the title or content. Rows are ranked by matches,
 * with title matches counting three times.
 */
final class LikeDriver implements SearchDriverInterface {

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

		if ( array() === $terms ) {
			return array();
		}

		$score      = array();
		$score_args = array();
		$match      = array();
		$match_args = array();
		foreach ( $terms as $term ) {
			$like         = '%' . $wpdb->esc_like( $term ) . '%';
			$score[]      = '(title LIKE %s) * 3 + (content LIKE %s)';
			$score_args[] = $like;
			$score_args[] = $like;
			$match[]      = '(title LIKE %s OR content LIKE %s)';
			$match_args[] = $like;
			$match_args[] = $like;
		}

		$where = implode( ' AND ', $match );
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
}
