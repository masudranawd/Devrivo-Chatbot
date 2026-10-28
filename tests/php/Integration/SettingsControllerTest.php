<?php
/**
 * Settings REST endpoint tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Plugin;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class SettingsControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/devrivo-chatbot/v1/settings';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		Plugin::instance()->settings()->flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	public function test_logged_out_users_get_401(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'GET' )->get_status() );
		$this->assertSame( 401, $this->request( 'POST', array( 'appearance' => array( 'radius' => 1 ) ) )->get_status() );
	}

	public function test_subscribers_and_editors_get_403(): void {
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 403, $this->request( 'GET' )->get_status(), $role );
			$this->assertSame( 403, $this->request( 'DELETE' )->get_status(), $role );
		}
	}

	public function test_admin_can_read_all_sections(): void {
		$this->as_admin();

		$response = $this->request( 'GET' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		foreach ( array( 'display', 'general', 'appearance', 'behaviour', 'actions', 'offers', 'flows', 'forms', 'ai', 'privacy', 'integrations' ) as $section ) {
			$this->assertArrayHasKey( $section, $data );
		}
		$this->assertInstanceOf( \stdClass::class, $data['flows'], 'Empty objects are returned as {}.' );
	}

	public function test_admin_partial_update_is_saved(): void {
		$this->as_admin();

		$response = $this->request( 'PATCH', array( 'behaviour' => array( 'auto_open' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['behaviour']['auto_open'] );
		$this->assertFalse( Plugin::instance()->settings()->get( 'behaviour' )['auto_open'] );
	}

	public function test_invalid_update_returns_400_with_field(): void {
		$this->as_admin();

		$response = $this->request( 'POST', array( 'appearance' => array( 'radius' => 500 ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'appearance', $response->get_data()['data']['params'] );
	}

	public function test_empty_body_returns_400(): void {
		$this->as_admin();

		$this->assertSame( 400, $this->request( 'POST', array() )->get_status() );
	}

	public function test_reset_section(): void {
		$this->as_admin();
		$this->request( 'POST', array( 'appearance' => array( 'radius' => 3 ) ) );

		$request = new WP_REST_Request( 'DELETE', self::ROUTE );
		$request->set_param( 'section', 'appearance' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 22, $response->get_data()['appearance']['radius'] );
	}

	public function test_role_granted_the_capability_can_manage(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_role( 'editor' )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $editor );

		$status = $this->request( 'GET' )->get_status();
		get_role( 'editor' )->remove_cap( Capabilities::MANAGE );

		$this->assertSame( 200, $status );
	}

	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatches a JSON request.
	 *
	 * @param string               $method HTTP method.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function request( string $method, array $body = array() ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE );
		if ( 'GET' !== $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
