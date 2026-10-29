<?php
/**
 * Uninstall routine.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\AI\Budget;
use DevRivo\Chatbot\Core\Presets\PresetApplier;
use DevRivo\Chatbot\Core\Settings\Settings;
use DevRivo\Chatbot\Data\Leads;
use DevRivo\Chatbot\Knowledge\Index;
use DevRivo\Chatbot\Knowledge\Rebuild;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin's capability and cron events on every site, and its data
 * (options, and the leads and index tables) only on sites where the admin opted in
 * (Privacy → Delete data on uninstall).
 */
final class Uninstaller {

	/**
	 * Runs uninstall for the site, or for every site on a multisite network.
	 */
	public static function uninstall(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Option names removed on uninstall.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return array(
			Activator::SCHEMA_OPTION,
			Settings::OPTION,
			Settings::DISPLAY_OPTION,
			Settings::CONFIG_VERSION_OPTION,
			PresetApplier::BACKUP_OPTION,
			Index::DRIVER_OPTION,
			Index::HAS_CONTENT_OPTION,
			Rebuild::OPTION,
			Budget::OPTION,
			'devrivo_chatbot_delete_data_on_uninstall', // Legacy 0.1.0 option.
		);
	}

	/**
	 * Cleans up the current site.
	 */
	private static function uninstall_site(): void {
		Capabilities::remove_all();

		foreach ( Deactivator::cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}

		if ( ! Settings::delete_data_on_uninstall() ) {
			return;
		}

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		Leads::drop_table();
		Index::drop_table();
	}
}
