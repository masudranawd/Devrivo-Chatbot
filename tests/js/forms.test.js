/**
 * Form validation, submitter (token + retry) and the inline form view.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	fieldError,
	formErrors,
	isDate,
} from '../../assets-src/widget/lib/forms';
import {
	createSubmitter,
	MIN_AGE_MS,
} from '../../assets-src/widget/lib/submit';
import { formView } from '../../assets-src/widget/views/form';

const XSS = '<img src=x onerror=alert(1)>';

const field = ( type, extra = {} ) => ( {
	id: type,
	type,
	label: type,
	required: false,
	placeholder: '',
	maxLength: 200,
	options: [],
	...extra,
} );

const FORM = {
	id: 'contact',
	title: 'Contact us',
	submitLabel: 'Send it',
	successMessage: 'Thanks!',
	fields: [
		field( 'text', {
			id: 'name',
			label: 'Name',
			required: true,
			maxLength: 5,
		} ),
		field( 'email', { required: true } ),
		field( 'tel' ),
		field( 'date' ),
		field( 'select', { options: [ 'Morning', 'Evening' ] } ),
		field( 'textarea' ),
	],
};

const I18N = {
	required: 'required',
	submit: 'Send',
	sending: 'Sending…',
	sent: 'Sent',
	form_success: 'Default thanks',
	field_required: 'Required!',
	invalid_email: 'Bad email',
	invalid_phone: 'Bad phone',
	invalid_date: 'Bad date',
	invalid_option: 'Bad option',
	too_long: 'Too long',
	consent_needed: 'Tick it',
	form_error: 'Oops',
	network_error: 'Offline',
	rate_limited: 'Slow down',
	new_tab: '(new tab)',
	choose: 'Choose…',
};

const CONSENT = {
	text: 'I agree.',
	policyUrl: 'https://example.com/privacy',
	policyLabel: 'Privacy policy',
};

describe( 'client validation', () => {
	it( 'matches the server rules per field type', () => {
		expect( fieldError( field( 'text', { required: true } ), '  ' ) ).toBe(
			'field_required'
		);
		expect( fieldError( field( 'text' ), '' ) ).toBe( '' );
		expect( fieldError( field( 'text', { maxLength: 3 } ), 'abcd' ) ).toBe(
			'too_long'
		);
		expect( fieldError( field( 'text', { maxLength: 3 } ), 'äöü' ) ).toBe(
			'',
			'Counts characters, not bytes.'
		);
		expect( fieldError( field( 'email' ), 'ann@example.com' ) ).toBe( '' );
		expect( fieldError( field( 'email' ), 'ann@' ) ).toBe(
			'invalid_email'
		);
		expect( fieldError( field( 'tel' ), '+44 (0)20 7946-0000' ) ).toBe(
			''
		);
		expect( fieldError( field( 'tel' ), 'call me' ) ).toBe(
			'invalid_phone'
		);
		expect( fieldError( field( 'date' ), '2026-02-28' ) ).toBe( '' );
		expect( fieldError( field( 'date' ), '2026-02-30' ) ).toBe(
			'invalid_date'
		);
		expect(
			fieldError( field( 'select', { options: [ 'A' ] } ), 'B' )
		).toBe( 'invalid_option' );
		expect( isDate( '2024-02-29' ) ).toBe( true );
		expect( isDate( '2025-02-29' ) ).toBe( false );
	} );

	it( 'requires consent', () => {
		expect(
			formErrors( FORM, { name: 'Ann', email: 'a@b.co' }, false )
		).toEqual( { consent: 'consent_needed' } );
		expect(
			formErrors( FORM, { name: 'Ann', email: 'a@b.co' }, true )
		).toEqual( {} );
	} );
} );

describe( 'submitter', () => {
	/**
	 * Fake fetch: answers the session URL with tokens and the submit URL with queued responses.
	 *
	 * @param {Array<Object>} responses Submit responses { status, body }.
	 */
	const fakeFetch = ( responses ) => {
		let tokens = 0;
		return vi.fn( async ( url ) => {
			if ( url.includes( '/session' ) ) {
				tokens++;
				return {
					ok: true,
					json: async () => ( { token: `t${ tokens }` } ),
				};
			}
			const next = responses.shift();
			return {
				ok: next.status < 300,
				status: next.status,
				json: async () => next.body,
			};
		} );
	};

	const setup = ( responses ) => {
		let clock = 0;
		const fetch = fakeFetch( responses );
		const wait = vi.fn( async ( ms ) => {
			clock += ms;
		} );
		const submitter = createSubmitter( {
			sessionUrl:
				'https://example.com/wp-json/devrivo-chatbot/v1/session',
			submitUrl:
				'https://example.com/?rest_route=/devrivo-chatbot/v1/forms/%7Bform%7D/submit',
			fetch,
			wait,
			now: () => clock,
		} );
		return { submitter, fetch, wait };
	};

	it( 'waits for the minimum fill time and posts without cookies', async () => {
		const { submitter, fetch, wait } = setup( [
			{ status: 200, body: { success: true, message: 'ok' } },
		] );

		await submitter.prepare();
		const result = await submitter.submit( 'contact', { fields: {} } );

		expect( result.ok ).toBe( true );
		expect( wait ).toHaveBeenCalledWith( MIN_AGE_MS );
		const [ url, init ] = fetch.mock.calls[ 1 ];
		expect( url ).toBe(
			'https://example.com/?rest_route=/devrivo-chatbot/v1/forms/contact/submit'
		);
		expect( init.credentials ).toBe( 'omit' );
		expect( JSON.parse( init.body ) ).toEqual( {
			fields: {},
			token: 't1',
		} );
		expect( fetch.mock.calls[ 0 ][ 1 ].credentials ).toBe( 'omit' );
	} );

	it( 'gets a new token and retries once when the token is invalid', async () => {
		const { submitter, fetch } = setup( [
			{ status: 403, body: { code: 'devrivo_chatbot_invalid_token' } },
			{ status: 403, body: { code: 'devrivo_chatbot_invalid_token' } },
		] );

		const result = await submitter.submit( 'contact', {} );

		expect( result.status ).toBe( 403 );
		const tokens = fetch.mock.calls
			.filter( ( [ url ] ) => ! url.includes( '/session' ) )
			.map( ( [ , init ] ) => JSON.parse( init.body ).token );
		expect( tokens ).toEqual( [ 't1', 't2' ] );
	} );

	it( 'retries once with the same token when it was too fast', async () => {
		const { submitter, fetch } = setup( [
			{ status: 403, body: { code: 'devrivo_chatbot_too_fast' } },
			{ status: 200, body: { success: true } },
		] );

		expect( ( await submitter.submit( 'contact', {} ) ).ok ).toBe( true );
		expect(
			fetch.mock.calls.filter( ( [ url ] ) => url.includes( '/session' ) )
		).toHaveLength( 1 );
	} );
} );

