/**
 * Quick actions editor logic (pure functions on the actions list).
 */

/**
 * Moves an item up (-1) or down (+1).
 *
 * @param {Array}  list  List.
 * @param {number} index Item.
 * @param {number} delta -1 or 1.
 * @return {Array} New list (unchanged when the move is out of range).
 */
export function move( list, index, delta ) {
	const target = index + delta;
	if ( target < 0 || target >= list.length || index < 0 ) {
		return list;
	}
	const next = [ ...list ];
	[ next[ index ], next[ target ] ] = [ next[ target ], next[ index ] ];
	return next;
}

/**
 * A machine ID from a label, unique in the list.
 *
 * @param {string}   label Label.
 * @param {string[]} taken IDs in use.
 * @return {string} ID (lowercase letters, digits, - and _; max 40).
 */
export function uniqueId( label, taken ) {
	const base =
		String( label )
			.toLowerCase()
			.normalize( 'NFKD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9]+/g, '-' )
			.replace( /^-+|-+$/g, '' )
			.slice( 0, 34 ) || 'action';
	let id = base;
	for ( let n = 2; taken.includes( id ); n++ ) {
		id = `${ base }-${ n }`;
	}
	return id;
}

/**
 * A new action with empty config values for its type.
 *
 * @param {Array}  list  Actions.
 * @param {Object} type  Action type from boot (id, fields).
 * @param {string} label Label.
 * @return {Array} New list.
 */
export function addAction( list, type, label ) {
	const config = {};
	for ( const field of type.fields || [] ) {
		if ( field.type === 'boolean' ) {
			config[ field.key ] = field.key === 'new_tab';
		} else if ( field.type === 'array' ) {
			config[ field.key ] = [];
		} else {
			config[ field.key ] = '';
		}
	}
	return [
		...list,
		{
			id: uniqueId(
				label,
				list.map( ( a ) => a.id )
			),
			label,
			type: type.id,
			urgent: false,
			show_in_menu: true,
			config,
		},
	];
}

/**
 * Removes an action.
 *
 * @param {Array}  list  Actions.
 * @param {number} index Item.
 * @return {Array} New list.
 */
export function removeAction( list, index ) {
	return list.filter( ( _, i ) => i !== index );
}

/**
 * Changes one key of an action (or of its config when `inConfig`).
 *
 * @param {Array}   list     Actions.
 * @param {number}  index    Item.
 * @param {string}  key      Key.
 * @param {unknown} value    Value.
 * @param {boolean} inConfig Change config[key].
 * @return {Array} New list.
 */
export function updateAction( list, index, key, value, inConfig = false ) {
	return list.map( ( action, i ) => {
		if ( i !== index ) {
			return action;
		}
		return inConfig
			? {
					...action,
					config: { ...( action.config || {} ), [ key ]: value },
				}
			: { ...action, [ key ]: value };
	} );
}

/**
 * Required config keys that are empty, so the widget hides the action.
 * Unknown or unsupported types report ["type"].
 *
 * @param {Object} action Action.
 * @param {Array}  types  Action types from boot.
 * @return {string[]} Missing keys.
 */
export function missing( action, types ) {
	const type = types.find( ( t ) => t.id === action.type );
	if ( ! type || ! type.supported ) {
		return [ 'type' ];
	}
	const config = action.config || {};
	return ( type.required || [] ).filter(
		( key ) =>
			config[ key ] === undefined || String( config[ key ] ).trim() === ''
	);
}
