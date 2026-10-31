/**
 * Setup wizard logic: steps, business profile suggestions, quick action
 * fixes from the profile, and the quick opening-hours schedule.
 */
import { DAYS } from './hours';
import { missing } from './actions';

// Schema::SETUP_STEPS.
export const STEPS = [
	'scan',
	'preset',
	'business',
	'ai',
	'appearance',
	'knowledge',
	'test',
	'golive',
];

export const BUSINESS_KEYS = [
	'name',
	'phone',
	'whatsapp',
	'email',
	'booking_url',
	'address',
];

// Default monthly token limit offered when turning AI on.
export const DEFAULT_TOKEN_LIMIT = 200000;

/**
 * Index of a saved step ('' or unknown: the first).
 *
 * @param {string} step Step ID.
 * @return {number} Index.
 */
export function stepIndex( step ) {
	return Math.max( 0, STEPS.indexOf( step ) );
}

/**
 * A phone number in the WhatsApp action's format (digits, spaces, leading +).
 *
 * @param {string} phone Phone number.
 * @return {string} Number, or '' when it can't fit.
 */
export function toWhatsapp( phone ) {
	const value = String( phone || '' )
		.trim()
		.replace( /[^\d+ ]/g, ' ' )
		.replace( /(?!^)\+/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
	return /^\+?[0-9 ]{6,20}$/.test( value ) ? value : '';
}

/**
 * Business profile to start the form with: saved values first; empty fields
 * take what the scan found, and are listed in `suggested` so the screen can
 * ask the admin to check them.
 *
 * @param {Object} saved general.business.
 * @param {Object} scan  GET /setup/scan result (or null).
 * @return {{values: Object, suggested: string[]}} Start values.
 */
export function initialBusiness( saved, scan ) {
	const values = {};
	for ( const key of BUSINESS_KEYS ) {
		values[ key ] = String( ( saved && saved[ key ] ) || '' );
	}
	const found = {
		name: scan?.site_name || '',
		phone: scan?.contacts?.phones?.[ 0 ] || '',
		email: scan?.contacts?.emails?.[ 0 ] || '',
	};
	const suggested = [];
	for ( const [ key, value ] of Object.entries( found ) ) {
		if ( ! values[ key ] && value ) {
			values[ key ] = String( value );
			suggested.push( key );
		}
	}
	return { values, suggested };
}

/**
 * Quick actions that are hidden because a required value is empty, with the
 * value the business profile can fill in: phone for calls, WhatsApp number
 * (else the phone) for WhatsApp, email, and the booking link for links.
 *
 * @param {Array}  actions  Saved quick actions.
 * @param {Array}  types    Action types from boot.
 * @param {Object} business Business profile.
 * @return {Array<{index: number, label: string, type: string, key: string, value: string}>} Fixes.
 */
export function actionFixes( actions, types, business ) {
	const sources = {
		call: { key: 'phone', value: business.phone },
		whatsapp: {
			key: 'phone',
			value:
				toWhatsapp( business.whatsapp ) || toWhatsapp( business.phone ),
		},
		email: { key: 'email', value: business.email },
		link: { key: 'url', value: business.booking_url },
	};
	const fixes = [];
	( actions || [] ).forEach( ( action, index ) => {
		const source = sources[ action.type ];
		if ( ! source || ! missing( action, types ).includes( source.key ) ) {
			return;
		}
		fixes.push( {
			index,
			label: action.label,
			type: action.type,
			key: source.key,
			value: String( source.value || '' ),
		} );
	} );
	return fixes;
}

/**
 * Applies the ticked fixes with a value to the actions.
 *
 * @param {Array} actions Quick actions.
 * @param {Array} fixes   Fixes with { index, key, value, on }.
 * @return {Array} New actions.
 */
export function applyFixes( actions, fixes ) {
	const next = [ ...actions ];
	for ( const fix of fixes ) {
		if ( fix.on && String( fix.value ).trim() && next[ fix.index ] ) {
			next[ fix.index ] = {
				...next[ fix.index ],
				config: {
					...( next[ fix.index ].config || {} ),
					[ fix.key ]: String( fix.value ).trim(),
				},
			};
		}
	}
	return next;
}

/**
 * Weekly schedule with the same times on the chosen days, closed on the rest.
 *
 * @param {string[]} days  Day keys (mon…sun).
 * @param {string}   open  HH:MM.
 * @param {string}   close HH:MM.
 * @return {Object<string, Array<{open: string, close: string}>>} Schedule.
 */
export function weeklySchedule( days, open, close ) {
	const schedule = {};
	for ( const day of DAYS ) {
		schedule[ day ] = days.includes( day ) ? [ { open, close } ] : [];
	}
	return schedule;
}

/**
 * Whether a schedule has any opening times.
 *
 * @param {Object} schedule Schedule.
 * @return {boolean} Has hours.
 */
export function hasHours( schedule ) {
	return DAYS.some(
		( day ) => Array.isArray( schedule?.[ day ] ) && schedule[ day ].length
	);
}

/**
 * Whether the admin already has their own content (so a preset might
 * overwrite something).
 *
 * @param {Object} all All settings.
 * @return {boolean} Has own settings.
 */
export function hasOwnSettings( all ) {
	return Boolean(
		( all?.actions || [] ).length ||
		Object.keys( all?.flows || {} ).length ||
		Object.keys( all?.forms || {} ).length ||
		( all?.offers || [] ).length ||
		( all?.knowledge?.faqs || [] ).length ||
		all?.general?.preset
	);
}
