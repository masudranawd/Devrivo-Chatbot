<?php
/**
 * Deactivation.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Data\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Stops scheduled work. Never deletes data; that is uninstall's job.
 */
final class Deactivator {

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		foreach ( self::cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Cron hooks owned by the plugin. Add each one here when it ships.
	 *
	 * @return string[]
	 */
	public static function cron_hooks(): array {
		return array( Privacy::PURGE_HOOK );
	}
}
