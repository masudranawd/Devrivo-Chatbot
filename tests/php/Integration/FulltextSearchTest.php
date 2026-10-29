<?php
/**
 * FULLTEXT search tests.
 *
 * InnoDB FULLTEXT only sees committed rows, and every test runs inside a
 * transaction. So the fixtures are created and indexed in
 * wpSetUpBeforeClass(), which the test suite commits, and removed again in
 * wpTearDownAfterClass(). Tests only read them (changes made inside a test
 * are rolled back as usual).
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Knowledge\Index;
use DevRivo\Chatbot\Knowledge\Indexer;
use DevRivo\Chatbot\Knowledge\Search;
use DevRivo\Chatbot\Knowledge\Search\FulltextDriver;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTest_Factory;
use WP_UnitTestCase;

final class FulltextSearchTest extends WP_UnitTestCase {

	/**
	 * Fixture post IDs by name.
	 *
	 * @var array<string, int>
	 */
	private static array $ids = array();

	/**
	 * Creates and indexes the fixtures (committed by the test suite).
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		Plugin::instance()->settings()->flush();

		$pages = array(
			'implants'  => array( 'page', 'Dental implants', 'Dental implants replace missing teeth. Our dentists place implants in two short visits and check on them every year.' ),
			'hours'     => array( 'page', 'Opening hours', 'The practice is open on weekdays. A dentist is available for emergencies.' ),
			'whitening' => array( 'post', 'Whitening tips', 'Whitening works best after a hygiene visit.' ),
			'guide'     => array( 'page', 'Patient guide', implode( "\n\n", array_map( static fn( int $i ): string => "Part {$i}. " . str_repeat( 'Welcome to the patient guide for new visitors. ', 10 ), range( 1, 10 ) ) ) . "\n\nImplants are covered in a separate leaflet." ),
		);

		foreach ( $pages as $name => list( $type, $title, $content ) ) {
			self::$ids[ $name ] = $factory->post->create(
				array(
					'post_type'    => $type,
					'post_title'   => $title,
					'post_content' => $content,
				)
			);
			Plugin::instance()->knowledge()->posts()->index( self::$ids[ $name ] );
		}
		wp_unschedule_hook( Indexer::HOOK );
		update_option( Index::DRIVER_OPTION, 'fulltext', false );
	}

	/**
	 * Removes the committed fixtures.
	 */
	public static function wpTearDownAfterClass(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Index::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_option( Index::HAS_CONTENT_OPTION );
		wp_unschedule_hook( Indexer::HOOK );
	}

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_fixtures_are_committed_and_fulltext_is_used(): void {
		$this->assertTrue( Index::has_fulltext() );
		$this->assertSame( 'fulltext', Index::driver() );
		$this->assertNotNull( ( new FulltextDriver() )->search( array( 'implants' ), array(), 10 ) );
	}

	public function test_ranks_by_relevance_with_prefix_matching_and_one_result_per_post(): void {
		$results = $this->search( 'implant' );

		$this->assertSame( array( 'Dental implants', 'Patient guide' ), array_column( $results, 'title' ), '"implant*" finds "implants"; best match first; the guide once.' );
		$this->assertGreaterThan( $results[1]['score'], $results[0]['score'] );
		$this->assertStringContainsString( 'Implants are covered', $results[1]['excerpt'], 'Best chunk of the guide.' );

		$this->assertEqualsCanonicalizing( array( 'Dental implants', 'Opening hours' ), array_column( $this->search( 'dentist' ), 'title' ) );
	}

	public function test_any_term_may_match(): void {
		$this->assertEqualsCanonicalizing(
			array( 'Opening hours', 'Whitening tips' ),
			array_column( $this->search( 'weekdays whitening' ), 'title' )
		);
	}

	public function test_boolean_operators_are_stripped(): void {
		$this->assertSame( array( 'Opening hours' ), array_column( $this->search( '+weekdays -"open" (emergencies)* ~<>@' ), 'title' ) );
		$this->assertSame( array(), $this->search( '"*" + - ~' ) );
	}

	public function test_short_terms_fall_back_to_like(): void {
		$this->assertNull( ( new FulltextDriver() )->search( array( 'we' ), array(), 10 ), 'Too short for FULLTEXT.' );
		$this->assertIsArray( $this->search( 'we' ) );
	}

	public function test_type_filter(): void {
		$this->assertSame( array( 'Whitening tips' ), array_column( $this->search( 'whitening hygiene', array( 'post' ) ), 'title' ) );
		$this->assertSame( array(), $this->search( 'whitening', array( 'page' ) ) );
	}

	public function test_unpublished_posts_are_left_out_at_query_time(): void {
		wp_update_post(
			array(
				'ID'          => self::$ids['hours'],
				'post_status' => 'draft',
			)
		);
		wp_unschedule_hook( Indexer::HOOK );

		$this->assertSame( array( 'Dental implants' ), array_column( $this->search( 'dentist' ), 'title' ) );
	}

	public function test_endpoint_uses_fulltext(): void {
		$request = new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/search' );
		$request->set_query_params( array( 'q' => 'Implants?' ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Dental implants', $response->get_data()['results'][0]['title'] );
		$this->assertSame( get_permalink( self::$ids['implants'] ), $response->get_data()['results'][0]['url'] );
	}

	/**
	 * Searches with the stored driver (FULLTEXT).
	 *
	 * @param string   $query Query.
	 * @param string[] $types Post types.
	 * @return array<int, array<string, mixed>>
	 */
	private function search( string $query, array $types = array() ): array {
		return ( new Search( Plugin::instance()->knowledge() ) )->run( $query, $types );
	}
}
