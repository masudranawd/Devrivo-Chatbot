<?php
/**
 * MySQL FULLTEXT search driver.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Search;

use DevRivo\Chatbot\Knowledge\Index;

defined( 'ABSPATH' ) || exit;

/**
 * MATCH … AGAINST in BOOLEAN MODE. Terms are already reduced to letters and
 * digits, so they can't carry boolean operators; terms of three or more
 * characters get a "*" so "dentist" also finds "dentists". Any term may match
 * and rows are ranked by relevance.
 */
final class FulltextDriver implements SearchDriverInterface {

	/**
	 * Matching rows, best first.
	 *
	 * @param string[] $terms      Search terms.
	 * @param string[] $post_types Only posts of these types; empty for everything.
	 * @param int      $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>|null Null when no term is long enough for FULLTEXT or the query failed.
	 */
	public function search( array $terms, array $post_types, int $limit ): ?array {
		global $wpdb;

		$words = array();
		foreach ( $terms as $term ) {
			if ( mb_strlen( $term ) >= 3 ) {
				$words[] = $term . '*';
			}
		}
		if ( array() === $words ) {
			return null;
		}

		$against = implode( ' ', $words );
		$where   = '';
		$args    = array( $against, Index::table(), $against );
		if ( array() !== $post_types ) {
			$where = ' AND source = %s AND post_type IN (' . implode( ', ', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
			$args  = array_merge( $args, array( 'post' ), $post_types );
		}
		$args[] = $limit;

		$suppress = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where only adds %s placeholders.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; visitor queries are never cached or stored.
			$wpdb->prepare(
				"SELECT id, source, object_id, source_key, title, content, MATCH (title, content) AGAINST (%s IN BOOLEAN MODE) AS score
				FROM %i WHERE MATCH (title, content) AGAINST (%s IN BOOLEAN MODE){$where}
				ORDER BY score DESC, id ASC LIMIT %d",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable
		$failed = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		return $failed || ! is_array( $rows ) ? null : $rows;
	}
}
