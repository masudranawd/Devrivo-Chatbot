<?php
/**
 * Leads storage, privacy tools, migration, uninstall and admin REST tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Activator;
use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Deactivator;
use DevRivo\Chatbot\Core\Migrations;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Uninstaller;
use DevRivo\Chatbot\Data\Leads;
use DevRivo\Chatbot\Data\Privacy;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class LeadsTest extends WP_UnitTestCase {

	private Leads $leads;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		Plugin::instance()->settings()->flush();
		$this->leads = new Leads();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_migration_creates_the_table_and_is_idempotent(): void {
		update_option( Activator::SCHEMA_OPTION, 2, true );
		wp_clear_scheduled_hook( Privacy::PURGE_HOOK );

		Migrations::run();
		Leads::create_table();

		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', Leads::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame(
			array( 'id', 'form_id', 'status', 'email', 'name', 'data', 'page_url', 'consent_text', 'consent_at', 'created_at', 'updated_at' ),
			$columns
		);
		$keys = array_unique( $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', Leads::table() ), 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertEqualsCanonicalizing( array( 'PRIMARY', 'email', 'status_created', 'created_at' ), $keys );
		$this->assertSame( DEVRIVO_CHATBOT_SCHEMA_VERSION, (int) get_option( Activator::SCHEMA_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( Privacy::PURGE_HOOK ) );
		$this->assertContains( Privacy::PURGE_HOOK, Deactivator::cron_hooks() );
	}

	public function test_repository_crud_and_filters(): void {
		$a = $this->lead( 'contact', 'a@example.com' );
		$b = $this->lead( 'support', 'b@example.com' );
		$c = $this->lead( 'contact', 'c@example.com' );

		$found = $this->leads->find( $a );
		$this->assertSame( 'a@example.com', $found['email'] );
		$this->assertSame(
			array(
				array(
					'id'    => 'name',
					'label' => 'Name',
					'value' => 'Ann',
				),
			),
			$found['data']
		);
		$this->assertNull( $this->leads->find( 999999 ) );

		$this->assertTrue( $this->leads->update_status( $b, 'contacted' ) );
		$this->assertFalse( $this->leads->update_status( $b, 'deleted' ), 'Unknown status.' );
		$this->assertFalse( $this->leads->update_status( 999999, 'closed' ), 'Unknown lead.' );

		$all = $this->leads->paginate();
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( array( $c, $b, $a ), array_column( $all['items'], 'id' ), 'Newest first.' );

		$this->assertSame( array( $c, $a ), array_column( $this->leads->paginate( array( 'form_id' => 'contact' ) )['items'], 'id' ) );
		$this->assertSame( array( $b ), array_column( $this->leads->paginate( array( 'status' => 'contacted' ) )['items'], 'id' ) );

		$page = $this->leads->paginate(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( 3, $page['total'] );
		$this->assertSame( array( $a ), array_column( $page['items'], 'id' ) );

		$this->assertTrue( $this->leads->delete( $a ) );
		$this->assertFalse( $this->leads->delete( $a ) );
		$this->assertSame( 2, $this->leads->paginate()['total'] );
	}

	public function test_sql_values_are_never_interpolated(): void {
		$this->lead( 'contact', "o'brien@example.com" );

		$this->assertCount( 1, $this->leads->find_by_email( "o'brien@example.com" ) );
		$this->assertSame( 0, $this->leads->paginate( array( 'form_id' => "x' OR '1'='1" ) )['total'] );
	}

	public function test_privacy_export_and_erase_by_email(): void {
		$this->lead( 'contact', 'ann@example.com' );
		$this->lead( 'support', 'ann@example.com' );
		$this->lead( 'contact', 'bob@example.com' );
		$privacy = new Privacy( $this->leads, Plugin::instance()->settings() );

		$this->assertArrayHasKey( 'devrivo-chatbot', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'devrivo-chatbot', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		$export = $privacy->export( 'ann@example.com' );
		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );
		$this->assertSame( 'devrivo-chatbot-leads', $export['data'][0]['group_id'] );
		$this->assertContains( 'Ann', array_column( $export['data'][0]['data'], 'value' ) );

		$erase = $privacy->erase( 'ann@example.com' );
		$this->assertTrue( $erase['items_removed'] );
		$this->assertFalse( $erase['items_retained'] );
		$this->assertTrue( $erase['done'] );
		$this->assertSame( array(), $this->leads->find_by_email( 'ann@example.com' ) );
		$this->assertCount( 1, $this->leads->find_by_email( 'bob@example.com' ) );
	}

	public function test_privacy_export_and_erase_are_paginated(): void {
		for ( $i = 0; $i < 101; $i++ ) {
			$this->lead( 'contact', 'many@example.com' );
		}
		$privacy = new Privacy( $this->leads, Plugin::instance()->settings() );

		$first  = $privacy->export( 'many@example.com', 1 );
		$second = $privacy->export( 'many@example.com', 2 );
		$this->assertCount( 100, $first['data'] );
		$this->assertFalse( $first['done'] );
		$this->assertCount( 1, $second['data'] );
		$this->assertTrue( $second['done'] );

		$this->assertFalse( $privacy->erase( 'many@example.com', 1 )['done'] );
		$this->assertTrue( $privacy->erase( 'many@example.com', 2 )['done'] );
		$this->assertSame( array(), $this->leads->find_by_email( 'many@example.com' ) );
	}

	public function test_purge_respects_retention_and_batches(): void {
		Plugin::instance()->settings()->update( array( 'privacy' => array( 'retention_days' => 30 ) ) );
		$recent = $this->lead( 'contact', 'new@example.com' );
		$old    = array();
		for ( $i = 0; $i < 501; $i++ ) {
			$old[] = $this->lead( 'contact', 'old@example.com', 31 );
		}

		$counts = array();
		$record = static function ( array $c ) use ( &$counts ): void {
			$counts[] = $c;
		};
		add_action( 'devrivo_chatbot_data_purged', $record );
		( new Privacy( $this->leads, Plugin::instance()->settings() ) )->purge();
		remove_action( 'devrivo_chatbot_data_purged', $record );

		$this->assertSame( array( array( 'leads' => 501 ) ), $counts, 'More than one batch of 500.' );
		$this->assertNotNull( $this->leads->find( $recent ) );
		$this->assertNull( $this->leads->find( $old[0] ) );

		$this->assertSame( 0, $this->leads->purge_older_than( 30, 2 ) );
		$this->lead( 'contact', 'x@example.com', 40 );
		$this->lead( 'contact', 'x@example.com', 40 );
		$this->lead( 'contact', 'x@example.com', 40 );
		$this->assertSame( 2, $this->leads->purge_older_than( 30, 2 ), 'Batch size is respected.' );
	}

	public function test_retention_filter(): void {
		$lead = $this->lead( 'contact', 'a@example.com', 5 );
		$days = static fn(): int => 3;
		add_filter( 'devrivo_chatbot_retention_days', $days );

		( new Privacy( $this->leads, Plugin::instance()->settings() ) )->purge();
		remove_filter( 'devrivo_chatbot_retention_days', $days );

		$this->assertNull( $this->leads->find( $lead ) );
	}

	public function test_uninstall_drops_the_table_only_with_opt_in(): void {
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			if ( false !== stripos( $query, 'DROP' ) && false !== strpos( $query, Leads::table() ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $capture, 1 );
		Privacy::schedule();

		Uninstaller::uninstall();
		$this->assertSame( array(), $queries, 'Kept without the opt-in.' );
		$this->assertFalse( wp_next_scheduled( Privacy::PURGE_HOOK ), 'Cron is always cleared.' );

		Plugin::instance()->settings()->update( array( 'privacy' => array( 'delete_data_on_uninstall' => true ) ) );
		Uninstaller::uninstall();
		remove_filter( 'query', $capture, 1 );

		$this->assertCount( 1, $queries );
		Capabilities::grant_defaults();
	}

	public function test_leads_rest_permissions(): void {
		$id     = $this->lead( 'contact', 'a@example.com' );
		$routes = array(
			array( 'GET', '/devrivo-chatbot/v1/leads' ),
			array( 'GET', "/devrivo-chatbot/v1/leads/{$id}" ),
			array( 'PATCH', "/devrivo-chatbot/v1/leads/{$id}", array( 'status' => 'closed' ) ),
			array( 'DELETE', "/devrivo-chatbot/v1/leads/{$id}" ),
		);

		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->request( ...$route )->get_status(), $route[0] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->request( ...$route )->get_status(), $route[0] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( $routes as $route ) {
			$response = $this->request( ...$route );
			$this->assertSame( 200, $response->get_status(), $route[0] );
			$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		}
	}

	public function test_leads_rest_list_filters_and_headers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$a = $this->lead( 'contact', 'a@example.com' );
		$this->lead( 'support', 'b@example.com' );
		$this->lead( 'contact', 'c@example.com' );
		$this->leads->update_status( $a, 'spam' );

		$page = $this->request( 'GET', '/devrivo-chatbot/v1/leads', array( 'per_page' => 2 ) );
		$this->assertCount( 2, $page->get_data() );
		$this->assertSame( '3', $page->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $page->get_headers()['X-WP-TotalPages'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $page->get_data()[0]['created_at'] );

		$this->assertCount( 2, $this->request( 'GET', '/devrivo-chatbot/v1/leads', array( 'form_id' => 'contact' ) )->get_data() );
		$this->assertSame( array( $a ), array_column( $this->request( 'GET', '/devrivo-chatbot/v1/leads', array( 'status' => 'spam' ) )->get_data(), 'id' ) );

		$this->assertSame( 400, $this->request( 'GET', '/devrivo-chatbot/v1/leads', array( 'per_page' => 101 ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/devrivo-chatbot/v1/leads', array( 'status' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'PATCH', "/devrivo-chatbot/v1/leads/{$a}", array( 'status' => 'nope' ) )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/devrivo-chatbot/v1/leads/999999' )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', '/devrivo-chatbot/v1/leads/999999' )->get_status() );

		$patched = $this->request( 'PATCH', "/devrivo-chatbot/v1/leads/{$a}", array( 'status' => 'contacted' ) );
		$this->assertSame( 'contacted', $patched->get_data()['status'] );
	}

	/**
	 * Inserts a lead.
	 *
	 * @param string $form_id  Form ID.
	 * @param string $email    Email.
	 * @param int    $days_ago Age in days.
	 * @return int Lead ID.
	 */
	private function lead( string $form_id, string $email, int $days_ago = 0 ): int {
		$id = (int) $this->leads->create(
			array(
				'form_id'      => $form_id,
				'email'        => $email,
				'name'         => 'Ann',
				'data'         => array(
					array(
						'id'    => 'name',
						'label' => 'Name',
						'value' => 'Ann',
					),
				),
				'page_url'     => home_url( '/' ),
				'consent_text' => 'I agree.',
			)
		);

		if ( $days_ago > 0 ) {
			global $wpdb;
			$wpdb->update( Leads::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return $id;
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method Method.
	 * @param string               $route  Route.
	 * @param array<string, mixed> $params Params.
	 */
	private function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}
}
