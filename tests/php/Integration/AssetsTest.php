<?php
/**
 * Front-end assets tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Frontend\Assets;
use DevRivo\Chatbot\Core\Frontend\Display;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_UnitTestCase;

final class AssetsTest extends WP_UnitTestCase {

	/**
	 * Fixture build folder with fake .asset.php files (CI runs PHP tests without a JS build).
	 */
	private string $build_dir;

	public function set_up(): void {
		parent::set_up();

		$this->build_dir = get_temp_dir() . 'devrivo-build-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->build_dir );
		foreach ( array(
			'widget-loader' => 'loaderhash',
			'widget'        => 'widgethash',
		) as $name => $version ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				"{$this->build_dir}/{$name}.asset.php",
				"<?php return array( 'dependencies' => array(), 'version' => '{$version}' );"
			);
		}

		delete_option( Settings::DISPLAY_OPTION );
		$this->reset_scripts();
	}

	public function tear_down(): void {
		foreach ( (array) glob( $this->build_dir . '/*' ) as $file ) {
			wp_delete_file( (string) $file );
		}
		rmdir( $this->build_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		$this->reset_scripts();

		parent::tear_down();
	}

	public function test_nothing_is_enqueued_when_not_displayed(): void {
		$this->go_to( home_url( '/' ) );

		$this->assets()->enqueue();

		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( array(), wp_scripts()->queue );
		$this->assertSame( array(), wp_styles()->queue );
	}

	public function test_loader_is_enqueued_deferred_in_footer_when_displayed(): void {
		$this->enable();

		$this->assets()->enqueue();

		$this->assertTrue( wp_script_is( Assets::HANDLE, 'enqueued' ) );
		$this->assertSame( array( Assets::HANDLE ), wp_scripts()->queue, 'Only the loader.' );
		$this->assertSame( array(), wp_styles()->queue, 'No CSS file.' );

		$script = wp_scripts()->registered[ Assets::HANDLE ];
		$this->assertStringEndsWith( 'build/widget-loader.js', $script->src );
		$this->assertSame( 'loaderhash', $script->ver );
		$this->assertSame( array(), $script->deps, 'No jQuery or other dependencies.' );
		$this->assertSame( 'defer', wp_scripts()->get_data( Assets::HANDLE, 'strategy' ) );
		$this->assertSame( 1, wp_scripts()->get_data( Assets::HANDLE, 'group' ), 'In the footer.' );
	}

	public function test_inline_boot_is_valid_escaped_json_with_versioned_config_url(): void {
		$this->enable( array( 'launcher_label' => '</script><b>"Hi" & \'bye\'' ) );

		$this->assets()->enqueue();

		$before = implode( '', (array) wp_scripts()->get_data( Assets::HANDLE, 'before' ) );
		$this->assertStringStartsWith( 'window.devrivoChatbotBoot = ', $before );
		$this->assertStringNotContainsString( '</script>', $before );
		$this->assertStringNotContainsString( '<b>', $before );
		$this->assertStringContainsString( (string) wp_json_encode( '"Hi" & ' . "'bye'", JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ), $before, 'Quotes and ampersands are hex-escaped.' );

		$json = substr( rtrim( $before, ';' ), strlen( 'window.devrivoChatbotBoot = ' ) );
		$boot = json_decode( $json, true );

		$this->assertIsArray( $boot );
		$this->assertSame( array( 'configUrl', 'widgetUrl', 'fontUrl', 'position', 'primaryColor', 'launcherLabel', 'devices' ), array_keys( $boot ) );
		$this->assertStringContainsString( 'devrivo-chatbot/v1/config', urldecode( $boot['configUrl'] ) );
		$this->assertStringContainsString( 'v=' . Settings::config_version(), $boot['configUrl'] );
		$this->assertStringContainsString( 'locale=' . determine_locale(), $boot['configUrl'] );
		$this->assertStringContainsString( 'build/widget.js?ver=widgethash', $boot['widgetUrl'] );
		$this->assertSame( 'left', $boot['position'] );
		$this->assertSame( array( 'mobile' ), $boot['devices'] );
	}

	public function test_empty_launcher_label_uses_translated_default(): void {
		$this->enable( array( 'launcher_label' => '' ) );

		$this->assertSame( 'How can we help?', $this->assets()->boot()['launcherLabel'] );
	}

	public function test_nothing_is_enqueued_without_a_build(): void {
		$this->enable();

		( new Assets( new Display(), $this->build_dir . '/missing' ) )->enqueue();

		$this->assertFalse( wp_script_is( Assets::HANDLE, 'enqueued' ) );
	}

	private function assets(): Assets {
		return new Assets( new Display(), $this->build_dir );
	}

	/**
	 * Enables the widget everywhere and visits the home page.
	 *
	 * @param array<string, mixed> $display Extra display settings.
	 */
	private function enable( array $display = array() ): void {
		( new Settings() )->update(
			array(
				'display' => array_merge(
					array(
						'enabled'  => true,
						'position' => 'left',
						'rules'    => array(
							'mode'       => 'all',
							'post_ids'   => array(),
							'post_types' => array(),
							'devices'    => array( 'mobile' ),
						),
					),
					$display
				),
			)
		);
		$this->go_to( home_url( '/' ) );
	}

	private function reset_scripts(): void {
		// Fresh script and style registries for each test.
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
