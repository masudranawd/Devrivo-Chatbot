<?php
/**
 * Presets REST endpoint tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class PresetsControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/devrivo-chatbot/v1/presets';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		delete_option( Settings::DISPLAY_OPTION );
		delete_option( PresetApplier::BACKUP_OPTION );
		Plugin::instance()->settings()->flush();
		Plugin::instance()->presets()->flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertArrayHasKey( self::ROUTE . '/undo', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[a-z0-9_-]+)', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<id>[a-z0-9_-]+)/apply', $routes );
	}

	public function test_logged_out_users_get_401(): void {
		wp_set_current_user( 0 );

		foreach ( $this->all_routes() as $label => $route ) {
			$this->assertSame( 401, $this->request( $route[0], $route[1] )->get_status(), $label );
		}
	}

	public function test_subscribers_get_403(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		foreach ( $this->all_routes() as $label => $route ) {
			$this->assertSame( 403, $this->request( $route[0], $route[1] )->get_status(), $label );
		}
	}

	public function test_admin_gets_200_on_every_route(): void {
		$this->as_admin();

		foreach ( $this->all_routes() as $label => $route ) {
			$response = $this->request( $route[0], $route[1] );
			$this->assertSame( 200, $response->get_status(), $label );
			$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'], $label );
		}
	}

	public function test_list_marks_the_active_preset(): void {
		$this->as_admin();
		$this->request( 'POST', '/agency/apply' );

		$items = $this->request( 'GET' )->get_data();

		$this->assertSame( array( 'business', 'healthcare', 'portfolio', 'agency' ), array_column( $items, 'id' ) );
		$this->assertSame( array( false, false, false, true ), array_column( $items, 'active' ) );
		$this->assertSame( array( 'id', 'title', 'description', 'version', 'active' ), array_keys( $items[0] ) );
	}

	public function test_get_returns_full_preset(): void {
		$this->as_admin();

		$data = $this->request( 'GET', '/healthcare' )->get_data();

		$this->assertSame( 'healthcare', $data['id'] );
		$this->assertSame( 3, $data['version'] );
		$this->assertCount( 8, $data['settings']['actions'] );
		$this->assertSame( 'enquire', $data['settings']['actions'][0]['config']['flow'] );
		$this->assertSame( 'ask', $data['settings']['flows']['enquire']['start'] );
		$this->assertNotSame( '', $data['settings']['ai']['custom_instructions'] );
	}

	public function test_unknown_preset_returns_404(): void {
		$this->as_admin();

		$this->assertSame( 404, $this->request( 'GET', '/nope' )->get_status() );
		$this->assertSame( 404, $this->request( 'POST', '/nope/apply' )->get_status() );
	}

	public function test_invalid_mode_returns_400(): void {
		$this->as_admin();

		$this->assertSame( 400, $this->request( 'POST', '/business/apply', array( 'mode' => 'append' ) )->get_status() );
	}

	public function test_apply_response_contains_settings_applied_and_skipped(): void {
		$this->as_admin();

		$response = $this->request( 'POST', '/business/apply', array( 'mode' => 'merge' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'settings', 'applied', 'skipped' ), array_keys( $data ) );
		$this->assertSame( 'business', $data['settings']['general']['preset'] );
		$this->assertSame( array( 'behaviour', 'actions', 'forms', 'ai' ), $data['applied'] );
		$this->assertSame( array(), $data['skipped'] );
		$this->assertInstanceOf( \stdClass::class, $data['settings']['flows'], 'Empty objects are returned as {}.' );
	}

	public function test_undo_without_backup_returns_404(): void {
		$this->as_admin();

		$this->assertSame( 404, $this->request( 'POST', '/undo' )->get_status() );
	}

	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Every route, in an order where each one succeeds for an admin (apply before undo).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function all_routes(): array {
		return array(
			'list'  => array( 'GET', '' ),
			'get'   => array( 'GET', '/business' ),
			'apply' => array( 'POST', '/business/apply' ),
			'undo'  => array( 'POST', '/undo' ),
		);
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $path   Path after /presets.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function request( string $method, string $path = '', array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::ROUTE . $path );
		if ( 'GET' !== $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
