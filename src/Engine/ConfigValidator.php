<?php
/**
 * Cross-section settings validation.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Engine;

use DevRivo\Chatbot\Core\Settings\Schema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Checks rules that span settings sections, after each section passed its
 * own schema: action configs per type, unique action IDs, flow, form and
 * action references, form fields, and opening hours.
 *
 * Settings::update() only rejects errors the update introduces, so problems
 * already stored (e.g. from an older version) never block unrelated saves.
 */
final class ConfigValidator {

	/**
	 * Field IDs the form endpoint uses itself.
	 */
	public const RESERVED_FIELDS = array( 'consent', 'website', 'token' );

	/**
	 * Validates full settings.
	 *
	 * @param array<string, mixed> $settings Candidate settings (all sections).
	 * @param array<string, mixed> $baseline Current settings: errors already present there are ignored.
	 * @return true|WP_Error True when valid.
	 */
	public static function validate( array $settings, array $baseline = array() ): bool|WP_Error {
		$errors = array_diff_assoc( self::errors( $settings ), array() === $baseline ? array() : self::errors( $baseline ) );

		if ( array() === $errors ) {
			return true;
		}

		$field = (string) array_key_first( $errors );

		return new WP_Error(
			'rest_invalid_param',
			$errors[ $field ],
			array(
				'status' => 400,
				'params' => $errors,
			)
		);
	}

	/**
	 * Every problem found, keyed by field path (e.g. "flows[urgent][start]").
	 *
	 * @param array<string, mixed> $settings Settings (all sections).
	 * @return array<string, string>
	 */
	public static function errors( array $settings ): array {
		$actions = is_array( $settings['actions'] ?? null ) ? $settings['actions'] : array();
		$flows   = is_array( $settings['flows'] ?? null ) ? $settings['flows'] : array();
		$forms   = is_array( $settings['forms'] ?? null ) ? $settings['forms'] : array();
		$hours   = $settings['behaviour']['hours'] ?? array();

		return array_merge(
			self::action_errors( $actions, $flows, $forms ),
			self::flow_errors( $flows, array_column( $actions, 'id' ) ),
			self::form_errors( $forms ),
			self::hours_errors( is_array( $hours ) ? $hours : array() )
		);
	}

	/**
	 * Action config per type, unique IDs, and flow and form references.
	 *
	 * @param array<int, mixed>    $actions Actions.
	 * @param array<string, mixed> $flows   Flows.
	 * @param array<string, mixed> $forms   Forms.
	 * @return array<string, string>
	 */
	private static function action_errors( array $actions, array $flows, array $forms ): array {
		$types  = ActionTypes::all();
		$errors = array();
		$seen   = array();

		foreach ( $actions as $index => $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$path = "actions[{$index}]";
			$id   = (string) ( $action['id'] ?? '' );

			if ( isset( $seen[ $id ] ) ) {
				/* translators: %s: Action ID. */
				$errors[ "{$path}[id]" ] = sprintf( __( 'Two quick actions use the ID "%s". Each ID must be unique.', 'devrivo-chatbot' ), $id );
			}
			$seen[ $id ] = true;

			$type = $types[ $action['type'] ?? '' ] ?? null;
			if ( null === $type ) {
				continue; // Unknown type: kept as stored, never shown.
			}

			$config = is_array( $action['config'] ?? null ) ? $action['config'] : array();
			$filled = Schema::with_defaults( $config, $type['schema'] );
			$valid  = rest_validate_value_from_schema( is_array( $filled ) ? array_replace( $filled, $config ) : $config, $type['schema'], "{$path}[config]" );
			if ( is_wp_error( $valid ) ) {
				$errors[ "{$path}[config]" ] = $valid->get_error_message();
				continue;
			}

			$flow = (string) ( $config['flow'] ?? '' );
			if ( 'flow' === $action['type'] && '' !== $flow && ! isset( $flows[ $flow ] ) ) {
				/* translators: %s: Flow ID. */
				$errors[ "{$path}[config][flow]" ] = sprintf( __( 'The flow "%s" does not exist.', 'devrivo-chatbot' ), $flow );
			}

			$form = (string) ( $config['form'] ?? '' );
			if ( 'form' === $action['type'] && '' !== $form && ! isset( $forms[ $form ] ) ) {
				/* translators: %s: Form ID. */
				$errors[ "{$path}[config][form]" ] = sprintf( __( 'The form "%s" does not exist.', 'devrivo-chatbot' ), $form );
			}
		}

		return $errors;
	}

