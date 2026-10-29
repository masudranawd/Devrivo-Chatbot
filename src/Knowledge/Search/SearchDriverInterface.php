<?php
/**
 * Search driver contract.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Finds matching chunks in the index table.
 */
interface SearchDriverInterface {

	/**
	 * Matching rows, best first.
	 *
	 * @param string[] $terms      Search terms: lowercase letters and digits only, 2+ characters.
	 * @param string[] $post_types Only posts of these types (no FAQs); empty for everything.
	 * @param int      $limit      Maximum rows.
	 * @return array<int, array<string, mixed>>|null Rows (id, source, object_id, source_key, title, content, score),
	 *                                               or null when the driver can't run this search.
	 */
	public function search( array $terms, array $post_types, int $limit ): ?array;
}
