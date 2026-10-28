<?php
/**
 * Settings schema.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * JSON schema (draft-04 subset supported by the WordPress REST API) for every
 * settings section, plus default and shape helpers.
 *
 * Validation and sanitisation use core's rest_validate_value_from_schema() and
 * rest_sanitize_value_from_schema(). Strings without a format or enum are also
 * cleaned by Settings, according to the custom "x-sanitize" keyword:
 * "text" (default), "textarea" or "html" (limited inline HTML).
 *
 * No translated strings here: this runs before `init` during installs.
 */
final class Schema {

	/**
	 * Section stored in its own small, autoloaded option.
	 */
	public const DISPLAY = 'display';

	/**
	 * Pattern for machine IDs (actions, flows, forms).
	 */
	private const ID_PATTERN = '^[a-z0-9_-]{1,40}$';

	/**
	 * Section schemas, keyed by section name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sections(): array {
		return array(
			self::DISPLAY  => self::display(),
			'appearance'   => self::appearance(),
			'behaviour'    => self::behaviour(),
			'actions'      => self::actions(),
			'flows'        => self::free_form_map(),
			'forms'        => self::free_form_map(),
			'ai'           => self::ai(),
			'privacy'      => self::privacy(),
			'integrations' => self::integrations(),
		);
	}

	/**
	 * One section's schema.
	 *
	 * @param string $name Section name.
	 * @return array<string, mixed>|null
	 */
	public static function section( string $name ): ?array {
		return self::sections()[ $name ] ?? null;
	}

	/**
	 * Defaults for every section.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::sections() as $name => $schema ) {
			$defaults[ $name ] = self::default_for( $schema );
		}
		return $defaults;
	}

	/**
	 * Default value for a schema node.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	public static function default_for( array $schema ): mixed {
		if ( array_key_exists( 'default', $schema ) ) {
			return $schema['default'];
		}

		if ( 'object' === ( $schema['type'] ?? null ) ) {
			$value = array();
			foreach ( (array) ( $schema['properties'] ?? array() ) as $key => $child ) {
				$value[ $key ] = self::default_for( $child );
			}
			return $value;
		}

		return 'array' === ( $schema['type'] ?? null ) ? array() : null;
	}

	/**
	 * Fills missing keys with defaults (used when reading stored values, so
	 * settings added in newer versions appear without a migration).
	 *
	 * @param mixed                $value  Stored value.
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	public static function with_defaults( mixed $value, array $schema ): mixed {
		if ( null === $value ) {
			return self::default_for( $schema );
		}

		if ( 'object' === ( $schema['type'] ?? null ) && isset( $schema['properties'] ) && is_array( $value ) ) {
			$filled = array();
			foreach ( $schema['properties'] as $key => $child ) {
				$filled[ $key ] = self::with_defaults( $value[ $key ] ?? null, $child );
			}
			return $filled;
		}

		return $value;
	}

	/**
	 * Converts empty arrays to objects where the schema expects an object, so
	 * JSON responses contain {} rather than [].
	 *
	 * @param mixed                $value  Value.
	 * @param array<string, mixed> $schema Schema node.
	 * @return mixed
	 */
	public static function to_json_shape( mixed $value, array $schema ): mixed {
		$type = $schema['type'] ?? null;

		if ( 'object' === $type && is_array( $value ) ) {
			if ( array() === $value ) {
				return new \stdClass();
			}
			foreach ( $value as $key => $item ) {
				$child         = $schema['properties'][ $key ] ?? ( is_array( $schema['additionalProperties'] ?? null ) ? $schema['additionalProperties'] : array() );
				$value[ $key ] = self::to_json_shape( $item, $child );
			}
			return $value;
		}

		if ( 'array' === $type && is_array( $value ) ) {
			$items = is_array( $schema['items'] ?? null ) ? $schema['items'] : array();
			return array_map( static fn( $item ) => self::to_json_shape( $item, $items ), $value );
		}

		return $value;
	}

