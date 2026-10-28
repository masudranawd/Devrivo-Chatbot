<?php
/**
 * Preset repository.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Presets;

use DevRivo\Chatbot\Core\Settings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Loads and validates industry presets.
 *
 * Built-in presets are PHP files in src/Presets/ (PHP rather than JSON so their
 * text is translatable). Other plugins and themes add or replace presets with
 * the `devrivo_chatbot_presets` filter. Invalid presets are skipped and
 * reported with _doing_it_wrong().
 *
 * Presets are only used in the admin and REST API, never on the front end, so
 * they are cached for the current request only.
 */
final class PresetRepository {

	/**
	 * Settings sections a preset may contain.
	 */
	public const SECTIONS = array( 'appearance', 'behaviour', 'actions', 'flows', 'forms', 'ai' );

	/**
	 * Pattern for preset IDs.
	 */
	private const ID_PATTERN = '/^[a-z0-9_-]{1,40}$/';

	/**
	 * IDs that clash with REST routes.
	 */
	private const RESERVED_IDS = array( 'undo' );

	/**
	 * Site-specific keys a preset may never set, by section. Presets describe an
	 * industry, not a site: turning AI on, choosing a model or token budget, and
	 * branding stay the admin's decision and survive applying any preset.
	 */
	private const FORBIDDEN_KEYS = array(
		'ai'         => array( 'enabled', 'model', 'monthly_token_limit' ),
		'appearance' => array( 'brand_name', 'avatar_id' ),
	);

	/**
	 * Built-in preset IDs (file names in src/Presets/).
	 */
	private const BUILT_IN = array( 'business', 'healthcare', 'portfolio', 'agency' );

	/**
	 * In-request cache of valid presets.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $presets = null;

	/**
	 * All valid presets, keyed by ID.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->presets ) {
			return $this->presets;
		}

		$sources = array();
		foreach ( self::BUILT_IN as $id ) {
			$sources[ $id ] = dirname( __DIR__, 2 ) . '/Presets/' . $id . '.php';
		}

		/**
		 * Filters the available industry presets.
		 *
		 * Each entry is a preset definition array, or the path to a .php file
		 * that returns one, or to a .json file containing one. A definition has
		 * the keys id, version (int >= 1), title, description and settings
		 * (only the appearance, behaviour, actions, flows, forms and ai
		 * sections). A later entry with the same id replaces an earlier one.
		 * Invalid presets are skipped.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int|string, array<string, mixed>|string> $presets Preset definitions or file paths, keyed by ID for built-ins.
		 */
		$sources = apply_filters( 'devrivo_chatbot_presets', $sources );

		$presets = array();
		foreach ( $sources as $key => $source ) {
			$preset = is_string( $source ) ? self::load_file( $source ) : $source;
			$error  = self::validate( $preset );

			if ( null !== $error || ! is_array( $preset ) ) {
				_doing_it_wrong(
					__METHOD__,
					esc_html(
						sprintf(
							/* translators: 1: Preset key or file path, 2: Reason. */
							__( 'DevRivo Chatbot skipped the invalid preset "%1$s": %2$s', 'devrivo-chatbot' ),
							is_string( $source ) ? $source : (string) $key,
							(string) $error
						)
					),
					'1.0.0'
				);
				continue;
			}

			$presets[ $preset['id'] ] = array(
				'id'          => $preset['id'],
				'version'     => $preset['version'],
				'title'       => $preset['title'],
				'description' => $preset['description'] ?? '',
				'settings'    => $preset['settings'],
			);
		}

		$this->presets = $presets;

		return $presets;
	}

	/**
	 * One preset.
	 *
	 * @param string $id Preset ID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Clears the in-request cache.
	 */
	public function flush(): void {
		$this->presets = null;
	}

	/**
	 * Reads a preset from a .php or .json file.
	 *
	 * @param string $path File path.
	 * @return mixed The definition, or null if the file can't be read.
	 */
	private static function load_file( string $path ): mixed {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		return match ( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			'php'   => include $path,
			'json'  => wp_json_file_decode( $path, array( 'associative' => true ) ),
			default => null,
		};
	}

	/**
	 * Validates a preset definition.
	 *
	 * @param mixed $preset Definition.
	 * @return string|null Reason it is invalid, or null if valid.
	 */
	private static function validate( mixed $preset ): ?string {
		if ( ! is_array( $preset ) ) {
			return __( 'Not a readable preset file or array.', 'devrivo-chatbot' );
		}

		$id = $preset['id'] ?? null;
		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) || in_array( $id, self::RESERVED_IDS, true ) ) {
			return __( 'The id is invalid or reserved.', 'devrivo-chatbot' );
		}

		if ( ! is_string( $preset['title'] ?? null ) || '' === trim( $preset['title'] ) ) {
			return __( 'Title must be a non-empty string.', 'devrivo-chatbot' );
		}

		if ( isset( $preset['description'] ) && ! is_string( $preset['description'] ) ) {
			return __( 'Description must be a string.', 'devrivo-chatbot' );
		}

		if ( ! is_int( $preset['version'] ?? null ) || $preset['version'] < 1 ) {
			return __( 'Version must be an integer of 1 or more.', 'devrivo-chatbot' );
		}

		if ( ! is_array( $preset['settings'] ?? null ) ) {
			return __( 'Settings must be an object.', 'devrivo-chatbot' );
		}

		foreach ( $preset['settings'] as $section => $value ) {
			$schema = in_array( $section, self::SECTIONS, true ) ? Schema::section( (string) $section ) : null;
			if ( null === $schema ) {
				/* translators: %s: Settings section name. */
				return sprintf( __( 'Section "%s" is not allowed in presets.', 'devrivo-chatbot' ), $section );
			}

			$forbidden = is_array( $value ) ? array_intersect( self::FORBIDDEN_KEYS[ $section ] ?? array(), array_keys( $value ) ) : array();
			if ( array() !== $forbidden ) {
				return sprintf(
					/* translators: 1: Settings section name, 2: Comma-separated setting keys. */
					__( 'Presets may not set site-specific settings: %1$s.%2$s.', 'devrivo-chatbot' ),
					$section,
					implode( ', ', $forbidden )
				);
			}

			$valid = rest_validate_value_from_schema( self::candidate( $value, $schema ), $schema, (string) $section );
			if ( is_wp_error( $valid ) ) {
				return $valid->get_error_message();
			}
		}

		return null;
	}

	/**
	 * Value to validate for a section: partial objects are filled with defaults,
	 * keeping unknown keys so strict objects still reject them.
	 *
	 * @param mixed                $value  Preset section value.
	 * @param array<string, mixed> $schema Section schema.
	 * @return mixed
	 */
	private static function candidate( mixed $value, array $schema ): mixed {
		if ( 'object' !== $schema['type'] || ! is_array( $value ) ) {
			return $value;
		}

		$filled = Schema::with_defaults( $value, $schema );

		return is_array( $filled ) ? array_replace( $filled, $value ) : $filled;
	}
}
