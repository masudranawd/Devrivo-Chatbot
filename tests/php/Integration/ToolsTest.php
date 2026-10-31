<?php
/**
 * Admin tools endpoint tests: preset backup status, settings export and
 * import, test email.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Admin\TransferController;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

final class ToolsTest extends WP_UnitTestCase {

	private const NS = '/devrivo-chatbot/v1';

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
		reset_phpmailer_instance();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		reset_phpmailer_instance();

		parent::tear_down();
	}

	public function test_routes_need_the_manage_capability(): void {
		// A valid body, so the permission check (not argument validation) decides.
		$routes = array(
			array( 'GET', '/presets/backup', array() ),
			array( 'GET', '/settings/export', array() ),
			array( 'POST', '/settings/import', array( 'data' => $this->file( array( 'appearance' => array( 'brand_name' => 'X' ) ) ) ) ),
			array( 'POST', '/integrations/test-email', array() ),
		);

		wp_set_current_user( 0 );
		foreach ( $routes as $route ) {
			$this->assertSame( 401, $this->request( $route[0], $route[1], $route[2] )->get_status(), $route[0] . ' ' . $route[1] );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $routes as $route ) {
			$this->assertSame( 403, $this->request( $route[0], $route[1], $route[2] )->get_status(), $route[0] . ' ' . $route[1] );
		}
		$this->assertSame( '', $this->settings()->get( 'appearance' )['brand_name'], 'Nothing imported.' );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 0 ), 'No email without permission.' );
	}

	public function test_backup_status_follows_apply_and_undo(): void {
		$this->as_admin();

		$response = $this->request( 'GET', '/presets/backup' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'exists'  => false,
				'preset'  => '',
				'created' => null,
			),
			$response->get_data()
		);

		$this->request( 'POST', '/presets/agency/apply', array( 'mode' => 'replace' ) );
		$data = $this->request( 'GET', '/presets/backup' )->get_data();
		$this->assertTrue( $data['exists'] );
		$this->assertSame( 'agency', $data['preset'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $data['created'] );

		$this->request( 'POST', '/presets/undo' );
		$this->assertFalse( $this->request( 'GET', '/presets/backup' )->get_data()['exists'] );
	}

	public function test_export_shape(): void {
		$this->as_admin();
		$this->settings()->update( array( 'appearance' => array( 'brand_name' => 'Acme' ) ) );

		$response = $this->request( 'GET', '/settings/export' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame( array( 'meta', 'settings' ), array_keys( $data ) );
		$this->assertSame( array( 'plugin', 'plugin_version', 'settings_version', 'exported_at', 'site' ), array_keys( $data['meta'] ) );
		$this->assertSame( 'devrivo-chatbot', $data['meta']['plugin'] );
		$this->assertSame( DEVRIVO_CHATBOT_VERSION, $data['meta']['plugin_version'] );
		$this->assertSame( Settings::VERSION, $data['meta']['settings_version'] );
		$this->assertSame( home_url( '/' ), $data['meta']['site'] );
		$this->assertSame( 'Acme', $data['settings']['appearance']['brand_name'] );
		$this->assertSame( array_keys( $this->settings()->all() ), array_keys( $data['settings'] ) );
		$this->assertStringContainsString( '"flows":{}', (string) wp_json_encode( $data ), 'Empty maps stay JSON objects.' );
	}

	public function test_export_then_import_round_trip(): void {
		$this->as_admin();
		$this->request( 'POST', '/presets/healthcare/apply', array( 'mode' => 'replace' ) );
		$export = $this->request( 'GET', '/settings/export' )->get_data();
		$before = $this->settings()->all();

		$this->settings()->reset();
		delete_option( PresetApplier::BACKUP_OPTION );

		$response = $this->import( $this->decoded( $export ), array(), false );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$this->settings()->flush();
		$this->assertSame( $before, $this->settings()->all() );
	}

	public function test_import_rejects_bad_files(): void {
		$this->as_admin();
		$good = $this->file( array( 'appearance' => array( 'brand_name' => 'X' ) ) );

		$cases = array(
			'no meta'        => array( 'settings' => $good['settings'] ),
			'wrong plugin'   => array_replace_recursive( $good, array( 'meta' => array( 'plugin' => 'other-plugin' ) ) ),
			'no version'     => array_replace_recursive( $good, array( 'meta' => array( 'settings_version' => 'one' ) ) ),
			'newer version'  => array_replace_recursive( $good, array( 'meta' => array( 'settings_version' => Settings::VERSION + 1 ) ) ),
			'empty settings' => array(
				'meta'     => $good['meta'],
				'settings' => array(),
			),
		);

		foreach ( $cases as $label => $file ) {
			$response = $this->import( $file );
			$this->assertSame( 400, $response->get_status(), $label );
		}
		$this->assertSame( 'devrivo_chatbot_invalid_import', $this->import( $cases['wrong plugin'] )->get_data()['code'] );

		$this->assertSame( 400, $this->request( 'POST', '/settings/import', array( 'sections' => array() ) )->get_status(), 'Missing data.' );
		$this->assertSame( '', $this->settings()->get( 'appearance' )['brand_name'], 'Nothing saved.' );
		$this->assertFalse( get_option( PresetApplier::BACKUP_OPTION ), 'No backup for a refused file.' );
	}

	public function test_import_size_limit(): void {
		$this->as_admin();
		$file = $this->file( array( 'knowledge' => array( 'faqs' => array() ) ) );

		$file['padding'] = str_repeat( 'a', TransferController::MAX_BYTES );
		$response        = $this->import( $file );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'devrivo_chatbot_import_too_large', $response->get_data()['code'] );
	}

	public function test_import_only_the_chosen_sections(): void {
		$this->as_admin();
		$file = $this->file(
			array(
				'appearance' => array( 'brand_name' => 'Imported' ),
				'behaviour'  => array( 'greeting' => 'Imported hello' ),
			)
		);

		$response = $this->import( $file, array( 'appearance' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'appearance' ), $response->get_data()['sections'] );
		$this->assertSame( 'Imported', $response->get_data()['settings']['appearance']['brand_name'] );
		$this->settings()->flush();
		$this->assertSame( 'Imported', $this->settings()->get( 'appearance' )['brand_name'] );
		$this->assertSame( '', $this->settings()->get( 'behaviour' )['greeting'], 'Not chosen.' );

		$this->assertSame( 400, $this->import( $file, array( 'forms' ) )->get_status(), 'A section the file does not have.' );
		$this->assertSame( 400, $this->import( $file, array( 'nope' ) )->get_status(), 'An unknown section.' );
	}

	public function test_skip_site_specific_keeps_this_sites_values(): void {
		$this->as_admin();
		$this->settings()->update(
			array(
				'display'    => array(
					'rules' => array(
						'mode'     => 'include',
						'post_ids' => array( 11 ),
					),
				),
				'appearance' => array( 'avatar_id' => 5 ),
				'knowledge'  => array( 'exclude_ids' => array( 7 ) ),
			)
		);
		$file = $this->file(
			array(
				'display'    => array(
					'rules' => array(
						'mode'       => 'exclude',
						'post_ids'   => array( 99, 98 ),
						'post_types' => array( 'page' ),
						'devices'    => array( 'mobile' ),
					),
				),
				'appearance' => array(
					'avatar_id'  => 77,
					'brand_name' => 'From file',
				),
				'knowledge'  => array(
					'exclude_ids' => array( 55 ),
					'post_types'  => array( 'page' ),
				),
				'ai'         => array(
					'enabled'             => true,
					'provider'            => 'other-provider',
					'model'               => 'other-model',
					'monthly_token_limit' => 500,
					'custom_instructions' => 'Be brief.',
				),
			)
		);

		$response = $this->import( $file );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );

		$this->settings()->flush();
		$all = $this->settings()->all();
		$this->assertSame( array( 11 ), $all['display']['rules']['post_ids'] );
		$this->assertSame( 'exclude', $all['display']['rules']['mode'] );
		$this->assertSame( array( 'mobile' ), $all['display']['rules']['devices'] );
		$this->assertSame( 5, $all['appearance']['avatar_id'] );
		$this->assertSame( 'From file', $all['appearance']['brand_name'] );
		$this->assertSame( array( 7 ), $all['knowledge']['exclude_ids'] );
		$this->assertSame( array( 'page' ), $all['knowledge']['post_types'] );
		$this->assertFalse( $all['ai']['enabled'] );
		$this->assertSame( '', $all['ai']['provider'] );
		$this->assertSame( 0, $all['ai']['monthly_token_limit'] );
		$this->assertSame( 'Be brief.', $all['ai']['custom_instructions'] );
	}

	public function test_without_skip_the_files_values_are_used(): void {
		$this->as_admin();
		$this->settings()->update( array( 'appearance' => array( 'avatar_id' => 5 ) ) );

		$response = $this->import( $this->file( array( 'appearance' => array( 'avatar_id' => 77 ) ) ), array(), false );

		$this->assertSame( 200, $response->get_status() );
		$this->settings()->flush();
		$this->assertSame( 77, $this->settings()->get( 'appearance' )['avatar_id'] );
	}

	public function test_import_is_all_or_nothing_with_field_errors(): void {
		$this->as_admin();
		$this->settings()->update( array( 'appearance' => array( 'brand_name' => 'Mine' ) ) );
		$this->request( 'POST', '/presets/agency/apply', array( 'mode' => 'replace' ) );
		$backup = get_option( PresetApplier::BACKUP_OPTION );
		$before = $this->settings()->all();

		$file     = $this->file(
			array(
				'appearance' => array( 'brand_name' => 'Imported' ),
				'actions'    => array(
					array(
						'id'     => 'go',
						'label'  => 'Go',
						'type'   => 'flow',
						'config' => array( 'flow' => 'missing' ),
					),
				),
			)
		);
		$response = $this->import( $file );

		$this->assertSame( 400, $response->get_status() );
		$this->assertArrayHasKey( 'actions[0][config][flow]', $response->get_data()['data']['params'] );
		$this->settings()->flush();
		$this->assertSame( $before, $this->settings()->all(), 'Nothing saved.' );
		$this->assertSame( $backup, get_option( PresetApplier::BACKUP_OPTION ), 'The earlier backup is kept.' );
	}

	public function test_import_creates_a_backup_and_undo_restores(): void {
		$this->as_admin();
		$this->settings()->update( array( 'appearance' => array( 'brand_name' => 'Mine' ) ) );
		$before = $this->settings()->all();

		$this->import( $this->file( array( 'appearance' => array( 'brand_name' => 'Imported' ) ) ) );

		$status = $this->request( 'GET', '/presets/backup' )->get_data();
		$this->assertTrue( $status['exists'] );
		$this->assertSame( '', $status['preset'], 'An import, not a preset.' );

		$undo = $this->request( 'POST', '/presets/undo' );
		$this->assertSame( 200, $undo->get_status() );
		$this->settings()->flush();
		$this->assertSame( 'Mine', $this->settings()->get( 'appearance' )['brand_name'] );
		$this->assertSame( $before, $this->settings()->all() );
	}

	public function test_test_email_goes_to_saved_recipients(): void {
		$this->as_admin();
		$this->settings()->update( array( 'integrations' => array( 'notification_emails' => array( 'team@example.com', 'boss@example.com' ) ) ) );

		$response = $this->request( 'POST', '/integrations/test-email' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
		$this->assertSame(
			array(
				'sent'       => true,
				'recipients' => array( 'team@example.com', 'boss@example.com' ),
			),
			$response->get_data()
		);
		$mail = tests_retrieve_phpmailer_instance()->get_sent( 0 );
		$this->assertSame( array( 'team@example.com', 'boss@example.com' ), array_column( $mail->to, 0 ) );
		$this->assertStringContainsString( 'Test email', $mail->subject );
		$this->assertStringNotContainsString( "\n", $mail->subject );
	}

	public function test_test_email_falls_back_to_the_admin_email(): void {
		$this->as_admin();
		$admin = static fn(): string => 'site-admin@example.com';
		add_filter( 'pre_option_admin_email', $admin );

		$response = $this->request( 'POST', '/integrations/test-email' );
		remove_filter( 'pre_option_admin_email', $admin );

		$this->assertSame( array( 'site-admin@example.com' ), $response->get_data()['recipients'] );
		$this->assertSame( array( 'site-admin@example.com' ), array_column( tests_retrieve_phpmailer_instance()->get_sent( 0 )->to, 0 ) );
	}

	public function test_test_email_is_rate_limited_per_user(): void {
		$this->as_admin();

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->request( 'POST', '/integrations/test-email' )->get_status(), "Email {$i}" );
		}
		$limited = $this->request( 'POST', '/integrations/test-email' );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertSame( 'devrivo_chatbot_rate_limited', $limited->get_data()['code'] );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent( 3 ), 'The fourth email is not sent.' );

		// Another admin has their own limit.
		$this->as_admin();
		$this->assertSame( 200, $this->request( 'POST', '/integrations/test-email' )->get_status() );
	}

	public function test_test_email_reports_a_mailer_failure(): void {
		$this->as_admin();
		add_filter( 'pre_wp_mail', '__return_false' );

		$response = $this->request( 'POST', '/integrations/test-email' );

		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'devrivo_chatbot_email_failed', $response->get_data()['code'] );
	}

	/**
	 * An exported file with the given sections.
	 *
	 * @param array<string, mixed> $settings Sections.
	 * @return array<string, mixed>
	 */
	private function file( array $settings ): array {
		return array(
			'meta'     => array(
				'plugin'           => 'devrivo-chatbot',
				'plugin_version'   => DEVRIVO_CHATBOT_VERSION,
				'settings_version' => Settings::VERSION,
				'exported_at'      => '2026-10-31T10:00:00Z',
				'site'             => 'https://example.org/',
			),
			'settings' => $settings,
		);
	}

	/**
	 * A response's data as decoded JSON (what the browser would send back).
	 *
	 * @param array<string, mixed> $data Data.
	 * @return array<string, mixed>
	 */
	private function decoded( array $data ): array {
		return (array) json_decode( (string) wp_json_encode( $data ), true );
	}

	/**
	 * POST /settings/import.
	 *
	 * @param array<string, mixed> $file     File.
	 * @param string[]             $sections Sections.
	 * @param bool                 $skip     skip_site_specific.
	 */
	private function import( array $file, array $sections = array(), bool $skip = true ): WP_REST_Response {
		return $this->request(
			'POST',
			'/settings/import',
			array(
				'data'               => $file,
				'sections'           => $sections,
				'skip_site_specific' => $skip,
			)
		);
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
