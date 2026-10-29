<?php
/**
 * Knowledge sources registry.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Knowledge\Sources\FaqSource;
use DevRivo\Chatbot\Knowledge\Sources\PostSource;
use DevRivo\Chatbot\Knowledge\Sources\SourceInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the knowledge index, its sources and the search.
 */
final class Knowledge {

	/**
	 * Index.
	 *
	 * @var Index
	 */
	private Index $index;

	/**
	 * Post source.
	 *
	 * @var PostSource
	 */
	private PostSource $posts;

	/**
	 * FAQ source.
	 *
	 * @var FaqSource
	 */
	private FaqSource $faqs;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings repository.
	 */
	public function __construct( private Settings $settings ) {
		$this->index = new Index();
		$this->posts = new PostSource( $settings, $this->index );
		$this->faqs  = new FaqSource( $settings, $this->index );
	}

	/**
	 * Hooks indexing and the rebuild.
	 */
	public function register(): void {
		( new Indexer( $this->settings, $this ) )->register();
		( new Rebuild( $this ) )->register();
	}

	/**
	 * Index.
	 */
	public function index(): Index {
		return $this->index;
	}

	/**
	 * Post source.
	 */
	public function posts(): PostSource {
		return $this->posts;
	}

	/**
	 * FAQ source.
	 */
	public function faqs(): FaqSource {
		return $this->faqs;
	}

	/**
	 * Search over the index.
	 */
	public function search(): Search {
		return new Search( $this );
	}

	/**
	 * Sources keyed by ID.
	 *
	 * @return array<string, SourceInterface>
	 */
	public function sources(): array {
		/**
		 * Filters the knowledge sources.
		 *
		 * Each source implements DevRivo\Chatbot\Knowledge\Sources\SourceInterface
		 * and writes its own rows to the index. Entries that don't implement it
		 * are ignored.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, mixed> $sources SourceInterface objects (built in: posts and FAQs).
		 */
		$sources = (array) apply_filters( 'devrivo_chatbot_knowledge_sources', array( $this->posts, $this->faqs ) );

		$valid = array();
		foreach ( $sources as $source ) {
			if ( $source instanceof SourceInterface && preg_match( '/^[a-z_]{1,20}$/', $source->id() ) ) {
				$valid[ $source->id() ] = $source;
			}
		}

		return $valid;
	}
}
