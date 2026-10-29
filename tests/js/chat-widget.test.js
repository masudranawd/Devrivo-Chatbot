/**
 * Chat in the widget ("Ask a question"): AI and Guided answers, history in
 * memory, sources, suggested actions, AI tag and disclaimer, errors.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import { render } from '../../assets-src/widget/widget';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import { parseBoot } from '../../assets-src/widget/lib/boot';
import {
	createChat,
	MAX_HISTORY,
	normalizeAnswer,
} from '../../assets-src/widget/lib/chat';

const boot = parseBoot( {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config',
	widgetUrl: 'https://example.com/build/widget.js',
	devices: [ 'desktop', 'mobile' ],
} );

const RAW = {
	behaviour: { auto_open: false },
	ai_available: true,
	disclaimer: 'AI assistant. General information only.',
	chat_url: 'https://example.com/?rest_route=/devrivo-chatbot/v1/chat',
	actions: [
		{
			id: 'contact',
			label: 'Contact us',
			type: 'link',
			config: { url: 'https://example.com/contact/', new_tab: false },
		},
	],
	i18n: {
		ask: 'Ask a question',
		ask_prompt: 'What would you like to know?',
		read_more: 'Read more on our site',
		back_to_menu: 'Back to menu',
		new_tab: '(opens in a new tab)',
		ai_tag: 'AI',
		chat_limited: 'Too many messages.',
		chat_error: 'Could not answer.',
	},
};

const ANSWER = {
	mode: 'ai',
	text: 'We are open on weekdays.\nCall us to book.',
	sources: [ { title: 'Opening hours', url: 'https://example.com/hours/' } ],
	actions: [ 'contact' ],
};

let host;
let widget;
let chat;

const $ = ( selector ) => host.shadowRoot.querySelector( selector );
const $$ = ( selector ) => [ ...host.shadowRoot.querySelectorAll( selector ) ];
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const botTexts = () => $$( '.dc-msg.dc-bot' ).map( ( m ) => m.textContent );

function setup( raw = RAW, reply = { ok: true, status: 200, answer: ANSWER } ) {
	host = document.createElement( 'div' );
	host.attachShadow( { mode: 'open' } );
	document.body.append( host );
	chat = {
		send: vi.fn( async () =>
			typeof reply === 'function' ? reply() : reply
		),
	};
	widget = render( host, normalizeConfig( raw, boot ), {
		typingTime: () => 0,
		chat,
		searcher: { search: vi.fn() },
		submitter: { prepare: vi.fn(), submit: vi.fn() },
	} );
}

async function send( text ) {
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

describe( 'chat in the widget', () => {
	it( 'sends the message and shows the answer, sources and actions', async () => {
		setup();
		$( '.dc-ask' ).click();
		await send( 'When are you open?' );

		expect( chat.send ).toHaveBeenCalledWith( 'When are you open?', [] );
		expect( $( '.dc-msg.dc-user' ).textContent ).toBe(
			'When are you open?'
		);
		const answer = botTexts().at( -1 );
		expect( answer ).toBe( 'We are open on weekdays.\nCall us to book.' );

		const card = $( '.dc-link' );
		expect( card.getAttribute( 'href' ) ).toBe(
			'https://example.com/hours/'
		);
		expect( card.querySelector( 'strong' ).textContent ).toBe(
			'Opening hours'
		);

		const cta = $( '.dc-ctas [data-action="contact"]' );
		expect( cta.textContent ).toBe( 'Contact us' );
		expect( cta.getAttribute( 'href' ) ).toMatch(
			/^https:\/\/example\.com\/contact\/\?utm_source=devrivo-chatbot/
		);
		expect( $( '.dc-reply' ).textContent ).toBe( 'Back to menu' );
		expect( host.shadowRoot.activeElement ).toBe(
			$( '.dc-composer input' )
		);
	} );

	it( 'keeps the conversation in memory and sends at most 8 turns', async () => {
		setup();
		$( '.dc-ask' ).click();
		for ( let i = 1; i <= 6; i++ ) {
			await send( `Question ${ i }` );
		}

		const history = chat.send.mock.calls.at( -1 )[ 1 ];
		expect( history ).toHaveLength( 8 );
		expect( history[ 0 ] ).toEqual( { role: 'user', text: 'Question 2' } );
		expect( history.at( -1 ) ).toEqual( {
			role: 'assistant',
			text: ANSWER.text,
		} );
		expect(
			window.localStorage.length + window.sessionStorage.length
		).toBe( 0 );
	} );

	it( 'shows the AI tag and disclaimer only when AI is available', () => {
		setup();
		expect( $( '.dc-ai-tag' ).textContent ).toBe( 'AI' );
		expect( $( '.dc-disclaimer' ).textContent ).toBe(
			'AI assistant. General information only.'
		);
		widget.destroy();
		host.remove();

		setup( {
			...RAW,
			ai_available: false,
			search_enabled: true,
			search_url: 'https://example.com/search',
		} );
		expect( $( '.dc-ask' ) ).not.toBeNull();
		expect( $( '.dc-ai-tag' ) ).toBeNull();
		expect( $( '.dc-disclaimer' ) ).toBeNull();
	} );

	it( 'shows Guided answers the same way', async () => {
		setup(
			{
				...RAW,
				ai_available: false,
				search_enabled: true,
				search_url: 'https://example.com/search',
			},
			{
				ok: true,
				status: 200,
				answer: {
					mode: 'guided',
					text: 'I couldn’t find that on our website.',
					sources: [],
					actions: [ 'contact' ],
				},
			}
		);
		$( '.dc-ask' ).click();
		await send( 'unicorns' );

		expect( botTexts().at( -1 ) ).toBe(
			'I couldn’t find that on our website.'
		);
		expect( $( '.dc-ctas [data-action="contact"]' ) ).not.toBeNull();
		expect( $( '.dc-link' ) ).toBeNull();
	} );

	it( 'shows friendly messages for rate limits and network errors', async () => {
		setup( RAW, { ok: false, status: 429, answer: null } );
		$( '.dc-ask' ).click();
		await send( 'Hello' );
		expect( botTexts().at( -1 ) ).toBe( 'Too many messages.' );

		chat.send.mockRejectedValueOnce( new Error( 'offline' ) );
		await send( 'Hello' );
		expect( botTexts().at( -1 ) ).toBe( 'Could not answer.' );
		expect( chat.send.mock.calls.at( -1 )[ 1 ] ).toEqual( [] );
	} );

	it( 'renders answers as text, never HTML', async () => {
		setup( RAW, {
			ok: true,
			status: 200,
			answer: {
				mode: 'ai',
				text: '<img src=x onerror=alert(1)> <b>bold</b>',
				sources: [
					{ title: '<i>x</i>', url: 'https://example.com/x/' },
				],
				actions: [ 'unknown' ],
			},
		} );
		$( '.dc-ask' ).click();
		await send( 'xss' );

		expect( $( '.dc-msgs img' ) ).toBeNull();
		expect( $( '.dc-msgs b' ) ).toBeNull();
		expect( botTexts().at( -1 ) ).toBe(
			'<img src=x onerror=alert(1)> <b>bold</b>'
		);
		expect( $( '.dc-link strong' ).textContent ).toBe( '<i>x</i>' );
		expect( $( '.dc-ctas' ) ).toBeNull();
	} );
} );

describe( 'lib/chat', () => {
	it( 'posts the message, recent history, page and token without cookies', async () => {
		const fetch = vi.fn( async ( url ) =>
			url.includes( 'session' )
				? {
						ok: true,
						status: 200,
						json: async () => ( { token: 'T1' } ),
					}
				: {
						ok: true,
						status: 200,
						json: async () => ANSWER,
					}
		);
		const client = createChat( {
			chatUrl: 'https://example.com/chat',
			session: {
				send: ( post ) => post( 'T1' ),
				prepare: async () => {},
			},
			fetch,
		} );
		const history = Array.from( { length: 12 }, ( _, i ) => ( {
			role: i % 2 ? 'assistant' : 'user',
			text: `t${ i }`,
		} ) );

		const result = await client.send( 'Hi', history );

		const [ url, init ] = fetch.mock.calls[ 0 ];
		const body = JSON.parse( init.body );
		expect( url ).toBe( 'https://example.com/chat' );
		expect( init.method ).toBe( 'POST' );
		expect( init.credentials ).toBe( 'omit' );
		expect( body.message ).toBe( 'Hi' );
		expect( body.token ).toBe( 'T1' );
		expect( body.history ).toHaveLength( MAX_HISTORY );
		expect( body.history[ 0 ].text ).toBe( 't4' );
		expect( body.page_url ).toBe( window.location.href );
		expect( result.answer.mode ).toBe( 'ai' );
	} );

	it( 'gets a new token once when the token is invalid', async () => {
		let tokens = 0;
		const fetch = vi.fn( async ( url ) => {
			if ( url.includes( 'session' ) ) {
				tokens++;
				return {
					ok: true,
					status: 200,
					json: async () => ( { token: `T${ tokens }` } ),
				};
			}
			return tokens === 1
				? {
						ok: false,
						status: 403,
						json: async () => ( {
							code: 'devrivo_chatbot_invalid_token',
						} ),
					}
				: { ok: true, status: 200, json: async () => ANSWER };
		} );
		const client = createChat( {
			chatUrl: 'https://example.com/chat',
			sessionUrl: 'https://example.com/session',
			fetch,
		} );

		const result = await client.send( 'Hi' );

		expect( tokens ).toBe( 2 );
		expect( result.ok ).toBe( true );
		expect( JSON.parse( fetch.mock.calls.at( -1 )[ 1 ].body ).token ).toBe(
			'T2'
		);
	} );

	it( 'keeps only well-formed answers with safe source links', () => {
		expect( normalizeAnswer( null ) ).toBeNull();
		expect( normalizeAnswer( { text: '' } ) ).toBeNull();
		expect(
			normalizeAnswer( {
				mode: 'weird',
				text: 'Hi',
				sources: [
					{ title: 'A', url: 'javascript:alert(1)' },
					{ title: 'B', url: 'https://example.com/b/' },
					{ url: 'https://example.com/c/' },
				],
				actions: [ 'contact', 5, 'book', 'x', 'y' ],
			} )
		).toEqual( {
			mode: 'guided',
			text: 'Hi',
			sources: [ { title: 'B', url: 'https://example.com/b/' } ],
			actions: [ 'contact', 'book', 'x' ],
		} );
	} );
} );
