<?php
/**
 * Deactivation.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Data\Privacy;
use DevRivo\Chatbot\Knowledge\Indexer;
use DevRivo\Chatbot\Knowledge\Rebuild;

defined( 'ABSPATH' ) || exit;

/**
 * Stops scheduled work. Never deletes data; that is uninstall's job.
 */
final class Deactivator {

	/**
	 * Deactivation hook callback.
	 */
	public static function deactivate(): void {
		// wp_unschedule_hook() also removes events with arguments (one per post).
		foreach ( self::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Cron hooks owned by the plugin. Add each one here when it ships.
	 *
	 * @return string[]
	 */
	public static function cron_hooks(): array {
		return array( Privacy::PURGE_HOOK, Indexer::HOOK, Rebuild::HOOK );
	}
}
