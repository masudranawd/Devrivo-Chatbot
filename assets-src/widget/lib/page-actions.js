/**
 * Page-specific quick actions: which rule matches the current page, and the
 * menu it gives (pinned actions first, then the rule's actions). Runs in the
 * browser, so cached pages still get the right menu.
 */

/**
 * A path for comparing: decoded, lowercase, without query or hash, with one
 * leading and one trailing slash.
 *
 * @param {unknown} value Path.
 * @return {string} Normalised path.
 */
export function normalizePath( value ) {
	let path = String( value ?? '' ).split( /[?#]/ )[ 0 ];
	try {
		path = decodeURIComponent( path );
	} catch {
		// Keep a malformed path as it is.
	}
	path = `/${ path.toLowerCase() }/`.replace( /\/{2,}/g, '/' );
	return path;
}

/**
 * The current path relative to the site home: "/pearl/about/" with the home
 * "/pearl/" becomes "/about/".
 *
 * @param {string} pathname location.pathname.
 * @param {string} homePath Path of the site home.
 * @return {string} Normalised relative path.
 */
export function relativePath( pathname, homePath = '/' ) {
	const path = normalizePath( pathname );
	const home = normalizePath( homePath );
	return path.startsWith( home ) ? `/${ path.slice( home.length ) }` : path;
}

/**
 * The first rule that matches the page (rule order is priority), or null.
 *
 * @param {Array}  rules       Normalised rules { postIds, paths, includeChildren, actions }.
 * @param {Object} page        Current page.
 * @param {number} page.postId Queried post ID (0 when not a single post or page).
 * @param {string} page.path   Normalised path relative to the home.
 * @return {Object|null} Rule.
 */
export function matchRule( rules, { postId = 0, path = '/' } = {} ) {
	const current = normalizePath( path );
	return (
		( rules || [] ).find(
			( rule ) =>
				( postId > 0 && rule.postIds.includes( postId ) ) ||
				rule.paths.some( ( value ) => {
					const target = normalizePath( value );
					return (
						current === target ||
						( rule.includeChildren && current.startsWith( target ) )
					);
				} )
		) || null
	);
}

/**
 * Menu actions for a page: pinned actions first (in their order), then the
 * matched rule's actions, or the normal menu when no rule matches. Each
 * action appears once.
 *
 * @param {Array}       actions Normalised actions.
 * @param {Object|null} rule    Matched rule.
 * @return {Array} Actions.
 */
export function composeMenu( actions, rule ) {
	const byId = new Map( actions.map( ( action ) => [ action.id, action ] ) );
	const rest = rule
		? rule.actions.map( ( id ) => byId.get( id ) ).filter( Boolean )
		: actions.filter( ( action ) => action.showInMenu );
	const seen = new Set();

	return [ ...actions.filter( ( action ) => action.pinned ), ...rest ].filter(
		( action ) => ! seen.has( action.id ) && seen.add( action.id )
	);
}
