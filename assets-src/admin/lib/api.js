/**
 * REST helpers: requests under devrivo-chatbot/v1 and server errors mapped
 * to fields.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export const NAMESPACE = '/devrivo-chatbot/v1';

/**
 * Request to the plugin's REST namespace.
 *
 * @param {string} path    Path after the namespace, e.g. "/settings".
 * @param {Object} options apiFetch options (method, data, parse…).
 * @return {Promise<unknown>} Response.
 */
export function request( path, options = {} ) {
	return apiFetch( { ...options, path: NAMESPACE + path } );
}

/**
 * GET with headers: { data, total, totalPages }.
 *
 * @param {string} path Path after the namespace (or a full path starting with /wp/).
 * @return {Promise<{data: unknown, total: number, totalPages: number}>} Result.
 */
export async function requestWithTotals( path ) {
	const response = await apiFetch( {
		path: path.startsWith( '/wp/' ) ? path : NAMESPACE + path,
		parse: false,
	} );
	const data = await response.json();

	return {
		data,
		total: Number( response.headers.get( 'X-WP-Total' ) ) || 0,
		totalPages: Number( response.headers.get( 'X-WP-TotalPages' ) ) || 0,
	};
}

/**
 * Field errors from a REST error. 400 responses carry data.params keyed by
 * paths such as "behaviour[hours][timezone]" or "actions[2][config]"; they
 * become "hours.timezone" (section prefix removed) and "2.config".
 *
 * @param {unknown} error    Error thrown by apiFetch.
 * @param {string}  sections Section names to strip from paths.
 * @return {{message: string, fields: Object<string, string>}} Errors.
 */
export function toErrors( error, sections = [] ) {
	const data = error && typeof error === 'object' ? error : {};
	const message =
		typeof data.message === 'string' && data.message
			? data.message
			: __(
					'Something went wrong. Please try again.',
					'devrivo-chatbot'
				);
	const params =
		data.data && typeof data.data.params === 'object' && data.data.params
			? data.data.params
			: {};

	const fields = {};
	for ( const [ key, text ] of Object.entries( params ) ) {
		if ( typeof text !== 'string' ) {
			continue;
		}
		fields[ fieldPath( key, sections ) ] = text;
	}

	return { message, fields };
}

/**
 * "behaviour[hours][timezone]" -> "behaviour.hours.timezone", then the
 * section prefix is removed when it is one of `sections` and more than one
 * part remains.
 *
 * @param {string}   key      Server path.
 * @param {string[]} sections Sections.
 * @return {string} Field path.
 */
export function fieldPath( key, sections = [] ) {
	const parts = key
		.replace( /\]/g, '' )
		.split( '[' )
		.filter( ( part ) => part !== '' );
	if ( parts.length > 1 && sections.includes( parts[ 0 ] ) ) {
		parts.shift();
	}
	return parts.join( '.' );
}
