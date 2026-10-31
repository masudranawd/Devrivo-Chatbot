<?php
/**
 * Setup wizard tests: site scan, business profile, setup state routes,
 * Plugins screen notice, and that presets and imports never touch the
 * business profile or setup state.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Admin\SetupNotice;
use DevRivo\Chatbot\Admin\SiteScan;
use DevRivo\Chatbot\AI\PromptBuilder;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class SetupTest extends WP_UnitTestCase {

	private const NS = '/devrivo-chatbot/v1';

	private const BUSINESS = array(
		'name'        => 'Smile Dental',
		'phone'       => '+44 (20) 7946-0000',
		'whatsapp'    => '+44 7700 900123',
		'email'       => 'hello@smile.example',
		'booking_url' => 'https://smile.example/book/',
		'address'     => "1 High Street\nLondon",
	);

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Settings::OPTION );
		delete_option( Settings::DISPLAY_OPTION );
		delete_option( PresetApplier::BACKUP_OPTION );
		update_option( 'active_plugins', array() );
		update_option( 'show_on_front', 'posts' );
		update_option( 'blogname', 'Test Site' );
		update_option( 'blogdescription', '' );
		$this->settings()->flush();
		Plugin::instance()->presets()->flush();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		set_current_screen( 'front' );

		parent::tear_down();
	}

	public function test_routes_need_the_manage_capability(): void {
		$routes = array(
			array( 'GET', '/setup/scan', array() ),
			array( 'POST', '/setup/step', array( 'step' => 'ai' ) ),
			array( 'POST', '/setup/complete', array( 'enable_widget' => true ) ),
			array( 'POST', '/setup/dismiss-notice', array() ),
		);

		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->request( $route[0], $route[1], $route[2] )->get_status(), $route[1] );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->request( $route[0], $route[1], $route[2] )->get_status(), $route[1] );
		}

		$this->settings()->flush();
		$this->assertSame(
			array(
				'completed'        => false,
				'completed_at'     => 0,
				'step'             => '',
				'notice_dismissed' => false,
			),
			$this->settings()->get( 'general' )['setup']
		);
		$this->assertFalse( $this->settings()->get( 'display' )['enabled'] );
	}

	public function test_scan_shape_and_nothing_is_saved(): void {
		$this->as_admin();
		update_option( 'blogdescription', 'Family &amp; children' );
		update_option( 'active_plugins', array( 'elementor/elementor.php', 'ameliabooking/ameliabooking.php' ) );
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$before = get_option( Settings::OPTION );

		$response = $this->request( 'GET', '/setup/scan' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'site_name', 'tagline', 'plugins', 'content', 'privacy_policy', 'admin_email', 'suggestion', 'contacts' ), array_keys( $data ) );
		$this->assertSame( 'Test Site', $data['site_name'] );
		$this->assertSame( 'Family & children', $data['tagline'] );
		$this->assertSame( array( 'elementor', 'amelia' ), array_column( $data['plugins'], 'id' ) );
		$this->assertSame( array( 'builder', 'booking' ), array_column( $data['plugins'], 'kind' ) );
		$pages = array_values( array_filter( $data['content'], static fn( array $c ): bool => 'page' === $c['slug'] ) );
		$this->assertSame( 1, $pages[0]['published'] );
		$this->assertNotContains( 'attachment', array_column( $data['content'], 'slug' ) );
		$this->assertFalse( $data['privacy_policy'] );
		$this->assertSame( get_option( 'admin_email' ), $data['admin_email'] );
		$this->assertSame( array( 'preset', 'reasons', 'note' ), array_keys( $data['suggestion'] ) );
		$this->assertSame( array( 'emails', 'phones', 'sources' ), array_keys( $data['contacts'] ) );
		$this->assertSame( $before, get_option( Settings::OPTION ), 'A scan saves nothing.' );
	}

	public function test_privacy_policy_must_be_a_published_page(): void {
		$this->as_admin();
		$draft = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $draft );
		$this->assertFalse( $this->request( 'GET', '/setup/scan' )->get_data()['privacy_policy'] );

		update_option( 'wp_page_for_privacy_policy', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$this->assertTrue( $this->request( 'GET', '/setup/scan' )->get_data()['privacy_policy'] );
	}

	/**
	 * @dataProvider keyword_sets
	 *
	 * @param string $name    Site title.
	 * @param string $tagline Tagline.
	 * @param string $preset  Expected preset.
	 * @param string $reason  Expected text in the first reason.
	 */
	public function test_preset_suggestion_per_keyword_set( string $name, string $tagline, string $preset, string $reason ): void {
		$suggestion = $this->scanner()->suggest( $name, $tagline, false );

		$this->assertSame( $preset, $suggestion['preset'] );
		$this->assertStringContainsString( $reason, $suggestion['reasons'][0] );
		$this->assertLessThanOrEqual( 3, count( $suggestion['reasons'] ) );
		$this->assertSame( '', $suggestion['note'] );
	}

	/**
	 * Keyword sets.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public static function keyword_sets(): array {
		return array(
			'healthcare' => array( 'Smile Dental Clinic', 'Gentle care for patients', 'healthcare', '“clinic”' ),
			'portfolio'  => array( 'Jane Doe', 'Wedding photography and my portfolio', 'portfolio', 'Your tagline mentions' ),
			'agency'     => array( 'Bright Digital', 'A marketing agency', 'agency', '“digital”' ),
			'business'   => array( 'Acme Plumbing Services', '', 'business', '“services”' ),
			'nothing'    => array( 'Acme', 'Hello world', 'business', 'No industry keywords' ),
		);
	}

	public function test_published_page_titles_count_but_drafts_do_not(): void {
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Clinic patients dentist',
				'post_status' => 'draft',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Our dentist',
				'post_password' => 'secret',
			)
		);
		$this->assertSame( 'business', $this->scanner()->suggest( 'Acme', '', false )['preset'] );

		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Meet our dentists',
			)
		);
		$suggestion = $this->scanner()->suggest( 'Acme', '', false );
		$this->assertSame( 'healthcare', $suggestion['preset'] );
		$this->assertStringContainsString( 'The page “Meet our dentists” mentions “dentists”.', $suggestion['reasons'][0] );
	}

	public function test_woocommerce_suggests_business_with_a_note(): void {
		$this->as_admin();
		update_option( 'blogname', 'Smile Dental Clinic' );
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

		$data = $this->request( 'GET', '/setup/scan' )->get_data();

		$this->assertContains( 'woocommerce', array_column( $data['plugins'], 'id' ) );
		$this->assertSame( 'business', $data['suggestion']['preset'] );
		$this->assertStringContainsString( '1.1', $data['suggestion']['note'] );
		$this->assertStringContainsString( 'WooCommerce', $data['suggestion']['reasons'][0] );
	}

	public function test_contacts_from_front_page_and_elementor_contact_page(): void {
		$front = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Home',
				'post_content' => '<p>Call 020 7946 0000 or (020) 7946-0000. Since 2010 - 2024. Opened 2024-05-01.</p><p>Write to Info@Example.com or bad@-example.com.</p>',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$contact = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Get in touch',
				'post_name'    => 'contact-us',
				'post_content' => '',
			)
		);
		update_post_meta( $contact, '_elementor_edit_mode', 'builder' );
		update_post_meta( $contact, '_elementor_data', wp_slash( (string) wp_json_encode( self::elementor( 'WhatsApp +44 7700 900123, email hello@example.org' ) ) ) );

		$contacts = $this->scanner()->contacts();

		$this->assertSame( array( 'info@example.com', 'hello@example.org' ), $contacts['emails'], 'Lowercase, valid only.' );
		$this->assertSame( array( '020 7946 0000', '+44 7700 900123' ), $contacts['phones'], 'Same digits once; no dates or year ranges.' );
		$this->assertSame( array( 'Home', 'Get in touch' ), array_column( $contacts['sources'], 'title' ) );
	}

	public function test_contacts_ignore_drafts_private_and_password_protected_pages(): void {
		$protected = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Home',
				'post_password' => 'secret',
				'post_content'  => 'front@example.com 020 7000 0001',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $protected );

		foreach ( array( 'draft', 'private' ) as $status ) {
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Contact ' . $status,
					'post_status'  => $status,
					'post_content' => $status . '@example.com 020 7000 0002',
				)
			);
		}
		self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Contact secret',
				'post_password' => 'secret',
				'post_content'  => 'secret@example.com 020 7000 0003',
			)
		);

		$this->assertSame(
			array(
				'emails'  => array(),
				'phones'  => array(),
				'sources' => array(),
			),
			$this->scanner()->contacts()
		);
	}

	public function test_contacts_are_capped_at_three_each(): void {
		$front = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'a@example.com, b@example.com, c@example.com, d@example.com. Phones: 0201 111 111, 0202 222 222, 0203 333 333, 0204 444 444.',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$contacts = $this->scanner()->contacts();

		$this->assertSame( array( 'a@example.com', 'b@example.com', 'c@example.com' ), $contacts['emails'] );
		$this->assertCount( 3, $contacts['phones'] );
	}

	public function test_phone_extraction_rules(): void {
		$this->assertSame( array( '+1 555 123 4567' ), SiteScan::phones( 'Call +1.555.123.4567 today' ) );
		$this->assertSame( array(), SiteScan::phones( 'Order 12345, call 123 456' ), 'Fewer than 7 digits.' );
		$this->assertSame( array(), SiteScan::phones( 'ID 1234567890123456' ), 'More than 15 digits.' );
		$this->assertSame( array(), SiteScan::emails( 'x@y and @example.com and a@b.c' ) );
	}

	public function test_business_profile_validation(): void {
		$saved = $this->settings()->update( array( 'general' => array( 'business' => self::BUSINESS ) ) );
		$this->assertIsArray( $saved );
		$this->assertSame( self::BUSINESS, $saved['general']['business'] );

		$empty = array_fill_keys( array_keys( self::BUSINESS ), '' );
		$this->assertIsArray( $this->settings()->update( array( 'general' => array( 'business' => $empty ) ) ), 'Every field may be empty.' );

		$invalid = array(
			'name'        => str_repeat( 'a', 121 ),
			'phone'       => 'call me',
			'whatsapp'    => '+44 (77) 0090',
			'email'       => 'not-an-email',
			'booking_url' => 'ftp://example.com/book',
			'address'     => str_repeat( 'a', 301 ),
		);
		foreach ( $invalid as $key => $value ) {
			$result = $this->settings()->update( array( 'general' => array( 'business' => array_merge( self::BUSINESS, array( $key => $value ) ) ) ) );
			$this->assertWPError( $result, $key );
		}
		$this->assertWPError( $this->settings()->update( array( 'general' => array( 'business' => array( 'fax' => '1' ) ) ) ), 'Unknown key.' );
		$this->assertWPError( $this->settings()->update( array( 'general' => array( 'setup' => array( 'step' => 'nope' ) ) ) ) );
	}

	public function test_presets_never_set_business_or_setup(): void {
		$this->setExpectedIncorrectUsage( PresetRepository::class . '::all' );
		$add = static function ( array $presets ): array {
			$presets[] = array(
				'id'       => 'sneaky',
				'version'  => 1,
				'title'    => 'Sneaky',
				'settings' => array( 'general' => array( 'business' => array( 'name' => 'X' ) ) ),
			);
			return $presets;
		};
		add_filter( 'devrivo_chatbot_presets', $add );
		$this->assertNull( ( new PresetRepository() )->get( 'sneaky' ) );
		remove_filter( 'devrivo_chatbot_presets', $add );

		$this->as_admin();
		$this->settings()->update( array( 'general' => array( 'business' => self::BUSINESS ) ) );
		$this->request( 'POST', '/setup/step', array( 'step' => 'business' ) );

		$this->request( 'POST', '/presets/healthcare/apply', array( 'mode' => 'replace' ) );
		$this->settings()->flush();
		$general = $this->settings()->get( 'general' );
		$this->assertSame( 'healthcare', $general['preset'] );
		$this->assertSame( self::BUSINESS, $general['business'] );
		$this->assertSame( 'business', $general['setup']['step'] );

		// Undo restores the preset's sections but keeps the current profile and progress.
		$this->settings()->update( array( 'general' => array( 'business' => array_merge( self::BUSINESS, array( 'name' => 'Changed after' ) ) ) ) );
		$this->request( 'POST', '/setup/step', array( 'step' => 'ai' ) );
		$this->request( 'POST', '/presets/undo' );
		$this->settings()->flush();
		$general = $this->settings()->get( 'general' );
		$this->assertSame( '', $general['preset'] );
		$this->assertSame( 'Changed after', $general['business']['name'] );
		$this->assertSame( 'ai', $general['setup']['step'] );
	}

	public function test_imports_never_set_business_or_setup(): void {
		$this->as_admin();
		$this->settings()->update( array( 'general' => array( 'business' => self::BUSINESS ) ) );

		$response = $this->request(
			'POST',
			'/settings/import',
			array(
				'data'               => array(
					'meta'     => array(
						'plugin'           => 'devrivo-chatbot',
						'settings_version' => Settings::VERSION,
					),
					'settings' => array(
						'general' => array(
							'preset'   => 'agency',
							'business' => array( 'name' => 'Other site' ),
							'setup'    => array(
								'completed'        => true,
								'notice_dismissed' => true,
							),
						),
					),
				),
				'skip_site_specific' => false,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->settings()->flush();
		$general = $this->settings()->get( 'general' );
		$this->assertSame( 'agency', $general['preset'] );
		$this->assertSame( self::BUSINESS, $general['business'] );
		$this->assertFalse( $general['setup']['completed'] );
		$this->assertFalse( $general['setup']['notice_dismissed'] );
	}

	public function test_prompt_has_the_business_profile_only_in_its_block(): void {
		$this->settings()->update(
			array(
				'general'    => array( 'business' => array_merge( self::BUSINESS, array( 'email' => '' ) ) ),
				'appearance' => array( 'brand_name' => 'Brand Name' ),
			)
		);

		$prompt = ( new PromptBuilder( $this->settings(), Plugin::instance()->knowledge() ) )->build( 'hello', '' )['prompt'];
		$start  = (int) strpos( $prompt, "## Business\n" );
		$end    = (int) strpos( $prompt, '## Site content' );
		$block  = substr( $prompt, $start, $end - $start );

		$this->assertStringStartsWith( 'You are the website assistant for Smile Dental.', $prompt, 'The profile name comes first.' );
		$this->assertStringContainsString( "Name: Smile Dental\nPhone: +44 (20) 7946-0000\nWhatsApp: +44 7700 900123\nBooking page: https://smile.example/book/\nAddress: 1 High Street London", $block );
		$this->assertStringNotContainsString( 'Email:', $block, 'Empty fields are left out.' );
		foreach ( array( '7946-0000', '900123', 'smile.example/book', 'High Street' ) as $value ) {
			$this->assertSame( 1, substr_count( $prompt, $value ), $value );
		}

		$this->settings()->update( array( 'general' => array( 'business' => array_fill_keys( array_keys( self::BUSINESS ), '' ) ) ) );
		$prompt = ( new PromptBuilder( $this->settings(), Plugin::instance()->knowledge() ) )->build( 'hello', '' )['prompt'];
		$this->assertStringContainsString( "## Business\nName: Brand Name\n\n", $prompt, 'Falls back to the brand name.' );
		$this->assertStringNotContainsString( 'Public contact details', $prompt );
	}

	public function test_step_complete_and_dismiss_effects(): void {
		$this->as_admin();

		$response = $this->request( 'POST', '/setup/step', array( 'step' => 'appearance' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( 'appearance', $response->get_data()['general']['setup']['step'] );
		$this->assertSame( 400, $this->request( 'POST', '/setup/step', array( 'step' => 'nope' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/setup/step' )->get_status() );

		$data = $this->request( 'POST', '/setup/complete' )->get_data();
		$this->assertTrue( $data['general']['setup']['completed'] );
		$this->assertEqualsWithDelta( time(), $data['general']['setup']['completed_at'], 5 );
		$this->assertSame( 'appearance', $data['general']['setup']['step'] );
		$this->assertFalse( $data['display']['enabled'], 'The widget stays off unless asked.' );

		$data = $this->request( 'POST', '/setup/complete', array( 'enable_widget' => true ) )->get_data();
		$this->assertTrue( $data['display']['enabled'] );
		$this->settings()->flush();
		$this->assertTrue( $this->settings()->get( 'display' )['enabled'] );

		$data = $this->request( 'POST', '/setup/dismiss-notice' )->get_data();
		$this->assertTrue( $data['general']['setup']['notice_dismissed'] );
		$this->assertTrue( $data['general']['setup']['completed'], 'Other setup keys are kept.' );
	}

	public function test_notice_visibility_rules(): void {
		$notice = new SetupNotice( $this->settings() );
		$this->as_admin();

		set_current_screen( 'plugins' );
		$this->assertTrue( $notice->should_show() );
		$html = $this->render( $notice );
		$this->assertStringContainsString( 'id="devrivo-chatbot-setup-notice"', $html );
		$this->assertStringContainsString( 'notice notice-info is-dismissible', $html );
		$this->assertStringContainsString( 'page=devrivo-chatbot#/setup', $html );

		set_current_screen( 'dashboard' );
		$this->assertFalse( $notice->should_show(), 'Plugins screen only.' );
		$this->assertSame( '', $this->render( $notice ) );

		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertFalse( $notice->should_show(), 'Capability needed.' );

		$this->as_admin();
		$this->settings()->update( array( 'general' => array( 'setup' => array( 'notice_dismissed' => true ) ) ) );
		$this->assertFalse( $notice->should_show(), 'Dismissed.' );

		$this->settings()->update(
			array(
				'general' => array(
					'setup' => array(
						'notice_dismissed' => false,
						'completed'        => true,
					),
				),
			)
		);
		$this->assertFalse( $notice->should_show(), 'Completed.' );
	}

	public function test_notice_script_uses_wp_api_fetch_only_with_the_notice(): void {
		$notice = new SetupNotice( $this->settings() );
		$this->as_admin();

		set_current_screen( 'dashboard' );
		$notice->enqueue();
		$this->assertStringNotContainsString( 'dismiss-notice', implode( '', (array) wp_scripts()->get_data( 'wp-api-fetch', 'after' ) ) );

		set_current_screen( 'plugins' );
		$notice->enqueue();
		$this->assertTrue( wp_script_is( 'wp-api-fetch', 'enqueued' ) );
		$after = implode( '', (array) wp_scripts()->get_data( 'wp-api-fetch', 'after' ) );
		$this->assertStringContainsString( '/devrivo-chatbot/v1/setup/dismiss-notice', $after );
		$this->assertStringContainsString( '#devrivo-chatbot-setup-notice .notice-dismiss', $after );
	}

	/**
	 * Elementor data with one text widget.
	 *
	 * @param string $text Text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function elementor( string $text ): array {
		return array(
			array(
				'id'       => 'a1',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => 'b2',
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>' . $text . '</p>' ),
						'elements'   => array(),
					),
				),
			),
		);
	}

	private function render( SetupNotice $notice ): string {
		ob_start();
		$notice->render();

		return (string) ob_get_clean();
	}

	private function scanner(): SiteScan {
		return new SiteScan( Plugin::instance()->knowledge()->posts(), Plugin::instance()->presets() );
	}

	private function as_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function settings(): Settings {
		return Plugin::instance()->settings();
	}

	/**
	 * Dispatches a request.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path after the namespace.
	 * @param array<string, mixed> $body   JSON body.
	 */
	private function request( string $method, string $path, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, self::NS . $path );
		if ( 'GET' !== $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_get_server()->dispatch( $request );
	}
}
