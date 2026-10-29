/**
 * Widget rendering and behaviour tests (jsdom, Shadow DOM).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ACTION_EVENT, mount, render } from '../../assets-src/widget/widget';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import { parseBoot } from '../../assets-src/widget/lib/boot';

const XSS = '<img src=x onerror=alert(1)>';

const boot = parseBoot( {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config?v=1',
	widgetUrl: 'https://example.com/build/widget.js',
	position: 'right',
	primaryColor: '#1e3b36',
	launcherLabel: 'How can we help?',
	devices: [ 'desktop', 'mobile' ],
} );

const RAW = {
	version: 1,
	chat_enabled: false,
	display: {
		position: 'right',
		primary_color: '#1e3b36',
		launcher_label: 'Ask us',
	},
	appearance: {
		color_scheme: 'auto',
		radius: 22,
		brand_name: 'Acme',
		font: 'system',
		avatar_url: '',
	},
	behaviour: {
		greeting: 'Hello',
		welcome: 'How can we help?',
		auto_open: false,
		auto_open_delay: 800,
	},
	actions: [
		{
			id: 'book',
			label: 'Book a visit',
			type: 'link',
			urgent: false,
			config: { url: 'https://example.com/book', new_tab: true },
		},
		{
			id: 'urgent',
			label: 'Urgent help',
			type: 'flow',
			urgent: true,
			config: { flow: 'urgent' },
		},
	],
	flows: {
		urgent: {
			title: 'Urgent',
			start: 'hi',
			nodes: { hi: { type: 'end', text: 'Help is here' } },
		},
	},
	offers: [],
	i18n: {
		assistant: 'Assistant',
		close: 'Close',
		close_menu: 'Close menu',
		back: 'Back',
		home: 'Home',
		offers: 'Offers',
		menu: 'Quick actions',
		ask: 'Ask a question',
		ask_hint: 'Get an answer',
		send: 'Send',
		message_label: 'Your message',
		placeholder: 'Type…',
		welcome: 'How can we help you today?',
		offer_cta: 'Find out more',
		good_morning: 'Good morning',
		good_afternoon: 'Good afternoon',
		good_evening: 'Good evening',
	},
};

let host;
let widget;

/**
 * Renders a widget from a raw config (with overrides).
 *
 * @param {Object} overrides Raw config overrides.
 * @param {Object} options   Render options.
 * @return {ShadowRoot} Shadow root.
 */
function setup( overrides = {}, options = {} ) {
	widget = render(
		host,
		normalizeConfig( { ...RAW, ...overrides }, boot ),
		options
	);
	return host.shadowRoot;
}

const $ = ( selector ) => host.shadowRoot.querySelector( selector );
const press = ( key ) =>
	document.dispatchEvent(
		new window.KeyboardEvent( 'keydown', { key, bubbles: true } )
	);

beforeEach( () => {
	window.sessionStorage.clear();
	host = document.createElement( 'div' );
	host.attachShadow( { mode: 'open' } );
	document.body.append( host );
} );

afterEach( () => {
	if ( widget ) {
		widget.destroy();
		widget = null;
	}
	host.remove();
	vi.useRealTimers();
	vi.restoreAllMocks();
} );

