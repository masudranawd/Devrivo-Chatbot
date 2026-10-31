<?php
/**
 * Knowledge index tests: migration, extraction, chunking, rules, indexing,
 * rebuild, FAQs and uninstall.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Activator;
use DevRivo\Chatbot\Core\Deactivator;
use DevRivo\Chatbot\Core\Migrations;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Uninstaller;
use DevRivo\Chatbot\Knowledge\Chunker;
use DevRivo\Chatbot\Knowledge\Index;
use DevRivo\Chatbot\Knowledge\Indexer;
use DevRivo\Chatbot\Knowledge\Knowledge;
use DevRivo\Chatbot\Knowledge\Rebuild;
use DevRivo\Chatbot\Tests\Support\RestoresCapabilities;
use WP_UnitTestCase;

final class KnowledgeIndexTest extends WP_UnitTestCase {

	use RestoresCapabilities;

	private Knowledge $knowledge;

	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION );
		Plugin::instance()->settings()->flush();
		$this->knowledge = Plugin::instance()->knowledge();
	}

	public function tear_down(): void {
		$this->restore_capabilities();
		wp_unschedule_hook( Indexer::HOOK );
		wp_unschedule_hook( Rebuild::HOOK );
		Plugin::instance()->settings()->flush();

		parent::tear_down();
	}

	public function test_migration_creates_the_table_detects_fulltext_and_starts_a_rebuild(): void {
		delete_option( Rebuild::OPTION );
		delete_option( Index::DRIVER_OPTION );

		Migrations::v4_knowledge_index();

		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'id', 'source', 'object_id', 'source_key', 'chunk', 'post_type', 'title', 'content', 'content_hash', 'updated_at' ), $columns );
		$keys = array_unique( $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', Index::table() ), 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertEqualsCanonicalizing( array( 'PRIMARY', 'source_object', Index::FULLTEXT_KEY ), $keys );

		$this->assertSame( 'fulltext', Index::driver() );
		$this->assertFalse( wp_load_alloptions()[ Index::DRIVER_OPTION ] ?? false, 'Driver option is not autoloaded.' );
		$this->assertSame( 'running', Rebuild::state()['status'] );
		$this->assertNotFalse( wp_next_scheduled( Rebuild::HOOK ) );
		$this->assertSame( 4, DEVRIVO_CHATBOT_SCHEMA_VERSION );
	}

	public function test_like_fallback_when_fulltext_is_unavailable(): void {
		// Simulate a database without FULLTEXT: the ALTER fails and SHOW INDEX finds nothing.
		$no_fulltext = static function ( string $query ): string {
			if ( str_contains( $query, 'ADD FULLTEXT' ) ) {
				return 'SELECT devrivo_no_such_column FROM DUAL';
			}
			if ( str_starts_with( $query, 'SHOW INDEX' ) ) {
				return str_replace( Index::FULLTEXT_KEY, 'devrivo_missing_key', $query );
			}
			return $query;
		};
		add_filter( 'query', $no_fulltext );

		$driver = Index::add_fulltext();
		remove_filter( 'query', $no_fulltext );

		$this->assertSame( 'like', $driver );
		$this->assertSame( 'like', Index::driver() );
		$this->assertTrue( Index::has_fulltext(), 'The real table is untouched.' );
	}

	public function test_extracts_text_from_blocks_and_shortcodes_without_the_content_filters(): void {
		add_shortcode( 'devrivo_test_sc', static fn(): string => 'SHORTCODE OUTPUT' );
		$filtered = 0;
		$counter  = static function ( string $content ) use ( &$filtered ): string {
			++$filtered;
			return $content;
		};
		add_filter( 'the_content', $counter );

		// Set on the object: saving would run kses, which removes <script> tags.
		$post               = self::factory()->post->create_and_get();
		$post->post_content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Opening&nbsp;hours</h2>\n<!-- /wp:heading -->\n\n"
					. "<!-- wp:paragraph -->\n<p>We are open <strong>Monday</strong> to Friday.<br>Closed on bank holidays.</p>\n<!-- /wp:paragraph -->\n\n"
					. "<!-- wp:list -->\n<ul><li>Free   parking</li><li>Step-free &amp; easy access</li></ul>\n<!-- /wp:list -->\n"
			. '[devrivo_test_sc] [inactive_plugin id="3"]Kept text[/inactive_plugin]<script>alert(1)</script><style>p{color:red}</style>';

		$text = $this->knowledge->posts()->extract( $post );
		remove_filter( 'the_content', $counter );
		remove_shortcode( 'devrivo_test_sc' );

		$this->assertSame(
			"Opening hours\n\nWe are open Monday to Friday.\n\nClosed on bank holidays.\n\nFree parking\n\nStep-free & easy access\n\nKept text",
			$text
		);
		$this->assertSame( 0, $filtered, 'the_content filters never run.' );
	}

	public function test_extracts_elementor_widget_text(): void {
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_content' => '<p>Gentle family dentistry</p><p>Plain fallback copy.</p>',
			)
		);
		update_post_meta( $post->ID, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post->ID, '_elementor_data', wp_slash( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/elementor-data.json' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$text = $this->knowledge->posts()->extract( $post );

		foreach ( array( 'Gentle family dentistry', 'We look after children and adults in a calm, friendly practice.', 'New patients are always welcome.', 'Book a check-up', 'Free parking behind the practice', 'Step-free access to every room', 'Hygiene visits', 'A hygienist cleans and polishes your teeth', 'Whitening', 'Plain fallback copy.' ) as $expected ) {
			$this->assertStringContainsString( $expected, $text );
		}
		foreach ( array( 'https://', '#1E3B36', 'fa-solid', 'fas fa-check', 'intro-copy', 'trackVisitor', 'horizontal', 'full_width', '<p>', '3f2a1b0', 'large' ) as $unexpected ) {
			$this->assertStringNotContainsString( $unexpected, $text, $unexpected );
		}
		$this->assertSame( 1, substr_count( $text, 'Gentle family dentistry' ), 'Text in both Elementor data and post_content appears once.' );

		// Without builder mode, only post_content counts.
		update_post_meta( $post->ID, '_elementor_edit_mode', '' );
		$this->assertStringNotContainsString( 'Whitening', $this->knowledge->posts()->extract( $post ) );
	}

	public function test_post_content_filter(): void {
		$post   = self::factory()->post->create_and_get( array( 'post_content' => 'Original' ) );
		$filter = static fn( string $text, \WP_Post $p ): string => $text . "\n\nExtra for " . $p->ID;
		add_filter( 'devrivo_chatbot_post_content', $filter, 10, 2 );

		$text = $this->knowledge->posts()->extract( $post );
		remove_filter( 'devrivo_chatbot_post_content', $filter, 10 );

		$this->assertSame( "Original\n\nExtra for {$post->ID}", $text );
	}

	public function test_chunker_splits_at_boundaries_with_overlap_and_limits(): void {
		$this->assertSame( array(), Chunker::split( "  \n\n " ) );
		$this->assertSame( array( 'Short text.' ), Chunker::split( 'Short text.' ) );

		$paragraph = str_repeat( 'Word ', 100 ) . 'end.'; // ~504 characters.
		$chunks    = Chunker::split( implode( "\n\n", array_fill( 0, 6, $paragraph ) ) );

		$this->assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $index => $chunk ) {
			$this->assertLessThanOrEqual( Chunker::SIZE + 5, mb_strlen( $chunk ), "Chunk {$index} size." );
			if ( $index > 0 ) {
				$this->assertStringStartsWith( 'Word', $chunk, 'Overlap starts at a word.' );
				$this->assertStringEndsWith( 'end.', $chunk, 'Ends at a paragraph boundary.' );
			}
		}
		$tail = mb_substr( $chunks[0], -60 );
		$this->assertStringContainsString( trim( $tail ), $chunks[1], 'The next chunk repeats the end of the previous one.' );

		// One paragraph of long sentences splits at sentence ends.
		$sentences = implode( ' ', array_fill( 0, 40, 'This sentence is about fifty characters long, ok.' ) );
		foreach ( Chunker::split( $sentences ) as $chunk ) {
			$this->assertStringEndsWith( 'ok.', $chunk );
		}

		// A single huge word still splits.
		$this->assertGreaterThan( 1, count( Chunker::split( str_repeat( 'x', 3000 ) ) ) );

		// Limits: at most MAX_CHUNKS chunks, from at most MAX_CHARS characters.
		$huge = Chunker::split( implode( "\n\n", array_fill( 0, 400, $paragraph ) ) );
		$this->assertLessThanOrEqual( Chunker::MAX_CHUNKS, count( $huge ) );
		$this->assertLessThanOrEqual( Chunker::MAX_CHARS + Chunker::MAX_CHUNKS * Chunker::OVERLAP, mb_strlen( implode( '', $huge ) ) );
	}

	public function test_should_index_rules(): void {
		$posts = $this->knowledge->posts();
		$ok    = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$this->assertTrue( $posts->should_index( $ok ) );

		$cases = array(
			'private'  => array( 'post_status' => 'private' ),
			'draft'    => array( 'post_status' => 'draft' ),
			'future'   => array(
				'post_status' => 'future',
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			),
			'password' => array( 'post_password' => 'secret' ),
		);
		foreach ( $cases as $label => $args ) {
			$this->assertFalse( $posts->should_index( self::factory()->post->create_and_get( $args ) ), $label );
		}

		$yoast = self::factory()->post->create_and_get();
		update_post_meta( $yoast->ID, '_yoast_wpseo_meta-robots-noindex', '1' );
		$this->assertFalse( $posts->should_index( $yoast ), 'Yoast noindex.' );
		update_post_meta( $yoast->ID, '_yoast_wpseo_meta-robots-noindex', '2' );
		$this->assertTrue( $posts->should_index( $yoast ), 'Yoast "index".' );

		$rank_math = self::factory()->post->create_and_get();
		update_post_meta( $rank_math->ID, 'rank_math_robots', array( 'noindex', 'nofollow' ) );
		$this->assertFalse( $posts->should_index( $rank_math ), 'Rank Math noindex.' );
		update_post_meta( $rank_math->ID, 'rank_math_robots', array( 'index' ) );
		$this->assertTrue( $posts->should_index( $rank_math ) );

		register_post_type( 'dc_hidden', array( 'public' => false ) );
		register_post_type( 'dc_product', array( 'public' => true ) );
		$hidden  = self::factory()->post->create_and_get( array( 'post_type' => 'dc_hidden' ) );
		$product = self::factory()->post->create_and_get( array( 'post_type' => 'dc_product' ) );
		Plugin::instance()->settings()->update( array( 'knowledge' => array( 'post_types' => array( 'page', 'dc_hidden', 'dc_product', 'nope' ) ) ) );
		$this->assertSame( array( 'page', 'dc_product' ), $posts->post_types(), 'Only existing, viewable types.' );
		$this->assertFalse( $posts->should_index( $hidden ), 'Not viewable.' );
		$this->assertTrue( $posts->should_index( $product ) );
		$this->assertFalse( $posts->should_index( $yoast ), 'Posts are no longer an indexed type.' );

		Plugin::instance()->settings()->update( array( 'knowledge' => array( 'exclude_ids' => array( $ok->ID ) ) ) );
		$this->assertFalse( $posts->should_index( $ok ), 'Excluded.' );

		$types = static fn(): array => array( 'post' );
		$never = static fn( bool $index, \WP_Post $post ): bool => $index && $post->ID !== $product->ID;
		add_filter( 'devrivo_chatbot_index_post_types', $types );
		add_filter( 'devrivo_chatbot_should_index_post', $never, 10, 2 );
		$this->assertSame( array( 'post' ), $posts->post_types() );
		$this->assertTrue( $posts->should_index( $rank_math ) );
		remove_filter( 'devrivo_chatbot_index_post_types', $types );
		$this->assertFalse( $posts->should_index( $product ), 'should_index_post filter.' );
		remove_filter( 'devrivo_chatbot_should_index_post', $never, 10 );

		unregister_post_type( 'dc_hidden' );
		unregister_post_type( 'dc_product' );
	}

	public function test_saving_schedules_one_delayed_event_per_post(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Again',
			)
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'And again',
			)
		);

		$this->assertSame( 1, $this->scheduled( $id ) );
		$this->assertGreaterThanOrEqual( time() + Indexer::DELAY - 1, wp_next_scheduled( Indexer::HOOK, array( $id ) ) );

		// Revisions and types that aren't indexed don't schedule anything.
		$attachment = self::factory()->attachment->create();
		$this->assertSame( 0, $this->scheduled( $attachment ) );
	}

	public function test_index_post_stores_chunks_skips_unchanged_and_removes(): void {
		$events = array();
		$listen = static function ( int $post_id, int $count ) use ( &$events ): void {
			$events[] = array( $post_id, $count );
		};
		add_action( 'devrivo_chatbot_post_indexed', $listen, 10, 2 );

		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Parking &amp; access',
				'post_content' => '<p>' . str_repeat( 'Plenty of free parking. ', 120 ) . '</p>',
			)
		);
		do_action( Indexer::HOOK, $id );

		$rows = $this->rows( $id );
		$this->assertGreaterThan( 1, count( $rows ) );
		$this->assertSame( range( 0, count( $rows ) - 1 ), array_map( 'intval', array_column( $rows, 'chunk' ) ) );
		$this->assertSame( 'Parking & access', $rows[0]['title'] );
		$this->assertSame( 'page', $rows[0]['post_type'] );
		$this->assertSame( array( array( $id, count( $rows ) ) ), $events );
		$this->assertTrue( Index::has_content() );

		// Unchanged: skipped.
		$this->assertSame( 'unchanged', $this->knowledge->posts()->index( $id ) );
		$this->assertCount( 1, $events );

		// Changed: replaced.
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'Short now.',
			)
		);
		do_action( Indexer::HOOK, $id );
		$this->assertCount( 1, $this->rows( $id ) );
		$this->assertSame( 'Short now.', $this->rows( $id )[0]['content'] );
		$this->assertCount( 2, $events );

		// Trashed: removed.
		wp_trash_post( $id );
		$this->assertSame( 1, $this->scheduled( $id ) );
		do_action( Indexer::HOOK, $id );
		$this->assertSame( array(), $this->rows( $id ) );

		// Deleted: removed.
		$other = self::factory()->post->create( array( 'post_content' => 'Other' ) );
		$this->knowledge->posts()->index( $other );
		wp_unschedule_hook( Indexer::HOOK );
		wp_delete_post( $other, true );
		$this->assertSame( 1, $this->scheduled( $other ) );
		do_action( Indexer::HOOK, $other );
		$this->assertSame( array(), $this->rows( $other ) );

		remove_action( 'devrivo_chatbot_post_indexed', $listen, 10 );
	}

	public function test_title_only_pages_are_indexed(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Gallery',
				'post_content' => '',
			)
		);

		$this->assertSame( 'indexed', $this->knowledge->posts()->index( $id ) );
		$this->assertSame( '', $this->rows( $id )[0]['content'] );
	}

	public function test_rebuild_runs_in_batches_cleans_up_and_fires_the_hook(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$ids = self::factory()->post->create_many( Rebuild::BATCH + 1, array( 'post_content' => 'Batch content' ) );
		Plugin::instance()->settings()->update(
			array(
				'knowledge' => array(
					'faqs' => array(
						array(
							'id'       => 'hours',
							'question' => 'When are you open?',
							'answer'   => 'Weekdays.',
						),
					),
				),
			)
		);

		// A stale row: indexed earlier, now a draft.
		$stale = self::factory()->post->create( array( 'post_content' => 'Stale' ) );
		$this->knowledge->posts()->index( $stale );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $stale ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $stale );

		$stats  = null;
		$listen = static function ( array $data ) use ( &$stats ): void {
			$stats = $data;
		};
		add_action( 'devrivo_chatbot_index_rebuilt', $listen );

		$expected = (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish;
		Rebuild::start();
		wp_unschedule_hook( Indexer::HOOK );

		do_action( Rebuild::HOOK );
		$state = Rebuild::state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( Rebuild::BATCH, $state['processed'] );
		$this->assertSame( $expected, $state['total'] );
		$this->assertNotFalse( wp_next_scheduled( Rebuild::HOOK ), 'Next batch scheduled.' );

		while ( 'running' === Rebuild::state()['status'] ) {
			do_action( Rebuild::HOOK );
		}
		remove_action( 'devrivo_chatbot_index_rebuilt', $listen );

		$state = Rebuild::state();
		$this->assertSame( 'done', $state['status'] );
		$this->assertSame( $expected, $state['processed'] );
		$this->assertGreaterThan( 0, $state['finished_at'] );
		foreach ( $ids as $id ) {
			$this->assertNotEmpty( $this->rows( $id ), "Post {$id}" );
		}
		$this->assertSame( array(), $this->rows( $stale ), 'Stale rows removed.' );
		$this->assertSame( $expected, $stats['posts'] );
		$this->assertSame( 1, $stats['faqs'] );
		$this->assertSame( 1, $stats['removed'] );
		$this->assertFalse( wp_load_alloptions()[ Rebuild::OPTION ] ?? false, 'State is not autoloaded.' );
	}

	public function test_faqs_are_reindexed_when_the_knowledge_settings_are_saved(): void {
		$settings = Plugin::instance()->settings();
		$faq      = static fn( string $id, string $answer ): array => array(
			'id'       => $id,
			'question' => "Question {$id}?",
			'answer'   => $answer,
		);

		$settings->update( array( 'knowledge' => array( 'faqs' => array( $faq( 'a', 'Alpha' ), $faq( 'b', 'Beta' ) ) ) ) );
		$this->assertSame( array( 'Alpha', 'Beta' ), $this->faq_answers() );
		$this->assertTrue( Index::has_content() );

		$settings->update( array( 'knowledge' => array( 'faqs' => array( $faq( 'b', 'Beta 2' ) ) ) ) );
		$this->assertSame( array( 'Beta 2' ), $this->faq_answers() );

		// Unrelated saves don't touch FAQs; changing post types starts a rebuild.
		delete_option( Rebuild::OPTION );
		$settings->update( array( 'behaviour' => array( 'welcome' => 'Hi' ) ) );
		$this->assertSame( 'idle', Rebuild::state()['status'] );
		$settings->update( array( 'knowledge' => array( 'post_types' => array( 'page' ) ) ) );
		$this->assertSame( 'running', Rebuild::state()['status'] );

		$settings->update( array( 'knowledge' => array( 'faqs' => array() ) ) );
		$this->assertSame( array(), $this->faq_answers() );
	}

	public function test_has_content_flag_bumps_the_config_version(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->knowledge->index()->refresh_has_content();
		$this->assertFalse( Index::has_content() );

		$version = Settings::config_version();
		$this->knowledge->posts()->index( self::factory()->post->create() );

		$this->assertTrue( Index::has_content() );
		$this->assertSame( $version + 1, Settings::config_version() );
		$this->assertArrayHasKey( Index::HAS_CONTENT_OPTION, wp_load_alloptions(), 'Tiny flag, autoloaded.' );
	}

	public function test_cron_hooks_are_cleared_on_deactivation(): void {
		$this->assertContains( Indexer::HOOK, Deactivator::cron_hooks() );
		$this->assertContains( Rebuild::HOOK, Deactivator::cron_hooks() );

		Indexer::schedule( 123 );
		Rebuild::start();
		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( Indexer::HOOK, array( 123 ) ) );
		$this->assertFalse( wp_next_scheduled( Rebuild::HOOK ) );
	}

	public function test_uninstall_drops_the_index_only_with_opt_in(): void {
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			if ( false !== stripos( $query, 'DROP' ) && str_contains( $query, Index::table() ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture, 1 );
		update_option( Rebuild::OPTION, array( 'status' => 'done' ), false );
		Indexer::schedule( 5 );

		Uninstaller::uninstall();
		$this->assertSame( array(), $queries, 'Kept without the opt-in.' );
		$this->assertNotFalse( get_option( Rebuild::OPTION ) );
		$this->assertFalse( wp_next_scheduled( Indexer::HOOK, array( 5 ) ), 'Cron is always cleared.' );

		Plugin::instance()->settings()->update( array( 'privacy' => array( 'delete_data_on_uninstall' => true ) ) );
		Uninstaller::uninstall();
		remove_filter( 'query', $capture, 1 );

		$this->assertCount( 1, $queries );
		foreach ( array( Rebuild::OPTION, Index::DRIVER_OPTION, Index::HAS_CONTENT_OPTION ) as $option ) {
			$this->assertContains( $option, Uninstaller::options() );
			$this->assertFalse( get_option( $option ), $option );
		}

		Activator::maybe_install();
		update_option( Index::DRIVER_OPTION, 'fulltext', false );
	}

	/**
	 * Index rows of a post, by chunk.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array<string, string>>
	 */
	private function rows( int $post_id ): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE source = %s AND object_id = %d ORDER BY chunk', Index::table(), 'post', $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Indexed FAQ answers, by ID.
	 *
	 * @return string[]
	 */
	private function faq_answers(): array {
		global $wpdb;

		return $wpdb->get_col( $wpdb->prepare( 'SELECT content FROM %i WHERE source = %s ORDER BY source_key', Index::table(), 'faq' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Number of scheduled index events for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function scheduled( int $post_id ): int {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) ( $hooks[ Indexer::HOOK ] ?? array() ) as $event ) {
				if ( array( $post_id ) === $event['args'] ) {
					++$count;
				}
			}
		}

		return $count;
	}
}
