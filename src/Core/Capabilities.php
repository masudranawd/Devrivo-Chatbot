<?php
/**
 * Plugin capabilities.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capability for managing the plugin.
 *
 * Administrators get it on install. Agencies can grant it to other roles (for
 * example a client's editor) with any role editor, without giving them
 * manage_options. Super admins on multisite pass every check automatically.
 */
final class Capabilities {

	/**
	 * Manage settings, actions, flows, forms, knowledge and leads.
	 */
	public const MANAGE = 'devrivo_chatbot_manage';

	/**
	 * Grants the capability to administrators.
	 */
	public static function grant_defaults(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Removes the capability from every role.
	 */
	public static function remove_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE ) ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}
}
