<?php
/**
 * Post knowledge source.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge\Sources;

use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Knowledge\Chunker;
use DevRivo\Chatbot\Knowledge\Index;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Published posts and pages (and other public post types the admin picks).
 *
 * Text comes from post_content (shortcodes and tags removed; the_content
 * filters are never run because of their side effects) plus Elementor's
 * widget text for pages built with Elementor.
 */
final class PostSource implements SourceInterface {

	/**
	 * Elementor setting keys that hold visible text (besides *_text, *_title,
	 * *_content and *_description).
	 */
	private const ELEMENTOR_KEYS = array( 'title', 'editor', 'text', 'description', 'content', 'heading', 'caption', 'subtitle' );

	/**
	 * Parts of Elementor setting keys that never hold visible text.
	 */
	private const ELEMENTOR_SKIP = array( 'css', 'class', 'color', 'font', 'size', 'url', 'link', 'icon', 'image', 'html', 'id', 'align', 'width', 'height', 'animation' );

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
		return 'post';
	}

	/**
	 * Posts are indexed in batches by the rebuild, so nothing to do here.
	 */
	public function sync(): int {
		return 0;
	}

	/**
	 * Whether the post still qualifies.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $key       Unused.
	 */
	public function is_current( int $object_id, string $key ): bool {
		$post = get_post( $object_id );

		return $post instanceof WP_Post && $this->should_index( $post );
	}

	/**
	 * Result for a post that is still published at query time.
	 *
	 * @param array<string, mixed> $row Index row.
	 * @return array{type: string, title: string, url: string, excerpt: string|null}|null
	 */
	public function result( array $row ): ?array {
		$post = get_post( (int) ( $row['object_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}

		$url = get_permalink( $post );
		if ( '' === $url ) {
			return null;
		}

		return array(
			'type'    => 'page',
			'title'   => self::plain( $post->post_title ),
			'url'     => $url,
			'excerpt' => null,
		);
	}

	/**
	 * Post types to index: Settings → Knowledge, filtered, and only types
	 * that exist and are publicly viewable.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = (array) ( $this->settings->get( 'knowledge' )['post_types'] ?? array() );

		/**
		 * Filters the post types included in the knowledge index.
		 *
		 * Types that don't exist or aren't publicly viewable are always left out.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $post_types Post type slugs from Settings → Knowledge.
		 */
		$types = (array) apply_filters( 'devrivo_chatbot_index_post_types', $types );

		return array_values(
			array_unique(
				array_filter(
					$types,
					static fn( $type ): bool => post_type_exists( (string) $type ) && is_post_type_viewable( (string) $type )
				)
			)
		);
	}

	/**
	 * Whether a post belongs in the index: published, no password, an indexed
	 * post type, not excluded and not marked noindex by Yoast SEO or Rank Math.
	 *
	 * @param WP_Post $post Post.
	 */
	public function should_index( WP_Post $post ): bool {
		$exclude = array_map( 'intval', (array) ( $this->settings->get( 'knowledge' )['exclude_ids'] ?? array() ) );

		$index = 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, $this->post_types(), true )
			&& ! in_array( $post->ID, $exclude, true )
			&& ! self::noindex( $post->ID );

		/**
		 * Filters whether a single post is included in the knowledge index.
		 *
		 * @since 1.0.0
		 *
		 * @param bool    $index Whether the post is indexed.
		 * @param WP_Post $post  Post.
		 */
		return (bool) apply_filters( 'devrivo_chatbot_should_index_post', $index, $post );
	}

	/**
	 * Indexes one post, or removes it when it no longer qualifies (or no
	 * longer exists). Unchanged content is skipped.
	 *
	 * @param int $post_id Post ID.
	 * @return string "indexed", "unchanged" or "removed".
	 */
	public function index( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! $this->should_index( $post ) ) {
			$this->index->delete( $this->id(), $post_id, null );
			return 'removed';
		}

		$title = self::plain( $post->post_title );
		$text  = $this->extract( $post );
		$hash  = md5( implode( "\n", array( Chunker::SIZE, $post->post_type, $title, $text ) ) );

		if ( $this->index->hash( $this->id(), $post_id ) === $hash ) {
			return 'unchanged';
		}

		// A page with only a title is still findable by its title.
		$chunks = Chunker::split( $text );
		$chunks = array() === $chunks ? array( '' ) : $chunks;

		$this->index->replace(
			array(
				'source'     => $this->id(),
				'object_id'  => $post_id,
				'source_key' => '',
				'post_type'  => $post->post_type,
				'title'      => $title,
				'hash'       => $hash,
			),
			$chunks
		);

		/**
		 * Fires after a post was (re-)indexed.
		 *
		 * @since 1.0.0
		 *
		 * @param int $post_id     Post ID.
		 * @param int $chunk_count Number of chunks stored.
		 */
		do_action( 'devrivo_chatbot_post_indexed', $post_id, count( $chunks ) );

		return 'indexed';
	}

	/**
	 * Published posts of the indexed types, by ascending ID.
	 *
	 * @param int $after Only IDs above this.
	 * @param int $limit Maximum IDs.
	 * @return int[]
	 */
	public function batch( int $after, int $limit ): array {
		global $wpdb;

		$types = $this->post_types();
		if ( array() === $types ) {
			return array();
		}

		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in is only %s placeholders, one per type.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batching by ID; WP_Query can't filter "ID > n".
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_status = 'publish' AND ID > %d AND post_type IN ({$in}) ORDER BY ID ASC LIMIT %d",
				array_merge( array( $wpdb->posts, $after ), $types, array( $limit ) )
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Number of published posts of the indexed types.
	 */
	public function count(): int {
		$total = 0;
		foreach ( $this->post_types() as $type ) {
			$total += (int) ( wp_count_posts( $type )->publish ?? 0 );
		}

		return $total;
	}

	/**
	 * Plain text of a post: post_content plus Elementor widget text, filtered.
	 *
	 * @param WP_Post $post Post.
	 */
	public function extract( WP_Post $post ): string {
		$paragraphs = self::paragraphs( self::html_to_text( strip_shortcodes( $post->post_content ) ) );

		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			// Elementor also saves a plain copy in post_content; keep each paragraph once.
			$paragraphs = array_merge( self::elementor_paragraphs( get_post_meta( $post->ID, '_elementor_data', true ) ), $paragraphs );
		}

		$text = implode( "\n\n", array_values( array_unique( $paragraphs ) ) );

		/**
		 * Filters the plain text extracted from a post before it is chunked.
		 *
		 * Paragraphs are separated by blank lines.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $text Plain text.
		 * @param WP_Post $post Post.
		 */
		$text = apply_filters( 'devrivo_chatbot_post_content', $text, $post );

		return implode( "\n\n", self::paragraphs( (string) $text ) );
	}

	/**
	 * Whether Yoast SEO or Rank Math marks the post noindex.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function noindex( int $post_id ): bool {
		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			return true;
		}

		$rank_math = get_post_meta( $post_id, 'rank_math_robots', true );

		return is_array( $rank_math )
			? in_array( 'noindex', $rank_math, true )
			: str_contains( (string) $rank_math, 'noindex' );
	}

	/**
	 * Text from HTML: block-level ends become paragraph breaks, then tags,
	 * leftover shortcode-like tags and entities are removed.
	 *
	 * @param string $html HTML.
	 */
	private static function html_to_text( string $html ): string {
		$html = (string) preg_replace( '#<br\s*/?>|</(p|div|h[1-6]|li|dt|dd|blockquote|section|article|header|footer|tr|th|td|figcaption|pre|table|ul|ol)>#i', "$0\n\n", $html );
		$text = wp_strip_all_tags( $html );
		// Shortcodes of plugins that aren't active (strip_shortcodes() only knows registered ones).
		$text = (string) preg_replace( '#\[/?[a-z][a-z0-9_-]*(\s[^\]]*)?\]#', '', $text );

		return html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Non-empty paragraphs with whitespace normalised.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function paragraphs( string $text ): array {
		$text  = str_replace( array( "\r", "\u{00A0}" ), array( '', ' ' ), $text );
		$lines = array();

		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( (string) preg_replace( '/[ \t\f\v]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Visible text from Elementor data (JSON in _elementor_data).
	 *
	 * @param mixed $data Meta value: JSON string or decoded array.
	 * @return string[]
	 */
	private static function elementor_paragraphs( mixed $data ): array {
		if ( is_string( $data ) ) {
			$data = json_decode( $data, true );
		}
		if ( ! is_array( $data ) ) {
			return array();
		}

		$texts = array();
		self::walk_elements( $data, $texts );

		$paragraphs = array();
		foreach ( $texts as $text ) {
			$paragraphs = array_merge( $paragraphs, self::paragraphs( self::html_to_text( $text ) ) );
		}

		return $paragraphs;
	}

	/**
	 * Walks sections, columns and widgets.
	 *
	 * @param array<mixed> $elements Elements.
	 * @param string[]     $texts    Collected text.
	 */
	private static function walk_elements( array $elements, array &$texts ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( is_array( $element['settings'] ?? null ) ) {
				self::collect_settings( $element['settings'], $texts );
			}
			if ( is_array( $element['elements'] ?? null ) ) {
				self::walk_elements( $element['elements'], $texts );
			}
		}
	}

	/**
	 * Text values of a widget's settings, including repeater items (tabs,
	 * accordions, icon lists).
	 *
	 * @param array<mixed> $settings Settings.
	 * @param string[]     $texts    Collected text.
	 */
	private static function collect_settings( array $settings, array &$texts ): void {
		foreach ( $settings as $key => $value ) {
			$key = (string) $key;
			if ( str_starts_with( $key, '_' ) ) {
				continue; // Elementor's internal settings (_id, _css_classes, …).
			}

			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						self::collect_settings( $item, $texts );
					}
				}
				continue;
			}

			if ( is_string( $value ) && self::is_text_key( $key ) && self::is_text_value( $value ) ) {
				$texts[] = $value;
			}
		}
	}

	/**
	 * Whether a setting key holds visible text.
	 *
	 * @param string $key Key.
	 */
	private static function is_text_key( string $key ): bool {
		if ( in_array( $key, self::ELEMENTOR_KEYS, true ) ) {
			return true;
		}
		if ( ! preg_match( '/_(text|title|content|description)$/', $key ) ) {
			return false;
		}
		foreach ( self::ELEMENTOR_SKIP as $part ) {
			if ( preg_match( '/(^|_)' . $part . '(_|$)/', $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a value looks like text rather than a URL, number, colour or CSS.
	 *
	 * @param string $value Value.
	 */
	private static function is_text_value( string $value ): bool {
		$value = trim( $value );

		if ( '' === $value || is_numeric( $value )
			|| preg_match( '#^(https?:|//|www\.|mailto:|tel:|\#)#i', $value )
			|| preg_match( '/^-?[\d.]+\s*(px|em|rem|%|vh|vw|s|ms)?$/i', $value )
		) {
			return false;
		}

		// Single lowercase tokens are option values ("yes", "center", "default").
		return ! preg_match( '/^[a-z0-9_-]+$/', $value );
	}

	/**
	 * Plain single-line text (titles).
	 *
	 * @param string $text Text.
	 */
	private static function plain( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}
}
