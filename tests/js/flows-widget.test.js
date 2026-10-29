/**
 * Flows inside the widget: chat view, bubbles, quick replies, CTAs, typing
 * indicator, overlay rules, status dot and safe text.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '../../assets-src/widget/widget';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import { parseBoot } from '../../assets-src/widget/lib/boot';

const XSS = '<img src=x onerror=alert(1)>';

const boot = parseBoot( {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config',
	widgetUrl: 'https://example.com/build/widget.js',
	devices: [ 'desktop', 'mobile' ],
} );

const ALWAYS_OPEN = Object.fromEntries(
	[ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ].map( ( day ) => [
		day,
		[ { open: '00:00', close: '23:59' } ],
	] )
);

const RAW = {
	behaviour: {
		auto_open: false,
		utm: true,
		hours: { timezone: 'UTC', schedule: {}, show_status: false },
	},
	actions: [
		{
			id: 'urgent',
			label: 'I need urgent help',
			type: 'flow',
			urgent: true,
			config: { flow: 'urgent' },
		},
		{
			id: 'call_practice',
			label: 'Call the practice',
			type: 'call',
			show_in_menu: false,
			config: { phone: '+44 20 7946 0000' },
		},
		{
			id: 'whatsapp_practice',
			label: 'WhatsApp us',
			type: 'whatsapp',
			show_in_menu: false,
			config: { phone: '+44 7700 900123', message: 'Hi' },
		},
		{
			id: 'book',
			label: 'Book',
			type: 'link',
			show_in_menu: false,
			config: { url: 'https://example.com/book?x=1', new_tab: true },
		},
	],
	flows: {
		urgent: {
			title: 'Urgent',
			start: 'triage',
			nodes: {
				triage: {
					type: 'choices',
					text: 'What is happening?',
					choices: [
						{
							label: 'Heavy bleeding',
							next: 'emergency',
							urgent: true,
						},
						{ label: 'Toothache', next: 'when' },
						{ label: 'Book online', action: 'book' },
					],
				},
				emergency: {
					type: 'end',
					text: 'Contact your local emergency services immediately.',
				},
				when: {
					type: 'condition',
					then: 'open',
					else: 'closed',
				},
				open: {
					type: 'action',
					text: 'Please call us now.',
					actions: [ 'call_practice', 'book' ],
				},
				closed: {
					type: 'action',
					text: 'We may be closed.',
					actions: [ 'whatsapp_practice' ],
				},
			},
		},
	},
	i18n: {
		back_to_menu: 'Back to menu',
		open_now: 'We are open now',
		closed_now: 'We are closed right now',
		assistant: 'Assistant',
	},
};

let host;
let widget;

const $ = ( selector ) => host.shadowRoot.querySelector( selector );
const $$ = ( selector ) => [ ...host.shadowRoot.querySelectorAll( selector ) ];
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const bubbles = ( kind ) =>
	$$( `.dc-msg.dc-${ kind }:not(.dc-typing)` ).map( ( m ) => m.textContent );

/**
 * Renders the widget and starts the urgent flow from the menu.
 *
 * @param {Object} overrides Raw config overrides.
 * @param {Object} options   Render options.
 */
async function startUrgent( overrides = {}, options = {} ) {
	widget = render( host, normalizeConfig( { ...RAW, ...overrides }, boot ), {
		typingTime: () => 0,
		...options,
	} );
	$( '.dc-launcher' ).click(); // Popover.
	$( '.dc-chip[data-action="urgent"]' ).click();
	await flush();
}

beforeEach( () => {
	window.sessionStorage.clear();
	host = document.createElement( 'div' );
	host.attachShadow( { mode: 'open' } );
	document.body.append( host );
} );

afterEach( () => {
	widget.destroy();
	host.remove();
	vi.useRealTimers();
	vi.restoreAllMocks();
	delete window.matchMedia;
} );

