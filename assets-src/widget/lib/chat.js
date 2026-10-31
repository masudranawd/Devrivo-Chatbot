/**
 * Chat: sends a message with the recent conversation (kept in memory only,
 * never in browser storage) and validates the answer, so the views can trust
 * its shape.
 */
import { createSession, postJson } from './session';
import { HTTP_PROTOCOLS, safeUrl } from './url';

export const MIN_AGE_MS = 1100;
export const MAX_HISTORY = 8;

/**
 * Creates a chat client.
 *
 * @param {Object}              options            Options.
 * @param {string}              options.chatUrl    Chat endpoint.
 * @param {string}              options.sessionUrl Session endpoint.
 * @param {Object}              options.session    Shared session (lib/session); created when missing.
 * @param {() => string}        options.pageUrl    Page the visitor is on (default: the current URL).
 * @param {typeof window.fetch} options.fetch      fetch (tests).
 * @return {{send: (message: string, history: Object[]) => Promise<Object>}} Client.
 */
export function createChat( {
	chatUrl,
	sessionUrl,
	session,
	pageUrl = () => window.location.href,
	fetch = ( ...args ) => window.fetch( ...args ),
} ) {
	const tokens = session || createSession( { sessionUrl, fetch } );

	return {
		/**
		 * Sends a message. Throws on network errors.
		 *
		 * @param {string}   message Message.
		 * @param {Object[]} history Earlier turns { role, text }; the last MAX_HISTORY are sent.
		 * @return {Promise<{ok: boolean, status: number, answer: Object|null}>} Result.
		 */
		async send( message, history = [] ) {
			const result = await tokens.send(
				( token ) =>
					postJson( fetch, chatUrl, {
						message,
						history: history.slice( -MAX_HISTORY ),
						page_url: pageUrl(),
						token,
					} ),
				MIN_AGE_MS
			);

			return {
				ok: result.ok,
				status: result.status,
				answer: result.ok ? normalizeAnswer( result.data ) : null,
			};
		},
	};
}

/**
 * Answer with a known mode and text; sources must be http(s) links.
 *
 * @param {unknown} data Response body.
 * @return {{mode: string, text: string, sources: Array<{title: string, url: string}>, actions: string[]}|null} Answer.
 */
export function normalizeAnswer( data ) {
	if (
		! data ||
		typeof data !== 'object' ||
		typeof data.text !== 'string' ||
		! data.text
	) {
		return null;
	}

	return {
		mode: data.mode === 'ai' ? 'ai' : 'guided',
		text: data.text,
		sources: ( Array.isArray( data.sources ) ? data.sources : [] )
			.filter( ( s ) => s && typeof s.title === 'string' )
			.map( ( s ) => ( {
				title: s.title,
				url: safeUrl( s.url, HTTP_PROTOCOLS ),
			} ) )
			.filter( ( s ) => s.url )
			.slice( 0, 3 ),
		actions: ( Array.isArray( data.actions ) ? data.actions : [] )
			.filter( ( id ) => typeof id === 'string' )
			.slice( 0, 3 ),
	};
}
