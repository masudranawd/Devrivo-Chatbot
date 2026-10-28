<?php
/**
 * Activation and install/upgrade.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Sets up options and runs schema upgrades.
 *
 * maybe_install() also runs on every boot. It is cheap (one autoloaded
 * option), and it covers plugin updates, which never fire the activation hook,
 * and network activation, where the hook runs on the main site only.
 */
final class Activator {

	/**
	 * Option holding the installed schema version.
	 */
	public const SCHEMA_OPTION = 'devrivo_chatbot_schema_version';

	/**
	 * Option: delete all plugin data on uninstall (admin opt-in, default off).
	 */
	public const UNINSTALL_OPTION = 'devrivo_chatbot_delete_data_on_uninstall';

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::maybe_install();
	}

	/**
	 * Installs or upgrades the current site when its schema is behind.
	 */
	public static function maybe_install(): void {
		$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

		if ( $installed >= DEVRIVO_CHATBOT_SCHEMA_VERSION ) {
			return;
		}

		if ( 0 === $installed ) {
			add_option( self::UNINSTALL_OPTION, 0, '', false );
		}

		// v1.0: the migration runner applies steps ( $installed + 1 ) to SCHEMA_VERSION here.

		update_option( self::SCHEMA_OPTION, DEVRIVO_CHATBOT_SCHEMA_VERSION, true );
	}
}
