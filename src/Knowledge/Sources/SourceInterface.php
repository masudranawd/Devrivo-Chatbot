<?php
/**
 * Knowledge source contract.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * A kind of content in the knowledge index (posts, FAQs, …).
 *
 * Rows in the index carry the source's id() in their "source" column. Sources
 * write their rows with Index::replace() and decide at search time whether a
 * matched row may still be shown.
 */
interface SourceInterface {

	/**
	 * Source ID stored in the index (lowercase letters, max 20 characters).
	 */
	public function id(): string;

	/**
	 * Re-indexes all of this source's content. Called at the end of a full
	 * rebuild. Sources indexed in batches by the rebuild itself (posts) do
	 * nothing here.
	 *
	 * @return int Items indexed.
	 */
	public function sync(): int;

	/**
	 * Whether an indexed item still belongs in the index (stale cleanup).
	 *
	 * @param int    $object_id Object ID.
	 * @param string $key       Source key.
	 */
	public function is_current( int $object_id, string $key ): bool;

	/**
	 * Public search result for a matched row, or null to leave it out.
	 *
	 * @param array<string, mixed> $row Index row (source, object_id, source_key, title, content).
	 * @return array{type: string, title: string, url: string, excerpt: string|null}|null
	 *         excerpt null: built from the matched chunk.
	 */
	public function result( array $row ): ?array;
}
