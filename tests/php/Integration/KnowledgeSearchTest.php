<?php
/**
 * Knowledge search tests (LIKE driver), REST endpoints, config and validation.
 *
 * FULLTEXT only sees committed rows, so FULLTEXT searches are tested in
 * FulltextSearchTest with committed fixtures. Here every search runs on the
 * LIKE driver, inside the test transaction.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Knowledge\Index;
use DevRivo\Chatbot\Knowledge\Indexer;
use DevRivo\Chatbot\Knowledge\Knowledge;
use DevRivo\Chatbot\Knowledge\Rebuild;
use DevRivo\Chatbot\Knowledge\Search;
use DevRivo\Chatbot\Knowledge\Search\LikeDriver;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class KnowledgeSearchTest extends WP_UnitTestCase {

	private Knowledge $knowledge;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server, $wpdb;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		Plugin::instance()->settings()->flush();
		$this->knowledge = Plugin::instance()->knowledge();

		// Rolled back after each test, like these rows.
		update_option( Index::DRIVER_OPTION, 'like', false );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_unschedule_hook( Indexer::HOOK );
		wp_unschedule_hook( Rebuild::HOOK );
		Plugin::instance()->settings()->flush();

		parent::tear_down();
	}

	public function test_terms_drop_operators_and_short_words(): void {
		$this->assertSame( array( 'opening', 'hours', 'café', '24' ), Search::terms( '+Opening -"hours" (café) a 24* opening' ) );
		$this->assertSame( array(), Search::terms( '* - + "' ) );
		$this->assertCount( 10, Search::terms( implode( ' ', range( 10, 30 ) ) ) );
	}

	public function test_like_search_ranks_titles_and_returns_the_best_chunk_per_post(): void {
		$title_hit   = $this->page( 'Parking', 'Information about the practice.' );
		$content_hit = $this->page( 'Visiting us', 'There is free parking behind the building.' );
		$long        = $this->page( 'Long page', implode( "\n\n", array_map( static fn( int $i ): string => "Section {$i}. " . str_repeat( 'Parking is free for patients. ', 15 ), range( 1, 12 ) ) ) );
		$this->page( 'Unrelated', 'Nothing to see.' );

		$this->assertGreaterThan( 1, $this->chunks( $long ) );

		$results = $this->search( 'parking' );

		// Title match first; equal scores keep index order; the long page appears once.
		$this->assertSame( array( 'Parking', 'Visiting us', 'Long page' ), array_column( $results, 'title' ) );
		$this->assertSame( get_permalink( $title_hit ), $results[0]['url'] );
		$this->assertSame( 'page', $results[0]['type'] );
		$this->assertSame( get_permalink( $content_hit ), $results[1]['url'] );
		$this->assertSame( array( 'type', 'title', 'url', 'excerpt', 'score' ), array_keys( $results[0] ) );

		// Any term may match; more distinct terms rank higher (title 3, content 2 per term).
		$this->assertSame( array( 'Visiting us', 'Long page', 'Parking' ), array_column( $this->search( 'free parking building' ), 'title' ) );
		$this->assertSame( array( 'Parking', 'Visiting us', 'Long page' ), array_column( $this->search( 'parking unicorns' ), 'title' ) );
		$this->assertSame( array(), $this->search( 'unicorns' ) );
	}

	public function test_like_handles_natural_questions(): void {
		$this->page( 'About us', 'What we do and who we are.' );
		$this->page( 'Contact', 'Call us during opening hours.' );
		$this->page( 'Opening hours', 'We are open on weekdays.' );
		$this->page( 'Your visit', 'Bring your card.' );

		$results = $this->search( 'What are your opening hours?' );

		$this->assertSame( array( 'Opening hours', 'Contact' ), array_column( $results, 'title' ), 'Stopwords ("what", "are", "your") never match.' );
		$this->assertSame( 6.0, $results[0]['score'], 'Two terms in the title.' );
		$this->assertSame( 4.0, $results[1]['score'], 'Two terms in the content.' );

		// Distinct terms matched beat a single title match.
		$this->page( 'Hours', 'See below.' );
		$this->assertSame( array( 'Opening hours', 'Contact', 'Hours' ), array_column( $this->search( 'opening hours' ), 'title' ) );

		// Only stopwords or short words: nothing to search.
		$this->assertSame( array(), $this->search( 'what are you up to' ) );
		$this->assertSame( array( 'opening', 'hours' ), LikeDriver::useful( array( 'what', 'opening', 'on', 'hours', 'your' ) ) );
	}

	public function test_excerpt_is_plain_text_around_the_first_match(): void {
		$text = str_repeat( 'Lorem ipsum dolor sit amet. ', 20 ) . 'Our dentists offer emergency appointments every weekday. ' . str_repeat( 'Consectetur adipiscing elit. ', 20 );
		$this->page( 'Emergencies', $text );

		$excerpt = $this->search( 'emergency' )[0]['excerpt'];

		$this->assertStringContainsString( 'emergency appointments', $excerpt );
		$this->assertStringStartsWith( '…', $excerpt );
		$this->assertStringEndsWith( '…', $excerpt );
		$this->assertLessThanOrEqual( 202, mb_strlen( $excerpt ) );

		$this->assertSame( 'Short.', Search::excerpt( "  Short. \n", array( 'x' ) ) );
	}

	public function test_only_posts_published_at_query_time_are_returned(): void {
		$draft    = $this->page( 'Prices', 'Our prices are listed here.' );
		$password = $this->page( 'Members prices', 'Prices for members.' );

		wp_update_post(
			array(
				'ID'          => $draft,
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'            => $password,
				'post_password' => 'secret',
			)
		);
		// The scheduled re-index has not run yet: rows are still there.
		$this->assertSame( 1, $this->chunks( $draft ) );

		$this->assertSame( array(), $this->search( 'prices' ) );
	}

	public function test_faqs_are_answers_without_urls(): void {
		Plugin::instance()->settings()->update(
			array(
				'knowledge' => array(
					'faqs' => array(
						array(
							'id'       => 'parking',
							'question' => 'Is there parking?',
							'answer'   => "Yes, free parking.\nBehind the building.",
						),
					),
				),
			)
		);

		$results = $this->search( 'parking' );

		$this->assertSame(
			array(
				'type'    => 'faq',
				'title'   => 'Is there parking?',
				'url'     => '',
				'excerpt' => "Yes, free parking.\nBehind the building.",
			),
			array_diff_key( $results[0], array( 'score' => 0 ) )
		);
	}

	public function test_type_filter_and_results_filter(): void {
		$this->page( 'Garden page', 'Garden tips.' );
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Garden article',
				'post_content' => 'More garden tips.',
			)
		);
		$this->knowledge->posts()->index( $post );
		Plugin::instance()->settings()->update(
			array(
				'knowledge' => array(
					'faqs' => array(
						array(
							'id'       => 'garden',
							'question' => 'Garden?',
							'answer'   => 'Garden answer.',
						),
					),
				),
			)
		);

		$this->assertCount( 3, $this->search( 'garden' ) );
		$this->assertSame( array( 'Garden article' ), array_column( $this->search( 'garden', array( 'post' ) ), 'title' ), 'Only posts, no FAQs.' );

		$seen   = null;
		$filter = static function ( array $results, string $query ) use ( &$seen ): array {
			$seen = $query;
			return array_slice( $results, 0, 1 );
		};
		add_filter( 'devrivo_chatbot_search_results', $filter, 10, 2 );
		$this->assertCount( 1, $this->search( 'garden' ) );
		remove_filter( 'devrivo_chatbot_search_results', $filter, 10 );
		$this->assertSame( 'garden', $seen );
	}

	public function test_at_most_five_results(): void {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->page( "Service {$i}", 'We offer this service.' );
		}

		$this->assertCount( Search::MAX_RESULTS, $this->search( 'service' ) );
	}

	public function test_search_endpoint(): void {
		$this->page( 'Opening hours', 'We are open on weekdays.' );

		$response = $this->get( array( 'q' => '  opening  ' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'Opening hours' ), array_column( $response->get_data()['results'], 'title' ) );

		foreach ( array( 'a', ' a ', str_repeat( 'x', Search::MAX_LENGTH + 1 ), '' ) as $bad ) {
			$this->assertSame( 400, $this->get( array( 'q' => $bad ) )->get_status(), "q={$bad}" );
		}
		$this->assertSame( 400, $this->get( array() )->get_status(), 'q is required.' );
		$this->assertSame( 200, $this->get( array( 'q' => str_repeat( 'x', Search::MAX_LENGTH ) ) )->get_status() );

		$this->assertSame(
			array(),
			$this->get(
				array(
					'q'     => 'opening',
					'types' => 'post',
				)
			)->get_data()['results'],
			'Only posts.'
		);
		$this->assertCount(
			1,
			$this->get(
				array(
					'q'     => 'opening',
					'types' => 'page,post',
				)
			)->get_data()['results']
		);
		$this->assertSame(
			array(),
			$this->get(
				array(
					'q'     => 'opening',
					'types' => 'nope',
				)
			)->get_data()['results'],
			'Types that are not indexed search nothing.'
		);
		$this->assertSame(
			400,
			$this->get(
				array(
					'q'     => 'opening',
					'types' => 'Bad Type',
				)
			)->get_status()
		);

		// POST only: questions never go in URLs.
		$get = new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/search' );
		$get->set_query_params( array( 'q' => 'opening' ) );
		$this->assertSame( 404, rest_get_server()->dispatch( $get )->get_status() );
	}

	public function test_privacy_text_says_questions_are_not_stored(): void {
		$privacy = new \DevRivo\Chatbot\Data\Privacy( new \DevRivo\Chatbot\Data\Leads(), Plugin::instance()->settings() );

		$this->assertStringContainsString( 'Your questions are not stored or logged by the assistant.', $privacy->policy_text() );
	}

	public function test_search_endpoint_is_rate_limited_per_ip(): void {
		$seen   = null;
		$filter = static function ( array $limits, array $context ) use ( &$seen ): array {
			$seen                    = array( $limits['search'], $context );
			$limits['search']['max'] = 2;
			return $limits;
		};
		add_filter( 'devrivo_chatbot_rate_limit', $filter, 10, 2 );

		$this->assertSame( 200, $this->get( array( 'q' => 'one' ) )->get_status() );
		$this->assertSame( 200, $this->get( array( 'q' => 'two' ) )->get_status() );
		$limited = $this->get( array( 'q' => 'three' ) );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		$other                  = $this->get( array( 'q' => 'four' ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		remove_filter( 'devrivo_chatbot_rate_limit', $filter, 10 );

		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'devrivo_chatbot_rate_limited', $limited->get_data()['code'] );
		$this->assertGreaterThan( 0, (int) $limited->get_headers()['Retry-After'] );
		$this->assertSame( 'no-store', $limited->get_headers()['Cache-Control'] );
		$this->assertSame( 200, $other->get_status(), 'Other IPs are not affected.' );
		$this->assertSame(
			array(
				array(
					'max'    => 60,
					'window' => 60,
				),
				array( 'endpoint' => 'search' ),
			),
			$seen
		);
	}

	public function test_status_and_rebuild_permissions(): void {
		$routes = array(
			array( 'GET', '/devrivo-chatbot/v1/knowledge/status' ),
			array( 'POST', '/devrivo-chatbot/v1/knowledge/rebuild' ),
		);

		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, rest_get_server()->dispatch( new WP_REST_Request( ...$route ) )->get_status(), $route[1] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( ...$route ) )->get_status(), $route[1] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->page( 'Indexed', 'Content.' );

		$status = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/knowledge/status' ) );
		$this->assertSame( 200, $status->get_status() );
		$this->assertSame( 'no-store', $status->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'driver', 'posts', 'chunks', 'faqs', 'rebuild' ), array_keys( $status->get_data() ) );
		$this->assertSame( 'like', $status->get_data()['driver'] );
		$this->assertSame( 1, $status->get_data()['posts'] );
		$this->assertSame( 1, $status->get_data()['chunks'] );

		$rebuild = rest_get_server()->dispatch( new WP_REST_Request( 'POST', '/devrivo-chatbot/v1/knowledge/rebuild' ) );
		$this->assertSame( 200, $rebuild->get_status() );
		$this->assertSame( 'running', $rebuild->get_data()['rebuild']['status'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rebuild->get_data()['rebuild']['started_at'] );
		$this->assertNull( $rebuild->get_data()['rebuild']['finished_at'] );
		$this->assertNotFalse( wp_next_scheduled( Rebuild::HOOK ) );
	}

	public function test_public_config_search_flags(): void {
		update_option( Index::HAS_CONTENT_OPTION, 0, true );
		Plugin::instance()->settings()->update(
			array(
				'actions'   => array(
					array(
						'id'     => 'contact',
						'label'  => 'Contact',
						'type'   => 'link',
						'config' => array( 'url' => 'https://example.com/contact/' ),
					),
					array(
						'id'     => 'articles',
						'label'  => 'Find an article',
						'type'   => 'page_search',
						'config' => array( 'post_types' => array( 'post' ) ),
					),
				),
				'behaviour' => array( 'fallback_action' => 'contact' ),
				'knowledge' => array( 'exclude_ids' => array( 987654 ) ),
			)
		);

		$off = $this->config();
		$this->assertFalse( $off['search_enabled'] );
		$this->assertSame( array( 'contact' ), array_column( $off['actions'], 'id' ), 'Nothing to search: no search action.' );

		$this->page( 'Something', 'Text.' );
		$on = $this->config();
		$this->assertTrue( $on['search_enabled'] );
		$this->assertStringContainsString( 'devrivo-chatbot/v1/search', $on['search_url'] );
		$this->assertSame( 'contact', $on['fallback_action'] );
		$this->assertSame( array( 'contact', 'articles' ), array_column( $on['actions'], 'id' ) );
		$this->assertSame( array( 'post_types' => array( 'post' ) ), (array) $on['actions'][1]['config'] );

		$json = (string) wp_json_encode( $on );
		foreach ( array( 'knowledge', 'exclude_ids', '987654', 'faqs' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
	}

	public function test_validation_of_fallback_action_and_faqs(): void {
		$settings = Plugin::instance()->settings();

		$missing = $settings->update( array( 'behaviour' => array( 'fallback_action' => 'nope' ) ) );
		$this->assertWPError( $missing );
		$this->assertArrayHasKey( 'behaviour[fallback_action]', $missing->get_error_data()['params'] );

		$faq       = array(
			'id'       => 'same',
			'question' => 'Q?',
			'answer'   => 'A.',
		);
		$duplicate = $settings->update( array( 'knowledge' => array( 'faqs' => array( $faq, $faq ) ) ) );
		$this->assertWPError( $duplicate );
		$this->assertArrayHasKey( 'knowledge[faqs][1][id]', $duplicate->get_error_data()['params'] );

		$too_long = $settings->update( array( 'knowledge' => array( 'faqs' => array( array_merge( $faq, array( 'answer' => str_repeat( 'a', 2001 ) ) ) ) ) ) );
		$this->assertWPError( $too_long );

		$too_many = $settings->update( array( 'knowledge' => array( 'exclude_ids' => range( 1, 501 ) ) ) );
		$this->assertWPError( $too_many );

		$this->assertSame( array( 'page', 'post' ), $settings->get( 'knowledge' )['post_types'], 'Defaults.' );
	}

	/**
	 * A published, indexed page.
	 *
	 * @param string $title   Title.
	 * @param string $content Content.
	 * @return int Post ID.
	 */
	private function page( string $title, string $content ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
		$this->knowledge->posts()->index( $id );

		return $id;
	}

	/**
	 * Searches with the LIKE driver.
	 *
	 * @param string   $query Query.
	 * @param string[] $types Post types.
	 * @return array<int, array<string, mixed>>
	 */
	private function search( string $query, array $types = array() ): array {
		return ( new Search( $this->knowledge, new LikeDriver() ) )->run( $query, $types );
	}

	/**
	 * Number of chunks indexed for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function chunks( int $post_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE source = %s AND object_id = %d', Index::table(), 'post', $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * POST /search with a JSON body.
	 *
	 * @param array<string, string> $params Body.
	 */
	private function get( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/devrivo-chatbot/v1/search' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $params ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Public widget config.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/config' ) )->get_data();
	}
}
