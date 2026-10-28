/**
 * Picks a readable text colour for a background colour (WCAG contrast).
 */

const DARK_TEXT = '#17201e';

/**
 * Relative luminance of a hex colour.
 *
 * @param {string} hex #rgb or #rrggbb.
 * @return {number} Luminance 0..1.
 */
export function luminance( hex ) {
	let value = hex.replace( '#', '' );
	if ( value.length === 3 ) {
		value = value
			.split( '' )
			.map( ( c ) => c + c )
			.join( '' );
	}

	const [ r, g, b ] = [ 0, 2, 4 ].map( ( i ) => {
		const c = parseInt( value.slice( i, i + 2 ), 16 ) / 255;
		return c <= 0.03928 ? c / 12.92 : ( ( c + 0.055 ) / 1.055 ) ** 2.4;
	} );

	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * White or near-black, whichever contrasts more with the background.
 *
 * @param {string} hex Background colour.
 * @return {string} Text colour.
 */
export function textOn( hex ) {
	const l = luminance( hex );
	const withWhite = 1.05 / ( l + 0.05 );
	const withDark = ( l + 0.05 ) / ( luminance( DARK_TEXT ) + 0.05 );

	return withWhite >= withDark ? '#ffffff' : DARK_TEXT;
}