describe( 'flows in the widget', () => {
	it( 'hides helper actions from the menu', async () => {
		await startUrgent();

		expect( $$( '.dc-chip' ).map( ( c ) => c.dataset.action ) ).toEqual( [
			'urgent',
		] );
		expect( $$( '.dc-row' ).map( ( r ) => r.dataset.action ) ).toEqual( [
			'urgent',
		] );
	} );

	it( 'opens the chat view with bot bubbles and urgent quick replies', async () => {
		await startUrgent();

		expect( widget.view() ).toBe( 'chat' );
		expect( $( '.dc-msgs' ).getAttribute( 'aria-live' ) ).toBe( 'polite' );
		expect( bubbles( 'bot' ) ).toEqual( [ 'What is happening?' ] );
		expect( $$( '.dc-reply' ).map( ( r ) => r.textContent ) ).toEqual( [
			'Heavy bleeding',
			'Toothache',
			'Book online',
		] );
		expect( $( '.dc-reply.dc-urgent' ).textContent ).toBe(
			'Heavy bleeding'
		);
		expect( host.shadowRoot.activeElement ).toBe( $( '.dc-reply' ) );
	} );

	it( 'a choice adds a visitor bubble and goes to the next step', async () => {
		await startUrgent();

		$$( '.dc-reply' )[ 0 ].click();
		await flush();

		expect( bubbles( 'user' ) ).toEqual( [ 'Heavy bleeding' ] );
		expect( bubbles( 'bot' ).at( -1 ) ).toBe(
			'Contact your local emergency services immediately.'
		);
		expect( $$( '.dc-reply' ).map( ( r ) => r.textContent ) ).toEqual( [
			'Back to menu',
		] );

		$( '.dc-reply' ).click();
		expect( widget.view() ).toBe( 'home' );
	} );

	it( 'open now: shows call and link CTAs as real links', async () => {
		await startUrgent( {
			behaviour: {
				...RAW.behaviour,
				hours: { timezone: 'UTC', schedule: ALWAYS_OPEN },
			},
		} );

		$$( '.dc-reply' )[ 1 ].click();
		await flush();

		const ctas = $$( '.dc-ctas .dc-cta' );
		expect( bubbles( 'bot' ).at( -1 ) ).toBe( 'Please call us now.' );
		expect( ctas.map( ( c ) => c.tagName ) ).toEqual( [ 'A', 'A' ] );
		expect( ctas[ 0 ].getAttribute( 'href' ) ).toBe( 'tel:+442079460000' );
		expect( ctas[ 0 ].hasAttribute( 'target' ) ).toBe( false );

		const book = new URL( ctas[ 1 ].getAttribute( 'href' ) );
		expect( book.searchParams.get( 'x' ) ).toBe( '1' );
		expect( book.searchParams.get( 'utm_content' ) ).toBe( 'book' );
		expect( ctas[ 1 ].getAttribute( 'target' ) ).toBe( '_blank' );
		expect( ctas[ 1 ].getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
	} );

	it( 'unknown hours (empty schedule) fall back to the closed branch', async () => {
		await startUrgent();

		$$( '.dc-reply' )[ 1 ].click();
		await flush();

		expect( bubbles( 'bot' ).at( -1 ) ).toBe( 'We may be closed.' );
		expect( $( '.dc-ctas .dc-cta' ).getAttribute( 'href' ) ).toBe(
			'https://wa.me/447700900123?text=Hi'
		);
	} );

	it( 'a choice with a link action opens it in a new tab', async () => {
		const open = vi.spyOn( window, 'open' ).mockReturnValue( null );
		await startUrgent();

		$$( '.dc-reply' )[ 2 ].click();

		expect( open ).toHaveBeenCalledWith(
			expect.stringContaining(
				'https://example.com/book?x=1&utm_source=devrivo-chatbot'
			),
			'_blank',
			'noopener'
		);
	} );

	it( 'shows a typing indicator before bot messages', async () => {
		vi.useFakeTimers();
		widget = render( host, normalizeConfig( RAW, boot ), {
			typingTime: () => 500,
		} );
		$( '.dc-row[data-action="urgent"]' ).click();
		await vi.advanceTimersByTimeAsync( 0 );

		expect( $( '.dc-typing' ) ).not.toBeNull();
		expect( $( '.dc-typing' ).getAttribute( 'aria-hidden' ) ).toBe(
			'true'
		);
		expect( bubbles( 'bot' ) ).toEqual( [] );

		await vi.advanceTimersByTimeAsync( 500 );
		expect( $( '.dc-typing' ) ).toBeNull();
		expect( bubbles( 'bot' ) ).toEqual( [ 'What is happening?' ] );
	} );

	it( 'shows no typing indicator when reduced motion is preferred', async () => {
		window.matchMedia = ( query ) => ( {
			matches: query === '(prefers-reduced-motion: reduce)',
		} );
		widget = render( host, normalizeConfig( RAW, boot ) );

		$( '.dc-row[data-action="urgent"]' ).click();

		expect( $( '.dc-typing' ) ).toBeNull();
		await flush();
		expect( bubbles( 'bot' ) ).toEqual( [ 'What is happening?' ] );
	} );

	it( 'renders flow text and labels as text', async () => {
		const flows = structuredClone( RAW.flows );
		flows.urgent.nodes.triage.text = XSS;
		flows.urgent.nodes.triage.choices[ 0 ].label = XSS;

		await startUrgent( { flows } );

		expect( host.shadowRoot.querySelector( 'img' ) ).toBeNull();
		expect( bubbles( 'bot' ) ).toEqual( [ XSS ] );
		expect( $( '.dc-reply' ).textContent ).toBe( XSS );
	} );

	it( 'starting another flow from the menu adds a separator', async () => {
		await startUrgent();
		$( '.dc-chat-head .dc-icon-btn' ).click(); // Back to Home.
		$( '.dc-row[data-action="urgent"]' ).click();
		await flush();

		expect( $$( '.dc-sep' ) ).toHaveLength( 1 );
		expect( bubbles( 'bot' ) ).toHaveLength( 2 );
		expect( $$( '.dc-replies' ) ).toHaveLength( 1 );
	} );
} );

describe( 'overlay and status', () => {
	it( 'the auto-opened popover (peek) has no overlay; a visitor-opened one does', () => {
		vi.useFakeTimers();
		widget = render(
			host,
			normalizeConfig(
				{
					...RAW,
					behaviour: {
						...RAW.behaviour,
						auto_open: true,
						auto_open_delay: 10,
					},
				},
				boot
			)
		);
		const overlay = $( '.dc-overlay' );

		vi.advanceTimersByTime( 10 );
		expect( widget.state() ).toBe( 'peek' );
		expect( overlay.hidden ).toBe( true );

		$( '.dc-pop-close' ).click();
		$( '.dc-launcher' ).click();
		expect( widget.state() ).toBe( 'pop' );
		expect( overlay.hidden ).toBe( false );

		$( '.dc-launcher' ).click();
		expect( widget.state() ).toBe( 'panel' );
		expect( overlay.hidden ).toBe( true );
	} );

	it( 'shows the open/closed status only when enabled', () => {
		widget = render( host, normalizeConfig( RAW, boot ) );
		expect( $( '.dc-dot' ).hidden ).toBe( true );
		expect( $( '.dc-status' ).hidden ).toBe( true );
		widget.destroy();

		widget = render(
			host,
			normalizeConfig(
				{
					...RAW,
					behaviour: {
						...RAW.behaviour,
						hours: {
							timezone: 'UTC',
							schedule: ALWAYS_OPEN,
							show_status: true,
						},
					},
				},
				boot
			)
		);
		expect( $( '.dc-launcher .dc-dot' ).hidden ).toBe( false );
		expect( $( '.dc-dot' ).classList.contains( 'is-closed' ) ).toBe(
			false
		);
		expect( $( '.dc-status' ).textContent ).toBe( 'We are open now' );
	} );
} );
