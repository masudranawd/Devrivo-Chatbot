<?php
/**
 * Preset repository and applier tests.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Tests\Integration;

use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Presets\PresetRepository;
use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Core\Uninstaller;
use WP_Error;
use WP_UnitTestCase;

final class PresetsTest extends WP_UnitTestCase {

	private const BUILT_IN = array( 'business', 'healthcare', 'portfolio', 'agency' );

	private Settings $settings;

	private PresetRepository $presets;

	private PresetApplier $applier;

	/**
	 * Temporary files to delete after each test.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	public function set_up(): void {
		parent::set_up();

		delete_option( Settings::OPTION );
		delete_option( Settings::DISPLAY_OPTION );
		delete_option( PresetApplier::BACKUP_OPTION );

		$this->settings = new Settings();
		$this->presets  = new PresetRepository();
		$this->applier  = new PresetApplier( $this->settings, $this->presets );
	}

	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			wp_delete_file( $file );
		}
		$this->temp_files = array();

		parent::tear_down();
	}

	public function test_all_returns_exactly_the_built_in_presets(): void {
		$this->assertSame( self::BUILT_IN, array_keys( $this->presets->all() ) );
	}

	public function test_every_built_in_preset_is_valid(): void {
		foreach ( $this->presets->all() as $id => $preset ) {
			$this->assertSame( $id, $preset['id'] );
			$this->assertSame( 1, $preset['version'] );
			$this->assertNotSame( '', $preset['title'] );
			$this->assertNotSame( '', $preset['description'] );
			$this->assertNotSame( '', $preset['settings']['behaviour']['welcome'], $id );
			$this->assertNotSame( '', $preset['settings']['ai']['custom_instructions'], $id );
			$this->assertGreaterThanOrEqual( 4, count( $preset['settings']['actions'] ), $id );
			$this->assertLessThanOrEqual( 5, count( $preset['settings']['actions'] ), $id );
			$this->assertEmpty( array_diff( array_keys( $preset['settings'] ), PresetRepository::SECTIONS ), $id );
		}
	}

	public function test_healthcare_marks_urgent_help_and_has_no_hard_coded_number(): void {
		$preset  = $this->presets->get( 'healthcare' );
		$actions = array_column( $preset['settings']['actions'], null, 'id' );

		$this->assertTrue( $actions['urgent']['urgent'] );
		$this->assertDoesNotMatchRegularExpression( '/\d{3}/', (string) wp_json_encode( $preset ), 'No phone numbers in the preset.' );
	}

	public function test_built_in_strings_are_translatable(): void {
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'devrivo-chatbot' === $domain && 'Healthcare' === $text ? 'Gesundheit' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		$title = ( new PresetRepository() )->get( 'healthcare' )['title'];
		remove_filter( 'gettext', $translate, 10 );

		$this->assertSame( 'Gesundheit', $title );
	}

	public function test_get_returns_null_for_unknown_preset(): void {
		$this->assertNull( $this->presets->get( 'nope' ) );
	}

	public function test_replace_overwrites_preset_sections_and_keeps_others(): void {
		$this->settings->update(
			array(
				'display'      => array( 'enabled' => true ),
				'privacy'      => array( 'retention_days' => 30 ),
				'integrations' => array( 'notification_emails' => array( 'owner@example.com' ) ),
				'behaviour'    => array(
					'greeting'  => 'Old greeting',
					'auto_open' => false,
				),
				'ai'           => array( 'model' => 'old-model' ),
			)
		);
		$before = $this->settings->all();
		$preset = $this->presets->get( 'business' );

		$result = $this->applier->apply( 'business' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'behaviour', 'actions', 'ai' ), $result['applied'] );
		$this->assertSame( array(), $result['skipped'] );

		$after = ( new Settings() )->all();
		$this->assertSame( $preset['settings']['behaviour']['welcome'], $after['behaviour']['welcome'] );
		$this->assertSame( '', $after['behaviour']['greeting'], 'Keys the preset leaves out reset to defaults.' );
		$this->assertTrue( $after['behaviour']['auto_open'] );
		$this->assertSame( '', $after['ai']['model'] );
		$this->assertSame( array_column( $preset['settings']['actions'], 'id' ), array_column( $after['actions'], 'id' ) );

		foreach ( array( 'display', 'privacy', 'integrations', 'appearance' ) as $section ) {
			$this->assertSame( $before[ $section ], $after[ $section ], $section );
		}
		$this->assertSame(
			array(
				'preset'         => 'business',
				'preset_version' => 1,
			),
			$after['general']
		);
	}

	public function test_merge_skips_changed_sections_and_applies_untouched_ones(): void {
		$this->settings->update( array( 'behaviour' => array( 'welcome' => 'My own welcome' ) ) );

		$result = $this->applier->apply( 'agency', 'merge' );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'actions', 'ai' ), $result['applied'] );
		$this->assertSame( array( 'behaviour' ), $result['skipped'] );

		$after = $this->settings->all();
		$this->assertSame( 'My own welcome', $after['behaviour']['welcome'] );
		$this->assertCount( 5, $after['actions'] );
		$this->assertSame( 'agency', $after['general']['preset'] );
	}

	public function test_apply_rejects_unknown_preset_and_mode(): void {
		$unknown = $this->applier->apply( 'nope' );
		$mode    = $this->applier->apply( 'business', 'append' );

		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );
		$this->assertInstanceOf( WP_Error::class, $mode );
		$this->assertSame( 400, $mode->get_error_data()['status'] );
		$this->assertFalse( get_option( PresetApplier::BACKUP_OPTION ) );
	}

	public function test_backup_option_is_not_autoloaded_and_removed_on_uninstall(): void {
		$this->applier->apply( 'business' );

		$backup = get_option( PresetApplier::BACKUP_OPTION );
		$this->assertSame( 'business', $backup['preset'] );
		$this->assertIsInt( $backup['time'] );
		$this->assertArrayNotHasKey( PresetApplier::BACKUP_OPTION, wp_load_alloptions(), 'Not autoloaded.' );
		$this->assertContains( PresetApplier::BACKUP_OPTION, Uninstaller::options() );
	}

	public function test_undo_restores_previous_settings_and_removes_backup(): void {
		$this->settings->update(
			array(
				'display'   => array( 'position' => 'left' ),
				'behaviour' => array( 'welcome' => 'Before' ),
				'actions'   => array(
					array(
						'id'    => 'mine',
						'label' => 'Mine',
						'type'  => 'link',
					),
				),
			)
		);
		$before = $this->settings->all();

		$this->applier->apply( 'healthcare' );
		$this->assertNotSame( $before, $this->settings->all() );

		$result = $this->applier->undo();

		$this->assertSame( $before, $result );
		$this->assertSame( $before, ( new Settings() )->all() );
		$this->assertFalse( get_option( PresetApplier::BACKUP_OPTION ) );
	}

	public function test_undo_without_backup_returns_error(): void {
		$result = $this->applier->undo();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_filter_adds_custom_presets_as_array_and_json_file(): void {
		$json               = get_temp_dir() . 'devrivo-preset-' . wp_generate_password( 8, false ) . '.json';
		$this->temp_files[] = $json;
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$json,
			(string) wp_json_encode(
				array(
					'id'       => 'gym',
					'version'  => 2,
					'title'    => 'Gym',
					'settings' => array( 'appearance' => array( 'radius' => 4 ) ),
				)
			)
		);

		$add = static function ( array $presets ) use ( $json ): array {
			$presets[] = array(
				'id'          => 'bakery',
				'version'     => 1,
				'title'       => 'Bakery',
				'description' => 'Bread and cakes.',
				'settings'    => array( 'behaviour' => array( 'welcome' => 'Fresh today!' ) ),
			);
			$presets[] = $json;
			return $presets;
		};
		add_filter( 'devrivo_chatbot_presets', $add );

		$all = ( new PresetRepository() )->all();
		remove_filter( 'devrivo_chatbot_presets', $add );

		$this->assertSame( array_merge( self::BUILT_IN, array( 'bakery', 'gym' ) ), array_keys( $all ) );
		$this->assertSame( 'Fresh today!', $all['bakery']['settings']['behaviour']['welcome'] );
		$this->assertSame( 2, $all['gym']['version'] );
		$this->assertSame( '', $all['gym']['description'] );
		$this->assertSame( 4, $all['gym']['settings']['appearance']['radius'] );
	}

	public function test_invalid_custom_presets_are_skipped(): void {
		$this->setExpectedIncorrectUsage( PresetRepository::class . '::all' );

		$valid   = array(
			'id'       => 'valid',
			'version'  => 1,
			'title'    => 'Valid',
			'settings' => array(),
		);
		$invalid = array(
			array_merge( $valid, array( 'id' => 'Bad ID' ) ),
			array_merge( $valid, array( 'id' => 'undo' ) ),
			array_merge( $valid, array( 'title' => '' ) ),
			array_merge( $valid, array( 'version' => 0 ) ),
			array_merge( $valid, array( 'settings' => array( 'privacy' => array( 'retention_days' => 5 ) ) ) ),
			array_merge( $valid, array( 'settings' => array( 'appearance' => array( 'radius' => 500 ) ) ) ),
			array_merge( $valid, array( 'settings' => array( 'appearance' => array( 'typo' => 1 ) ) ) ),
			array_merge( $valid, array( 'settings' => array( 'actions' => array( array( 'id' => 'x' ) ) ) ) ),
			'/does/not/exist.json',
		);

		$add = static function ( array $presets ) use ( $valid, $invalid ): array {
			return array_merge( $presets, $invalid, array( $valid ) );
		};
		add_filter( 'devrivo_chatbot_presets', $add );

		$ids = array_keys( ( new PresetRepository() )->all() );
		remove_filter( 'devrivo_chatbot_presets', $add );

		$this->assertSame( array_merge( self::BUILT_IN, array( 'valid' ) ), $ids );
	}

	public function test_preset_applied_action_fires_once_with_arguments(): void {
		$calls  = array();
		$record = static function ( ...$args ) use ( &$calls ): void {
			$calls[] = $args;
		};
		add_action( 'devrivo_chatbot_preset_applied', $record, 10, 3 );

		$result = $this->applier->apply( 'portfolio', 'merge' );
		remove_action( 'devrivo_chatbot_preset_applied', $record, 10 );

		$this->assertCount( 1, $calls );
		$this->assertSame( array( 'portfolio', 'merge', $result['settings'] ), $calls[0] );
	}

	public function test_every_preset_section_matches_its_schema_after_apply(): void {
		foreach ( self::BUILT_IN as $id ) {
			$result = $this->applier->apply( $id );
			$this->assertIsArray( $result, $id );

			foreach ( Schema::sections() as $name => $schema ) {
				$this->assertTrue( rest_validate_value_from_schema( $result['settings'][ $name ], $schema, $name ), "{$id}: {$name}" );
			}
		}
	}
}
