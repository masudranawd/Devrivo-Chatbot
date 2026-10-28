<?php
/**
 * Database and install migrations.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs numbered migrations up to DEVRIVO_CHATBOT_SCHEMA_VERSION, per site.
 *
 * Every step must be idempotent: two requests can start an upgrade at the same
 * time. The stored schema version is advanced after each successful step, so an
 * interrupted upgrade resumes where it stopped.
 */
final class Migrations {

	/**
	 * Uninstall opt-in option from 0.1.0, moved into settings in schema 2.
	 */
	private const LEGACY_UNINSTALL_OPTION = 'devrivo_chatbot_delete_data_on_uninstall';

	/**
	 * Runs pending steps for the current site.
	 *
	 * @param array<int, callable>|null $steps Steps keyed by schema version (for tests).
	 */
	public static function run( ?array $steps = null ): void {
		$steps     = $steps ?? self::steps();
		$installed = (int) get_option( Activator::SCHEMA_OPTION, 0 );

		ksort( $steps );

		foreach ( $steps as $version => $step ) {
			if ( $installed >= $version || $version > DEVRIVO_CHATBOT_SCHEMA_VERSION ) {
				continue;
			}

			$step();
			update_option( Activator::SCHEMA_OPTION, $version, true );
			$installed = $version;
		}
	}

	/**
	 * Steps keyed by the schema version they produce.
	 *
	 * @return array<int, callable>
	 */
	private static function steps(): array {
		return array(
			1 => array( self::class, 'v1_baseline' ),
			2 => array( self::class, 'v2_settings_and_capabilities' ),
		);
	}

	/**
	 * 0.1.0 baseline: uninstall opt-in option.
	 */
	public static function v1_baseline(): void {
		add_option( self::LEGACY_UNINSTALL_OPTION, 0, '', false );
	}

	/**
	 * 0.2.0: settings options, capability, and the uninstall opt-in moved into settings.
	 */
	public static function v2_settings_and_capabilities(): void {
		Capabilities::grant_defaults();

		add_option( Settings::DISPLAY_OPTION, Schema::default_for( (array) Schema::section( Schema::DISPLAY ) ), '', true );

		$stored = get_option( Settings::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$stored['version'] = $stored['version'] ?? Settings::VERSION;
		$stored['privacy'] = is_array( $stored['privacy'] ?? null ) ? $stored['privacy'] : array();

		// The 0.1.0 option is the source of truth when it exists.
		$legacy = get_option( self::LEGACY_UNINSTALL_OPTION, null );

		$stored['privacy']['delete_data_on_uninstall'] = null !== $legacy
			? (bool) $legacy
			: (bool) ( $stored['privacy']['delete_data_on_uninstall'] ?? false );

		update_option( Settings::OPTION, $stored, false );
		delete_option( self::LEGACY_UNINSTALL_OPTION );
	}
}