describe( 'form view', () => {
	let send;
	let onSuccess;
	let element;

	const $ = ( selector ) => element.querySelector( selector );
	const fill = ( values ) => {
		for ( const [ name, value ] of Object.entries( values ) ) {
			element.querySelector( `[name="${ name }"]` ).value = value;
		}
	};
	const submit = async () => {
		element.dispatchEvent(
			new window.Event( 'submit', { cancelable: true } )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	};

	beforeEach( () => {
		send = vi.fn();
		onSuccess = vi.fn();
		element = formView( FORM, {
			consent: CONSENT,
			i18n: I18N,
			send,
			onSuccess,
		} );
		document.body.append( element );
	} );

	afterEach( () => {
		element.remove();
		vi.restoreAllMocks();
	} );

	it( 'renders labels, required markers and controls', () => {
		expect( $( 'h3' ).textContent ).toBe( 'Contact us' );
		const name = $( '[name="name"]' );
		const label = element.querySelector( `label[for="${ name.id }"]` );
		expect( label.textContent ).toBe( 'Name * (required)' );
		expect( name.getAttribute( 'aria-required' ) ).toBe( 'true' );
		expect( name.getAttribute( 'maxlength' ) ).toBe( '5' );
		expect( $( '[name="email"]' ).type ).toBe( 'email' );
		expect( $( '[name="date"]' ).type ).toBe( 'date' );
		expect( $( '[name="textarea"]' ).tagName ).toBe( 'TEXTAREA' );
		expect(
			[ ...$( '[name="select"]' ).options ].map( ( o ) => o.value )
		).toEqual( [ '', 'Morning', 'Evening' ] );
		expect( $( '.dc-submit' ).textContent ).toBe( 'Send it' );
	} );

	it( 'hides the honeypot from people and assistive tech', () => {
		const trap = $( '.dc-hp' );
		const input = $( '[name="website"]' );

		expect( trap.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( input.getAttribute( 'tabindex' ) ).toBe( '-1' );
		expect( input.getAttribute( 'autocomplete' ) ).toBe( 'off' );
	} );

	it( 'builds a safe policy link and renders config text as text', () => {
		const link = $( '.dc-consent a' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'https://example.com/privacy'
		);
		expect( link.getAttribute( 'rel' ) ).toBe( 'noopener noreferrer' );
		element.remove();

		element = formView(
			{
				...FORM,
				title: XSS,
				fields: [ field( 'text', { label: XSS } ) ],
			},
			{
				consent: {
					...CONSENT,
					text: XSS,
					policyUrl: 'javascript:alert(1)',
				},
				i18n: I18N,
				send,
				onSuccess,
			}
		);
		expect( element.querySelector( 'img' ) ).toBeNull();
		expect( $( 'h3' ).textContent ).toBe( XSS );
		expect( $( '.dc-consent a' ) ).toBeNull();
		expect( $( '.dc-consent label' ).textContent ).toBe( XSS );
	} );

	it( 'shows client errors, links them and focuses the first one', async () => {
		fill( { email: 'bad' } );

		await submit();

		expect( send ).not.toHaveBeenCalled();
		const name = $( '[name="name"]' );
		const error = element.querySelector(
			`#${ name.getAttribute( 'aria-describedby' ) }`
		);
		expect( error.textContent ).toBe( 'Required!' );
		expect( name.hasAttribute( 'aria-invalid' ) ).toBe( true );
		expect( document.activeElement ).toBe( name );
		expect(
			element.querySelector(
				`#${ $( '[name="email"]' ).getAttribute( 'aria-describedby' ) }`
			).textContent
		).toBe( 'Bad email' );
		expect(
			element.querySelector(
				`#${ $( '[name="consent"]' ).getAttribute( 'aria-describedby' ) }`
			).textContent
		).toBe( 'Tick it' );
	} );

	it( 'sends the payload and shows success', async () => {
		send.mockResolvedValue( {
			ok: true,
			status: 200,
			data: { success: true, message: 'Thanks!' },
		} );
		fill( { name: 'Ann', email: 'ann@example.com', select: 'Evening' } );
		$( '[name="consent"]' ).checked = true;

		await submit();

		expect( send ).toHaveBeenCalledWith( {
			fields: {
				name: 'Ann',
				email: 'ann@example.com',
				tel: '',
				date: '',
				select: 'Evening',
				textarea: '',
			},
			consent: true,
			website: '',
			page_url: window.location.href,
		} );
		expect( onSuccess ).toHaveBeenCalledWith( 'Thanks!' );
		expect( $( '.dc-submit' ).textContent ).toBe( 'Sent' );
		expect( [ ...element.elements ].every( ( c ) => c.disabled ) ).toBe(
			true
		);
	} );

	it( 'shows server field errors', async () => {
		send.mockResolvedValue( {
			ok: false,
			status: 400,
			data: {
				code: 'rest_invalid_param',
				data: { params: { email: 'Server says no' } },
			},
		} );
		fill( { name: 'Ann', email: 'ann@example.com' } );
		$( '[name="consent"]' ).checked = true;

		await submit();

		const email = $( '[name="email"]' );
		expect(
			element.querySelector(
				`#${ email.getAttribute( 'aria-describedby' ) }`
			).textContent
		).toBe( 'Server says no' );
		expect( document.activeElement ).toBe( email );
		expect( $( '.dc-submit' ).disabled ).toBe( false );
	} );

	it( 'shows friendly rate limit and network messages', async () => {
		fill( { name: 'Ann', email: 'ann@example.com' } );
		$( '[name="consent"]' ).checked = true;

		send.mockResolvedValue( { ok: false, status: 429, data: {} } );
		await submit();
		expect( $( '.dc-form-status' ).textContent ).toBe( 'Slow down' );
		expect( $( '.dc-form-status' ).getAttribute( 'role' ) ).toBe( 'alert' );

		send.mockRejectedValue( new TypeError( 'Failed to fetch' ) );
		await submit();
		expect( $( '.dc-form-status' ).textContent ).toBe( 'Offline' );
		expect( $( '.dc-submit' ).disabled ).toBe( false );
	} );

	it( 'never stores form data in the browser', async () => {
		const local = vi.spyOn( window.Storage.prototype, 'setItem' );
		send.mockResolvedValue( {
			ok: true,
			status: 200,
			data: { success: true },
		} );
		fill( { name: 'Ann', email: 'ann@example.com' } );
		$( '[name="consent"]' ).checked = true;

		await submit();

		expect( local ).not.toHaveBeenCalled();
		expect( onSuccess ).toHaveBeenCalledWith( 'Thanks!' );
	} );
} );
