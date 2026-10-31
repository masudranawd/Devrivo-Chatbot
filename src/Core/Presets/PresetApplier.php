<?php
/**
 * Preset applier.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Presets;

use DevRivo\Chatbot\Core\Settings\Schema;
use DevRivo\Chatbot\Core\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies a preset to the settings, with a one-step undo.
 *
 * Modes:
 * - replace: every section in the preset is applied. For objects only the
 *   keys the preset provides are set, so other keys (e.g. AI enabled, model,
 *   brand name) keep their current values; lists are replaced whole.
 * - merge: a section is only applied if the admin hasn't changed it yet
 *   (its current value equals the default); otherwise it is skipped.
 *
 * Before writing, all current settings are saved to a backup option, which
 * undo() restores. Settings imports use the same backup (save_with_backup()).
 */
final class PresetApplier {

	/**
	 * Backup option (not autoloaded).
	 */
	public const BACKUP_OPTION = 'devrivo_chatbot_settings_backup';

	/**
	 * Allowed modes.
	 */
	public const MODES = array( 'replace', 'merge' );

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings Settings repository.
	 * @param PresetRepository $presets  Preset repository.
	 */
	public function __construct( private Settings $settings, private PresetRepository $presets ) {}

	/**
	 * Applies a preset.
	 *
	 * @param string $id   Preset ID.
	 * @param string $mode "replace" or "merge".
	 * @return array{settings: array<string, mixed>, applied: string[], skipped: string[]}|WP_Error
	 */
	public function apply( string $id, string $mode = 'replace' ): array|WP_Error {
		$preset = $this->presets->get( $id );
		if ( null === $preset ) {
			return new WP_Error(
				'devrivo_chatbot_preset_not_found',
				__( 'Preset not found.', 'devrivo-chatbot' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $mode, self::MODES, true ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'Mode must be "replace" or "merge".', 'devrivo-chatbot' ),
				array( 'status' => 400 )
			);
		}

		$current = $this->settings->all();
		$changes = array();
		$applied = array();
		$skipped = array();

		foreach ( $preset['settings'] as $section => $value ) {
			$schema = (array) Schema::section( $section );

			if ( 'merge' === $mode && Schema::default_for( $schema ) !== ( $current[ $section ] ?? null ) ) {
				$skipped[] = $section;
				continue;
			}

			// Settings::update() merges objects over current values and replaces lists and ID maps.
			$changes[ $section ] = $value;
			$applied[]           = $section;
		}

		// Replacing the actions also replaces the flows that point to them, so
		// flows from an earlier preset can't refer to actions that are gone.
		if ( 'replace' === $mode && isset( $changes['actions'] ) && ! isset( $changes['flows'] ) ) {
			$changes['flows'] = array();
			$applied[]        = 'flows';
		}

		$changes['general'] = array(
			'preset'         => $preset['id'],
			'preset_version' => $preset['version'],
		);

		$result = $this->save_with_backup( $changes, $preset['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after a preset was applied to the settings.
		 *
		 * @since 1.0.0
		 *
		 * @param string               $preset_id Preset ID.
		 * @param string               $mode      "replace" or "merge".
		 * @param array<string, mixed> $settings  All settings after the change.
		 */
		do_action( 'devrivo_chatbot_preset_applied', $preset['id'], $mode, $result );

		return array(
			'settings' => $result,
			'applied'  => $applied,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Saves all current settings to the backup option, then applies the
	 * changes in one Settings::update(). When the update fails nothing is
	 * saved and the previous backup is kept.
	 *
	 * @param array<string, mixed> $changes Section name => values.
	 * @param string               $preset  Preset ID, or '' for a settings import.
	 * @return array<string, mixed>|WP_Error All settings after the update.
	 */
	public function save_with_backup( array $changes, string $preset = '' ): array|WP_Error {
		$previous_backup = get_option( self::BACKUP_OPTION );
		update_option(
			self::BACKUP_OPTION,
			array(
				'preset'   => $preset,
				'time'     => time(),
				'settings' => $this->settings->all(),
			),
			false
		);

		$result = $this->settings->update( $changes );

		if ( is_wp_error( $result ) ) {
			if ( false === $previous_backup ) {
				delete_option( self::BACKUP_OPTION );
			} else {
				update_option( self::BACKUP_OPTION, $previous_backup, false );
			}
		}

		return $result;
	}

	/**
	 * Whether an undo is available, for the admin's "Undo last change" button.
	 *
	 * @return array{exists: bool, preset: string, created: string|null} Preset is '' after an import; created is UTC ISO 8601.
	 */
	public static function backup_status(): array {
		$backup = get_option( self::BACKUP_OPTION );
		$exists = is_array( $backup ) && is_array( $backup['settings'] ?? null );
		$time   = $exists ? (int) ( $backup['time'] ?? 0 ) : 0;

		return array(
			'exists'  => $exists,
			'preset'  => $exists ? (string) ( $backup['preset'] ?? '' ) : '',
			'created' => $time > 0 ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null,
		);
	}

	/**
	 * Restores the settings saved before the last preset was applied.
	 *
	 * @return array<string, mixed>|WP_Error All settings after the restore.
	 */
	public function undo(): array|WP_Error {
		$backup = get_option( self::BACKUP_OPTION );

		if ( ! is_array( $backup ) || ! is_array( $backup['settings'] ?? null ) ) {
			return new WP_Error(
				'devrivo_chatbot_no_preset_backup',
				__( 'There is nothing to undo.', 'devrivo-chatbot' ),
				array( 'status' => 404 )
			);
		}

		// Ignore sections a newer or older version doesn't know.
		$restore = array_intersect_key( $backup['settings'], Schema::sections() );

		// The business profile and setup state are not part of a preset or
		// import, so undoing one keeps their current values.
		if ( is_array( $restore['general'] ?? null ) ) {
			$general                        = (array) $this->settings->get( 'general' );
			$restore['general']['business'] = $general['business'] ?? array();
			$restore['general']['setup']    = $general['setup'] ?? array();
		}

		$result = $this->settings->update( $restore );

		if ( ! is_wp_error( $result ) ) {
			delete_option( self::BACKUP_OPTION );
		}

		return $result;
	}
}
