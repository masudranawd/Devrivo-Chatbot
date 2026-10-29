<?php
/**
 * Session token and security gate tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Security\Gate;
use DevRivo\Chatbot\Core\Security\RateLimiter;
use DevRivo\Chatbot\Core\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class SecurityTest extends WP_UnitTestCase {

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

	public function test_valid_token_is_accepted(): void {
		$issued = SessionToken::issue( 1000 );

		$payload = SessionToken::verify( $issued['token'], 1010 );

		$this->assertIsArray( $payload );
		$this->assertSame( 1000, $payload['iat'] );
		$this->assertSame( 1000 + SessionToken::TTL, $issued['expires'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $payload['sid'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $issued['token'], 'base64url, no padding.' );
	}

	public function test_tampered_malformed_and_expired_tokens_are_rejected(): void {
		$token                    = SessionToken::issue( 1000 )['token'];
		list( $body, $signature ) = explode( '.', $token );

		$forged = rtrim(
			strtr(
				base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Forging a token for the test.
					(string) wp_json_encode(
						array(
							'sid' => 'x',
							'iat' => 1,
							'exp' => PHP_INT_MAX,
						)
					)
				),
				'+/',
				'-_'
			),
			'='
		);

		$cases = array(
			'tampered body'      => $forged . '.' . $signature,
			'tampered signature' => $body . '.' . strrev( $signature ),
			'no signature'       => $body,
			'three parts'        => $token . '.x',
			'empty'              => '',
			'not a string'       => array( $token ),
			'null'               => null,
			'huge'               => str_repeat( 'a', 600 ) . '.' . $signature,
		);
		foreach ( $cases as $label => $candidate ) {
			$result = SessionToken::verify( $candidate, 1010 );
			$this->assertInstanceOf( WP_Error::class, $result, $label );
			$this->assertSame( 'devrivo_chatbot_invalid_token', $result->get_error_code(), $label );
			$this->assertSame( 403, $result->get_error_data()['status'], $label );
		}

		$expired = SessionToken::verify( $token, 1000 + SessionToken::TTL + 1 );
		$this->assertSame( 'devrivo_chatbot_invalid_token', $expired->get_error_code(), 'Expired.' );

		$future = SessionToken::verify( SessionToken::issue( 5000 )['token'], 1000 );
		$this->assertSame( 'devrivo_chatbot_invalid_token', $future->get_error_code(), 'Issued in the future.' );
	}

	public function test_token_used_too_fast_is_rejected(): void {
		$token = SessionToken::issue( 1000 )['token'];

		$result = SessionToken::verify( $token, 1000 + SessionToken::MIN_AGE - 1 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'devrivo_chatbot_too_fast', $result->get_error_code() );
		$this->assertIsArray( SessionToken::verify( $token, 1000 + SessionToken::MIN_AGE ) );
	}

	public function test_session_endpoint_is_public_and_not_cached(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/devrivo-chatbot/v1/session' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'token', 'expires' ), array_keys( $response->get_data() ) );
	}

	public function test_gate_rejects_oversized_bodies(): void {
		$request = $this->request( array( 'pad' => str_repeat( 'x', Gate::MAX_BODY ) ) );

		$this->assertSame( 413, $this->status( Gate::check( $request, array( 'endpoint' => 'test' ) ) ) );
	}

	public function test_gate_checks_origin_then_referer(): void {
		$foreign = $this->request( array(), array( 'Origin' => 'https://evil.example' ) );
		$this->assertSame( 403, $this->status( Gate::check( $foreign, array( 'endpoint' => 'test' ) ) ) );

		$none = $this->request( array(), array( 'Origin' => '' ) );
		$this->assertSame( 403, $this->status( Gate::check( $none, array( 'endpoint' => 'test' ) ) ) );

		$lookalike = $this->request( array(), array( 'Origin' => home_url() . '.evil.example' ) );
		$this->assertSame( 403, $this->status( Gate::check( $lookalike, array( 'endpoint' => 'test' ) ) ) );

		$referer = $this->request(
			array(),
			array(
				'Origin'  => '',
				'Referer' => home_url( '/contact/?x=1' ),
			)
		);
		$this->assertTrue( Gate::check( $referer, array( 'endpoint' => 'test' ) ) );
	}

	public function test_gate_requires_a_valid_token(): void {
		$missing = Gate::check( $this->request( array( 'token' => null ) ), array( 'endpoint' => 'test' ) );
		$invalid = Gate::check( $this->request( array( 'token' => 'nope.nope' ) ), array( 'endpoint' => 'test' ) );

		$this->assertSame( 'devrivo_chatbot_invalid_token', $missing->get_error_code() );
		$this->assertSame( 403, $this->status( $missing ) );
		$this->assertSame( 'devrivo_chatbot_invalid_token', $invalid->get_error_code() );
	}

	public function test_gate_rate_limits_per_client(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Gate::check( $this->request(), array( 'endpoint' => 'test' ) ), "Hit {$i}" );
		}

		$limited = Gate::check( $this->request(), array( 'endpoint' => 'test' ) );
		$this->assertSame( 429, $this->status( $limited ) );
		$this->assertGreaterThan( 0, $limited->get_error_data()['retry_after'] );
		$this->assertLessThanOrEqual( 600, $limited->get_error_data()['retry_after'] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertTrue( Gate::check( $this->request(), array( 'endpoint' => 'test' ) ), 'Other clients are not affected.' );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_forwarded_headers_are_ignored(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
		$first                           = RateLimiter::client_key();
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.2';

		$this->assertSame( $first, RateLimiter::client_key() );
		$this->assertStringNotContainsString( '127.0.0.1', $first, 'Hashed, never the raw IP.' );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	public function test_rate_limit_filter(): void {
		$seen   = array();
		$filter = static function ( array $limits, array $context ) use ( &$seen ): array {
			$seen = $context;
			return array(
				'max'    => 1,
				'window' => 60,
			);
		};
		add_filter( 'devrivo_chatbot_rate_limit', $filter, 10, 2 );

		$first  = Gate::check( $this->request(), array( 'endpoint' => 'filtered' ) );
		$second = Gate::check( $this->request(), array( 'endpoint' => 'filtered' ) );
		remove_filter( 'devrivo_chatbot_rate_limit', $filter, 10 );

		$this->assertTrue( $first );
		$this->assertSame( 429, $this->status( $second ) );
		$this->assertSame( array( 'endpoint' => 'filtered' ), $seen );
	}

	/**
	 * A JSON request from this site with a valid token.
	 *
	 * @param array<string, mixed>  $body    Body (merged over a valid token).
	 * @param array<string, string> $headers Headers (merged over a same-site Origin).
	 */
	private function request( array $body = array(), array $headers = array() ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/devrivo-chatbot/v1/test' );
		$request->set_header( 'Content-Type', 'application/json' );
		foreach ( array_merge( array( 'Origin' => home_url() ), $headers ) as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( (string) wp_json_encode( array_merge( array( 'token' => SessionToken::issue( time() - 10 )['token'] ), $body ) ) );

		return $request;
	}

	/**
	 * HTTP status of a gate result.
	 *
	 * @param true|WP_Error $result Result.
	 */
	private function status( bool|WP_Error $result ): int {
		return is_wp_error( $result ) ? (int) $result->get_error_data()['status'] : 200;
	}
}
