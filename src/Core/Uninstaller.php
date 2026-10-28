<?php
/**
 * Uninstall routine.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Removes plugin data, but only on sites where the admin opted in.
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
			Activator::UNINSTALL_OPTION,
		);
	}

	/**
	 * Removes data for the current site if the admin opted in.
	 */
	private static function uninstall_site(): void {
		if ( ! (bool) get_option( Activator::UNINSTALL_OPTION, 0 ) ) {
			return;
		}

		foreach ( self::options() as $option ) {
			delete_option( $option );
		}

		// v1.0: drop custom tables and delete transients here.
	}
}
