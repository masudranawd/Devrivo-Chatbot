<?php
/**
 * FAQ knowledge source.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Sources;

use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Knowledge\Index;

defined( 'ABSPATH' ) || exit;

/**
 * FAQs from Settings → Knowledge. Each FAQ is one row (source "faq",
 * source_key = FAQ ID). Re-indexed whenever the knowledge settings are saved.
 */
final class FaqSource implements SourceInterface {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 * @param Index    $index    Index.
	 */
	public function __construct( private Settings $settings, private Index $index ) {}

	/**
	 * Source ID.
	 */
	public function id(): string {
		return 'faq';
	}

	/**
	 * Replaces every FAQ row with the current FAQs.
	 *
	 * @return int FAQs indexed.
	 */
	public function sync(): int {
		$this->index->delete_source( $this->id() );

		$count = 0;
		foreach ( $this->faqs() as $id => $faq ) {
			$this->index->replace(
				array(
					'source'     => $this->id(),
					'object_id'  => 0,
					'source_key' => $id,
					'post_type'  => '',
					'title'      => $faq['question'],
					'hash'       => md5( $faq['question'] . "\n" . $faq['answer'] ),
				),
				array( $faq['answer'] )
			);
			++$count;
		}

		return $count;
	}

	/**
	 * Whether the FAQ still exists.
	 *
	 * @param int    $object_id Unused.
	 * @param string $key       FAQ ID.
	 */
	public function is_current( int $object_id, string $key ): bool {
		return isset( $this->faqs()[ $key ] );
	}

	/**
	 * The FAQ's current question and full answer.
	 *
	 * @param array<string, mixed> $row Index row.
	 * @return array{type: string, title: string, url: string, excerpt: string|null}|null
	 */
	public function result( array $row ): ?array {
		$faq = $this->faqs()[ (string) ( $row['source_key'] ?? '' ) ] ?? null;
		if ( null === $faq ) {
			return null;
		}

		return array(
			'type'    => 'faq',
			'title'   => $faq['question'],
			'url'     => '',
			'excerpt' => $faq['answer'],
		);
	}

	/**
	 * Current FAQs keyed by ID.
	 *
	 * @return array<string, array{question: string, answer: string}>
	 */
	private function faqs(): array {
		$faqs = array();
		foreach ( (array) ( $this->settings->get( 'knowledge' )['faqs'] ?? array() ) as $faq ) {
			if ( is_array( $faq ) && isset( $faq['id'], $faq['question'], $faq['answer'] ) ) {
				$faqs[ (string) $faq['id'] ] = array(
					'question' => (string) $faq['question'],
					'answer'   => (string) $faq['answer'],
				);
			}
		}

		return $faqs;
	}
}
