/**
 * Sends forms: gets a signed session token, waits until it is old enough for
 * the server's minimum fill time, posts without cookies, and retries once
 * with a fresh token when the server says the token is invalid or expired.
 *
 * Form values are never stored in the browser.
 */
import { createSession, postJson } from './session';

export const MIN_AGE_MS = 3100;

/**
 * Creates a submitter.
 *
 * @param {Object}                        options            Options.
 * @param {string}                        options.sessionUrl Session endpoint.
 * @param {string}                        options.submitUrl  Submit endpoint with a {form} placeholder.
 * @param {Object}                        options.session    Shared session (lib/session); created when missing.
 * @param {typeof window.fetch}           options.fetch      fetch (tests).
 * @param {(ms: number) => Promise<void>} options.wait       Delay (tests).
 * @param {() => number}                  options.now        Clock (tests).
 * @return {{prepare: () => Promise<void>, submit: (formId: string, payload: Object) => Promise<Object>}} Submitter.
 */
export function createSubmitter( {
	sessionUrl,
	submitUrl,
	session,
	fetch = ( ...args ) => window.fetch( ...args ),
	wait,
	now,
} ) {
	const tokens = session || createSession( { sessionUrl, fetch, wait, now } );

	return {
		/**
		 * Fetches a token early (when the form is shown).
		 *
		 * @return {Promise<void>} Resolves even when the request fails.
		 */
		prepare() {
			return tokens.prepare();
		},

		/**
		 * Sends a form. Throws on network errors.
		 *
		 * @param {string} formId  Form ID.
		 * @param {Object} payload Body without the token.
		 * @return {Promise<{ok: boolean, status: number, code: string, data: Object}>} Result.
		 */
		submit( formId, payload ) {
			const url = submitUrl.replace(
				/\{form\}|%7Bform%7D/i,
				encodeURIComponent( formId )
			);
			return tokens.send(
				( token ) => postJson( fetch, url, { ...payload, token } ),
				MIN_AGE_MS
			);
		},
	};
}
