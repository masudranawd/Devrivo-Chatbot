<?php
/**
 * Activation and install/upgrade.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Data\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and install/upgrade entry point.
 *
 * The maybe_install() method also runs on every boot. It is cheap (one autoloaded
 * option and the already loaded roles), and it covers plugin updates, which
 * never fire the activation hook, and network activation, where the hook runs
 * on the main site only.
 *
 * Both make sure administrators have the plugin capability: uninstalling
 * always removes it but keeps the data (and schema version) without the
 * opt-in, so after a reinstall no migration would grant it again.
 */
final class Activator {

	/**
	 * Option holding the installed schema version.
	 */
	public const SCHEMA_OPTION = 'devrivo_chatbot_schema_version';

	/**
	 * Activation hook callback.
	 */
	public static function activate(): void {
		self::maybe_install();
		Capabilities::grant_defaults();

		// Deactivation clears cron events; migrations don't run again on reactivation.
		Privacy::schedule();
	}

	/**
	 * Runs pending migrations when the stored schema is behind, and gives
	 * administrators the plugin capability when their role lacks it (e.g. a
	 * reinstall uploaded with "Replace current", without reactivation).
	 */
	public static function maybe_install(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) < DEVRIVO_CHATBOT_SCHEMA_VERSION ) {
			Migrations::run();
		}

		// Only writes when the administrator role lacks the capability.
		Capabilities::grant_defaults();
	}
}
