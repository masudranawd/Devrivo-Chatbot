<?php
/**
 * Typography settings, public config, presets and the bundled font files.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Admin\AdminPage;
use DevRivo\Chatbot\Core\Frontend\Assets;
use DevRivo\Chatbot\Core\Frontend\WidgetConfig;
use DevRivo\Chatbot\Core\Plugin;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_UnitTestCase;

final class TypographyTest extends WP_UnitTestCase {

	private const FONT = 'assets/fonts/figtree/figtree-variable.woff2';

	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION );
		delete_option( PresetApplier::BACKUP_OPTION );
		$this->settings()->flush();
		Plugin::instance()->presets()->flush();
	}

	public function test_defaults_and_validation(): void {
		$defaults = Schema::defaults()['appearance'];
		$this->assertSame( 'figtree', $defaults['font'] );
		$this->assertSame( 'default', $defaults['text_size'] );

		foreach ( array( 'figtree', 'system', 'inherit' ) as $font ) {
			$this->assertIsArray( $this->settings()->update( array( 'appearance' => array( 'font' => $font ) ) ), $font );
		}
		foreach ( array( 'small', 'default', 'large' ) as $size ) {
			$this->assertIsArray( $this->settings()->update( array( 'appearance' => array( 'text_size' => $size ) ) ), $size );
		}
		$this->assertWPError( $this->settings()->update( array( 'appearance' => array( 'font' => 'Figtree' ) ) ) );
		$this->assertWPError( $this->settings()->update( array( 'appearance' => array( 'font' => 'https://fonts.example.com/x.css' ) ) ) );
		$this->assertWPError( $this->settings()->update( array( 'appearance' => array( 'text_size' => 'huge' ) ) ) );
	}

	public function test_stored_values_stay_without_a_migration(): void {
		$stored = array(
			'version'    => Settings::VERSION,
			'appearance' => array(
				'color_scheme' => 'auto',
				'radius'       => 22,
				'brand_name'   => '',
				'avatar_id'    => 0,
				'font'         => 'system',
			),
		);
		update_option( Settings::OPTION, $stored, false );
		$this->settings()->flush();

		$appearance = $this->settings()->get( 'appearance' );

		$this->assertSame( 'system', $appearance['font'], 'An existing choice is kept.' );
		$this->assertSame( 'default', $appearance['text_size'], 'The new key reads its default.' );
		$this->assertSame( $stored, get_option( Settings::OPTION ), 'Nothing is rewritten.' );
	}

	public function test_public_config_has_font_and_text_size_only(): void {
		$this->settings()->update(
			array(
				'appearance' => array(
					'font'      => 'inherit',
					'text_size' => 'large',
				),
			)
		);

		$appearance = ( new WidgetConfig( $this->settings() ) )->build( 'en_US' )['appearance'];

		$this->assertSame( 'inherit', $appearance['font'] );
		$this->assertSame( 'large', $appearance['text_size'] );
		$this->assertSame( array( 'color_scheme', 'radius', 'brand_name', 'font', 'text_size', 'avatar_url' ), array_keys( $appearance ) );
	}

	public function test_font_is_a_site_choice_presets_never_set(): void {
		$presets = ( new PresetRepository() )->all();
		$this->assertSame( array( 'business', 'healthcare', 'portfolio', 'agency' ), array_keys( $presets ), 'All load without errors.' );
		foreach ( $presets as $id => $preset ) {
			$this->assertArrayNotHasKey( 'font', (array) ( $preset['settings']['appearance'] ?? array() ), $id );
		}

		$this->setExpectedIncorrectUsage( PresetRepository::class . '::all' );
		$add = static function ( array $sources ): array {
			$sources[] = array(
				'id'       => 'fonty',
				'version'  => 1,
				'title'    => 'Fonty',
				'settings' => array( 'appearance' => array( 'font' => 'system' ) ),
			);
			return $sources;
		};
		add_filter( 'devrivo_chatbot_presets', $add );
		$this->assertNull( ( new PresetRepository() )->get( 'fonty' ), 'A preset that sets the font is skipped.' );
		remove_filter( 'devrivo_chatbot_presets', $add );

		// Applying a preset keeps the admin's font and text size.
		$this->settings()->update(
			array(
				'appearance' => array(
					'font'      => 'system',
					'text_size' => 'large',
				),
			)
		);
		$result = ( new PresetApplier( $this->settings(), Plugin::instance()->presets() ) )->apply( 'business' );
		$this->assertIsArray( $result );
		$this->assertSame( 'system', $result['settings']['appearance']['font'] );
		$this->assertSame( 'large', $result['settings']['appearance']['text_size'] );
		$this->assertNotContains( 'appearance', $result['applied'] );
	}

	public function test_font_files_ship_with_the_plugin(): void {
		$font = DEVRIVO_CHATBOT_PATH . self::FONT;
		$ofl  = DEVRIVO_CHATBOT_PATH . 'assets/fonts/figtree/OFL.txt';

		$this->assertFileExists( $font );
		$this->assertSame( 'wOF2', (string) file_get_contents( $font, false, null, 0, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertLessThanOrEqual( 40 * 1024, filesize( $font ) );
		$this->assertFileExists( $ofl );
		$this->assertStringContainsString( 'SIL Open Font License', (string) file_get_contents( $ofl ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		// The zip is built from package.json "files", minus .distignore.
		$package = json_decode( (string) file_get_contents( DEVRIVO_CHATBOT_PATH . 'package.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertContains( 'assets', $package['files'] );
		$ignored = array_map( 'trim', (array) file( DEVRIVO_CHATBOT_PATH . '.distignore' ) );
		foreach ( array( '/assets', 'assets', '/assets/fonts', 'assets/fonts' ) as $path ) {
			$this->assertNotContains( $path, $ignored );
		}
		$workflow = (string) file_get_contents( DEVRIVO_CHATBOT_PATH . '.github/workflows/build.yml' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( self::FONT, $workflow, 'CI checks the zip contains the font.' );
		$this->assertStringContainsString( 'assets/fonts/figtree/OFL.txt', $workflow );
	}

	public function test_font_url_is_local_and_in_boot_data(): void {
		$url = Assets::font_url();

		$this->assertStringStartsWith( DEVRIVO_CHATBOT_URL . self::FONT, $url );
		$this->assertStringContainsString( 'ver=' . DEVRIVO_CHATBOT_VERSION, $url );
		$this->assertSame( $url, ( new Assets( new \DevRivo\Chatbot\Core\Frontend\Display() ) )->boot()['fontUrl'] );
		$this->assertSame( $url, ( new AdminPage() )->boot()['previewFontUrl'] );
	}

	private function settings(): Settings {
		return Plugin::instance()->settings();
	}
}
