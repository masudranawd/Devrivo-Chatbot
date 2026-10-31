/**
 * Opening hours editor logic (pure functions on the behaviour.hours schedule).
 */

export const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
export const MAX_RANGES = 3;
const TIME = /^([01][0-9]|2[0-3]):[0-5][0-9]$/;

/**
 * A complete schedule (every day present, ranges copied).
 *
 * @param {unknown} schedule Stored schedule.
 * @return {Object<string, Array<{open: string, close: string}>>} Schedule.
 */
export function normalizeSchedule( schedule ) {
	const source = schedule && typeof schedule === 'object' ? schedule : {};
	const result = {};
	for ( const day of DAYS ) {
		result[ day ] = ( Array.isArray( source[ day ] ) ? source[ day ] : [] )
			.filter( ( range ) => range && typeof range === 'object' )
			.map( ( range ) => ( {
				open: String( range.open || '' ),
				close: String( range.close || '' ),
			} ) );
	}
	return result;
}

/**
 * Adds a range to a day (09:00–17:00, or after the last range), up to MAX_RANGES.
 *
 * @param {Object} schedule Schedule.
 * @param {string} day      Day key.
 * @return {Object} New schedule.
 */
export function addRange( schedule, day ) {
	const next = normalizeSchedule( schedule );
	if ( next[ day ].length >= MAX_RANGES ) {
		return next;
	}
	const last = next[ day ][ next[ day ].length - 1 ];
	next[ day ].push(
		last && TIME.test( last.close ) && last.close < '22:00'
			? { open: last.close, close: '23:00' }
			: { open: '09:00', close: '17:00' }
	);
	return next;
}

/**
 * Removes a range.
 *
 * @param {Object} schedule Schedule.
 * @param {string} day      Day key.
 * @param {number} index    Range index.
 * @return {Object} New schedule.
 */
export function removeRange( schedule, day, index ) {
	const next = normalizeSchedule( schedule );
	next[ day ].splice( index, 1 );
	return next;
}

/**
 * Changes a range's open or close time.
 *
 * @param {Object} schedule Schedule.
 * @param {string} day      Day key.
 * @param {number} index    Range index.
 * @param {string} key      "open" or "close".
 * @param {string} value    HH:MM.
 * @return {Object} New schedule.
 */
export function updateRange( schedule, day, index, key, value ) {
	const next = normalizeSchedule( schedule );
	if ( next[ day ][ index ] ) {
		next[ day ][ index ] = { ...next[ day ][ index ], [ key ]: value };
	}
	return next;
}

/**
 * Copies one day's ranges to the other weekdays (Mon–Fri).
 *
 * @param {Object} schedule Schedule.
 * @param {string} day      Source day.
 * @return {Object} New schedule.
 */
export function copyToWeekdays( schedule, day ) {
	const next = normalizeSchedule( schedule );
	for ( const target of DAYS.slice( 0, 5 ) ) {
		next[ target ] = next[ day ].map( ( range ) => ( { ...range } ) );
	}
	return next;
}

/**
 * Problems the server would reject, keyed "day.index".
 *
 * @param {Object} schedule Schedule.
 * @return {Object<string, string>} Error codes: "format" or "order".
 */
export function rangeErrors( schedule ) {
	const errors = {};
	const next = normalizeSchedule( schedule );
	for ( const day of DAYS ) {
		next[ day ].forEach( ( range, index ) => {
			if ( ! TIME.test( range.open ) || ! TIME.test( range.close ) ) {
				errors[ `${ day }.${ index }` ] = 'format';
			} else if ( range.open >= range.close ) {
				errors[ `${ day }.${ index }` ] = 'order';
			}
		} );
	}
	return errors;
}
