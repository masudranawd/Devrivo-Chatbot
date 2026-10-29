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
	public const ID_PATTERN = '^[a-z0-9_-]{1,40}$';

	/**
	 * Pattern for an optional machine ID ('' or an ID).
	 */
	public const OPTIONAL_ID_PATTERN = '^([a-z0-9_-]{1,40})?$';

	/**
	 * Pattern for a 24-hour time (HH:MM).
	 */
	private const TIME_PATTERN = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

	/**
	 * Day keys of the opening-hours schedule.
	 */
	public const DAYS = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	/**
	 * Section schemas, keyed by section name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function sections(): array {
		return array(
			self::DISPLAY  => self::display(),
			'general'      => self::general(),
			'appearance'   => self::appearance(),
			'behaviour'    => self::behaviour(),
			'actions'      => self::actions(),
			'offers'       => self::offers(),
			'flows'        => self::flows(),
			'forms'        => self::forms(),
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

		// ID => item maps (flows, nodes): fill each item.
		if ( 'object' === ( $schema['type'] ?? null ) && isset( $schema['patternProperties'] ) && is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$child = self::child( $schema, (string) $key );
				if ( array() !== $child ) {
					$value[ $key ] = self::with_defaults( $item, $child );
				}
			}
		}

		return $value;
	}

	/**
	 * Schema of an object's property: from properties, then patternProperties,
	 * then additionalProperties. Empty when none applies.
	 *
	 * @param array<string, mixed> $schema Object schema.
	 * @param string               $key    Property name.
	 * @return array<string, mixed>
	 */
	public static function child( array $schema, string $key ): array {
		if ( isset( $schema['properties'][ $key ] ) ) {
			return $schema['properties'][ $key ];
		}

		foreach ( (array) ( $schema['patternProperties'] ?? array() ) as $pattern => $child ) {
			if ( rest_validate_json_schema_pattern( (string) $pattern, $key ) ) {
				return $child;
			}
		}

		return is_array( $schema['additionalProperties'] ?? null ) ? $schema['additionalProperties'] : array();
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
				$value[ $key ] = self::to_json_shape( $item, self::child( $schema, (string) $key ) );
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
	 * General: which preset was applied last (set by PresetApplier).
	 *
	 * @return array<string, mixed>
	 */
	private static function general(): array {
		return self::object(
			array(
				'preset'         => array(
					'type'    => 'string',
					'pattern' => '^([a-z0-9_-]{1,40})?$',
					'default' => '',
				),
				'preset_version' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
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
				'font'         => array(
					'type'    => 'string',
					'enum'    => array( 'system', 'inherit' ),
					'default' => 'system',
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
				'hours'           => self::hours(),
				// Add UTM parameters to quick-action links.
				'utm'             => array(
					'type'    => 'boolean',
					'default' => true,
				),
			)
		);
	}

	/**
	 * Opening hours. Timezone '' means the site timezone; ConfigValidator checks
	 * the timezone ID and that each opening is before its closing.
	 *
	 * @return array<string, mixed>
	 */
	private static function hours(): array {
		$day = array(
			'type'     => 'array',
			'maxItems' => 3,
			'default'  => array(),
			'items'    => self::object(
				array(
					'open'  => array(
						'type'     => 'string',
						'pattern'  => self::TIME_PATTERN,
						'required' => true,
					),
					'close' => array(
						'type'     => 'string',
						'pattern'  => self::TIME_PATTERN,
						'required' => true,
					),
				)
			),
		);

		return self::object(
			array(
				'timezone'    => array(
					'type'      => 'string',
					'maxLength' => 64,
					'default'   => '',
				),
				'schedule'    => self::object( array_fill_keys( self::DAYS, $day ) ),
				'show_status' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
	}

	/**
	 * Quick actions. Each type's "config" is checked by ConfigValidator against
	 * the ActionTypes schema; unknown types keep their config as stored.
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
					'id'           => array(
						'type'     => 'string',
						'pattern'  => self::ID_PATTERN,
						'required' => true,
					),
					'label'        => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 80,
						'required'  => true,
					),
					'type'         => array(
						'type'     => 'string',
						'pattern'  => '^[a-z_]{1,40}$',
						'required' => true,
					),
					'urgent'       => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'config'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'default'              => array(),
					),
					// False for helper actions used only inside flows.
					'show_in_menu' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				)
			),
		);
	}

	/**
	 * Offers shown in the widget's Offers tab.
	 *
	 * @return array<string, mixed>
	 */
	private static function offers(): array {
		return array(
			'type'     => 'array',
			'maxItems' => 6,
			'default'  => array(),
			'items'    => self::object(
				array(
					'id'        => array(
						'type'     => 'string',
						'pattern'  => self::ID_PATTERN,
						'required' => true,
					),
					'title'     => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 80,
						'required'  => true,
					),
					'text'      => array(
						'type'       => 'string',
						'maxLength'  => 300,
						'default'    => '',
						'x-sanitize' => 'textarea',
					),
					'cta_label' => array(
						'type'      => 'string',
						'maxLength' => 40,
						'default'   => '',
					),
					// ID of a quick action the call-to-action runs, or '' for none.
					'action'    => array(
						'type'    => 'string',
						'pattern' => '^([a-z0-9_-]{1,40})?$',
						'default' => '',
					),
					'featured'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				)
			),
		);
	}

	/**
	 * Guided conversation flows: flow ID => { title, start, nodes }.
	 *
	 * Map keys are checked with patternProperties (WordPress does not support
	 * propertyNames). ConfigValidator checks that node and action references exist.
	 *
	 * @return array<string, mixed>
	 */
	private static function flows(): array {
		$node = self::object(
			array(
				'type'      => array(
					'type'     => 'string',
					'enum'     => array( 'message', 'choices', 'condition', 'action', 'end' ),
					'required' => true,
				),
				'text'      => array(
					'type'       => 'string',
					'maxLength'  => 500,
					'default'    => '',
					'x-sanitize' => 'textarea',
				),
				'next'      => self::optional_id(),
				'choices'   => array(
					'type'     => 'array',
					'maxItems' => 8,
					'default'  => array(),
					'items'    => self::object(
						array(
							'label'  => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 60,
								'required'  => true,
							),
							'next'   => self::optional_id(),
							'action' => self::optional_id(),
							'urgent' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
				'condition' => array(
					'type'    => 'string',
					'enum'    => array( '', 'open_now' ),
					'default' => '',
				),
				'then'      => self::optional_id(),
				'else'      => self::optional_id(),
				// Used when the answer is unknown (no opening hours); falls back to "else".
				'unknown'   => self::optional_id(),
				// Action IDs shown as call-to-action buttons.
				'actions'   => array(
					'type'     => 'array',
					'maxItems' => 3,
					'default'  => array(),
					'items'    => array(
						'type'    => 'string',
						'pattern' => self::ID_PATTERN,
					),
				),
			)
		);

		$flow = self::object(
			array(
				'title' => array(
					'type'      => 'string',
					'maxLength' => 80,
					'default'   => '',
				),
				'start' => array(
					'type'     => 'string',
					'pattern'  => self::ID_PATTERN,
					'required' => true,
				),
				'nodes' => self::id_map( $node, 50 ),
			)
		);

		return self::id_map( $flow, 30 );
	}

	/**
	 * Enquiry forms: form ID => { title, submit_label, success_message, fields }.
	 * ConfigValidator checks field IDs (unique, not reserved) and select options.
	 *
	 * @return array<string, mixed>
	 */
	private static function forms(): array {
		$field = self::object(
			array(
				'id'          => array(
					'type'     => 'string',
					'pattern'  => self::ID_PATTERN,
					'required' => true,
				),
				'type'        => array(
					'type'     => 'string',
					'enum'     => array( 'text', 'email', 'tel', 'textarea', 'select', 'date' ),
					'required' => true,
				),
				'label'       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 80,
					'required'  => true,
				),
				'required'    => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'placeholder' => array(
					'type'      => 'string',
					'maxLength' => 80,
					'default'   => '',
				),
				'max_length'  => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 2000,
					'default' => 200,
				),
				// Select fields only.
				'options'     => array(
					'type'     => 'array',
					'maxItems' => 20,
					'default'  => array(),
					'items'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 80,
					),
				),
			)
		);

		$form = self::object(
			array(
				'title'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 80,
					'required'  => true,
				),
				'submit_label'    => array(
					'type'      => 'string',
					'maxLength' => 40,
					'default'   => '',
				),
				'success_message' => array(
					'type'       => 'string',
					'maxLength'  => 300,
					'default'    => '',
					'x-sanitize' => 'textarea',
				),
				'fields'          => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 12,
					'required' => true,
					'items'    => $field,
				),
			)
		);

		return self::id_map( $form, 50 );
	}

	/**
	 * Object keyed by machine IDs. WordPress does not support propertyNames, so
	 * keys are checked with patternProperties plus additionalProperties false.
	 *
	 * @param array<string, mixed> $item      Item schema.
	 * @param int                  $max_items Maximum number of items.
	 * @return array<string, mixed>
	 */
	private static function id_map( array $item, int $max_items ): array {
		return array(
			'type'                 => 'object',
			'patternProperties'    => array( self::ID_PATTERN => $item ),
			'additionalProperties' => false,
			'maxProperties'        => $max_items,
			'default'              => array(),
		);
	}

	/**
	 * Optional reference to an ID ('' for none).
	 *
	 * @return array<string, mixed>
	 */
	private static function optional_id(): array {
		return array(
			'type'    => 'string',
			'pattern' => self::OPTIONAL_ID_PATTERN,
			'default' => '',
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