describe( 'rendering', () => {
	it( 'renders config text as text, never as HTML', () => {
		const shadow = setup( {
			display: { ...RAW.display, launcher_label: XSS },
			appearance: { ...RAW.appearance, brand_name: XSS },
			behaviour: { ...RAW.behaviour, greeting: XSS, welcome: XSS },
			actions: [ { id: 'x', label: XSS, type: 'link', config: {} } ],
			offers: [
				{ id: 'o', title: XSS, text: XSS, cta_label: XSS, action: 'x' },
			],
		} );

		expect( shadow.querySelector( 'img' ) ).toBeNull();
		expect( $( '.dc-launcher-label' ).textContent ).toBe( XSS );
		expect( $( '.dc-chip' ).textContent ).toBe( XSS );
		expect( $( '.dc-row span' ).textContent ).toBe( XSS );
		expect( $( '.dc-brand' ).textContent ).toBe( XSS );
		expect( $( '.dc-offer h3' ).textContent ).toBe( XSS );
		expect( $( '.dc-panel' ).getAttribute( 'aria-label' ) ).toBe( XSS );
	} );

	it( 'shows the quick actions in the popover and the Home list', () => {
		setup();

		expect(
			[ ...host.shadowRoot.querySelectorAll( '.dc-chip' ) ].map(
				( c ) => c.textContent
			)
		).toEqual( [ 'Book a visit', 'Urgent help' ] );
		expect( host.shadowRoot.querySelectorAll( '.dc-row' ) ).toHaveLength(
			2
		);
		expect( $( '.dc-row.dc-urgent' ).textContent ).toBe( 'Urgent help' );
		expect( $( '.dc-greet' ).textContent ).toBe( 'Hello' );
	} );

	it( 'hides the Offers tab without offers and shows it with offers', () => {
		setup();
		expect( $( '.dc-tabs' ) ).toBeNull();
		widget.destroy();

		setup( {
			offers: [
				{
					id: 'o',
					title: 'Deal',
					text: 'Nice',
					cta_label: '',
					action: 'book',
					featured: true,
				},
			],
		} );
		expect( $( '.dc-tabs' ) ).not.toBeNull();
		expect( $( '.dc-offer.is-featured .dc-cta' ).textContent ).toBe(
			'Find out more'
		);
	} );

	it( 'hides "Ask a question" and the composer while chat is disabled', () => {
		setup();
		expect( $( '.dc-ask' ) ).toBeNull();
		expect( $( '.dc-composer' ) ).toBeNull();
		expect( $( '[data-view="chat"] .dc-msgs' ) ).not.toBeNull();
		widget.destroy();

		setup( { chat_enabled: true } );
		expect( $( '.dc-ask' ) ).not.toBeNull();
		expect( $( '[data-view="chat"] .dc-composer' ) ).not.toBeNull();
	} );

	it( 'uses a time-based greeting when none is set', () => {
		setup( { behaviour: { ...RAW.behaviour, greeting: '' } } );

		expect( [
			'Good morning',
			'Good afternoon',
			'Good evening',
		] ).toContain( $( '.dc-greet' ).textContent );
	} );

	it( 'applies the theme from config', () => {
		setup( {
			display: {
				...RAW.display,
				position: 'left',
				primary_color: '#ffeb3b',
			},
			appearance: {
				...RAW.appearance,
				color_scheme: 'dark',
				radius: 8,
				font: 'inherit',
			},
		} );
		const root = $( '.dc' );

		expect( root.dataset.position ).toBe( 'left' );
		expect( root.dataset.scheme ).toBe( 'dark' );
		expect( root.dataset.font ).toBe( 'inherit' );
		expect( root.style.getPropertyValue( '--dc-radius' ) ).toBe( '8px' );
		expect( root.style.getPropertyValue( '--dc-on-primary' ) ).toBe(
			'#17201e'
		);
	} );
} );

