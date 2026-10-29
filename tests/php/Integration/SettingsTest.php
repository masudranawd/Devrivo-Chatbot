<?php
/**
 * Settings repository, schema, migrations and capability tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Activator;
use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Migrations;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Settings\SettingsMigrator;
use DevRivo\Chatbot\Core\Uninstaller;
use WP_Error;
use WP_UnitTestCase;

final class SettingsTest extends WP_UnitTestCase {

	private Settings $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new Settings();
	}

	public function test_every_default_is_valid_against_its_schema(): void {
		foreach ( Schema::sections() as $name => $schema ) {
			$this->assertTrue( rest_validate_value_from_schema( Schema::default_for( $schema ), $schema, $name ), $name );
		}
	}

	public function test_defaults_are_returned_when_nothing_is_stored(): void {
		delete_option( Settings::OPTION );
		delete_option( Settings::DISPLAY_OPTION );

		$this->assertSame( Schema::defaults(), $this->settings->all() );
	}

	public function test_display_option_is_autoloaded_and_settings_option_is_not(): void {
		$this->settings->update(
			array(
				'display'    => array( 'enabled' => true ),
				'appearance' => array( 'radius' => 10 ),
			)
		);

		$this->assertTrue( $this->is_autoloaded( Settings::DISPLAY_OPTION ) );
		$this->assertFalse( $this->is_autoloaded( Settings::OPTION ) );
	}

	public function test_partial_update_keeps_other_keys_and_sections(): void {
		$before = $this->settings->all();

		$result = $this->settings->update( array( 'appearance' => array( 'radius' => 8 ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 8, $result['appearance']['radius'] );
		$this->assertSame( $before['appearance']['color_scheme'], $result['appearance']['color_scheme'] );
		$this->assertSame( $before['behaviour'], $result['behaviour'] );
		$this->assertSame( 8, ( new Settings() )->get( 'appearance' )['radius'], 'Persisted to the database.' );
	}

	public function test_lists_are_replaced_not_appended(): void {
		$action = array(
			'id'    => 'contact',
			'label' => 'Contact us',
			'type'  => 'link',
		);

		$this->settings->update( array( 'actions' => array( $action, array_merge( $action, array( 'id' => 'two' ) ) ) ) );
		$result = $this->settings->update( array( 'actions' => array( $action ) ) );

		$this->assertCount( 1, $result['actions'] );
	}

	public function test_invalid_value_is_rejected_and_nothing_is_saved(): void {
		$this->settings->update( array( 'behaviour' => array( 'auto_open_delay' => 500 ) ) );

		$result = $this->settings->update(
			array(
				'behaviour'  => array( 'auto_open_delay' => 1000 ),
				'appearance' => array( 'radius' => 99 ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'appearance', $result->get_error_data()['params'] );
		$this->assertSame( 500, ( new Settings() )->get( 'behaviour' )['auto_open_delay'], 'Valid sections in a failed request are not saved.' );
	}

	public function test_unknown_section_and_unknown_key_are_rejected(): void {
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( array( 'nope' => array() ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( array( 'appearance' => array( 'nope' => 1 ) ) ) );
	}

	public function test_formats_are_validated(): void {
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( array( 'display' => array( 'primary_color' => 'red' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->settings->update( array( 'integrations' => array( 'notification_emails' => array( 'not-an-email' ) ) ) ) );
	}

	public function test_strings_are_sanitised(): void {
		$result = $this->settings->update(
			array(
				'appearance' => array( 'brand_name' => '<script>x</script><b>Pearl</b>' ),
				'privacy'    => array( 'consent_text' => 'I agree to the <a href="https://example.com/privacy" onclick="x()">policy</a><script>y</script>' ),
				'flows'      => array(
					'book' => array(
						'title' => '<img src=x onerror=alert(1)>Book',
						'start' => 'end',
						'nodes' => array(
							'end' => array(
								'type' => 'end',
								'text' => "<b>Thanks</b>\nSee you soon",
							),
						),
					),
				),
			)
		);

		$this->assertSame( 'Pearl', $result['appearance']['brand_name'] );
		$consent = $result['privacy']['consent_text'];
		$this->assertStringContainsString( 'I agree to the <a href="https://example.com/privacy">policy</a>', $consent );
		$this->assertStringNotContainsString( '<script', $consent );
		$this->assertStringNotContainsString( 'onclick', $consent );
		$this->assertSame( 'Book', $result['flows']['book']['title'] );
		$this->assertSame( "Thanks\nSee you soon", $result['flows']['book']['nodes']['end']['text'], 'Node text keeps line breaks.' );
	}

	public function test_reset_one_section_and_unknown_section(): void {
		$this->settings->update(
			array(
				'appearance' => array( 'radius' => 4 ),
				'behaviour'  => array( 'auto_open' => false ),
			)
		);

		$result = $this->settings->reset( 'appearance' );

		$this->assertSame( Schema::defaults()['appearance'], $result['appearance'] );
		$this->assertFalse( $result['behaviour']['auto_open'] );
		$this->assertInstanceOf( WP_Error::class, $this->settings->reset( 'nope' ) );
	}

	public function test_corrupt_stored_section_falls_back_to_defaults(): void {
		update_option(
			Settings::OPTION,
			array(
				'version'    => Settings::VERSION,
				'appearance' => array( 'radius' => 'banana' ),
			),
			false
		);

		$this->assertSame( Schema::defaults()['appearance'], ( new Settings() )->get( 'appearance' ) );
	}

	public function test_migrator_runs_only_newer_steps_in_order(): void {
		$steps = array(
			3 => static fn( array $s ): array => array_merge( $s, array( 'log' => $s['log'] . '3' ) ),
			2 => static fn( array $s ): array => array_merge( $s, array( 'log' => $s['log'] . '2' ) ),
		);

		$result = SettingsMigrator::migrate( array( 'log' => '' ), 2, $steps );

		$this->assertSame( '3', $result['log'] );
		$this->assertSame( 3, $result['version'] );
	}

	public function test_old_settings_version_is_upgraded_on_read(): void {
		update_option( Settings::OPTION, array( 'appearance' => array( 'radius' => 5 ) ), false );

		$this->assertSame( 5, ( new Settings() )->get( 'appearance' )['radius'] );
		$this->assertSame( Settings::VERSION, get_option( Settings::OPTION )['version'] );
	}

	public function test_db_migrations_run_once_in_order_and_resume(): void {
		update_option( Activator::SCHEMA_OPTION, 1, true );
		$log = array();

		Migrations::run(
			array(
				2  => static function () use ( &$log ): void {
					$log[] = 2;
				},
				1  => static function () use ( &$log ): void {
					$log[] = 1;
				},
				99 => static function () use ( &$log ): void {
					$log[] = 99; // Above DEVRIVO_CHATBOT_SCHEMA_VERSION: must not run.
				},
			)
		);

		$this->assertSame( array( 2 ), $log );
		$this->assertSame( 2, (int) get_option( Activator::SCHEMA_OPTION ) );
	}

	public function test_legacy_uninstall_option_is_moved_into_settings(): void {
		update_option( Activator::SCHEMA_OPTION, 1, true );
		update_option( 'devrivo_chatbot_delete_data_on_uninstall', 1, false );

		Migrations::run();

		$this->assertFalse( get_option( 'devrivo_chatbot_delete_data_on_uninstall' ) );
		$this->assertTrue( Settings::delete_data_on_uninstall() );
	}

	public function test_capability_is_granted_to_administrators_only(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $editor, Capabilities::MANAGE ) );
	}

	public function test_uninstall_always_removes_the_capability(): void {
		Uninstaller::uninstall();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertNotFalse( get_option( Settings::OPTION ), 'Data stays without opt-in.' );

		Capabilities::grant_defaults();
	}

	/**
	 * Whether an option is autoloaded (handles WordPress 6.6+ autoload values).
	 *
	 * @param string $option Option name.
	 */
	private function is_autoloaded( string $option ): bool {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );

		return in_array( $value, wp_autoload_values_to_autoload(), true );
	}
}
