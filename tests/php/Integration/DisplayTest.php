<?php
/**
 * Display rules tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Frontend\Display;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_UnitTestCase;

final class DisplayTest extends WP_UnitTestCase {

	private int $post_id;

	private int $page_id;

	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::DISPLAY_OPTION );
		$this->post_id = self::factory()->post->create();
		$this->page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_ACCEPT'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_disabled_widget_is_not_displayed(): void {
		$this->go_to( get_permalink( $this->post_id ) );

		$this->assertFalse( ( new Display() )->should_display() );
	}

	public function test_mode_all_displays_everywhere(): void {
		$this->rules( array( 'mode' => 'all' ) );

		$this->go_to( home_url( '/' ) );
		$this->assertTrue( ( new Display() )->should_display() );

		$this->go_to( get_permalink( $this->post_id ) );
		$this->assertTrue( ( new Display() )->should_display() );
	}

	public function test_include_by_post_id(): void {
		$this->rules(
			array(
				'mode'     => 'include',
				'post_ids' => array( $this->post_id ),
			)
		);

		$this->assertTrue( $this->displayed_on( get_permalink( $this->post_id ) ) );
		$this->assertFalse( $this->displayed_on( get_permalink( $this->page_id ) ) );
		$this->assertFalse( $this->displayed_on( home_url( '/' ) ), 'Include mode needs a singular view.' );
	}

	public function test_include_by_post_type(): void {
		$this->rules(
			array(
				'mode'       => 'include',
				'post_types' => array( 'page' ),
			)
		);

		$this->assertTrue( $this->displayed_on( get_permalink( $this->page_id ) ) );
		$this->assertFalse( $this->displayed_on( get_permalink( $this->post_id ) ) );
	}

	public function test_exclude_by_post_id_and_type(): void {
		$this->rules(
			array(
				'mode'     => 'exclude',
				'post_ids' => array( $this->post_id ),
			)
		);
		$this->assertFalse( $this->displayed_on( get_permalink( $this->post_id ) ) );
		$this->assertTrue( $this->displayed_on( get_permalink( $this->page_id ) ) );
		$this->assertTrue( $this->displayed_on( home_url( '/' ) ) );

		$this->rules(
			array(
				'mode'       => 'exclude',
				'post_ids'   => array(),
				'post_types' => array( 'page' ),
			)
		);
		$this->assertFalse( $this->displayed_on( get_permalink( $this->page_id ) ) );
		$this->assertTrue( $this->displayed_on( get_permalink( $this->post_id ) ) );
	}

	public function test_admin_context_is_never_displayed(): void {
		$this->rules( array( 'mode' => 'all' ) );
		set_current_screen( 'dashboard' );

		$this->assertFalse( ( new Display() )->should_display() );
	}

	public function test_json_request_is_never_displayed(): void {
		$this->rules( array( 'mode' => 'all' ) );
		$this->go_to( home_url( '/' ) );
		$_SERVER['HTTP_ACCEPT'] = 'application/json';

		$this->assertFalse( ( new Display() )->should_display() );
	}

	public function test_feed_is_never_displayed(): void {
		$this->rules( array( 'mode' => 'all' ) );

		$this->assertFalse( $this->displayed_on( get_feed_link() ) );
	}

	public function test_filter_overrides_rules_and_receives_context(): void {
		$this->rules(
			array(
				'mode'     => 'include',
				'post_ids' => array( $this->page_id ),
			)
		);

		$seen   = array();
		$filter = static function ( bool $display, array $context ) use ( &$seen ): bool {
			$seen = $context;
			return ! $display;
		};
		add_filter( 'devrivo_chatbot_should_display', $filter, 10, 2 );

		$displayed = $this->displayed_on( get_permalink( $this->post_id ) );
		remove_filter( 'devrivo_chatbot_should_display', $filter, 10 );

		$this->assertTrue( $displayed );
		$this->assertSame(
			array(
				'post_id'   => $this->post_id,
				'post_type' => 'post',
			),
			$seen
		);
	}

	public function test_result_is_memoised(): void {
		$this->rules( array( 'mode' => 'all' ) );
		$this->go_to( home_url( '/' ) );

		$display = new Display();
		$this->assertTrue( $display->should_display() );

		$this->rules( array( 'mode' => 'include' ) );
		$this->assertTrue( $display->should_display() );
	}

	/**
	 * Enables the widget with the given rules.
	 *
	 * @param array<string, mixed> $rules Rules.
	 */
	private function rules( array $rules ): void {
		( new Settings() )->update(
			array(
				'display' => array(
					'enabled' => true,
					'rules'   => array_merge(
						array(
							'mode'       => 'all',
							'post_ids'   => array(),
							'post_types' => array(),
							'devices'    => array( 'desktop', 'mobile' ),
						),
						$rules
					),
				),
			)
		);
	}

	private function displayed_on( string $url ): bool {
		$this->go_to( $url );

		return ( new Display() )->should_display();
	}
}
