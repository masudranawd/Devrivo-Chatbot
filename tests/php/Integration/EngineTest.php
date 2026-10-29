<?php
/**
 * Action types, flows schema and cross-section validation tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Engine\ActionTypes;
use WP_Error;
use WP_UnitTestCase;

final class EngineTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION );
		$this->settings = new Settings();
	}

	/**
	 * Valid configs, including the empty values presets leave for the admin.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function valid_configs(): array {
		return array(
			'link'            => array( 'link', array( 'url' => 'https://example.com/book?x=1#top' ) ),
			'link empty'      => array( 'link', array( 'url' => '' ) ),
			'link same tab'   => array(
				'link',
				array(
					'url'     => 'http://example.com',
					'new_tab' => false,
				),
			),
			'call'            => array( 'call', array( 'phone' => '+44 (0)20 7946-0000' ) ),
			'call empty'      => array( 'call', array( 'phone' => '' ) ),
			'whatsapp'        => array(
				'whatsapp',
				array(
					'phone'   => '+44 7700 900123',
					'message' => 'Hello',
				),
			),
			'whatsapp empty'  => array( 'whatsapp', array() ),
			'email'           => array(
				'email',
				array(
					'email'   => 'team@example.com',
					'subject' => 'Hi',
				),
			),
			'email empty'     => array( 'email', array( 'email' => '' ) ),
			'flow empty'      => array( 'flow', array( 'flow' => '' ) ),
			'form'            => array( 'form', array( 'form' => 'contact' ) ),
			'page_search'     => array( 'page_search', array( 'post_types' => array( 'post' ) ) ),
			'ai_prompt'       => array( 'ai_prompt', array( 'prompt' => 'Where are you?' ) ),
			'custom_hook'     => array( 'custom_hook', array( 'hook' => 'my-hook' ) ),
			'unknown type'    => array( 'from_module', array( 'anything' => array( 1, 2 ) ) ),
			'link legacy key' => array( 'link', array( 'url' => '' ) ),
		);
	}

	/**
	 * @dataProvider valid_configs
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $config Config.
	 */
	public function test_valid_action_configs_are_saved( string $type, array $config ): void {
		$result = $this->settings->update( array( 'actions' => array( $this->action( $type, $config ) ) ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Invalid configs.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function invalid_configs(): array {
		return array(
			'link javascript' => array( 'link', array( 'url' => 'javascript:alert(1)' ) ),
			'link relative'   => array( 'link', array( 'url' => '/contact' ) ),
			'link spaces'     => array( 'link', array( 'url' => 'https://a b.com' ) ),
			'link unknown'    => array(
				'link',
				array(
					'url'   => '',
					'extra' => 1,
				),
			),
			'call letters'    => array( 'call', array( 'phone' => 'call me' ) ),
			'call too short'  => array( 'call', array( 'phone' => '12' ) ),
			'whatsapp dashes' => array( 'whatsapp', array( 'phone' => '+44-7700-900' ) ),
			'whatsapp long'   => array( 'whatsapp', array( 'message' => str_repeat( 'x', 301 ) ) ),
			'email invalid'   => array( 'email', array( 'email' => 'not-an-email' ) ),
			'email subject'   => array(
				'email',
				array(
					'email'   => 'a@example.com',
					'subject' => str_repeat( 'x', 151 ),
				),
			),
			'flow bad id'     => array( 'flow', array( 'flow' => 'Bad ID' ) ),
			'ai_prompt long'  => array( 'ai_prompt', array( 'prompt' => str_repeat( 'x', 301 ) ) ),
		);
	}

	/**
	 * @dataProvider invalid_configs
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $config Config.
	 */
	public function test_invalid_action_configs_are_rejected( string $type, array $config ): void {
		$result = $this->settings->update( array( 'actions' => array( $this->action( $type, $config ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'actions[0][config]', $result->get_error_data()['params'] );
		$this->assertSame( array(), $this->settings->get( 'actions' ), 'Nothing saved.' );
	}

	public function test_types_report_support_and_readiness(): void {
		$types = ActionTypes::all();

		foreach ( array( 'link', 'call', 'whatsapp', 'email', 'flow' ) as $type ) {
			$this->assertTrue( $types[ $type ]['supported'], $type );
			$this->assertSame( 'client', $types[ $type ]['runtime'] );
		}
		foreach ( array( 'form', 'page_search', 'ai_prompt', 'custom_hook' ) as $type ) {
			$this->assertFalse( $types[ $type ]['supported'], $type );
		}

		$this->assertTrue( ActionTypes::is_ready( $this->action( 'call', array( 'phone' => '123 456' ) ), $types ) );
		$this->assertFalse( ActionTypes::is_ready( $this->action( 'call', array( 'phone' => '' ) ), $types ), 'Incomplete.' );
		$this->assertFalse( ActionTypes::is_ready( $this->action( 'call', array() ), $types ), 'Incomplete.' );
		$this->assertFalse( ActionTypes::is_ready( $this->action( 'form', array( 'form' => 'x' ) ), $types ), 'Unsupported.' );
		$this->assertFalse( ActionTypes::is_ready( $this->action( 'nope', array() ), $types ), 'Unknown.' );
	}

	public function test_action_types_filter_adds_types_and_skips_broken_ones(): void {
		$filter = static function ( array $types ): array {
			$types['booking'] = array(
				'schema'    => array(
					'type'                 => 'object',
					'properties'           => array( 'slot' => array( 'type' => 'string' ) ),
					'additionalProperties' => false,
				),
				'supported' => true,
				'required'  => array( 'slot' ),
			);
			$types['Bad-ID']  = array( 'schema' => array() );
			$types['broken']  = 'nope';
			return $types;
		};
		add_filter( 'devrivo_chatbot_action_types', $filter );

		$types = ActionTypes::all();
		remove_filter( 'devrivo_chatbot_action_types', $filter );

		$this->assertArrayHasKey( 'booking', $types );
		$this->assertTrue( $types['booking']['supported'] );
		$this->assertSame( 'client', $types['booking']['runtime'] );
		$this->assertArrayNotHasKey( 'Bad-ID', $types );
		$this->assertArrayNotHasKey( 'broken', $types );
	}

	public function test_show_in_menu_defaults_to_true(): void {
		$this->settings->update( array( 'actions' => array( $this->action( 'call', array( 'phone' => '' ) ) ) ) );

		$this->assertTrue( ( new Settings() )->get( 'actions' )[0]['show_in_menu'] ?? true );
		$this->assertInstanceOf(
			WP_Error::class,
			$this->settings->update( array( 'actions' => array( $this->action( 'call', array() ) + array( 'show_in_menu' => 'maybe' ) ) ) )
		);
	}

	public function test_duplicate_action_ids_are_rejected(): void {
		$result = $this->settings->update(
			array(
				'actions' => array(
					$this->action( 'call', array(), 'same' ),
					$this->action( 'link', array(), 'same' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'actions[1][id]', $result->get_error_data()['params'] );
	}

	public function test_valid_flow_is_saved_with_defaults_filled(): void {
		$result = $this->settings->update( $this->flow_settings() );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$node = $result['flows']['help']['nodes']['start'];
		$this->assertSame( '', $node['condition'] );
		$this->assertSame( array(), $node['actions'] );
	}

	/**
	 * Broken references.
	 *
	 * @return array<string, array{0: array<int, string|int>, 1: mixed, 2: string}>
	 */
	public static function broken_flows(): array {
		$node = array( 'flows', 'help', 'nodes' );

		return array(
			'start'           => array( array( 'flows', 'help', 'start' ), 'nowhere', 'flows[help][start]' ),
			'next'            => array( array( ...$node, 'start', 'next' ), 'nowhere', 'flows[help][nodes][start][next]' ),
			'then'            => array( array( ...$node, 'check', 'then' ), 'nowhere', 'flows[help][nodes][check][then]' ),
			'else'            => array( array( ...$node, 'check', 'else' ), 'nowhere', 'flows[help][nodes][check][else]' ),
			'unknown'         => array( array( ...$node, 'check', 'unknown' ), 'nowhere', 'flows[help][nodes][check][unknown]' ),
			'choice next'     => array( array( ...$node, 'ask', 'choices', 0, 'next' ), 'nowhere', 'flows[help][nodes][ask][choices][0][next]' ),
			'choice action'   => array( array( ...$node, 'ask', 'choices', 1, 'action' ), 'nowhere', 'flows[help][nodes][ask][choices][1][action]' ),
			'choice empty'    => array( array( ...$node, 'ask', 'choices', 0 ), array( 'label' => 'Dead end' ), 'flows[help][nodes][ask][choices][0]' ),
			'node action'     => array( array( ...$node, 'cta', 'actions' ), array( 'nowhere' ), 'flows[help][nodes][cta][actions][0]' ),
			'other flow node' => array( array( ...$node, 'start', 'next' ), 'other-node', 'flows[help][nodes][start][next]' ),
			'flow action'     => array( array( 'actions', 1, 'config', 'flow' ), 'nowhere', 'actions[1][config][flow]' ),
		);
	}

	/**
	 * @dataProvider broken_flows
	 *
	 * @param array<int, string|int> $path  Path of the value to break.
	 * @param mixed                  $value Broken value.
	 * @param string                 $field Expected field in the error.
	 */
	public function test_broken_flow_references_are_rejected( array $path, mixed $value, string $field ): void {
		$settings = $this->flow_settings();
		$target   = &$settings;
		foreach ( $path as $key ) {
			$target = &$target[ $key ];
		}
		$target = $value;
		unset( $target );

		$result = $this->settings->update( $settings );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( $field, $result->get_error_data()['params'] );
		$this->assertSame( array(), $this->settings->get( 'flows' ), 'All or nothing.' );
		$this->assertSame( array(), $this->settings->get( 'actions' ), 'All or nothing.' );
	}

	public function test_map_keys_must_be_ids(): void {
		$settings = $this->flow_settings();

		$bad_flow                  = $settings;
		$bad_flow['flows']['Bad!'] = $bad_flow['flows']['help'];
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $bad_flow ) );

		$bad_node                                     = $settings;
		$bad_node['flows']['help']['nodes']['Node 1'] = array( 'type' => 'end' );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $bad_node ) );

		$bad_form = array( 'forms' => array( 'Bad Form' => array() ) );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $bad_form ) );
	}

	public function test_node_schema_is_strict(): void {
		$settings = $this->flow_settings();

		$unknown_type = $settings;
		$unknown_type['flows']['help']['nodes']['end']['type'] = 'jump';
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $unknown_type ) );

		$extra_key = $settings;
		$extra_key['flows']['help']['nodes']['end']['goto'] = 'x';
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $extra_key ) );

		$too_many_ctas = $settings;
		$too_many_ctas['flows']['help']['nodes']['cta']['actions'] = array( 'a', 'b', 'c', 'd' );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $too_many_ctas ) );

		$long_label = $settings;
		$long_label['flows']['help']['nodes']['ask']['choices'][0]['label'] = str_repeat( 'x', 61 );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( $long_label ) );
	}

	public function test_flows_are_replaced_so_they_can_be_removed(): void {
		$this->settings->update( $this->flow_settings() );

		$second                    = $this->flow_settings();
		$second['flows']['second'] = $second['flows']['help'];
		$this->settings->update( $second );
		$this->assertCount( 3, $this->settings->get( 'flows' ) );

		$this->settings->update( $this->flow_settings() );
		$this->assertSame( array( 'help', 'other' ), array_keys( $this->settings->get( 'flows' ) ) );
	}

	public function test_problems_already_stored_do_not_block_other_saves(): void {
		// An older version stored a flow action whose flow is missing.
		update_option(
			Settings::OPTION,
			array(
				'version' => Settings::VERSION,
				'actions' => array( $this->action( 'flow', array( 'flow' => 'gone' ) ) ),
			),
			false
		);
		$this->settings->flush();

		$this->assertIsArray( $this->settings->update( array( 'appearance' => array( 'radius' => 4 ) ) ) );

		$result = $this->settings->update( array( 'actions' => array( $this->action( 'flow', array( 'flow' => 'also-gone' ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $result, 'New problems still block the save.' );
	}

	public function test_hours_are_validated(): void {
		$valid = $this->settings->update(
			array(
				'behaviour' => array(
					'hours' => array(
						'timezone'    => 'Europe/London',
						'schedule'    => array(
							'mon' => array(
								array(
									'open'  => '09:00',
									'close' => '12:30',
								),
								array(
									'open'  => '13:30',
									'close' => '17:00',
								),
							),
							'sat' => array(
								array(
									'open'  => '00:00',
									'close' => '23:59',
								),
							),
						),
						'show_status' => true,
					),
				),
			)
		);
		$this->assertIsArray( $valid );
		$this->assertSame( array(), $valid['behaviour']['hours']['schedule']['sun'] );
		$this->assertTrue( $valid['behaviour']['utm'] );

		$invalid = array(
			'timezone'      => array( 'timezone' => 'Mars/Olympus' ),
			'time format'   => array(
				'schedule' => array(
					'mon' => array(
						array(
							'open'  => '9:00',
							'close' => '17:00',
						),
					),
				),
			),
			'hour 24'       => array(
				'schedule' => array(
					'mon' => array(
						array(
							'open'  => '09:00',
							'close' => '24:00',
						),
					),
				),
			),
			'close first'   => array(
				'schedule' => array(
					'tue' => array(
						array(
							'open'  => '17:00',
							'close' => '09:00',
						),
					),
				),
			),
			'same time'     => array(
				'schedule' => array(
					'tue' => array(
						array(
							'open'  => '09:00',
							'close' => '09:00',
						),
					),
				),
			),
			'unknown day'   => array( 'schedule' => array( 'funday' => array() ) ),
			'four ranges'   => array(
				'schedule' => array(
					'wed' => array_fill(
						0,
						4,
						array(
							'open'  => '09:00',
							'close' => '10:00',
						)
					),
				),
			),
			'missing close' => array( 'schedule' => array( 'wed' => array( array( 'open' => '09:00' ) ) ) ),
		);
		foreach ( $invalid as $label => $hours ) {
			$this->assertInstanceOf( WP_Error::class, $this->settings->update( array( 'behaviour' => array( 'hours' => $hours ) ) ), $label );
		}

		$error = $this->settings->update( array( 'behaviour' => array( 'hours' => $invalid['close first'] ) ) );
		$this->assertArrayHasKey( 'behaviour[hours][schedule][tue][0]', $error->get_error_data()['params'] );
	}

	/**
	 * An action.
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $config Config.
	 * @param string               $id     ID.
	 * @return array<string, mixed>
	 */
	private function action( string $type, array $config, string $id = 'a' ): array {
		return array(
			'id'     => $id,
			'label'  => 'Label',
			'type'   => $type,
			'config' => $config,
		);
	}

	/**
	 * Valid actions + a flow using every node type.
	 *
	 * @return array<string, mixed>
	 */
	private function flow_settings(): array {
		return array(
			'actions' => array(
				$this->action( 'call', array( 'phone' => '+1 555 0100' ), 'call' ),
				$this->action( 'flow', array( 'flow' => 'help' ), 'help' ),
			),
			'flows'   => array(
				'help'  => array(
					'title' => 'Help',
					'start' => 'start',
					'nodes' => array(
						'start' => array(
							'type' => 'message',
							'text' => 'Hi',
							'next' => 'ask',
						),
						'ask'   => array(
							'type'    => 'choices',
							'text'    => 'Pick one',
							'choices' => array(
								array(
									'label' => 'Check hours',
									'next'  => 'check',
								),
								array(
									'label'  => 'Call',
									'action' => 'call',
									'urgent' => true,
								),
							),
						),
						'check' => array(
							'type'      => 'condition',
							'condition' => 'open_now',
							'then'      => 'cta',
							'else'      => 'end',
						),
						'cta'   => array(
							'type'    => 'action',
							'text'    => 'Call us',
							'actions' => array( 'call' ),
						),
						'end'   => array(
							'type' => 'end',
							'text' => 'Bye',
						),
					),
				),
				'other' => array(
					'start' => 'other-node',
					'nodes' => array( 'other-node' => array( 'type' => 'end' ) ),
				),
			),
		);
	}
}
