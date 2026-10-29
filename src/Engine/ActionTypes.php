<?php
/**
 * Quick action types.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Engine;

use DevRivo\Chatbot\Core\Settings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of quick action types.
 *
 * Each type: config schema (strict object), runtime ('client': the widget runs
 * it), whether it is supported yet, and the config keys it needs. Empty values
 * are valid so presets can leave site details for the admin; an action with an
 * empty required value is "incomplete" and is not shown to visitors.
 *
 * Stored actions of unknown types (e.g. from an inactive module) are kept but
 * treated as unsupported.
 */
final class ActionTypes {

	/**
	 * All registered types.
	 *
	 * @return array<string, array{schema: array<string, mixed>, runtime: string, supported: bool, required: string[]}>
	 */
	public static function all(): array {
		/**
		 * Filters the quick action types.
		 *
		 * Each type is an array with:
		 * - schema:    JSON schema of the action's config (a strict object).
		 *              Every config key is sent to the browser, so keep them public.
		 * - runtime:   'client' (the widget runs it).
		 * - supported: whether the widget can run it; unsupported actions are hidden.
		 * - required:  config keys that must be non-empty for the action to show.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $types Types keyed by ID (lowercase letters and underscores).
		 */
		$types = apply_filters( 'devrivo_chatbot_action_types', self::built_in() );

		// Entries a filter broke are skipped.
		$valid = array();
		foreach ( $types as $id => $type ) {
			if ( ! preg_match( '/^[a-z_]{1,40}$/', $id ) || ! is_array( $type ) || ! is_array( $type['schema'] ?? null ) ) {
				continue;
			}

			$valid[ $id ] = array(
				'schema'    => $type['schema'],
				'runtime'   => is_string( $type['runtime'] ?? null ) ? $type['runtime'] : 'client',
				'supported' => (bool) ( $type['supported'] ?? false ),
				'required'  => array_values( array_filter( (array) ( $type['required'] ?? array() ), 'is_string' ) ),
			);
		}

		return $valid;
	}

	/**
	 * One type, or null if unknown.
	 *
	 * @param string $type Type ID.
	 * @return array{schema: array<string, mixed>, runtime: string, supported: bool, required: string[]}|null
	 */
	public static function get( string $type ): ?array {
		return self::all()[ $type ] ?? null;
	}

	/**
	 * Whether an action can be shown to visitors: its type is supported and
	 * every required config value is filled in.
	 *
	 * @param array<string, mixed>                $action Stored action.
	 * @param array<string, array<string, mixed>> $types  Types (from all()).
	 */
	public static function is_ready( array $action, array $types ): bool {
		$type = $types[ $action['type'] ?? '' ] ?? null;
		if ( null === $type || empty( $type['supported'] ) ) {
			return false;
		}

		$config = is_array( $action['config'] ?? null ) ? $action['config'] : array();
		foreach ( (array) $type['required'] as $key ) {
			if ( ! isset( $config[ $key ] ) || '' === trim( (string) $config[ $key ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Built-in types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function built_in(): array {
		return array(
			'link'        => self::type(
				array(
					'url'     => self::text( 2000, '^(https?://[^\s]+)?$' ),
					'new_tab' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				array( 'url' ),
				true
			),
			'call'        => self::type(
				array( 'phone' => self::text( 30, '^(\+?[0-9 ()-]{3,30})?$' ) ),
				array( 'phone' ),
				true
			),
			'whatsapp'    => self::type(
				array(
					'phone'   => self::text( 20, '^(\+?[0-9 ]{6,20})?$' ),
					'message' => self::text( 300 ),
				),
				array( 'phone' ),
				true
			),
			'email'       => self::type(
				array(
					'email'   => array(
						'anyOf'   => array(
							array(
								'type' => 'string',
								'enum' => array( '' ),
							),
							array(
								'type'   => 'string',
								'format' => 'email',
							),
						),
						'default' => '',
					),
					'subject' => self::text( 150 ),
				),
				array( 'email' ),
				true
			),
			'flow'        => self::type(
				array( 'flow' => self::text( 40, Schema::OPTIONAL_ID_PATTERN ) ),
				array( 'flow' ),
				true
			),
			'form'        => self::type(
				array( 'form' => self::text( 40, Schema::OPTIONAL_ID_PATTERN ) ),
				array( 'form' ),
				true
			),
			// Not supported yet: search (Step 6), AI (Step 7) and custom hooks.
			'page_search' => self::type(
				array(
					'post_types' => array(
						'type'     => 'array',
						'maxItems' => 20,
						'default'  => array(),
						'items'    => array(
							'type'    => 'string',
							'pattern' => '^[a-z0-9_-]{1,20}$',
						),
					),
				),
				array()
			),
			'ai_prompt'   => self::type( array( 'prompt' => self::text( 300 ) ), array( 'prompt' ) ),
			'custom_hook' => self::type( array( 'hook' => self::text( 40, Schema::OPTIONAL_ID_PATTERN ) ), array( 'hook' ) ),
		);
	}

	/**
	 * A type definition with a strict config schema.
	 *
	 * @param array<string, array<string, mixed>> $properties Config properties.
	 * @param string[]                            $required   Keys that must be filled in.
	 * @param bool                                $supported  Whether the widget runs it.
	 * @return array<string, mixed>
	 */
	private static function type( array $properties, array $required, bool $supported = false ): array {
		return array(
			'schema'    => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			),
			'runtime'   => 'client',
			'supported' => $supported,
			'required'  => $required,
		);
	}

	/**
	 * String property, empty by default.
	 *
	 * @param int    $max_length Maximum length.
	 * @param string $pattern    Optional pattern (must accept '').
	 * @return array<string, mixed>
	 */
	private static function text( int $max_length, string $pattern = '' ): array {
		$schema = array(
			'type'      => 'string',
			'maxLength' => $max_length,
			'default'   => '',
		);
		if ( '' !== $pattern ) {
			$schema['pattern'] = $pattern;
		}

		return $schema;
	}
}
