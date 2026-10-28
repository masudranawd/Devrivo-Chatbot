<?php
/**
 * Settings repository.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Settings;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes all plugin settings.
 *
 * Storage:
 * - `devrivo_chatbot_display`  (autoloaded, tiny): the "display" section, so the
 *   front end can decide whether to load anything without an extra query.
 * - `devrivo_chatbot_settings` (not autoloaded): every other section plus a
 *   settings "version" used by SettingsMigrator.
 *
 * Updates are partial per section: keys sent for a section are merged over its
 * current values (lists are replaced), the whole section is validated, and
 * nothing is saved if any section is invalid.
 */
final class Settings {

	/**
	 * Main settings option (not autoloaded).
	 */
	public const OPTION = 'devrivo_chatbot_settings';

	/**
	 * Display option (autoloaded).
	 */
	public const DISPLAY_OPTION = 'devrivo_chatbot_display';

	/**
	 * Config version option (autoloaded, tiny). Increments on every save so the
	 * public widget config URL changes and caches refresh.
	 */
	public const CONFIG_VERSION_OPTION = 'devrivo_chatbot_config_version';

	/**
	 * Current settings structure version.
	 */
	public const VERSION = 1;

	/**
	 * Allowed inline HTML for "html" strings (e.g. consent text with a policy link).
	 */
	private const INLINE_HTML = array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
		'strong' => array(),
		'em'     => array(),
	);

	/**
	 * In-request cache of all sections. Options themselves are already in the object cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * All sections, with defaults filled in.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$this->cache = $this->load();
		}

		return $this->cache;
	}

	/**
	 * One section.
	 *
	 * @param string $section Section name.
	 * @return mixed Section value, or null for an unknown section.
	 */
	public function get( string $section ): mixed {
		return $this->all()[ $section ] ?? null;
	}

	/**
	 * Validates and saves a partial update.
	 *
	 * @param array<string, mixed> $changes Section name => values.
	 * @return array<string, mixed>|WP_Error All settings after the update, or an error (nothing saved).
	 */
	public function update( array $changes ): array|WP_Error {
		$current  = $this->all();
		$sections = Schema::sections();
		$next     = array();

		foreach ( $changes as $name => $value ) {
			$name = (string) $name;

			if ( ! isset( $sections[ $name ] ) ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %s: Settings section name. */
					sprintf( __( 'Unknown settings section: %s.', 'devrivo-chatbot' ), $name ),
					array( 'status' => 400 )
				);
			}

			$schema = $sections[ $name ];

			if ( ! is_array( $value ) ) {
				return self::invalid( $name, __( 'Each settings section must be a JSON object or list.', 'devrivo-chatbot' ) );
			}

			// Objects merge over current values; lists are replaced as a whole.
			$candidate = 'object' === $schema['type'] && is_array( $current[ $name ] )
				? array_merge( $current[ $name ], $value )
				: $value;

			$valid = rest_validate_value_from_schema( $candidate, $schema, $name );
			if ( is_wp_error( $valid ) ) {
				return self::invalid( $name, $valid->get_error_message() );
			}

			$next[ $name ] = self::clean( rest_sanitize_value_from_schema( $candidate, $schema, $name ), $schema );
		}

		if ( array() !== $next ) {
			$this->save( array_merge( $current, $next ), array_keys( $next ) );
		}

		return $this->all();
	}

	/**
	 * Restores defaults for one section, or for everything.
	 *
	 * @param string|null $section Section name, or null for all sections.
	 * @return array<string, mixed>|WP_Error
	 */
	public function reset( ?string $section = null ): array|WP_Error {
		$defaults = Schema::defaults();

		if ( null === $section ) {
			$this->save( $defaults, array_keys( $defaults ) );
			return $this->all();
		}

		if ( ! array_key_exists( $section, $defaults ) ) {
			return new WP_Error(
				'rest_invalid_param',
				/* translators: %s: Settings section name. */
				sprintf( __( 'Unknown settings section: %s.', 'devrivo-chatbot' ), $section ),
				array( 'status' => 400 )
			);
		}

		$values             = $this->all();
		$values[ $section ] = $defaults[ $section ];
		$this->save( $values, array( $section ) );

		return $this->all();
	}

	/**
	 * Whether the admin opted in to deleting all data on uninstall.
	 *
	 * Static and dependency-free because it runs from uninstall.php.
	 */
	public static function delete_data_on_uninstall(): bool {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) && ! empty( $stored['privacy']['delete_data_on_uninstall'] );
	}

	/**
	 * Current config version (1 until the first save).
	 */
	public static function config_version(): int {
		return max( 1, (int) get_option( self::CONFIG_VERSION_OPTION, 1 ) );
	}

	/**
	 * Clears the in-request cache (e.g. after another process changed options).
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * Reads stored values, migrates old structures and fills defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function load(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$version = (int) ( $stored['version'] ?? 0 );
		if ( array() !== $stored && $version < self::VERSION ) {
			$stored = SettingsMigrator::migrate( $stored, $version );
			update_option( self::OPTION, $stored, false );
		}

		$display = get_option( self::DISPLAY_OPTION, array() );
		$values  = array();

		foreach ( Schema::sections() as $name => $schema ) {
			$raw   = Schema::DISPLAY === $name ? ( is_array( $display ) ? $display : null ) : ( $stored[ $name ] ?? null );
			$value = Schema::with_defaults( $raw, $schema );

			// Corrupt or hand-edited data falls back to defaults instead of breaking the site.
			if ( is_wp_error( rest_validate_value_from_schema( $value, $schema, $name ) ) ) {
				$value = Schema::default_for( $schema );
			}

			$values[ $name ] = $value;
		}

		return $values;
	}

	/**
	 * Writes the options that contain changed sections.
	 *
	 * @param array<string, mixed> $values  All sections.
	 * @param string[]             $changed Changed section names.
	 */
	private function save( array $values, array $changed ): void {
		if ( in_array( Schema::DISPLAY, $changed, true ) ) {
			update_option( self::DISPLAY_OPTION, $values[ Schema::DISPLAY ], true );
		}

		if ( array() !== array_diff( $changed, array( Schema::DISPLAY ) ) ) {
			$stored = array( 'version' => self::VERSION );
			foreach ( $values as $name => $value ) {
				if ( Schema::DISPLAY !== $name ) {
					$stored[ $name ] = $value;
				}
			}
			update_option( self::OPTION, $stored, false );
		}

		update_option( self::CONFIG_VERSION_OPTION, self::config_version() + 1, true );

		$this->cache = null;
	}

	/**
	 * Cleans strings that have no format or enum, walking the schema.
	 *
	 * Strings in free-form nodes (no schema) are cleaned as plain text.
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	private static function clean( mixed $value, array $schema ): mixed {
		if ( is_array( $value ) ) {
			$type = $schema['type'] ?? null;

			if ( 'array' === $type ) {
				$items = is_array( $schema['items'] ?? null ) ? $schema['items'] : array();
				return array_values( array_map( static fn( $item ) => self::clean( $item, $items ), $value ) );
			}

			$cleaned = array();
			foreach ( $value as $key => $item ) {
				$child = $schema['properties'][ $key ] ?? ( is_array( $schema['additionalProperties'] ?? null ) ? $schema['additionalProperties'] : array() );

				$cleaned[ is_string( $key ) ? sanitize_key( $key ) : $key ] = self::clean( $item, $child );
			}
			return $cleaned;
		}

		if ( is_string( $value ) && ! isset( $schema['format'] ) && ! isset( $schema['enum'] ) ) {
			return match ( $schema['x-sanitize'] ?? 'text' ) {
				'textarea' => sanitize_textarea_field( $value ),
				'html'     => wp_kses( $value, self::INLINE_HTML ),
				default    => sanitize_text_field( $value ),
			};
		}

		return $value;
	}

	/**
	 * Validation error for a section, in REST format.
	 *
	 * @param string $section Section name.
	 * @param string $message Message.
	 */
	private static function invalid( string $section, string $message ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			$message,
			array(
				'status' => 400,
				'params' => array( $section => $message ),
			)
		);
	}
}