describe( 'behaviour and keyboard', () => {
	it( 'opens the popover, then the panel, and Esc returns focus to the launcher', () => {
		setup();
		const launcher = $( '.dc-launcher' );

		launcher.focus();
		launcher.click();
		expect( widget.state() ).toBe( 'pop' );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( host.shadowRoot.activeElement ).toBe( $( '.dc-chip' ) );

		launcher.click();
		expect( widget.state() ).toBe( 'panel' );
		expect( $( '.dc-panel' ).getAttribute( 'role' ) ).toBe( 'dialog' );
		expect(
			$( '.dc-panel' ).contains( host.shadowRoot.activeElement )
		).toBe( true );

		press( 'Escape' );
		expect( widget.state() ).toBe( 'closed' );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( host.shadowRoot.activeElement ).toBe( launcher );
	} );

	it( 'moves through the popover with arrow keys', () => {
		setup( {}, { open: true } );
		const chips = host.shadowRoot.querySelectorAll( '.dc-chip' );

		expect( host.shadowRoot.activeElement ).toBe( chips[ 0 ] );
		chips[ 0 ].dispatchEvent(
			new window.KeyboardEvent( 'keydown', {
				key: 'ArrowDown',
				bubbles: true,
			} )
		);
		expect( host.shadowRoot.activeElement ).toBe( chips[ 1 ] );
	} );

	it( 'dispatches the action event and closes the popover for links', () => {
		setup( {}, { open: true } );
		const listener = vi.fn();
		host.addEventListener( ACTION_EVENT, listener );
		const chip = $( '.dc-chip[data-action="book"]' );
		chip.addEventListener( 'click', ( e ) => e.preventDefault() );

		chip.click();

		expect( listener ).toHaveBeenCalledTimes( 1 );
		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			id: 'book',
			type: 'link',
		} );
		expect( widget.state() ).toBe( 'closed' );
	} );

	it( 'opens the chat panel for flow actions', () => {
		setup( {}, { open: true, typingTime: () => 0 } );
		const listener = vi.fn();
		host.addEventListener( ACTION_EVENT, listener );

		$( '.dc-chip[data-action="urgent"]' ).click();

		expect( listener.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			id: 'urgent',
			type: 'flow',
		} );
		expect( widget.state() ).toBe( 'panel' );
		expect( widget.view() ).toBe( 'chat' );
	} );

	it( 'peeks after the delay, and not again once dismissed this visit', () => {
		vi.useFakeTimers();
		setup( {
			behaviour: {
				...RAW.behaviour,
				auto_open: true,
				auto_open_delay: 500,
			},
		} );

		vi.advanceTimersByTime( 499 );
		expect( widget.state() ).toBe( 'closed' );
		vi.advanceTimersByTime( 1 );
		expect( widget.state() ).toBe( 'peek' );
		expect( host.shadowRoot.activeElement ).toBeNull();

		$( '.dc-pop-close' ).click();
		expect( widget.state() ).toBe( 'closed' );
		expect(
			window.sessionStorage.getItem( 'devrivoChatbotDismissed' )
		).toBe( '1' );
		widget.destroy();

		setup( {
			behaviour: {
				...RAW.behaviour,
				auto_open: true,
				auto_open_delay: 0,
			},
		} );
		vi.advanceTimersByTime( 1000 );
		expect( widget.state() ).toBe( 'closed' );
	} );

	it( 'switches between Home and Offers tabs', () => {
		setup( { offers: [ { id: 'o', title: 'Deal', action: '' } ] } );
		$( '.dc-launcher' ).click();
		$( '.dc-launcher' ).click();

		$( '[data-tab="offers"]' ).click();
		expect( widget.view() ).toBe( 'offers' );
		expect(
			$( '[data-tab="offers"]' ).getAttribute( 'aria-selected' )
		).toBe( 'true' );
		expect(
			$( '[data-view="offers"]' ).classList.contains( 'is-active' )
		).toBe( true );
		expect( $( '.dc-offer .dc-cta' ) ).toBeNull();
	} );
} );

describe( 'mount', () => {
	it( 'fetches the config without credentials and renders', async () => {
		const fetch = vi.spyOn( window, 'fetch' ).mockResolvedValue( {
			ok: true,
			json: async () => RAW,
		} );

		widget = await mount( host, boot, { open: false } );

		expect( fetch ).toHaveBeenCalledWith(
			boot.configUrl,
			expect.objectContaining( { credentials: 'omit' } )
		);
		expect( $( '.dc-launcher-label' ).textContent ).toBe( 'Ask us' );
	} );

	it( 'keeps the loader launcher and stays silent when the config fails', async () => {
		host.shadowRoot.innerHTML =
			'<button class="loader-launcher">Help</button>';
		const error = vi.spyOn( console, 'error' );
		vi.spyOn( window, 'fetch' ).mockResolvedValue( {
			ok: false,
			status: 500,
		} );

		expect( await mount( host, boot ) ).toBeNull();
		expect( $( '.loader-launcher' ) ).not.toBeNull();
		expect( $( '.dc-panel' ) ).toBeNull();
		expect( error ).not.toHaveBeenCalled();
	} );

	it( 'opens the popover when the launcher was clicked while loading', async () => {
		vi.spyOn( window, 'fetch' ).mockResolvedValue( {
			ok: true,
			json: async () => RAW,
		} );
		const options = { open: false };

		const pending = mount( host, boot, options );
		options.open = true;
		widget = await pending;

		expect( widget.state() ).toBe( 'pop' );
	} );
} );
