<?php
/**
 * Uninstall routine.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

use DevRivo\Chatbot\Core\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Removes the plugin's capability on every site, and its data only on sites
 * where the admin opted in (Privacy → Delete data on uninstall).
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
			'devrivo_chatbot_delete_data_on_uninstall', // Legacy 0.1.0 option.
		);
	}

	/**
	 * Cleans up the current site.
	 */
	private static function uninstall_site(): void {
		Capabilities::remove_all();

		if ( ! Settings::delete_data_on_uninstall() ) {
			return;
		}

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		// v1.0: drop custom tables and delete transients here.
	}
}
