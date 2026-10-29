/**
 * Signed session tokens for public endpoints (forms, chat): fetched once,
 * waited on until old enough for the endpoint's minimum age, and refreshed
 * once when the server says the token is invalid or expired.
 */

/**
 * Creates a session.
 *
 * @param {Object}                        options            Options.
 * @param {string}                        options.sessionUrl Session endpoint.
 * @param {typeof window.fetch}           options.fetch      fetch (tests).
 * @param {(ms: number) => Promise<void>} options.wait       Delay (tests).
 * @param {() => number}                  options.now        Clock (tests).
 * @return {{prepare: () => Promise<void>, send: (post: (token: string) => Promise<Object>, minAgeMs: number) => Promise<Object>}} Session.
 */
export function createSession( {
	sessionUrl,
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

	// Waits until the token is old enough for the endpoint.
	const ripe = async ( current, minAgeMs ) => {
		const age = now() - current.at;
		if ( age < minAgeMs ) {
			await wait( minAgeMs - age );
		}
		return current;
	};

	return {
		/**
		 * Fetches a token early (e.g. when a form is shown).
		 *
		 * @return {Promise<void>} Resolves even when the request fails.
		 */
		prepare() {
			pending = fetchToken().catch( () => null );
			return pending.then( () => {} );
		},

		/**
		 * Runs a request with a token, retrying once with a fresh token when
		 * it is invalid, or after waiting when it was used too soon.
		 *
		 * @param {(token: string) => Promise<{code?: string}>} post     Sends the request.
		 * @param {number}                                      minAgeMs Minimum token age.
		 * @return {Promise<Object>} The request's result.
		 */
		async send( post, minAgeMs ) {
			if ( pending ) {
				await pending;
			}
			let current = await ripe(
				token || ( await fetchToken() ),
				minAgeMs
			);
			let result = await post( current.value );

			if ( result.code === 'devrivo_chatbot_invalid_token' ) {
				current = await ripe( await fetchToken(), minAgeMs );
				result = await post( current.value );
			} else if ( result.code === 'devrivo_chatbot_too_fast' ) {
				await wait( minAgeMs );
				result = await post( current.value );
			}

			return result;
		},
	};
}

/**
 * POSTs JSON without cookies and reads the JSON reply.
 *
 * @param {typeof window.fetch} fetch fetch.
 * @param {string}              url   URL.
 * @param {Object}              body  Body.
 * @return {Promise<{ok: boolean, status: number, code: string, data: Object}>} Result.
 */
export async function postJson( fetch, url, body ) {
	const response = await fetch( url, {
		method: 'POST',
		credentials: 'omit',
		cache: 'no-store',
		headers: {
			'Content-Type': 'application/json',
			Accept: 'application/json',
		},
		body: JSON.stringify( body ),
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
}
