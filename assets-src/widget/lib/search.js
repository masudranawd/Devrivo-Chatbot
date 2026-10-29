/**
 * Knowledge search: asks the public search endpoint and validates the
 * results, so the views can trust their shape. Questions go in a POST body
 * (never in URLs) and are never stored in the browser.
 */
import { HTTP_PROTOCOLS, safeUrl } from './url';

const TYPE = /^[a-z0-9_-]{1,20}$/;

/**
 * Creates a searcher.
 *
 * @param {Object}              options       Options.
 * @param {string}              options.url   Search endpoint.
 * @param {typeof window.fetch} options.fetch fetch (tests).
 * @return {{search: (query: string, types?: string[]) => Promise<{ok: boolean, status: number, results: Object[]}>}} Searcher.
 */
export function createSearcher( {
	url,
	fetch = ( ...args ) => window.fetch( ...args ),
} ) {
	return {
		/**
		 * Searches. Throws on network errors.
		 *
		 * @param {string}   query Question.
		 * @param {string[]} types Only these post types (page_search actions).
		 * @return {Promise<{ok: boolean, status: number, results: Object[]}>} Result.
		 */
		async search( query, types = [] ) {
			const body = { q: query };
			const only = types.filter( ( type ) => TYPE.test( type ) );
			if ( only.length ) {
				body.types = only.join( ',' );
			}

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
			let data = null;
			try {
				data = await response.json();
			} catch {
				data = null;
			}

			return {
				ok: response.ok,
				status: response.status,
				results: response.ok ? normalizeResults( data ) : [],
			};
		},
	};
}

/**
 * Results with a known type and text; page links must be http(s).
 *
 * @param {unknown} data Response body.
 * @return {Array<{type: string, title: string, url: string, excerpt: string}>} Results.
 */
export function normalizeResults( data ) {
	const list =
		data && typeof data === 'object' && Array.isArray( data.results )
			? data.results
			: [];

	return list
		.filter(
			( r ) =>
				r &&
				typeof r === 'object' &&
				[ 'page', 'faq' ].includes( r.type ) &&
				typeof r.title === 'string'
		)
		.map( ( r ) => ( {
			type: r.type,
			title: r.title,
			url: r.type === 'page' ? safeUrl( r.url, HTTP_PROTOCOLS ) : '',
			excerpt: typeof r.excerpt === 'string' ? r.excerpt : '',
		} ) )
		.filter( ( r ) => ( r.type === 'page' ? r.url : r.excerpt ) );
}
