<?php
/**
 * Keeps the index in sync with content changes.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Knowledge;

use DevRivo\Chatbot\Core\Settings\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Post changes schedule one deduplicated cron event per post, about ten
 * seconds later, so meta saved after save_post (Elementor) is included.
 * Saving the knowledge settings re-indexes FAQs right away, and starts a full
 * rebuild when the post types or excluded IDs change.
 */
final class Indexer {

	/**
	 * Cron hook that indexes one post (argument: post ID).
	 */
	public const HOOK = 'devrivo_chatbot_index_post';

	/**
	 * Seconds between a change and indexing it.
	 */
	public const DELAY = 10;

	/**
	 * Constructor.
	 *
	 * @param Settings  $settings  Settings repository.
	 * @param Knowledge $knowledge Knowledge.
	 */
	public function __construct( private Settings $settings, private Knowledge $knowledge ) {}

	/**
	 * Hooks content and settings changes.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'trashed_post', array( $this, 'on_removed' ) );
		add_action( 'deleted_post', array( $this, 'on_removed' ) );
		add_action( self::HOOK, array( $this, 'index_post' ) );
		add_action( 'add_option_' . Settings::OPTION, array( $this, 'on_settings_added' ), 10, 2 );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'on_settings_saved' ), 10, 2 );
	}

	/**
	 * After a post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save( int $post_id, WP_Post $post ): void {
		$this->maybe_schedule( $post );
	}

	/**
	 * When a post's status changes (publish, unpublish, schedule, …).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( $new_status !== $old_status ) {
			$this->maybe_schedule( $post );
		}
	}

	/**
	 * After a post is trashed or deleted: remove it if it is indexed.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_removed( int $post_id ): void {
		if ( null !== $this->knowledge->index()->hash( 'post', $post_id ) ) {
			self::schedule( $post_id );
		}
	}

	/**
	 * Cron: indexes (or removes) one post.
	 *
	 * @param int|string $post_id Post ID.
	 */
	public function index_post( int|string $post_id ): void {
		$this->knowledge->posts()->index( (int) $post_id );
	}

	/**
	 * Settings stored for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 */
	public function on_settings_added( string $option, mixed $value ): void {
		$this->on_settings_saved( array(), $value );
	}

	/**
	 * Settings saved: re-index FAQs when they changed, and rebuild when the
	 * indexed post types or excluded IDs changed.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public function on_settings_saved( mixed $old_value, mixed $value ): void {
		$old = is_array( $old_value ) && is_array( $old_value['knowledge'] ?? null ) ? $old_value['knowledge'] : array();
		$new = is_array( $value ) && is_array( $value['knowledge'] ?? null ) ? $value['knowledge'] : array();

		if ( $old === $new ) {
			return;
		}

		// The repository may still hold the values from before this save.
		$this->settings->flush();

		if ( ( $old['faqs'] ?? array() ) !== ( $new['faqs'] ?? array() ) ) {
			$this->knowledge->faqs()->sync();
		}

		if ( ( $old['post_types'] ?? null ) !== ( $new['post_types'] ?? null ) || ( $old['exclude_ids'] ?? null ) !== ( $new['exclude_ids'] ?? null ) ) {
			Rebuild::start();
		}
	}

	/**
	 * Schedules indexing for one post (once, however often it is saved).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function schedule( int $post_id ): void {
		if ( false === wp_next_scheduled( self::HOOK, array( $post_id ) ) ) {
			wp_schedule_single_event( time() + self::DELAY, self::HOOK, array( $post_id ) );
		}
	}

	/**
	 * Schedules a post of an indexed type, or one that is already indexed.
	 * Revisions and autosaves are ignored.
	 *
	 * @param WP_Post $post Post.
	 */
	private function maybe_schedule( WP_Post $post ): void {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		if ( in_array( $post->post_type, $this->knowledge->posts()->post_types(), true ) || null !== $this->knowledge->index()->hash( 'post', $post->ID ) ) {
			self::schedule( $post->ID );
		}
	}
}
