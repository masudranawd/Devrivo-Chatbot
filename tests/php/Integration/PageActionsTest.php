<?php
/**
 * Page-specific quick actions: settings, validation, the question type,
 * public config, boot data and import.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Frontend\Assets;
use DevRivo\Chatbot\Core\Frontend\Display;
use DevRivo\Chatbot\Core\Frontend\WidgetConfig;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Engine\ActionTypes;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class PageActionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		delete_option( PresetApplier::BACKUP_OPTION );
		$this->settings()->flush();
		Plugin::instance()->presets()->flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	public function test_valid_rules_and_pinned_actions_save(): void {
		$saved = $this->settings()->update(
			array(
				'actions'      => array( $this->action( 'call', array( 'phone' => '123 456' ), 'call' ) + array( 'pinned' => true ), $this->question( 'cost', 'How much does it cost?' ) ),
				'page_actions' => array(
					$this->rule(
						'implants',
						array(
							'post_ids' => array( 5 ),
							'paths'    => array( '/services/implants/' ),
						)
					),
				),
			)
		);

		$this->assertIsArray( $saved );
		$this->assertTrue( $saved['actions'][0]['pinned'] );
		$this->assertFalse( $this->settings()->get( 'actions' )[1]['pinned'] ?? false );
		$this->assertArrayNotHasKey( 'include_children', $saved['page_actions'][0], 'List items keep only the keys sent.' );
		$this->assertTrue( ( new WidgetConfig( $this->settings() ) )->build( 'en_US' )['page_actions'][0]['include_children'], 'Default on.' );
		$this->assertSame( array( 'cost' ), $saved['page_actions'][0]['actions'] );
	}

	/**
	 * @dataProvider invalid_rules
	 *
	 * @param array<string, mixed> $patch Rule keys.
	 */
	public function test_invalid_rules_are_rejected( array $patch ): void {
		$result = $this->settings()->update(
			array(
				'actions'      => array( $this->question( 'cost', 'Cost?' ), $this->question( 'time', 'How long?' ) ),
				'page_actions' => array( $this->rule( 'r', $patch ) ),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->settings()->get( 'page_actions' ), 'Nothing saved.' );
	}

	/**
	 * Invalid rules.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function invalid_rules(): array {
		return array(
			'no pages or paths' => array( array() ),
			'relative path'     => array( array( 'paths' => array( 'services/' ) ) ),
			'path with a space' => array( array( 'paths' => array( '/a b/' ) ) ),
			'too long path'     => array( array( 'paths' => array( '/' . str_repeat( 'a', 200 ) ) ) ),
			'too many paths'    => array( array( 'paths' => array_map( static fn( int $i ): string => "/p{$i}/", range( 1, 11 ) ) ) ),
			'too many pages'    => array( array( 'post_ids' => range( 1, 51 ) ) ),
			'no actions'        => array(
				array(
					'paths'   => array( '/a/' ),
					'actions' => array(),
				),
			),
			'too many actions'  => array(
				array(
					'paths'   => array( '/a/' ),
					'actions' => array( 'cost', 'time', 'a', 'b', 'c', 'd' ),
				),
			),
			'repeated action'   => array(
				array(
					'paths'   => array( '/a/' ),
					'actions' => array( 'cost', 'cost' ),
				),
			),
			'unknown action'    => array(
				array(
					'paths'   => array( '/a/' ),
					'actions' => array( 'cost', 'gone' ),
				),
			),
			'empty name'        => array(
				array(
					'paths' => array( '/a/' ),
					'name'  => '',
				),
			),
			'unknown key'       => array(
				array(
					'paths' => array( '/a/' ),
					'x'     => 1,
				),
			),
		);
	}

	public function test_validator_field_errors_and_unique_ids(): void {
		$result = $this->settings()->update(
			array(
				'actions'      => array( $this->question( 'cost', 'Cost?' ) ),
				'page_actions' => array(
					$this->rule( 'same', array( 'paths' => array( '/a/' ) ) ),
					$this->rule( 'same', array( 'post_ids' => array( 3 ) ) ),
					$this->rule(
						'other',
						array(
							'paths'   => array( '/b/' ),
							'actions' => array( 'cost', 'gone' ),
						)
					),
				),
			)
		);

		$this->assertWPError( $result );
		$params = $result->get_error_data()['params'];
		$this->assertArrayHasKey( 'page_actions[1][id]', $params );
		$this->assertArrayHasKey( 'page_actions[2][actions][1]', $params );

		// Deleting an action a rule still uses is refused.
		$this->settings()->update(
			array(
				'actions'      => array( $this->question( 'cost', 'Cost?' ) ),
				'page_actions' => array( $this->rule( 'r', array( 'paths' => array( '/a/' ) ) ) ),
			)
		);
		$deleted = $this->settings()->update( array( 'actions' => array() ) );
		$this->assertWPError( $deleted );
		$this->assertArrayHasKey( 'page_actions[0][actions][0]', $deleted->get_error_data()['params'] );
	}

	public function test_up_to_100_quick_actions_can_be_saved(): void {
		$actions = array_map( fn( int $i ): array => $this->question( "q{$i}", "Question {$i}?" ), range( 1, 100 ) );

		$saved = $this->settings()->update( array( 'actions' => $actions ) );
		$this->assertIsArray( $saved );
		$this->assertCount( 100, $saved['actions'] );

		$actions[] = $this->question( 'q101', 'One too many?' );
		$this->assertWPError( $this->settings()->update( array( 'actions' => $actions ) ) );
		$this->settings()->flush();
		$this->assertCount( 100, $this->settings()->get( 'actions' ), 'Nothing saved.' );
	}

	public function test_question_type_is_supported_and_needs_its_text(): void {
		$types = ActionTypes::all();

		$this->assertTrue( $types['ai_prompt']['supported'] );
		$this->assertSame( array( 'prompt' ), $types['ai_prompt']['required'] );
		$this->assertTrue( ActionTypes::is_ready( $this->question( 'q', 'Hello?' ), $types ) );
		$this->assertFalse( ActionTypes::is_ready( $this->question( 'q', '' ), $types ), 'Incomplete.' );
		$this->assertWPError( $this->settings()->update( array( 'actions' => array( $this->question( 'q', str_repeat( 'a', 301 ) ) ) ) ) );
	}

	public function test_public_config_shape_and_filtering(): void {
		$this->settings()->update(
			array(
				'actions'      => array(
					$this->action( 'call', array( 'phone' => '123 456' ), 'call' ) + array( 'pinned' => true ),
					$this->question( 'cost', 'How much?' ),
					$this->question( 'empty', '' ),
					$this->action( 'link', array( 'url' => '' ), 'broken' ),
				),
				'page_actions' => array(
					$this->rule(
						'implants',
						array(
							'name'             => 'Secret admin name',
							'post_ids'         => array( 5 ),
							'paths'            => array( '/services/implants/' ),
							'include_children' => false,
							'actions'          => array( 'empty', 'cost', 'call' ),
						)
					),
					$this->rule(
						'only-broken',
						array(
							'paths'   => array( '/x/' ),
							'actions' => array( 'empty', 'broken' ),
						)
					),
				),
			)
		);

		$config = ( new WidgetConfig( $this->settings() ) )->build( 'en_US' );

		$this->assertSame( array( 'call', 'cost' ), array_column( $config['actions'], 'id' ) );
		$this->assertTrue( $config['actions'][0]['pinned'] );
		$this->assertFalse( $config['actions'][1]['pinned'] );
		$this->assertSame( array( 'prompt' ), array_keys( (array) $config['actions'][1]['config'] ) );
		$this->assertSame(
			array(
				array(
					'post_ids'         => array( 5 ),
					'paths'            => array( '/services/implants/' ),
					'include_children' => false,
					'actions'          => array( 'cost', 'call' ),
				),
			),
			$config['page_actions'],
			'Hidden actions are dropped (order kept); a rule left empty is dropped; no IDs or names.'
		);
		$this->assertStringNotContainsString( 'Secret admin name', (string) wp_json_encode( $config ) );
	}

	public function test_boot_has_the_post_id_and_home_path(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$boot = static fn(): array => ( new Assets( new Display() ) )->boot();

		$this->go_to( get_permalink( $page ) );
		$this->assertSame( $page, $boot()['postId'] );
		$this->assertSame( '/', $boot()['homePath'] );

		$this->go_to( home_url( '/' ) );
		$this->assertSame( 0, $boot()['postId'], 'Not a single post or page.' );

		$home = static fn(): string => 'https://example.org/pearldentalcare';
		add_filter( 'home_url', $home );
		$this->assertSame( '/pearldentalcare/', Assets::home_path() );
		remove_filter( 'home_url', $home );
	}

	public function test_import_keeps_paths_and_drops_page_ids(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$file = array(
			'meta'     => array(
				'plugin'           => 'devrivo-chatbot',
				'settings_version' => Settings::VERSION,
			),
			'settings' => array(
				'actions'      => array( $this->question( 'cost', 'Cost?' ) ),
				'page_actions' => array(
					$this->rule(
						'both',
						array(
							'post_ids' => array( 9 ),
							'paths'    => array( '/a/' ),
						)
					),
					$this->rule( 'ids-only', array( 'post_ids' => array( 9 ) ) ),
				),
			),
		);

		$response = $this->import( $file, true );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->settings()->flush();
		$rules = $this->settings()->get( 'page_actions' );
		$this->assertSame( array( 'both' ), array_column( $rules, 'id' ), 'A rule with only page IDs is dropped.' );
		$this->assertSame( array(), $rules[0]['post_ids'] );
		$this->assertSame( array( '/a/' ), $rules[0]['paths'] );

		$this->assertSame( 200, $this->import( $file, false )->get_status() );
		$this->settings()->flush();
		$this->assertSame( array( 9 ), $this->settings()->get( 'page_actions' )[0]['post_ids'], 'Kept without skip_site_specific.' );
	}

	public function test_presets_never_set_page_actions(): void {
		$this->setExpectedIncorrectUsage( PresetRepository::class . '::all' );
		$add = function ( array $presets ): array {
			$presets[] = array(
				'id'       => 'paged',
				'version'  => 1,
				'title'    => 'Paged',
				'settings' => array( 'page_actions' => array( $this->rule( 'r', array( 'paths' => array( '/a/' ) ) ) ) ),
			);
			return $presets;
		};
		add_filter( 'devrivo_chatbot_presets', $add );
		$this->assertNull( ( new PresetRepository() )->get( 'paged' ) );
		remove_filter( 'devrivo_chatbot_presets', $add );

		foreach ( ( new PresetRepository() )->all() as $id => $preset ) {
			$this->assertArrayNotHasKey( 'page_actions', $preset['settings'], $id );
		}
	}

	/**
	 * POST /settings/import.
	 *
	 * @param array<string, mixed> $file File.
	 * @param bool                 $skip skip_site_specific.
	 */
	private function import( array $file, bool $skip ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/devrivo-chatbot/v1/settings/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'data'               => $file,
					'skip_site_specific' => $skip,
				)
			)
		);

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A rule.
	 *
	 * @param string               $id    ID.
	 * @param array<string, mixed> $patch Keys.
	 * @return array<string, mixed>
	 */
	private function rule( string $id, array $patch = array() ): array {
		return array_merge(
			array(
				'id'      => $id,
				'name'    => 'Rule ' . $id,
				'actions' => array( 'cost' ),
			),
			$patch
		);
	}

	/**
	 * A question action.
	 *
	 * @param string $id     ID.
	 * @param string $prompt Question.
	 * @return array<string, mixed>
	 */
	private function question( string $id, string $prompt ): array {
		return $this->action( 'ai_prompt', array( 'prompt' => $prompt ), $id ) + array( 'show_in_menu' => false );
	}

	/**
	 * An action.
	 *
	 * @param string               $type   Type.
	 * @param array<string, mixed> $config Config.
	 * @param string               $id     ID.
	 * @return array<string, mixed>
	 */
	private function action( string $type, array $config, string $id ): array {
		return array(
			'id'     => $id,
			'label'  => ucfirst( $id ),
			'type'   => $type,
			'config' => $config,
		);
	}

	private function settings(): Settings {
		return Plugin::instance()->settings();
	}
}