	/**
	 * Form fields: unique, not reserved, and options only (and always) for selects.
	 *
	 * @param array<string, mixed> $forms Forms.
	 * @return array<string, string>
	 */
	private static function form_errors( array $forms ): array {
		$errors = array();

		foreach ( $forms as $form_id => $form ) {
			$seen = array();
			foreach ( (array) ( is_array( $form ) ? ( $form['fields'] ?? array() ) : array() ) as $index => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$path = "forms[{$form_id}][fields][{$index}]";
				$id   = (string) ( $field['id'] ?? '' );

				if ( in_array( $id, self::RESERVED_FIELDS, true ) ) {
					/* translators: %s: Field ID. */
					$errors[ "{$path}[id]" ] = sprintf( __( 'The field ID "%s" is reserved. Please choose another.', 'devrivo-chatbot' ), $id );
				} elseif ( isset( $seen[ $id ] ) ) {
					/* translators: %s: Field ID. */
					$errors[ "{$path}[id]" ] = sprintf( __( 'Two fields use the ID "%s". Each ID must be unique.', 'devrivo-chatbot' ), $id );
				}
				$seen[ $id ] = true;

				$has_options = array() !== (array) ( $field['options'] ?? array() );
				if ( 'select' === ( $field['type'] ?? '' ) && ! $has_options ) {
					$errors[ "{$path}[options]" ] = __( 'A dropdown needs at least one option.', 'devrivo-chatbot' );
				} elseif ( 'select' !== ( $field['type'] ?? '' ) && $has_options ) {
					$errors[ "{$path}[options]" ] = __( 'Only dropdown fields can have options.', 'devrivo-chatbot' );
				}
			}
		}

		return $errors;
	}

	/**
	 * Node and action references inside flows.
	 *
	 * @param array<string, mixed> $flows      Flows.
	 * @param array<int, mixed>    $action_ids Existing action IDs.
	 * @return array<string, string>
	 */
	private static function flow_errors( array $flows, array $action_ids ): array {
		$errors = array();

		foreach ( $flows as $flow_id => $flow ) {
			if ( ! is_array( $flow ) ) {
				continue;
			}
			$path  = "flows[{$flow_id}]";
			$nodes = is_array( $flow['nodes'] ?? null ) ? $flow['nodes'] : array();

			$node_ref   = static function ( mixed $ref, string $field ) use ( $nodes, &$errors ): void {
				if ( is_string( $ref ) && '' !== $ref && ! isset( $nodes[ $ref ] ) ) {
					/* translators: %s: Flow step ID. */
					$errors[ $field ] = sprintf( __( 'The step "%s" does not exist in this flow.', 'devrivo-chatbot' ), $ref );
				}
			};
			$action_ref = static function ( mixed $ref, string $field ) use ( $action_ids, &$errors ): void {
				if ( is_string( $ref ) && '' !== $ref && ! in_array( $ref, $action_ids, true ) ) {
					/* translators: %s: Action ID. */
					$errors[ $field ] = sprintf( __( 'The quick action "%s" does not exist.', 'devrivo-chatbot' ), $ref );
				}
			};

			$start = (string) ( $flow['start'] ?? '' );
			if ( ! isset( $nodes[ $start ] ) ) {
				/* translators: %s: Flow step ID. */
				$errors[ "{$path}[start]" ] = sprintf( __( 'The step "%s" does not exist in this flow.', 'devrivo-chatbot' ), $start );
			}

			foreach ( $nodes as $node_id => $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				$node_path = "{$path}[nodes][{$node_id}]";

				foreach ( array( 'next', 'then', 'else', 'unknown' ) as $key ) {
					$node_ref( $node[ $key ] ?? '', "{$node_path}[{$key}]" );
				}

				foreach ( (array) ( $node['choices'] ?? array() ) as $index => $choice ) {
					$next   = (string) ( $choice['next'] ?? '' );
					$action = (string) ( $choice['action'] ?? '' );
					if ( '' === $next && '' === $action ) {
						$errors[ "{$node_path}[choices][{$index}]" ] = __( 'Each choice needs a next step or a quick action.', 'devrivo-chatbot' );
						continue;
					}
					$node_ref( $next, "{$node_path}[choices][{$index}][next]" );
					$action_ref( $action, "{$node_path}[choices][{$index}][action]" );
				}

				foreach ( (array) ( $node['actions'] ?? array() ) as $index => $action ) {
					$action_ref( $action, "{$node_path}[actions][{$index}]" );
				}
			}
		}

		return $errors;
	}

	/**
	 * Timezone and opening times.
	 *
	 * @param array<string, mixed> $hours Opening hours.
	 * @return array<string, string>
	 */
	private static function hours_errors( array $hours ): array {
		$errors   = array();
		$timezone = (string) ( $hours['timezone'] ?? '' );

		if ( '' !== $timezone && ! in_array( $timezone, timezone_identifiers_list(), true ) ) {
			/* translators: %s: Timezone ID. */
			$errors['behaviour[hours][timezone]'] = sprintf( __( '"%s" is not a valid timezone.', 'devrivo-chatbot' ), $timezone );
		}

		foreach ( (array) ( $hours['schedule'] ?? array() ) as $day => $ranges ) {
			foreach ( (array) $ranges as $index => $range ) {
				if ( is_array( $range ) && (string) ( $range['open'] ?? '' ) >= (string) ( $range['close'] ?? '' ) ) {
					$errors[ "behaviour[hours][schedule][{$day}][{$index}]" ] = __( 'The opening time must be before the closing time.', 'devrivo-chatbot' );
				}
			}
		}

		return $errors;
	}
}
