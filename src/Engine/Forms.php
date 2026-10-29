<?php
/**
 * Form fields and submission validation.
 *
 * @package DevRivo\Chatbot
 */

declare( strict_types=1 );

namespace DevRivo\Chatbot\Engine;

use DevRivo\Chatbot\Core\Settings\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a form's fields (the same list for the widget and for validation)
 * and validates and cleans a visitor's submission. Unknown keys are ignored.
 */
final class Forms {

	/**
	 * Phone numbers: digits, spaces and + ( ) - . (3-30 characters).
	 */
	private const TEL_PATTERN = '/^\+?[0-9 ().-]{3,30}$/';

	/**
	 * A form's fields, with defaults filled in.
	 *
	 * @param string               $form_id Form ID.
	 * @param array<string, mixed> $form    Stored form.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( string $form_id, array $form ): array {
		$schema = (array) ( Schema::child( (array) Schema::section( 'forms' ), $form_id )['properties']['fields']['items'] ?? array() );
		$fields = array_map(
			static fn( $field ): array => (array) Schema::with_defaults( $field, $schema ),
			(array) ( $form['fields'] ?? array() )
		);

		/**
		 * Filters a form's fields before the widget shows them and before a
		 * submission is validated (both use the same list).
		 *
		 * Each field has id, type, label, required, placeholder, max_length and options.
		 *
		 * @since 1.0.0
		 *
		 * @param array<int, mixed> $fields  Fields.
		 * @param string            $form_id Form ID.
		 */
		$fields = apply_filters( 'devrivo_chatbot_form_fields', $fields, $form_id );

		return array_values( array_filter( (array) $fields, static fn( $field ): bool => is_array( $field ) && is_string( $field['id'] ?? null ) ) );
	}

	/**
	 * Validates and cleans submitted values.
	 *
	 * @param array<int, array<string, mixed>> $fields Fields (from fields()).
	 * @param mixed                            $input  Submitted values keyed by field ID.
	 * @return array{values: array<string, string>, errors: array<string, string>}
	 */
	public static function validate( array $fields, mixed $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$values = array();
		$errors = array();

		foreach ( $fields as $field ) {
			$id    = (string) $field['id'];
			$type  = (string) ( $field['type'] ?? 'text' );
			$raw   = $input[ $id ] ?? '';
			$value = is_string( $raw ) || is_int( $raw ) || is_float( $raw ) ? trim( (string) $raw ) : null;

			if ( null === $value ) {
				$errors[ $id ] = __( 'This value is not valid.', 'devrivo-chatbot' );
				continue;
			}

			if ( '' === $value ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[ $id ] = __( 'This field is required.', 'devrivo-chatbot' );
				}
				$values[ $id ] = '';
				continue;
			}

			$error = self::check( $field, $type, $value );
			if ( null !== $error ) {
				$errors[ $id ] = $error;
				continue;
			}

			$values[ $id ] = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Checks one non-empty value.
	 *
	 * @param array<string, mixed> $field Field.
	 * @param string               $type  Field type.
	 * @param string               $value Trimmed value.
	 * @return string|null Error message, or null when valid.
	 */
	private static function check( array $field, string $type, string $value ): ?string {
		$max = max( 1, (int) ( $field['max_length'] ?? 200 ) );
		if ( mb_strlen( $value ) > $max ) {
			/* translators: %d: Maximum number of characters. */
			return sprintf( _n( 'Please use %d character or fewer.', 'Please use %d characters or fewer.', $max, 'devrivo-chatbot' ), $max );
		}

		switch ( $type ) {
			case 'email':
				return is_email( $value ) ? null : __( 'Enter an email address like name@example.com.', 'devrivo-chatbot' );

			case 'tel':
				return preg_match( self::TEL_PATTERN, $value ) ? null : __( 'Enter a valid phone number.', 'devrivo-chatbot' );

			case 'date':
				$parts = preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ? array( (int) $m[2], (int) $m[3], (int) $m[1] ) : null;
				return null !== $parts && checkdate( ...$parts ) ? null : __( 'Enter a valid date.', 'devrivo-chatbot' );

			case 'select':
				return in_array( $value, array_map( 'strval', (array) ( $field['options'] ?? array() ) ), true ) ? null : __( 'Choose one of the options.', 'devrivo-chatbot' );
		}

		return null;
	}
}
