/**
 * Forms builder logic (pure functions on the forms map: form ID => { title,
 * submit_label, success_message, fields }).
 */
import { uniqueId } from './actions';

export const FIELD_TYPES = [
	'text',
	'email',
	'tel',
	'textarea',
	'select',
	'date',
];
export const MAX_FORMS = 50;
export const MAX_FIELDS = 12;
export const MAX_OPTIONS = 20;
// Field IDs the form endpoint uses itself.
export const RESERVED_FIELDS = [ 'consent', 'website', 'token' ];

/**
 * A new field with a unique, non-reserved ID made from its label.
 *
 * @param {Array}  fields      Existing fields.
 * @param {string} type        Field type.
 * @param {string} label       Label.
 * @param {string} firstOption First option for a dropdown.
 * @return {Object} Field.
 */
export function newField( fields, type, label, firstOption = '' ) {
	return {
		id: uniqueId(
			label,
			[ ...fields.map( ( f ) => f.id ), ...RESERVED_FIELDS ],
			'field'
		),
		type,
		label,
		required: false,
		placeholder: '',
		max_length: type === 'textarea' ? 1000 : 200,
		options: type === 'select' && firstOption ? [ firstOption ] : [],
	};
}

/**
 * Adds a form with one required email field.
 *
 * @param {Object} forms      Forms.
 * @param {string} title      Title.
 * @param {string} emailLabel Label of the email field.
 * @return {{forms: Object, id: string}} New forms and the new form's ID.
 */
export function addForm( forms, title, emailLabel ) {
	const id = uniqueId( title, Object.keys( forms || {} ), 'form' );
	const email = { ...newField( [], 'email', emailLabel ), required: true };
	return {
		id,
		forms: {
			...forms,
			[ id ]: {
				title,
				submit_label: '',
				success_message: '',
				fields: [ email ],
			},
		},
	};
}

/**
 * Removes a form.
 *
 * @param {Object} forms Forms.
 * @param {string} id    Form ID.
 * @return {Object} New forms.
 */
export function removeForm( forms, id ) {
	const next = { ...forms };
	delete next[ id ];
	return next;
}

/**
 * Changes keys of a form.
 *
 * @param {Object} forms Forms.
 * @param {string} id    Form ID.
 * @param {Object} patch Keys to set.
 * @return {Object} New forms.
 */
export function updateForm( forms, id, patch ) {
	return { ...forms, [ id ]: { ...forms[ id ], ...patch } };
}

/**
 * Changes one key of a field. A new type resets what only fits the old one:
 * dropdowns get one option, other types none.
 *
 * @param {Array}   fields      Fields.
 * @param {number}  index       Field.
 * @param {string}  key         Key.
 * @param {unknown} value       Value.
 * @param {string}  firstOption First option when a field becomes a dropdown.
 * @return {Array} New fields.
 */
export function updateField( fields, index, key, value, firstOption = '' ) {
	return fields.map( ( field, i ) => {
		if ( i !== index ) {
			return field;
		}
		const next = { ...field, [ key ]: value };
		if ( key === 'type' ) {
			if ( value === 'select' ) {
				next.options = field.options?.length
					? field.options
					: [ firstOption ].filter( Boolean );
			} else {
				next.options = [];
			}
		}
		return next;
	} );
}

/**
 * Adds an option to a dropdown field (up to MAX_OPTIONS).
 *
 * @param {Array}  fields Fields.
 * @param {number} index  Field.
 * @param {string} text   Option text.
 * @return {Array} New fields.
 */
export function addOption( fields, index, text ) {
	const options = fields[ index ].options || [];
	if ( options.length >= MAX_OPTIONS ) {
		return fields;
	}
	return updateField( fields, index, 'options', [ ...options, text ] );
}

/**
 * Changes an option's text.
 *
 * @param {Array}  fields Fields.
 * @param {number} index  Field.
 * @param {number} option Option.
 * @param {string} text   Text.
 * @return {Array} New fields.
 */
export function updateOption( fields, index, option, text ) {
	return updateField(
		fields,
		index,
		'options',
		( fields[ index ].options || [] ).map( ( value, i ) =>
			i === option ? text : value
		)
	);
}

/**
 * Removes an option.
 *
 * @param {Array}  fields Fields.
 * @param {number} index  Field.
 * @param {number} option Option.
 * @return {Array} New fields.
 */
export function removeOption( fields, index, option ) {
	return updateField(
		fields,
		index,
		'options',
		( fields[ index ].options || [] ).filter( ( _, i ) => i !== option )
	);
}
