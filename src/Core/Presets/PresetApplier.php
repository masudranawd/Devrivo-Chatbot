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
 * - replace: every section in the preset becomes the full section value
 *   (keys the preset leaves out reset to their defaults; lists are replaced).
 * - merge: a section is only applied if the admin hasn't changed it yet
 *   (its current value equals the default); otherwise it is skipped.
 *
 * Before writing, all current settings are saved to a backup option, which
 * undo() restores.
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

			// Full objects, so Settings::update() doesn't merge over old keys.
			$changes[ $section ] = 'object' === $schema['type'] ? Schema::with_defaults( $value, $schema ) : $value;
			$applied[]           = $section;
		}

		$changes['general'] = array(
			'preset'         => $preset['id'],
			'preset_version' => $preset['version'],
		);

		$previous_backup = get_option( self::BACKUP_OPTION );
		update_option(
			self::BACKUP_OPTION,
			array(
				'preset'   => $preset['id'],
				'time'     => time(),
				'settings' => $current,
			),
			false
		);

		$result = $this->settings->update( $changes );

		if ( is_wp_error( $result ) ) {
			// Nothing was saved, so keep the backup the admin had before.
			if ( false === $previous_backup ) {
				delete_option( self::BACKUP_OPTION );
			} else {
				update_option( self::BACKUP_OPTION, $previous_backup, false );
			}
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
		$result = $this->settings->update( array_intersect_key( $backup['settings'], Schema::sections() ) );

		if ( ! is_wp_error( $result ) ) {
			delete_option( self::BACKUP_OPTION );
		}

		return $result;
	}
}
