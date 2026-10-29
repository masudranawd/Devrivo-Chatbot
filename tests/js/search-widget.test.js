/**
 * Guided answers: "Ask a question", page_search actions, results, fallback.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '../../assets-src/widget/widget';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import { parseBoot } from '../../assets-src/widget/lib/boot';
import {
	createSearcher,
	normalizeResults,
} from '../../assets-src/widget/lib/search';

const boot = parseBoot( {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config',
	widgetUrl: 'https://example.com/build/widget.js',
	devices: [ 'desktop', 'mobile' ],
} );

const RAW = {
	behaviour: { auto_open: false },
	search_enabled: true,
	search_url: 'https://example.com/?rest_route=/devrivo-chatbot/v1/search',
	fallback_action: 'contact',
	actions: [
		{
			id: 'contact',
			label: 'Contact us',
			type: 'link',
			config: { url: 'https://example.com/contact/', new_tab: false },
		},
		{
			id: 'articles',
			label: 'Find an article',
			type: 'page_search',
			config: { post_types: [ 'post' ] },
		},
	],
	i18n: {
		ask: 'Ask a question',
		ask_prompt: 'What would you like to know?',
		search_found: 'Here is what I found:',
		no_results: 'Not found.',
		read_more: 'Read more on our site',
		search_limited: 'Slow down.',
		search_error: 'Search failed.',
		back_to_menu: 'Back to menu',
		new_tab: '(opens in a new tab)',
	},
};

let host;
let widget;
let searcher;

const $ = ( selector ) => host.shadowRoot.querySelector( selector );
const $$ = ( selector ) => [ ...host.shadowRoot.querySelectorAll( selector ) ];
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const botTexts = () => $$( '.dc-msg.dc-bot' ).map( ( m ) => m.textContent );

function setup( raw = RAW, response = { ok: true, status: 200, results: [] } ) {
	host = document.createElement( 'div' );
	host.attachShadow( { mode: 'open' } );
	document.body.append( host );
	searcher = {
		search: vi.fn( async () =>
			typeof response === 'function' ? response() : response
		),
	};
	widget = render( host, normalizeConfig( raw, boot ), {
		typingTime: () => 0,
		searcher,
		submitter: { prepare: vi.fn(), submit: vi.fn() },
	} );
}

async function ask( text ) {
	$( '.dc-composer input' ).value = text;
	$( '.dc-composer' ).dispatchEvent(
		new window.Event( 'submit', { cancelable: true } )
	);
	await flush();
	await flush();
}

afterEach( () => {
	widget.destroy();
	host.remove();
} );

describe( 'ask a question', () => {
	beforeEach( () => setup() );

	it( 'shows "Ask a question" and opens the chat with the composer focused', () => {
		$( '.dc-ask' ).click();

		expect( widget.view() ).toBe( 'chat' );
		expect( botTexts() ).toEqual( [ 'What would you like to know?' ] );
		expect( host.shadowRoot.activeElement ).toBe(
			$( '.dc-composer input' )
		);
		expect( $( '.dc-composer input' ).getAttribute( 'maxlength' ) ).toBe(
			'100'
		);
	} );

	it( 'sends the question and shows the visitor bubble', async () => {
		$( '.dc-ask' ).click();
		await ask( '  opening hours ' );

		expect( searcher.search ).toHaveBeenCalledWith( 'opening hours', [] );
		expect( $( '.dc-msg.dc-user' ).textContent ).toBe( 'opening hours' );
		expect( $( '.dc-composer input' ).value ).toBe( '' );
	} );

	it( 'ignores questions shorter than two characters', async () => {
		$( '.dc-ask' ).click();
		await ask( 'a' );

		expect( searcher.search ).not.toHaveBeenCalled();
	} );

	it( 'shows a typing indicator while searching', async () => {
		let finish;
		searcher.search.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					finish = resolve;
				} )
		);
		$( '.dc-ask' ).click();
		$( '.dc-composer input' ).value = 'hours';
		$( '.dc-composer' ).dispatchEvent(
			new window.Event( 'submit', { cancelable: true } )
		);
		await flush();

		expect( $( '.dc-typing' ) ).not.toBeNull();
		expect( $( '.dc-send' ).disabled ).toBe( true );

		finish( { ok: true, status: 200, results: [] } );
		await flush();
		await flush();
		expect( $( '.dc-typing' ) ).toBeNull();
		expect( $( '.dc-send' ).disabled ).toBe( false );
	} );
} );

describe( 'results', () => {
	it( 'shows page results as "Read more" link cards', async () => {
		setup( RAW, {
			ok: true,
			status: 200,
			results: [
				{
					type: 'page',
					title: 'Opening hours',
					url: 'https://example.com/hours/',
					excerpt: 'We are open on weekdays.',
				},
				{
					type: 'page',
					title: 'Contact',
					url: 'https://example.com/contact/',
					excerpt: '',
				},
			],
		} );
		$( '.dc-ask' ).click();
		await ask( 'hours' );

		expect( botTexts().at( -1 ) ).toBe( 'Here is what I found:' );
		const cards = $$( '.dc-link' );
		expect( cards ).toHaveLength( 2 );
		expect( cards[ 0 ].getAttribute( 'href' ) ).toBe(
			'https://example.com/hours/'
		);
		expect( cards[ 0 ].getAttribute( 'target' ) ).toBe( '_blank' );
		expect( cards[ 0 ].getAttribute( 'rel' ) ).toBe(
			'noopener noreferrer'
		);
		expect( cards[ 0 ].querySelector( 'small' ).textContent ).toBe(
			'Read more on our site'
		);
		expect( cards[ 0 ].querySelector( 'strong' ).textContent ).toBe(
			'Opening hours'
		);
		expect( $( '.dc-reply' ).textContent ).toBe( 'Back to menu' );
		expect( host.shadowRoot.activeElement ).toBe(
			$( '.dc-composer input' )
		);
	} );

	it( 'shows an FAQ result as the answer', async () => {
		setup( RAW, {
			ok: true,
			status: 200,
			results: [
				{
					type: 'faq',
					title: 'Do you deliver?',
					url: '',
					excerpt: 'Yes, we deliver locally.',
				},
			],
		} );
		$( '.dc-ask' ).click();
		await ask( 'deliver' );

		expect( botTexts().at( -1 ) ).toBe( 'Yes, we deliver locally.' );
		expect( $( '.dc-link' ) ).toBeNull();
	} );

	it( 'renders result text as text, never HTML', async () => {
		setup( RAW, {
			ok: true,
			status: 200,
			results: [
				{
					type: 'page',
					title: '<img src=x onerror=alert(1)>',
					url: 'https://example.com/x/',
					excerpt: '<b>bold</b>',
				},
			],
		} );
		$( '.dc-ask' ).click();
		await ask( 'xss' );

		expect( $( '.dc-link img' ) ).toBeNull();
		expect( $( '.dc-link b' ) ).toBeNull();
		expect( $( '.dc-link strong' ).textContent ).toBe(
			'<img src=x onerror=alert(1)>'
		);
	} );

	it( 'offers the fallback action when nothing is found', async () => {
		setup();
		$( '.dc-ask' ).click();
		await ask( 'unicorns' );

		expect( botTexts().at( -1 ) ).toBe( 'Not found.' );
		const replies = $$( '.dc-reply' ).map( ( r ) => r.textContent );
		expect( replies ).toEqual( [ 'Contact us', 'Back to menu' ] );
	} );

	it( 'shows only "Back to menu" without a fallback action', async () => {
		setup( { ...RAW, fallback_action: '' } );
		$( '.dc-ask' ).click();
		await ask( 'unicorns' );

		expect( $$( '.dc-reply' ).map( ( r ) => r.textContent ) ).toEqual( [
			'Back to menu',
		] );
	} );

	it( 'shows friendly messages for rate limits and network errors', async () => {
		setup( RAW, { ok: false, status: 429, results: [] } );
		$( '.dc-ask' ).click();
		await ask( 'hours' );
		expect( botTexts().at( -1 ) ).toBe( 'Slow down.' );

		searcher.search.mockRejectedValueOnce( new Error( 'offline' ) );
		await ask( 'hours' );
		expect( botTexts().at( -1 ) ).toBe( 'Search failed.' );
	} );
} );

describe( 'page_search actions', () => {
	it( 'opens the composer and searches only the action’s post types', async () => {
		setup();
		$( '.dc-row[data-action="articles"]' ).click();

		expect( widget.view() ).toBe( 'chat' );
		await ask( 'design' );
		expect( searcher.search ).toHaveBeenCalledWith( 'design', [ 'post' ] );

		// "Ask a question" searches everything again.
		$( '.dc-chat-head .dc-icon-btn' ).click();
		$( '.dc-ask' ).click();
		await ask( 'design' );
		expect( searcher.search ).toHaveBeenLastCalledWith( 'design', [] );
	} );

	it( 'hides search when there is nothing to search', () => {
		setup( { ...RAW, search_enabled: false } );

		expect( $( '.dc-ask' ) ).toBeNull();
		expect( $( '.dc-composer' ) ).toBeNull();
		expect( $( '[data-action="articles"]' ) ).toBeNull();
	} );
} );

describe( 'lib/search', () => {
	it( 'POSTs q and types as JSON, never in the URL, without cookies', async () => {
		const fetch = vi.fn( async () => ( {
			ok: true,
			status: 200,
			json: async () => ( { results: [] } ),
		} ) );
		const client = createSearcher( {
			url: 'https://example.com/?rest_route=/devrivo-chatbot/v1/search',
			fetch,
		} );

		await client.search( 'a & b', [ 'post', 'bad type!' ] );

		const [ url, init ] = fetch.mock.calls[ 0 ];
		expect( url ).toBe(
			'https://example.com/?rest_route=/devrivo-chatbot/v1/search'
		);
		expect( init.method ).toBe( 'POST' );
		expect( init.headers[ 'Content-Type' ] ).toBe( 'application/json' );
		expect( JSON.parse( init.body ) ).toEqual( {
			q: 'a & b',
			types: 'post',
		} );
		expect( init.credentials ).toBe( 'omit' );
		expect( init.cache ).toBe( 'no-store' );

		await client.search( 'hours' );
		expect( JSON.parse( fetch.mock.calls[ 1 ][ 1 ].body ) ).toEqual( {
			q: 'hours',
		} );
	} );

	it( 'keeps only safe, well-formed results', () => {
		expect(
			normalizeResults( {
				results: [
					{ type: 'page', title: 'A', url: 'javascript:alert(1)' },
					{ type: 'page', title: 'B', url: 'https://example.com/b/' },
					{ type: 'faq', title: 'C', excerpt: 'Answer' },
					{ type: 'faq', title: 'D', excerpt: '' },
					{ type: 'evil', title: 'E', url: 'https://example.com/' },
					null,
				],
			} )
		).toEqual( [
			{
				type: 'page',
				title: 'B',
				url: 'https://example.com/b/',
				excerpt: '',
			},
			{ type: 'faq', title: 'C', url: '', excerpt: 'Answer' },
		] );
		expect( normalizeResults( 'nope' ) ).toEqual( [] );
	} );
} );
