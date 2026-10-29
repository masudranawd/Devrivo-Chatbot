/**
 * Opening hours, evaluated in the business's timezone (not the visitor's).
 */

export const DAYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];
const WEEK = [ 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' ];
const OFFSET = /^([+-])(\d{2}):?(\d{2})$/;

/**
 * Day and minute of the day in a timezone.
 *
 * Accepts IANA names (Europe/London) and the UTC offsets WordPress uses when
 * no city is chosen (+05:30).
 *
 * @param {Date}   date     Moment.
 * @param {string} timeZone Timezone, or '' for the browser's.
 * @return {{day: string, minutes: number}|null} Parts, or null for an unknown timezone.
 */
export function zonedParts( date, timeZone ) {
	const offset = OFFSET.exec( timeZone || '' );
	if ( offset ) {
		const sign = offset[ 1 ] === '-' ? -1 : 1;
		const shift =
			sign * ( Number( offset[ 2 ] ) * 60 + Number( offset[ 3 ] ) );
		const shifted = new Date( date.getTime() + shift * 60000 );

		return {
			day: WEEK[ shifted.getUTCDay() ],
			minutes: shifted.getUTCHours() * 60 + shifted.getUTCMinutes(),
		};
	}

	try {
		const parts = new Intl.DateTimeFormat( 'en-US', {
			timeZone: timeZone || undefined,
			weekday: 'short',
			hour: '2-digit',
			minute: '2-digit',
			hourCycle: 'h23',
		} ).formatToParts( date );
		const get = ( type ) => parts.find( ( p ) => p.type === type ).value;

		return {
			day: get( 'weekday' ).slice( 0, 3 ).toLowerCase(),
			minutes:
				( Number( get( 'hour' ) ) % 24 ) * 60 +
				Number( get( 'minute' ) ),
		};
	} catch {
		return null;
	}
}

/**
 * Minutes since midnight for "HH:MM".
 *
 * @param {string} time Time.
 * @return {number} Minutes.
 */
const toMinutes = ( time ) =>
	Number( time.slice( 0, 2 ) ) * 60 + Number( time.slice( 3, 5 ) );

/**
 * Whether the business is open.
 *
 * @param {Object} hours Normalised hours { timezone, schedule }.
 * @param {Date}   date  Moment to check.
 * @return {boolean|null} True/false, or null when unknown (no schedule or bad timezone).
 */
export function isOpenNow( hours, date = new Date() ) {
	const schedule = ( hours && hours.schedule ) || {};
	if ( ! DAYS.some( ( day ) => ( schedule[ day ] || [] ).length ) ) {
		return null;
	}

	const now = zonedParts( date, hours.timezone );
	if ( ! now ) {
		return null;
	}

	return ( schedule[ now.day ] || [] ).some(
		( range ) =>
			toMinutes( range.open ) <= now.minutes &&
			now.minutes < toMinutes( range.close )
	);
}
