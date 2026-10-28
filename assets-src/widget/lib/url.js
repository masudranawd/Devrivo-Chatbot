/**
 * URL validation for anything config data puts into href or src.
 */

export const LINK_PROTOCOLS = [ 'http:', 'https:', 'tel:', 'mailto:' ];
export const HTTP_PROTOCOLS = [ 'http:', 'https:' ];

/**
 * Returns an absolute URL if it uses an allowed protocol, else ''.
 *
 * Relative URLs resolve against the page, so "/contact" works while
 * "javascript:" or "data:" URLs never do.
 *
 * @param {unknown}  value     Candidate URL.
 * @param {string[]} protocols Allowed protocols (with the colon).
 * @param {string}   base      Base for relative URLs.
 * @return {string} Safe URL or ''.
 */
export function safeUrl(
	value,
	protocols = LINK_PROTOCOLS,
	base = window.location.href
) {
	if ( typeof value !== 'string' || value.trim() === '' ) {
		return '';
	}

	try {
		const url = new URL( value.trim(), base );
		return protocols.includes( url.protocol ) ? url.href : '';
	} catch {
		return '';
	}
}
