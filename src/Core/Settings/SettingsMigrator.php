<?php
/**
 * Settings structure migrations.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Upgrades stored settings when Settings::VERSION increases.
 *
 * Each step receives the stored array and returns the upgraded array. Steps
 * must be pure and idempotent. New keys with defaults need no step: Schema
 * fills them on read. Add a step only to rename, move or transform values.
 */
final class SettingsMigrator {

	/**
	 * Runs every step newer than the stored version.
	 *
	 * @param array<string, mixed>      $stored Stored settings.
	 * @param int                       $from   Stored version.
	 * @param array<int, callable>|null $steps  Steps keyed by target version (for tests).
	 * @return array<string, mixed>
	 */
	public static function migrate( array $stored, int $from, ?array $steps = null ): array {
		$steps = $steps ?? self::steps();
		ksort( $steps );

		foreach ( $steps as $version => $step ) {
			if ( $from < $version ) {
				$stored = $step( $stored );
			}
		}

		$stored['version'] = array() === $steps ? Settings::VERSION : max( Settings::VERSION, ...array_keys( $steps ) );

		return $stored;
	}

	/**
	 * Migration steps keyed by the version they upgrade to. Version 1 is the first structure.
	 *
	 * @return array<int, callable>
	 */
	private static function steps(): array {
		return array();
	}
}
