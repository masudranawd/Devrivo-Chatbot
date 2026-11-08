<?php
/**
 * Smoke tests for the plugin skeleton.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Admin\AdminPage;
use DevRivo\Chatbot\Core\Activator;
use DevRivo\Chatbot\Core\Capabilities;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Requirements;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Uninstaller;
use DevRivo\Chatbot\Tests\Support\RestoresCapabilities;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	use RestoresCapabilities;

	public function tear_down(): void {
		$this->restore_capabilities();

		parent::tear_down();
	}

	public function test_constants_are_defined(): void {
		$this->assertTrue( defined( 'DEVRIVO_CHATBOT_VERSION' ) );
		$this->assertSame( DEVRIVO_CHATBOT_VERSION, Plugin::instance()->version() );
	}

	public function test_loaded_action_fires_once(): void {
		$this->assertSame( 1, did_action( 'devrivo_chatbot_loaded' ) );
	}

	public function test_requirements_are_met(): void {
		$this->assertTrue( ( new Requirements() )->met() );
	}

	public function test_install_creates_options(): void {
		$this->assertSame( DEVRIVO_CHATBOT_SCHEMA_VERSION, (int) get_option( Activator::SCHEMA_OPTION ) );
		$this->assertIsArray( get_option( Settings::DISPLAY_OPTION ) );
		$this->assertFalse( Settings::delete_data_on_uninstall() );
	}

	public function test_maybe_install_upgrades_older_schema(): void {
		update_option( Activator::SCHEMA_OPTION, 0 );

		Activator::maybe_install();

		$this->assertSame( DEVRIVO_CHATBOT_SCHEMA_VERSION, (int) get_option( Activator::SCHEMA_OPTION ) );
	}

	public function test_reinstall_after_uninstall_without_opt_in_restores_the_menu(): void {
		global $menu;
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		Uninstaller::uninstall();
		$this->assertSame( DEVRIVO_CHATBOT_SCHEMA_VERSION, (int) get_option( Activator::SCHEMA_OPTION ), 'Data and schema version are kept.' );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertFalse( user_can( $admin, Capabilities::MANAGE ), 'The bug: no menu after a reinstall.' );

		Activator::activate();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertFalse( user_can( self::factory()->user->create( array( 'role' => 'editor' ) ), Capabilities::MANAGE ) );

		// Fresh capabilities for the current user, as on the next page load.
		wp_set_current_user( 0 );
		wp_set_current_user( $admin );
		( new AdminPage() )->add_menu();
		$item = array_values( array_filter( (array) $menu, static fn( $entry ): bool => is_array( $entry ) && AdminPage::SLUG === ( $entry[2] ?? '' ) ) );
		$this->assertCount( 1, $item );
		$this->assertTrue( current_user_can( $item[0][1] ), 'The administrator can open the menu page.' );
	}

	public function test_maybe_install_heals_a_missing_capability(): void {
		Capabilities::remove_all();
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );

		Activator::maybe_install();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertTrue( user_can( self::factory()->user->create( array( 'role' => 'administrator' ) ), Capabilities::MANAGE ) );

		// Nothing is written when the capability is already there.
		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'update_option_' . wp_roles()->role_key, $count );
		Activator::maybe_install();
		Activator::activate();
		remove_action( 'update_option_' . wp_roles()->role_key, $count );
		$this->assertSame( 0, $writes );
	}

	public function test_uninstall_keeps_data_without_opt_in(): void {
		Uninstaller::uninstall();

		$this->assertNotFalse( get_option( Activator::SCHEMA_OPTION ) );
	}

	public function test_uninstall_removes_data_with_opt_in(): void {
		Plugin::instance()->settings()->update( array( 'privacy' => array( 'delete_data_on_uninstall' => true ) ) );

		Uninstaller::uninstall();

		foreach ( Uninstaller::options() as $option ) {
			$this->assertFalse( get_option( $option ) );
		}

		// Keep the object cache consistent with the DB after rollback.
		Activator::maybe_install();
		Plugin::instance()->settings()->flush();
	}
}
