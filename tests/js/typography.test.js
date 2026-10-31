/**
 * Typography: bundled font loading, type tokens, text size scaling, mobile
 * input size, and no rem or remote fonts anywhere.
 */
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { baseCss } from '../../assets-src/widget/launcher';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import {
	ensureFont,
	FONT_FAMILY,
	FONT_STYLE_ID,
} from '../../assets-src/widget/lib/fonts';
import { widgetCss } from '../../assets-src/widget/styles';
import { mount, preview } from '../../assets-src/widget/widget';

const CSS = baseCss + widgetCss;
const FONT_URL =
	'https://example.com/wp-content/plugins/devrivo-chatbot/assets/fonts/figtree/figtree-variable.woff2?ver=1';
const SCALES = { small: 0.93, default: 1, large: 1.13 };

/**
 * Size tokens: name => base px.
 *
 * @return {Object<string, number>} Tokens.
 */
function sizeTokens() {
	const tokens = {};
	for ( const match of CSS.matchAll(
		/--dc-fs-([a-z]+):max\(12px,calc\((\d+)px \* var\(--dc-scale\)\)\)/g
	) ) {
		tokens[ match[ 1 ] ] = Number( match[ 2 ] );
	}
	return tokens;
}

/**
 * The scale set by a text size, read from the CSS.
 *
 * @param {string} size small | large.
 * @return {number} Scale.
 */
function cssScale( size ) {
	const match = new RegExp(
		`\\.dc\\[data-size="${ size }"\\]\\{--dc-scale:([.\\d]+)\\}`
	).exec( CSS );
	return match ? Number( match[ 1 ] ) : NaN;
}

const fontStyles = () =>
	document.head.querySelectorAll( `#${ FONT_STYLE_ID }` );

beforeEach( () => {
	document.getElementById( FONT_STYLE_ID )?.remove();
} );

afterEach( () => {
	document.body.replaceChildren();
	vi.restoreAllMocks();
} );

describe( 'bundled font', () => {
	it( 'adds one @font-face rule for figtree, with the boot URL', () => {
		expect( ensureFont( 'figtree', FONT_URL ) ).toBe( true );
		expect( ensureFont( 'figtree', FONT_URL ) ).toBe( false );

		const styles = fontStyles();
		expect( styles ).toHaveLength( 1 );
		const css = styles[ 0 ].textContent;
		expect( css ).toContain( `font-family:"${ FONT_FAMILY }"` );
		expect( css ).toContain( `url("${ FONT_URL }") format("woff2")` );
		expect( css ).toContain( 'font-display:swap' );
		expect( css ).toContain( 'font-weight:300 900' );
	} );

	it( 'adds nothing for system or inherit, or without a URL', () => {
		expect( ensureFont( 'system', FONT_URL ) ).toBe( false );
		expect( ensureFont( 'inherit', FONT_URL ) ).toBe( false );
		expect( ensureFont( 'figtree', '' ) ).toBe( false );
		expect( fontStyles() ).toHaveLength( 0 );
	} );

	it( 'normalises the font and text size settings', () => {
		const config = ( appearance ) =>
			normalizeConfig( { appearance }, { position: 'right' } );
		expect( config( { font: 'figtree' } ).font ).toBe( 'figtree' );
		expect( config( { font: 'inherit' } ).font ).toBe( 'inherit' );
		expect( config( { font: 'comic' } ).font ).toBe( 'system' );
		expect( config( {} ).font ).toBe( 'system' );
		expect( config( { text_size: 'large' } ).textSize ).toBe( 'large' );
		expect( config( { text_size: 'huge' } ).textSize ).toBe( 'default' );
	} );

	/**
	 * Mounts the widget like the loader does.
	 *
	 * @param {Object} appearance Appearance config.
	 * @return {Promise<HTMLElement>} Host.
	 */
	async function mountWith( appearance ) {
		vi.spyOn( window, 'fetch' ).mockResolvedValue( {
			ok: true,
			json: async () => ( { appearance, actions: [], i18n: {} } ),
		} );
		const host = document.createElement( 'div' );
		host.attachShadow( { mode: 'open' } );
		document.body.append( host );
		await mount( host, {
			configUrl: 'https://example.com/config',
			fontUrl: FONT_URL,
			position: 'right',
			primaryColor: '#123456',
			launcherLabel: '',
		} );
		return host;
	}

	it( 'mounting with figtree adds the font once; system and inherit add nothing', async () => {
		const first = await mountWith( { font: 'system' } );
		expect( fontStyles() ).toHaveLength( 0 );
		expect( first.shadowRoot.querySelector( '.dc' ).dataset.font ).toBe(
			'system'
		);

		await mountWith( { font: 'inherit' } );
		expect( fontStyles() ).toHaveLength( 0 );

		const host = await mountWith( { font: 'figtree', text_size: 'small' } );
		await mountWith( { font: 'figtree' } );
		expect( fontStyles() ).toHaveLength( 1 );
		const root = host.shadowRoot.querySelector( '.dc' );
		expect( root.dataset.font ).toBe( 'figtree' );
		expect( root.dataset.size ).toBe( 'small' );
		expect(
			host.shadowRoot.querySelector( `#${ FONT_STYLE_ID }` )
		).toBeNull();
	} );

	it( 'the admin preview loads the font from its option', () => {
		const host = document.createElement( 'div' );
		document.body.append( host );
		const widget = preview(
			host,
			{ appearance: { font: 'figtree', text_size: 'large' } },
			{ fontUrl: FONT_URL }
		);
		expect( fontStyles() ).toHaveLength( 1 );
		expect( host.shadowRoot.querySelector( '.dc' ).dataset.size ).toBe(
			'large'
		);
		widget.destroy();

		document.getElementById( FONT_STYLE_ID ).remove();
		const other = document.createElement( 'div' );
		document.body.append( other );
		preview(
			other,
			{ appearance: { font: 'figtree' } },
			{ fontUrl: 'javascript:alert(1)' }
		).destroy();
		expect( fontStyles() ).toHaveLength( 0 );
	} );
} );