	/**
	 * Display: whether and where the widget loads (autoloaded, keep it small).
	 *
	 * @return array<string, mixed>
	 */
	private static function display(): array {
		return self::object(
			array(
				'enabled'        => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'position'       => array(
					'type'    => 'string',
					'enum'    => array( 'right', 'left' ),
					'default' => 'right',
				),
				'launcher_label' => array(
					'type'      => 'string',
					'maxLength' => 60,
					'default'   => '',
				),
				'primary_color'  => array(
					'type'    => 'string',
					'format'  => 'hex-color',
					'default' => '#1e3b36',
				),
				'rules'          => self::object(
					array(
						'mode'       => array(
							'type'    => 'string',
							'enum'    => array( 'all', 'include', 'exclude' ),
							'default' => 'all',
						),
						'post_ids'   => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'integer',
								'minimum' => 1,
							),
							'maxItems' => 500,
							'default'  => array(),
						),
						'post_types' => array(
							'type'     => 'array',
							'items'    => array(
								'type'    => 'string',
								'pattern' => '^[a-z0-9_-]{1,20}$',
							),
							'maxItems' => 50,
							'default'  => array(),
						),
						'devices'    => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'desktop', 'mobile' ),
							),
							'uniqueItems' => true,
							'default'     => array( 'desktop', 'mobile' ),
						),
					)
				),
			)
		);
	}

	/**
	 * Appearance.
	 *
	 * @return array<string, mixed>
	 */
	private static function appearance(): array {
		return self::object(
			array(
				'color_scheme' => array(
					'type'    => 'string',
					'enum'    => array( 'auto', 'light', 'dark' ),
					'default' => 'auto',
				),
				'radius'       => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 32,
					'default' => 22,
				),
				'brand_name'   => array(
					'type'      => 'string',
					'maxLength' => 80,
					'default'   => '',
				),
				'avatar_id'    => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
			)
		);
	}

	/**
	 * Behaviour.
	 *
	 * @return array<string, mixed>
	 */
	private static function behaviour(): array {
		return self::object(
			array(
				'greeting'        => array(
					'type'      => 'string',
					'maxLength' => 200,
					'default'   => '',
				),
				'welcome'         => array(
					'type'      => 'string',
					'maxLength' => 200,
					'default'   => '',
				),
				'auto_open'       => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'auto_open_delay' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 60000,
					'default' => 800,
				),
			)
		);
	}

	/**
	 * Quick actions. Per-type "config" schemas are added with the Action engine (step 4).
	 *
	 * @return array<string, mixed>
	 */
	private static function actions(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 20,
			'default'  => array(),
			'items'    => self::object(
				array(
					'id'     => array(
						'type'     => 'string',
						'pattern'  => self::ID_PATTERN,
						'required' => true,
					),
					'label'  => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 80,
						'required'  => true,
					),
					'type'   => array(
						'type'     => 'string',
						'pattern'  => '^[a-z_]{1,40}$',
						'required' => true,
					),
					'urgent' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'config' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'default'              => array(),
					),
				)
			),
		);
	}

	/**
	 * Map of ID => definition (flows, forms). Strict schemas arrive in steps 4 and 5;
	 * until then nested strings are cleaned as plain text.
	 *
	 * @return array<string, mixed>
	 */
	private static function free_form_map(): array {
		return array(
			'type'                 => 'object',
			'propertyNames'        => array( 'pattern' => self::ID_PATTERN ),
			'additionalProperties' => array( 'type' => 'object' ),
			'maxProperties'        => 50,
			'default'              => array(),
		);
	}

	/**
	 * AI. Keys are never stored here: providers are configured in Settings → Connectors.
	 *
	 * @return array<string, mixed>
	 */
	private static function ai(): array {
		return self::object(
			array(
				'enabled'             => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'model'               => array(
					'type'      => 'string',
					'maxLength' => 100,
					'default'   => '',
				),
				'monthly_token_limit' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
				'custom_instructions' => array(
					'type'       => 'string',
					'maxLength'  => 2000,
					'default'    => '',
					'x-sanitize' => 'textarea',
				),
			)
		);
	}

	/**
	 * Privacy.
	 *
	 * @return array<string, mixed>
	 */
	private static function privacy(): array {
		return self::object(
			array(
				'retention_days'           => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 3650,
					'default' => 180,
				),
				'consent_text'             => array(
					'type'       => 'string',
					'maxLength'  => 500,
					'default'    => '',
					'x-sanitize' => 'html',
				),
				'anonymize_ip'             => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'credit_link'              => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'delete_data_on_uninstall' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
	}

	/**
	 * Integrations.
	 *
	 * @return array<string, mixed>
	 */
	private static function integrations(): array {
		return self::object(
			array(
				'notification_emails' => array(
					'type'     => 'array',
					'items'    => array(
						'type'   => 'string',
						'format' => 'email',
					),
					'maxItems' => 10,
					'default'  => array(),
				),
			)
		);
	}

	/**
	 * Strict object node (unknown keys are rejected).
	 *
	 * @param array<string, mixed> $properties Properties.
	 * @return array<string, mixed>
	 */
	private static function object( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
	}
}
