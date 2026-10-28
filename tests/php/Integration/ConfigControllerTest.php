<?php
/**
 * Public widget config endpoint tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class ConfigControllerTest extends WP_UnitTestCase {

	private const ROUTE = '/devrivo-chatbot/v1/config';

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		delete_option( Settings::DISPLAY_OPTION );
		Plugin::instance()->settings()->flush();
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_logged_out_visitors_get_200_with_cache_headers(): void {
		$response = $this->request();
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'public, max-age=86400', $headers['Cache-Control'] );
		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{32}"$/', $headers['ETag'] );
	}

	public function test_response_has_only_allowlisted_keys(): void {
		$this->settings()->update(
			array(
				'display'      => array( 'launcher_label' => 'Talk to us' ),
				'appearance'   => array( 'brand_name' => 'Acme' ),
				'ai'           => array(
					'enabled'             => true,
					'model'               => 'secret-model',
					'custom_instructions' => 'SECRET INSTRUCTIONS',
				),
				'privacy'      => array( 'consent_text' => 'SECRET CONSENT' ),
				'integrations' => array( 'notification_emails' => array( 'owner@example.com' ) ),
				'flows'        => array( 'secret-flow' => array( 'steps' => array() ) ),
				'forms'        => array( 'secret-form' => array( 'fields' => array() ) ),
			)
		);

		$data = $this->request()->get_data();
		$json = (string) wp_json_encode( $data );

		$this->assertSame( array( 'version', 'chat_enabled', 'display', 'appearance', 'behaviour', 'actions', 'offers', 'i18n' ), array_keys( $data ) );
		$this->assertSame( array( 'position', 'primary_color', 'launcher_label' ), array_keys( $data['display'] ) );
		$this->assertSame( array( 'color_scheme', 'radius', 'brand_name', 'font', 'avatar_url' ), array_keys( $data['appearance'] ) );
		$this->assertSame( array( 'greeting', 'welcome', 'auto_open', 'auto_open_delay' ), array_keys( $data['behaviour'] ) );
		$this->assertFalse( $data['chat_enabled'] );
		$this->assertSame( 'Talk to us', $data['display']['launcher_label'] );
		$this->assertSame( 'Acme', $data['appearance']['brand_name'] );

		foreach ( array( 'custom_instructions', 'notification_emails', 'privacy', 'integrations', 'flows', 'forms', 'SECRET', 'secret-model', 'owner@example.com', 'secret-flow', 'secret-form', '"enabled"', '"rules"' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
	}

	public function test_action_config_is_filtered_per_type(): void {
		$extra = array(
			'url'     => 'https://example.com/a',
			'phone'   => '+100',
			'message' => 'Hi',
			'email'   => 'a@example.com',
			'subject' => 'Hello',
			'form'    => 'contact',
			'flow'    => 'urgent',
			'prompt'  => 'Where?',
			'secret'  => 'nope',
		);
		$types = array( 'link', 'call', 'whatsapp', 'email', 'form', 'flow', 'ai_prompt', 'page_search', 'custom_thing' );

		$this->settings()->update(
			array(
				'actions' => array_map(
					static fn( string $type ): array => array(
						'id'     => str_replace( '_', '-', $type ),
						'label'  => $type,
						'type'   => $type,
						'config' => $extra,
					),
					$types
				),
			)
		);

		$actions = array_column( $this->request()->get_data()['actions'], null, 'type' );
		$keys    = static fn( string $type ): array => array_keys( (array) $actions[ $type ]['config'] );

		$this->assertSame( array( 'url' ), $keys( 'link' ) );
		$this->assertSame( array( 'phone' ), $keys( 'call' ) );
		$this->assertSame( array( 'phone', 'message' ), $keys( 'whatsapp' ) );
		$this->assertSame( array( 'email', 'subject' ), $keys( 'email' ) );
		$this->assertSame( array( 'form' ), $keys( 'form' ) );
		$this->assertSame( array( 'flow' ), $keys( 'flow' ) );
		$this->assertSame( array( 'prompt' ), $keys( 'ai_prompt' ) );
		$this->assertSame( array(), $keys( 'page_search' ) );
		$this->assertSame( array(), $keys( 'custom_thing' ) );
		$this->assertInstanceOf( \stdClass::class, $actions['page_search']['config'], 'Empty config is {}.' );
		$this->assertSame( array( 'id', 'label', 'type', 'urgent', 'config' ), array_keys( $actions['link'] ) );
		$this->assertSame( 'https://example.com/a', $actions['link']['config']->url );
	}

	public function test_offers_pointing_to_unknown_actions_are_dropped(): void {
		$this->settings()->update(
			array(
				'actions' => array(
					array(
						'id'    => 'book',
						'label' => 'Book',
						'type'  => 'link',
					),
				),
				'offers'  => array(
					array(
						'id'       => 'linked',
						'title'    => 'Linked',
						'action'   => 'book',
						'featured' => true,
					),
					array(
						'id'    => 'plain',
						'title' => 'No action',
					),
					array(
						'id'     => 'orphan',
						'title'  => 'Orphan',
						'action' => 'gone',
					),
				),
			)
		);

		$offers = $this->request()->get_data()['offers'];

		$this->assertSame( array( 'linked', 'plain' ), array_column( $offers, 'id' ) );
		$this->assertSame( array( 'id', 'title', 'text', 'cta_label', 'action', 'featured' ), array_keys( $offers[0] ) );
		$this->assertTrue( $offers[0]['featured'] );
	}

	public function test_filters_are_applied(): void {
		$this->settings()->update(
			array(
				'actions' => array(
					array(
						'id'    => 'book',
						'label' => 'Book',
						'type'  => 'link',
					),
				),
			)
		);

		$contexts = array();
		$actions  = static function ( array $actions, array $context ) use ( &$contexts ): array {
			$contexts[] = $context;
			$actions[]  = array(
				'id'     => 'extra',
				'label'  => 'Extra',
				'type'   => 'link',
				'urgent' => false,
				'config' => array( 'url' => '' ),
			);
			return $actions;
		};
		$greeting = static fn(): string => 'Hiya';
		$config   = static function ( array $config ): array {
			$config['custom'] = 'yes';
			return $config;
		};
		add_filter( 'devrivo_chatbot_quick_actions', $actions, 10, 2 );
		add_filter( 'devrivo_chatbot_greeting', $greeting );
		add_filter( 'devrivo_chatbot_widget_config', $config );

		$data = $this->request()->get_data();

		remove_filter( 'devrivo_chatbot_quick_actions', $actions, 10 );
		remove_filter( 'devrivo_chatbot_greeting', $greeting );
		remove_filter( 'devrivo_chatbot_widget_config', $config );

		$this->assertSame( array( 'book', 'extra' ), array_column( $data['actions'], 'id' ) );
		$this->assertSame( 'Hiya', $data['behaviour']['greeting'] );
		$this->assertSame( 'yes', $data['custom'] );
		$this->assertSame( array( array( 'locale' => determine_locale() ) ), $contexts );
	}

	public function test_i18n_strings_are_present(): void {
		$i18n = $this->request()->get_data()['i18n'];

		foreach ( array( 'close', 'back', 'home', 'offers', 'send', 'placeholder', 'menu', 'good_morning', 'good_afternoon', 'good_evening' ) as $key ) {
			$this->assertIsString( $i18n[ $key ] ?? null, $key );
			$this->assertNotSame( '', $i18n[ $key ], $key );
		}
	}

	public function test_matching_if_none_match_returns_304(): void {
		$etag = $this->request()->get_headers()['ETag'];

		$cached = $this->request( array( 'If-None-Match' => $etag ) );
		$weak   = $this->request( array( 'If-None-Match' => 'W/' . substr( $etag, 0, -1 ) . '-gzip"' ) );
		$stale  = $this->request( array( 'If-None-Match' => '"stale"' ) );

		$this->assertSame( 304, $cached->get_status() );
		$this->assertNull( $cached->get_data() );
		$this->assertSame( $etag, $cached->get_headers()['ETag'] );
		$this->assertSame( 304, $weak->get_status() );
		$this->assertSame( 200, $stale->get_status() );
	}

	public function test_config_version_increments_after_update_and_changes_etag(): void {
		$before = $this->request();

		$this->settings()->update( array( 'behaviour' => array( 'welcome' => 'New' ) ) );
		$after = $this->request();

		$this->assertSame( $before->get_data()['version'] + 1, $after->get_data()['version'] );
		$this->assertSame( Settings::config_version(), $after->get_data()['version'] );
		$this->assertNotSame( $before->get_headers()['ETag'], $after->get_headers()['ETag'] );
		$this->assertTrue( wp_load_alloptions()[ Settings::CONFIG_VERSION_OPTION ] > 1, 'Autoloaded.' );
	}

	public function test_invalid_locale_is_rejected(): void {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'locale', '../../etc' );

		$this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
	}

	private function settings(): Settings {
		return Plugin::instance()->settings();
	}

	/**
	 * Dispatches a GET request.
	 *
	 * @param array<string, string> $headers Request headers.
	 */
	private function request( array $headers = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_param( 'v', Settings::config_version() );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}

		return rest_get_server()->dispatch( $request );
	}
}