describe( 'type tokens', () => {
	it( 'defines the six roles with fixed px sizes', () => {
		expect( sizeTokens() ).toEqual( {
			greeting: 24,
			title: 17,
			body: 15,
			button: 14,
			label: 13,
			meta: 12,
		} );
		for ( const role of Object.keys( sizeTokens() ) ) {
			for ( const kind of [ 'fw', 'lh', 'ls' ] ) {
				expect( CSS ).toMatch(
					new RegExp( `--dc-${ kind }-${ role }:[^;]+;` )
				);
			}
		}
		expect( CSS ).toContain( '--dc-ls-greeting:-0.01em' );
		expect( CSS ).toContain(
			':host{all:initial;font-family:inherit;font-size:15px'
		);
	} );

	it( 'scales every size with the text size, never below 12px', () => {
		expect( cssScale( 'small' ) ).toBe( SCALES.small );
		expect( cssScale( 'large' ) ).toBe( SCALES.large );

		// Every size token is defined with the 12px floor (sizeTokens() only
		// matches that form), so no text can render below 12px at any scale.
		const tokens = sizeTokens();
		const definitions = CSS.match( /--dc-fs-[a-z]+:/g );
		expect( definitions ).toHaveLength( Object.keys( tokens ).length );
		const below = Object.entries( tokens ).filter(
			( [ , base ] ) => base * SCALES.small < 12
		);
		expect( below.map( ( [ role ] ) => role ) ).toEqual( [ 'meta' ] );
		expect( Math.max( 12, tokens.body * SCALES.large ) ).toBeCloseTo(
			16.95
		);
		expect( Math.max( 12, tokens.meta * SCALES.small ) ).toBe( 12 );
	} );

	it( 'uses tokens for every font size, weight and line height', () => {
		const sizes = [ ...CSS.matchAll( /font-size:([^;}]+)/g ) ].map(
			( m ) => m[ 1 ]
		);
		for ( const value of sizes ) {
			expect( value ).toMatch(
				/^(var\(--dc-fs-[a-z]+\)|15px|max\(16px,var\(--dc-fs-body\)\))$/
			);
		}
		for ( const [ prop, token ] of [
			[ 'font-weight', 'fw' ],
			[ 'line-height', 'lh' ],
			[ 'letter-spacing', 'ls' ],
		] ) {
			for ( const match of CSS.matchAll(
				new RegExp( `[;{\\s]${ prop }:([^;}]+)`, 'g' )
			) ) {
				expect( match[ 1 ] ).toMatch(
					new RegExp(
						`^(var\\(--dc-${ token }-[a-z]+\\)|inherit|1\\.5)$`
					)
				);
			}
		}
	} );

	it( 'has no rem anywhere', () => {
		expect( CSS ).not.toMatch( /\d(\.\d+)?rem\b/ );
	} );

	it( 'form fields and the composer are at least 16px on phones and tablets', () => {
		const media = /@media \(max-width:768px\)\{([^}]+\})\s*\}/.exec( CSS );
		expect( media ).not.toBeNull();
		for ( const selector of [
			'.dc-field input',
			'.dc-field select',
			'.dc-field textarea',
			'.dc-composer input',
		] ) {
			expect( media[ 1 ] ).toContain( selector );
		}
		expect( media[ 1 ] ).toContain(
			'font-size:max(16px,var(--dc-fs-body))'
		);
		expect( CSS ).toContain(
			'.dc[data-mobile] :is(.dc-field input,.dc-field select,.dc-field textarea,.dc-composer input){font-size:max(16px,var(--dc-fs-body))}'
		);
	} );

	it( 'inherits fonts in form controls and smooths text', () => {
		expect( CSS ).toContain(
			'.dc :where(button,input,select,textarea){font:inherit;letter-spacing:inherit}'
		);
		expect( CSS ).toContain( '-webkit-font-smoothing:antialiased' );
		expect( CSS ).toContain( '-moz-osx-font-smoothing:grayscale' );
		expect( CSS ).toContain(
			`.dc[data-font="figtree"]{font-family:"${ FONT_FAMILY }",var(--dc-font-system)}`
		);
	} );
} );

describe( 'no remote fonts', () => {
	it( 'no built or source file references a remote font service', () => {
		const files = [];
		const walk = ( dir ) => {
			if ( ! existsSync( dir ) ) {
				return;
			}
			for ( const entry of readdirSync( dir, { withFileTypes: true } ) ) {
				const path = join( dir, entry.name );
				if ( entry.isDirectory() ) {
					walk( path );
				} else if ( /\.(js|css|php|json)$/.test( entry.name ) ) {
					files.push( path );
				}
			}
		};
		walk( 'build' );
		walk( 'assets-src' );
		walk( 'src' );
		expect( files.some( ( f ) => f.startsWith( 'build' ) ) ).toBe(
			existsSync( 'build' )
		);

		for ( const file of files ) {
			const text = readFileSync( file, 'utf8' );
			expect( text ).not.toMatch(
				/fonts\.googleapis\.com|fonts\.gstatic\.com|use\.typekit|fonts\.bunny\.net/
			);
			expect( text ).not.toMatch( /@import\s+url\(\s*['"]?https?:/ );
		}
	} );
} );
