/**
 * Client-side form validation. Mirrors the server (Engine\Forms) so visitors
 * see problems before sending; the server still checks everything.
 */

const TEL = /^\+?[0-9 ().-]{3,30}$/;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;

/**
 * Whether "YYYY-MM-DD" is a real calendar date.
 *
 * @param {string} value Value.
 * @return {boolean} Valid.
 */
export function isDate( value ) {
	const match = DATE.exec( value );
	if ( ! match ) {
		return false;
	}
	const [ year, month, day ] = match.slice( 1 ).map( Number );
	const date = new Date( Date.UTC( year, month - 1, day ) );

	return (
		date.getUTCFullYear() === year &&
		date.getUTCMonth() === month - 1 &&
		date.getUTCDate() === day
	);
}

/**
 * Error key for one field, or '' when valid.
 *
 * @param {Object} field Field { type, required, max_length, options }.
 * @param {string} raw   Value.
 * @return {string} i18n key of the error, or ''.
 */
export function fieldError( field, raw ) {
	const value = String( raw ?? '' ).trim();

	if ( value === '' ) {
		return field.required ? 'field_required' : '';
	}
	// Code points, like mb_strlen() on the server.
	if ( Array.from( value ).length > field.maxLength ) {
		return 'too_long';
	}

	switch ( field.type ) {
		case 'email':
			return EMAIL.test( value ) ? '' : 'invalid_email';
		case 'tel':
			return TEL.test( value ) ? '' : 'invalid_phone';
		case 'date':
			return isDate( value ) ? '' : 'invalid_date';
		case 'select':
			return field.options.includes( value ) ? '' : 'invalid_option';
	}

	return '';
}

/**
 * Errors for a whole form.
 *
 * @param {Object}  form    Normalised form.
 * @param {Object}  values  Values keyed by field ID.
 * @param {boolean} consent Whether the consent box is ticked.
 * @return {Object} i18n error keys keyed by field ID (and "consent").
 */
export function formErrors( form, values, consent ) {
	const errors = {};

	for ( const field of form.fields ) {
		const error = fieldError( field, values[ field.id ] );
		if ( error ) {
			errors[ field.id ] = error;
		}
	}
	if ( consent !== true ) {
		errors.consent = 'consent_needed';
	}

	return errors;
}
