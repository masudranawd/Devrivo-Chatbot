/**
 * Loader tests: launcher in Shadow DOM, device check, lazy widget loading.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const BOOT = {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config?v=1',
	widgetUrl: 'https://example.com/build/widget.js?ver=1',
	position: 'left',
	primaryColor: '#1e3b36',
	launcherLabel: '<b>Help</b>',
	devices: [ 'desktop' ],
};

/**
 * Runs the loader (an IIFE) with fresh module state.
 */
async function runLoader() {
	vi.resetModules();
	await import( '../../assets-src/widget/loader.js' );
}

const host = () => document.getElementById( 'devrivo-chatbot' );

beforeEach( () => {
	window.devrivoChatbotBoot = { ...BOOT };
	window.requestIdleCallback = vi.fn();
} );

afterEach( () => {
	document.body.innerHTML = '';
	document.head.innerHTML = '';
	delete window.devrivoChatbotBoot;
	delete window.requestIdleCallback;
	delete window.matchMedia;
} );

describe( 'loader', () => {
	it( 'does nothing without boot data', async () => {
		delete window.devrivoChatbotBoot;
		await runLoader();

		expect( host() ).toBeNull();
	} );

	it( 'renders only the launcher, as text, in an open shadow root', async () => {
		await runLoader();

		const launcher = host().shadowRoot.querySelector( '.dc-launcher' );
		expect( launcher.textContent ).toBe( '<b>Help</b>' );
		expect( launcher.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect(
			host().shadowRoot.querySelector( '.dc' ).dataset.position
		).toBe( 'left' );
		expect( host().shadowRoot.querySelector( 'b' ) ).toBeNull();
		expect( document.querySelector( 'script' ) ).toBeNull();
	} );

	it( 'calls matchMedia on window and skips devices that are not allowed', async () => {
		window.matchMedia = function ( query ) {
			if ( this !== window ) {
				throw new TypeError( 'Illegal invocation' );
			}
			return { matches: query === '(max-width: 768px)' };
		};

		await runLoader();

		expect( host() ).toBeNull();
	} );

	it( 'loads the widget once on interaction and asks it to open after a click', async () => {
		await runLoader();
		const launcher = host().shadowRoot.querySelector( '.dc-launcher' );

		launcher.dispatchEvent( new window.Event( 'pointerenter' ) );
		launcher.click();

		const scripts = document.querySelectorAll( 'script' );
		expect( scripts ).toHaveLength( 1 );
		expect( scripts[ 0 ].src ).toBe( BOOT.widgetUrl );

		const mount = vi.fn();
		window.devrivoChatbotWidget = { mount };
		scripts[ 0 ].dispatchEvent( new window.Event( 'load' ) );

		expect( mount ).toHaveBeenCalledWith(
			host(),
			expect.objectContaining( { widgetUrl: BOOT.widgetUrl } ),
			{ open: true }
		);
		delete window.devrivoChatbotWidget;
	} );

	it( 'loads the widget when the browser is idle', async () => {
		await runLoader();

		expect( window.requestIdleCallback ).toHaveBeenCalledWith(
			expect.any( Function ),
			{ timeout: 3000 }
		);
		window.requestIdleCallback.mock.calls[ 0 ][ 0 ]();
		expect( document.querySelectorAll( 'script' ) ).toHaveLength( 1 );
	} );
} );
