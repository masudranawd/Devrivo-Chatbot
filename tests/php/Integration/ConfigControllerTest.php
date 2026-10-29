<?php
/**
 * Public widget config endpoint tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Settings\Schema;
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
				'privacy'      => array(
					'consent_text'   => 'I agree to the <a href="https://example.com/privacy">policy</a>.',
					'retention_days' => 77,
				),
				'integrations' => array( 'notification_emails' => array( 'owner@example.com' ) ),
				'flows'        => array( 'help' => self::flow() ),
				'forms'        => array( 'contact' => self::form() ),
			)
		);

		$data = $this->request()->get_data();
		$json = (string) wp_json_encode( $data );

		$this->assertSame( array( 'version', 'chat_enabled', 'search_enabled', 'search_url', 'fallback_action', 'display', 'appearance', 'behaviour', 'actions', 'flows', 'forms', 'consent', 'session_url', 'submit_url', 'offers', 'i18n' ), array_keys( $data ) );
		$this->assertSame( array( 'position', 'primary_color', 'launcher_label' ), array_keys( $data['display'] ) );
		$this->assertSame( array( 'color_scheme', 'radius', 'brand_name', 'font', 'avatar_url' ), array_keys( $data['appearance'] ) );
		$this->assertSame( array( 'greeting', 'welcome', 'auto_open', 'auto_open_delay', 'hours', 'utm' ), array_keys( $data['behaviour'] ) );
		$this->assertFalse( $data['chat_enabled'] );
		$this->assertSame( 'Talk to us', $data['display']['launcher_label'] );
		$this->assertSame( 'Acme', $data['appearance']['brand_name'] );
		$this->assertArrayHasKey( 'help', $data['flows'], 'Flows are public content.' );
		$this->assertArrayHasKey( 'contact', $data['forms'], 'Forms are public content.' );
		$this->assertSame( 'I agree to the policy.', $data['consent']['text'], 'Consent text only, as plain text.' );

		foreach ( array( 'custom_instructions', 'notification_emails', 'privacy', 'integrations', 'retention_days', 'delete_data_on_uninstall', 'SECRET', 'secret-model', 'owner@example.com', '"enabled"', '"rules"', 'href' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, $needle );
		}
	}

	public function test_action_config_is_filtered_per_type(): void {
		$this->settings()->update(
			array(
				'actions' => array(
					$this->action( 'link', array( 'url' => 'https://example.com/a' ) ),
					$this->action( 'call', array( 'phone' => '+44 1234 567' ) ),
					$this->action(
						'whatsapp',
						array(
							'phone'   => '+44 7700 900123',
							'message' => 'Hi',
						)
					),
					$this->action(
						'email',
						array(
							'email'   => 'a@example.com',
							'subject' => 'Hello',
						)
					),
					$this->action( 'flow', array( 'flow' => 'help' ) ),
				),
				'flows'   => array( 'help' => self::flow() ),
			)
		);

		// Data stored by an older version or a module: extra keys and other types.
		$stored            = get_option( Settings::OPTION );
		$stored['actions'] = array_merge(
			array(
				$this->action(
					'link',
					array(
						'url'    => 'https://example.com/b',
						'secret' => 'nope',
					),
					'legacy'
				),
			),
			$stored['actions']
		);
		update_option( Settings::OPTION, $stored, false );
		$this->settings()->flush();

		$actions = array_column( $this->request()->get_data()['actions'], null, 'id' );
		$keys    = static fn( string $id ): array => array_keys( (array) $actions[ $id ]['config'] );

		$this->assertSame( array( 'url', 'new_tab' ), $keys( 'link' ) );
		$this->assertSame( array( 'url', 'new_tab' ), $keys( 'legacy' ), 'Unknown config keys are never sent.' );
		$this->assertSame( array( 'phone' ), $keys( 'call' ) );
		$this->assertSame( array( 'phone', 'message' ), $keys( 'whatsapp' ) );
		$this->assertSame( array( 'email', 'subject' ), $keys( 'email' ) );
		$this->assertSame( array( 'flow' ), $keys( 'flow' ) );
		$this->assertSame( array( 'id', 'label', 'type', 'urgent', 'show_in_menu', 'config' ), array_keys( $actions['link'] ) );
		$this->assertSame( 'https://example.com/a', $actions['link']['config']->url );
		$this->assertTrue( $actions['link']['config']->new_tab );
		$this->assertTrue( $actions['link']['show_in_menu'] );
	}

	public function test_unsupported_incomplete_and_dangling_actions_are_left_out(): void {
		$this->settings()->update(
			array(
				'actions' => array(
					$this->action( 'link', array( 'url' => 'https://example.com/' ), 'ready' ),
					$this->action( 'link', array( 'url' => '' ), 'incomplete' ),
					$this->action( 'form', array( 'form' => '' ), 'form' ),
					$this->action( 'ai_prompt', array( 'prompt' => 'Hi' ), 'ai' ),
					$this->action( 'page_search', array(), 'search' ),
					$this->action( 'call', array( 'phone' => '+1 555 0100' ), 'helper' ) + array( 'show_in_menu' => false ),
				),
				'flows'   => array(
					'help' => array(
						'start' => 'ask',
						'nodes' => array(
							'ask'  => array(
								'type'    => 'choices',
								'choices' => array(
									array(
										'label'  => 'Go',
										'action' => 'ready',
									),
									array(
										'label'  => 'Gone',
										'action' => 'incomplete',
									),
									array(
										'label'  => 'Gone but continues',
										'action' => 'form',
										'next'   => 'done',
									),
								),
							),
							'done' => array(
								'type'    => 'action',
								'actions' => array( 'helper', 'incomplete' ),
							),
						),
					),
				),
			)
		);

		// A type from a module that is no longer active, stored earlier.
		$stored              = get_option( Settings::OPTION );
		$stored['actions'][] = $this->action( 'custom_thing', array( 'x' => 1 ), 'module' );
		update_option( Settings::OPTION, $stored, false );
		$this->settings()->flush();

		$data = $this->request()->get_data();
		$ask  = $data['flows']['help']['nodes']['ask'];

		$this->assertSame( array( 'ready', 'helper' ), array_column( $data['actions'], 'id' ) );
		$this->assertFalse( $data['actions'][1]['show_in_menu'] );
		$this->assertSame( array( 'Go', 'Gone but continues' ), array_column( $ask['choices'], 'label' ) );
		$this->assertSame( '', $ask['choices'][1]['action'] );
		$this->assertSame( 'done', $ask['choices'][1]['next'] );
		$this->assertSame( array( 'helper' ), $data['flows']['help']['nodes']['done']['actions'] );
	}

	public function test_flow_actions_need_their_flow(): void {
		$stored            = get_option( Settings::OPTION, array() );
		$stored['actions'] = array( $this->action( 'flow', array( 'flow' => 'missing' ) ) );
		update_option( Settings::OPTION, $stored, false );
		$this->settings()->flush();

		$this->assertSame( array(), $this->request()->get_data()['actions'] );
	}

	public function test_forms_consent_and_urls(): void {
		$this->settings()->update(
			array(
				'actions' => array( $this->action( 'form', array( 'form' => 'contact' ), 'contact' ) ),
				'forms'   => array( 'contact' => self::form() ),
			)
		);

		$data = $this->request()->get_data();

		$this->assertSame( array( 'contact' ), array_column( $data['actions'], 'id' ) );
		$this->assertSame( array( 'title', 'submit_label', 'success_message', 'fields' ), array_keys( $data['forms']['contact'] ) );
		$this->assertSame(
			array( 'id', 'type', 'label', 'required', 'placeholder', 'max_length', 'options' ),
			array_keys( $data['forms']['contact']['fields'][0] )
		);
		$this->assertSame( 200, $data['forms']['contact']['fields'][0]['max_length'] );
		$this->assertSame( array( 'text', 'policy_url', 'policy_label' ), array_keys( $data['consent'] ) );
		$this->assertNotSame( '', $data['consent']['text'], 'Translated default.' );
		$this->assertStringContainsString( 'devrivo-chatbot/v1/session', urldecode( $data['session_url'] ) );
		$this->assertStringContainsString( 'devrivo-chatbot/v1/forms/{form}/submit', urldecode( $data['submit_url'] ) );
	}

	public function test_form_actions_need_their_form(): void {
		$stored            = get_option( Settings::OPTION, array() );
		$stored['actions'] = array( $this->action( 'form', array( 'form' => 'missing' ) ) );
		update_option( Settings::OPTION, $stored, false );
		$this->settings()->flush();

		$data = $this->request()->get_data();

		$this->assertSame( array(), $data['actions'] );
		$this->assertInstanceOf( \stdClass::class, $data['forms'], 'Empty forms are {}.' );
	}

	public function test_hours_use_the_site_timezone_when_empty(): void {
		update_option( 'timezone_string', 'Europe/London' );
		$this->settings()->update(
			array(
				'behaviour' => array(
					'hours' => array(
						'schedule'    => array(
							'mon' => array(
								array(
									'open'  => '09:00',
									'close' => '17:00',
								),
							),
						),
						'show_status' => true,
					),
				),
			)
		);

		$hours = $this->request()->get_data()['behaviour']['hours'];

		$this->assertSame( 'Europe/London', $hours['timezone'] );
		$this->assertTrue( $hours['show_status'] );
		$this->assertSame( '09:00', $hours['schedule']['mon'][0]['open'] );
		$this->assertSame( Schema::DAYS, array_keys( $hours['schedule'] ) );
	}

	public function test_offers_pointing_to_unknown_actions_are_dropped(): void {
		$this->settings()->update(
			array(
				'actions' => array(
					$this->action( 'link', array( 'url' => 'https://example.com/book' ), 'book' ),
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
					$this->action( 'link', array( 'url' => 'https://example.com/book' ), 'book' ),
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
	 * An action of the given type.
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $config Config.
	 * @param string|null          $id     ID (defaults to the type).
	 * @return array<string, mixed>
	 */
	private function action( string $type, array $config, ?string $id = null ): array {
		return array(
			'id'     => $id ?? str_replace( '_', '-', $type ),
			'label'  => ucfirst( $type ),
			'type'   => $type,
			'config' => $config,
		);
	}

	/**
	 * A minimal valid form.
	 *
	 * @return array<string, mixed>
	 */
	private static function form(): array {
		return array(
			'title'  => 'Contact',
			'fields' => array(
				array(
					'id'       => 'email',
					'type'     => 'email',
					'label'    => 'Email',
					'required' => true,
				),
			),
		);
	}

	/**
	 * A minimal valid flow.
	 *
	 * @return array<string, mixed>
	 */
	private static function flow(): array {
		return array(
			'title' => 'Help',
			'start' => 'hi',
			'nodes' => array(
				'hi' => array(
					'type' => 'end',
					'text' => 'Hello there',
				),
			),
		);
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
