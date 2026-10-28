/**
 * Tiny DOM helpers. Text is always set with textContent or text nodes, never
 * innerHTML, so config strings can't inject markup.
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

/**
 * Creates an element.
 *
 * @param {string}                         tag      Tag name.
 * @param {Object}                         props    Attributes; `text` sets textContent, `class` the class name, `on*` adds listeners.
 * @param {Array<Node|string>|Node|string} children Child nodes or strings (added as text).
 * @return {HTMLElement} Element.
 */
export function h( tag, props = {}, children = [] ) {
	const node = document.createElement( tag );

	for ( const [ key, value ] of Object.entries( props ) ) {
		if ( value === undefined || value === null || value === false ) {
			continue;
		}
		if ( key === 'text' ) {
			node.textContent = String( value );
		} else if ( key === 'class' ) {
			node.className = value;
		} else if ( key.startsWith( 'on' ) && typeof value === 'function' ) {
			node.addEventListener( key.slice( 2 ).toLowerCase(), value );
		} else {
			node.setAttribute( key, value === true ? '' : String( value ) );
		}
	}

	for ( const child of [].concat( children ) ) {
		if ( child !== null && child !== undefined && child !== false ) {
			node.append( child );
		}
	}

	return node;
}

/**
 * Creates a decorative stroke icon.
 *
 * @param {string} path      SVG path data.
 * @param {number} size      Width and height in px.
 * @param {string} className Optional class (e.g. "dc-flip" for arrows that mirror in RTL).
 * @return {SVGElement} Icon.
 */
export function icon( path, size = 16, className = '' ) {
	const svg = document.createElementNS( SVG_NS, 'svg' );
	const attributes = {
		width: size,
		height: size,
		viewBox: '0 0 24 24',
		fill: 'none',
		stroke: 'currentColor',
		'stroke-width': '2.2',
		'stroke-linecap': 'round',
		'stroke-linejoin': 'round',
		'aria-hidden': 'true',
		focusable: 'false',
	};
	for ( const [ key, value ] of Object.entries( attributes ) ) {
		svg.setAttribute( key, String( value ) );
	}
	if ( className ) {
		svg.setAttribute( 'class', className );
	}

	const shape = document.createElementNS( SVG_NS, 'path' );
	shape.setAttribute( 'd', path );
	svg.append( shape );

	return svg;
}

export const ICONS = {
	close: 'M6 6l12 12M18 6L6 18',
	chevron: 'M9 6l6 6-6 6',
	back: 'M15 6l-6 6 6 6',
	send: 'M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z',
	home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
	offers: 'M3 8a2 2 0 012-2h14a2 2 0 012 2v2a2 2 0 000 4v2a2 2 0 01-2 2H5a2 2 0 01-2-2v-2a2 2 0 000-4zM14 6v12',
};
