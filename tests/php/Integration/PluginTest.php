<?php
/**
 * Smoke tests for the plugin skeleton.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Activator;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Requirements;
use DevRivo\Chatbot\Core\Uninstaller;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

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
		$this->assertSame( 0, (int) get_option( Activator::UNINSTALL_OPTION, -1 ) );
	}

	public function test_maybe_install_upgrades_older_schema(): void {
		update_option( Activator::SCHEMA_OPTION, 0 );

		Activator::maybe_install();

		$this->assertSame( DEVRIVO_CHATBOT_SCHEMA_VERSION, (int) get_option( Activator::SCHEMA_OPTION ) );
	}

	public function test_uninstall_keeps_data_without_opt_in(): void {
		Uninstaller::uninstall();

		$this->assertNotFalse( get_option( Activator::SCHEMA_OPTION ) );
	}

	public function test_uninstall_removes_data_with_opt_in(): void {
		update_option( Activator::UNINSTALL_OPTION, 1 );

		Uninstaller::uninstall();

		foreach ( Uninstaller::options() as $option ) {
			$this->assertFalse( get_option( $option ) );
		}

		// Keep the object cache consistent with the DB after rollback.
		Activator::maybe_install();
	}
}
