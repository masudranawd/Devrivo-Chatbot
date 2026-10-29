/**
 * Forms inside the widget: opened from the menu and from flows.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render } from '../../assets-src/widget/widget';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import { parseBoot } from '../../assets-src/widget/lib/boot';

const boot = parseBoot( {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config',
	widgetUrl: 'https://example.com/build/widget.js',
	devices: [ 'desktop', 'mobile' ],
} );

const RAW = {
	behaviour: { auto_open: false },
	actions: [
		{
			id: 'contact',
			label: 'Contact us',
			type: 'form',
			config: { form: 'contact' },
		},
		{ id: 'help', label: 'Help', type: 'flow', config: { flow: 'help' } },
		{
			id: 'ghost',
			label: 'Ghost form',
			type: 'form',
			config: { form: 'missing' },
		},
	],
	flows: {
		help: {
			start: 'ask',
			nodes: {
				ask: {
					type: 'choices',
					text: 'How can we help?',
					choices: [ { label: 'Call me back', action: 'contact' } ],
				},
			},
		},
	},
	forms: {
		contact: {
			title: 'Contact us',
			success_message: 'Thanks, we will call you.',
			fields: [
				{
					id: 'name',
					type: 'text',
					label: 'Name',
					required: true,
					max_length: 50,
				},
			],
		},
	},
	consent: { text: 'I agree.', policy_url: '', policy_label: 'Privacy' },
	session_url: 'https://example.com/session',
	submit_url: 'https://example.com/forms/{form}/submit',
	i18n: { back_to_menu: 'Back to menu', sent: 'Sent', submit: 'Send' },
};

let host;
let widget;
let submitter;

const $ = ( selector ) => host.shadowRoot.querySelector( selector );
const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

beforeEach( () => {
	host = document.createElement( 'div' );
	host.attachShadow( { mode: 'open' } );
	document.body.append( host );
	submitter = {
		prepare: vi.fn( async () => {} ),
		submit: vi.fn( async () => ( {
			ok: true,
			status: 200,
			data: { success: true, message: 'Thanks, we will call you.' },
		} ) ),
	};
	widget = render( host, normalizeConfig( RAW, boot ), {
		typingTime: () => 0,
		submitter,
	} );
} );

afterEach( () => {
	widget.destroy();
	host.remove();
} );

describe( 'forms in the widget', () => {
	it( 'leaves out form actions whose form is missing', () => {
		expect( $( '[data-action="ghost"]' ) ).toBeNull();
	} );

	it( 'opens the form from the menu and fetches a token straight away', async () => {
		$( '.dc-row[data-action="contact"]' ).click();

		expect( widget.view() ).toBe( 'chat' );
		expect( $( '.dc-msgs .dc-form[data-form="contact"]' ) ).not.toBeNull();
		expect( submitter.prepare ).toHaveBeenCalledTimes( 1 );
		expect( host.shadowRoot.activeElement ).toBe( $( '[name="name"]' ) );
	} );

	it( 'shows the success message and "Back to menu" after sending', async () => {
		$( '.dc-row[data-action="contact"]' ).click();
		$( '[name="name"]' ).value = 'Ann';
		$( '[name="consent"]' ).checked = true;

		$( '.dc-form' ).dispatchEvent(
			new window.Event( 'submit', { cancelable: true } )
		);
		await flush();
		await flush();

		expect( submitter.submit ).toHaveBeenCalledWith(
			'contact',
			expect.objectContaining( {
				consent: true,
				fields: { name: 'Ann' },
			} )
		);
		expect( $( '.dc-form' ).classList.contains( 'is-done' ) ).toBe( true );
		expect(
			[ ...host.shadowRoot.querySelectorAll( '.dc-msg.dc-bot' ) ].at( -1 )
				.textContent
		).toBe( 'Thanks, we will call you.' );
		expect( $( '.dc-reply' ).textContent ).toBe( 'Back to menu' );
	} );

	it( 'a flow choice can open a form without losing focus', async () => {
		$( '.dc-row[data-action="help"]' ).click();
		await flush();

		$( '.dc-reply' ).click();
		await flush();

		expect( $( '.dc-msgs .dc-form' ) ).not.toBeNull();
		expect( $( '.dc-reply' ).textContent ).toBe( 'Back to menu' );
		expect( host.shadowRoot.activeElement ).toBe( $( '[name="name"]' ) );
	} );
} );
