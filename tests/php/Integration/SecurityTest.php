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

	public function test_origin_ignores_case_and_www(): void {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$this->assertTrue( Gate::is_site_url( 'https://www.' . $host ) );
		$this->assertTrue( Gate::is_site_url( 'http://WWW.' . strtoupper( $host ) . '/page/' ) );
		$this->assertTrue( Gate::is_site_url( 'https://' . strtoupper( $host ) ) );

		$www_home = static fn(): string => 'https://www.' . $host;
		add_filter( 'pre_option_home', $www_home );
		$bare     = Gate::is_site_url( 'https://' . $host );
		remove_filter( 'pre_option_home', $www_home );
		$this->assertTrue( $bare, 'Bare host against a www home URL.' );

		$others = array(
			'https://evil.example',
			'https://www.evil.example',
			'https://ww.' . $host,
			'https://wwwx' . $host,
			'https://evil' . $host,
			'https://' . $host . '.evil.example',
			'https://sub.' . $host,
			'ftp://' . $host,
			'not a url',
		);
		foreach ( $others as $url ) {
			$this->assertFalse( Gate::is_site_url( $url ), $url );
		}

		$www   = $this->request( array(), array( 'Origin' => 'https://www.' . $host ) );
		$other = $this->request( array(), array( 'Origin' => 'https://www.evil.example' ) );
		$this->assertTrue( Gate::check( $www, array( 'endpoint' => 'test' ) ) );
		$this->assertSame( 403, $this->status( Gate::check( $other, array( 'endpoint' => 'test' ) ) ) );
	}

	public function test_origin_accepts_the_wordpress_address(): void {
		$site_url = static fn(): string => 'https://wp.example.net';
		add_filter( 'pre_option_siteurl', $site_url );

		$wp   = Gate::is_site_url( 'https://wp.example.net/wp-admin/' );
		$www  = Gate::is_site_url( 'https://www.wp.example.net' );
		$home = Gate::is_site_url( home_url( '/' ) );
		$evil = Gate::is_site_url( 'https://example.net' );
		remove_filter( 'pre_option_siteurl', $site_url );

		$this->assertTrue( $wp );
		$this->assertTrue( $www );
		$this->assertTrue( $home );
		$this->assertFalse( $evil );
	}

	public function test_gate_requires_a_valid_token(): void {
		$missing = Gate::check( $this->request( array( 'token' => null ) ), array( 'endpoint' => 'test' ) );
		$invalid = Gate::check( $this->request( array( 'token' => 'nope.nope' ) ), array( 'endpoint' => 'test' ) );

		$this->assertSame( 'devrivo_chatbot_invalid_token', $missing->get_error_code() );
		$this->assertSame( 403, $this->status( $missing ) );
		$this->assertSame( 'devrivo_chatbot_invalid_token', $invalid->get_error_code() );
	}

	public function test_gate_rate_limits_per_session(): void {
		$token = $this->token();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'test' ) ), "Hit {$i}" );
		}

		$limited = Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'test' ) );
		$this->assertSame( 429, $this->status( $limited ) );
		$this->assertSame( 'devrivo_chatbot_rate_limited', $limited->get_error_code() );
		$this->assertGreaterThan( 0, $limited->get_error_data()['retry_after'] );
		$this->assertLessThanOrEqual( 600, $limited->get_error_data()['retry_after'] );

		$this->assertTrue( Gate::check( $this->request(), array( 'endpoint' => 'test' ) ), 'Another visitor on the same IP is not blocked.' );
		$this->assertTrue( Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'other' ) ), 'Endpoints are counted apart.' );
	}

	public function test_shared_ip_is_limited_only_at_the_ip_limit(): void {
		// 30 visitors behind one IP (office, proxy), one request each.
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertTrue( Gate::check( $this->request(), array( 'endpoint' => 'test' ) ), "Visitor {$i}" );
		}

		$token   = $this->token();
		$limited = Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'test' ) );
		$this->assertSame( 429, $this->status( $limited ), 'The 31st request from the IP is blocked, even with a new session.' );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$other                  = Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'test' ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertTrue( $other, 'Other IPs are not affected.' );

		// The blocked request did not use up the session's own limit.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertTrue( Gate::check( $this->request( array( 'token' => $token ) ), array( 'endpoint' => 'test' ) ), "Session hit {$i}" );
		}
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_forwarded_headers_are_ignored_by_default(): void {
		$request                         = $this->request(
			array(),
			array(
				'X-Forwarded-For'  => '198.51.100.1',
				'CF-Connecting-IP' => '198.51.100.2',
			)
		);
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';

		$ip = RateLimiter::client_ip( $request );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$this->assertSame( '127.0.0.1', $ip );
		$this->assertStringNotContainsString( '127.0.0.1', RateLimiter::key( $ip ), 'Hashed, never the raw IP.' );
	}

	public function test_client_ip_filter_can_trust_a_proxy_header(): void {
		$cloudflare = static function ( string $ip, WP_REST_Request $request ): string {
			$cf = $request->get_header( 'cf_connecting_ip' );
			return $cf ? $cf : $ip;
		};
		add_filter( 'devrivo_chatbot_client_ip', $cloudflare, 10, 2 );

		$visitor = RateLimiter::client_ip( $this->request( array(), array( 'CF-Connecting-IP' => '198.51.100.2' ) ) );
		$v6      = RateLimiter::client_ip( $this->request( array(), array( 'CF-Connecting-IP' => '2001:db8::1' ) ) );
		$junk    = RateLimiter::client_ip( $this->request( array(), array( 'CF-Connecting-IP' => 'not-an-ip' ) ) );

		// Visitors behind the same proxy now get their own IP limit.
		for ( $i = 0; $i < 30; $i++ ) {
			Gate::check( $this->request( array(), array( 'CF-Connecting-IP' => '198.51.100.3' ) ), array( 'endpoint' => 'test' ) );
		}
		$blocked = Gate::check( $this->request( array(), array( 'CF-Connecting-IP' => '198.51.100.3' ) ), array( 'endpoint' => 'test' ) );
		$free    = Gate::check( $this->request( array(), array( 'CF-Connecting-IP' => '198.51.100.4' ) ), array( 'endpoint' => 'test' ) );
		remove_filter( 'devrivo_chatbot_client_ip', $cloudflare, 10 );

		$this->assertSame( '198.51.100.2', $visitor );
		$this->assertSame( '2001:db8::1', $v6 );
		$this->assertSame( '127.0.0.1', $junk, 'Values that are not an IP address fall back to REMOTE_ADDR.' );
		$this->assertSame( 429, $this->status( $blocked ) );
		$this->assertTrue( $free );
	}

	public function test_rate_limit_filter(): void {
		$seen   = array();
		$filter = static function ( array $limits, array $context ) use ( &$seen ): array {
			$seen                = array( $limits, $context );
			$limits['ip']['max'] = 2;
			return $limits;
		};
		add_filter( 'devrivo_chatbot_rate_limit', $filter, 10, 2 );

		$first  = Gate::check( $this->request(), array( 'endpoint' => 'filtered' ) );
		$second = Gate::check( $this->request(), array( 'endpoint' => 'filtered' ) );
		$third  = Gate::check( $this->request(), array( 'endpoint' => 'filtered' ) );
		remove_filter( 'devrivo_chatbot_rate_limit', $filter, 10 );

		$this->assertTrue( $first );
		$this->assertTrue( $second );
		$this->assertSame( 429, $this->status( $third ) );
		$this->assertSame(
			array(
				'session' => array(
					'max'    => 5,
					'window' => 600,
				),
				'ip'      => array(
					'max'    => 30,
					'window' => 600,
				),
				'search'  => array(
					'max'    => 60,
					'window' => 60,
				),
			),
			$seen[0],
			'Defaults.'
		);
		$this->assertSame( array( 'endpoint' => 'filtered' ), $seen[1] );
	}

	/**
	 * A signed session token old enough to use.
	 */
	private function token(): string {
		return SessionToken::issue( time() - 10 )['token'];
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
		$request->set_body( (string) wp_json_encode( array_merge( array( 'token' => $this->token() ), $body ) ) );

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
