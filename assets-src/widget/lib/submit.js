/**
 * Sends forms: gets a signed session token, waits until it is old enough for
 * the server's minimum fill time, posts without cookies, and retries once
 * with a fresh token when the server says the token is invalid or expired.
 *
 * Form values are never stored in the browser.
 */

export const MIN_AGE_MS = 3100;

/**
 * Creates a submitter.
 *
 * @param {Object}                        options            Options.
 * @param {string}                        options.sessionUrl Session endpoint.
 * @param {string}                        options.submitUrl  Submit endpoint with a {form} placeholder.
 * @param {typeof window.fetch}           options.fetch      fetch (tests).
 * @param {(ms: number) => Promise<void>} options.wait       Delay (tests).
 * @param {() => number}                  options.now        Clock (tests).
 * @return {{prepare: () => Promise<void>, submit: (formId: string, payload: Object) => Promise<Object>}} Submitter.
 */
export function createSubmitter( {
	sessionUrl,
	submitUrl,
	fetch = ( ...args ) => window.fetch( ...args ),
	wait = ( ms ) =>
		new Promise( ( resolve ) => window.setTimeout( resolve, ms ) ),
	now = () => Date.now(),
} ) {
	let token = null;
	let pending = null;

	const fetchToken = async () => {
		const response = await fetch( sessionUrl, {
			credentials: 'omit',
			cache: 'no-store',
			headers: { Accept: 'application/json' },
		} );
		const data = response.ok ? await response.json() : null;
		if ( ! data || typeof data.token !== 'string' ) {
			throw new Error( 'session' );
		}
		token = { value: data.token, at: now() };
		return token;
	};

	// Waits until the token is old enough for the server's minimum fill time.
	const ripe = async ( current ) => {
		const age = now() - current.at;
		if ( age < MIN_AGE_MS ) {
			await wait( MIN_AGE_MS - age );
		}
		return current;
	};

	const post = async ( formId, payload, current ) => {
		const url = submitUrl.replace(
			/\{form\}|%7Bform%7D/i,
			encodeURIComponent( formId )
		);
		const response = await fetch( url, {
			method: 'POST',
			credentials: 'omit',
			cache: 'no-store',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			},
			body: JSON.stringify( { ...payload, token: current.value } ),
		} );
		let data = {};
		try {
			data = await response.json();
		} catch {
			data = {};
		}

		return {
			ok: response.ok,
			status: response.status,
			code: data && data.code,
			data: data || {},
		};
	};

	return {
		/**
		 * Fetches a token early (when the form is shown).
		 *
		 * @return {Promise<void>} Resolves even when the request fails.
		 */
		prepare() {
			pending = fetchToken().catch( () => null );
			return pending.then( () => {} );
		},

		/**
		 * Sends a form. Throws on network errors.
		 *
		 * @param {string} formId  Form ID.
		 * @param {Object} payload Body without the token.
		 * @return {Promise<{ok: boolean, status: number, code: string, data: Object}>} Result.
		 */
		async submit( formId, payload ) {
			if ( pending ) {
				await pending;
			}
			let current = await ripe( token || ( await fetchToken() ) );
			let result = await post( formId, payload, current );

			if ( result.code === 'devrivo_chatbot_invalid_token' ) {
				current = await ripe( await fetchToken() );
				result = await post( formId, payload, current );
			} else if ( result.code === 'devrivo_chatbot_too_fast' ) {
				await wait( MIN_AGE_MS );
				result = await post( formId, payload, current );
			}

			return result;
		},
	};
}
