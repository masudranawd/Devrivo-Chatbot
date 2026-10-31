/**
 * Admin app: API error mapping, useSettings, hours and quick action logic,
 * Inbox rendering lead data as text, and the widget preview mode.
 */
import {
	act,
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

import apiFetch from '@wordpress/api-fetch';
import { fieldPath, toErrors } from '../../assets-src/admin/lib/api';
import {
	addAction,
	missing,
	move,
	removeAction,
	uniqueId,
	updateAction,
} from '../../assets-src/admin/lib/actions';
import {
	MAX_RANGES,
	addRange,
	copyToWeekdays,
	normalizeSchedule,
	rangeErrors,
	removeRange,
	updateRange,
} from '../../assets-src/admin/lib/hours';
import {
	resetSettingsCache,
	useSettings,
} from '../../assets-src/admin/lib/use-settings';
import Display, {
	tokenId,
	tokenLabel,
} from '../../assets-src/admin/screens/display';
import Inbox, {
	LeadDetail,
	safeLink,
} from '../../assets-src/admin/screens/inbox';
import { preview } from '../../assets-src/widget/widget';

const SETTINGS = {
	display: { enabled: false, position: 'right' },
	appearance: { brand_name: 'Acme', radius: 22 },
	behaviour: { greeting: '' },
	forms: { contact: { title: 'Contact us' } },
};

const XSS = '<img src=x onerror="window.__xss=1">';

beforeEach( () => {
	resetSettingsCache();
	apiFetch.mockReset();
	window.__xss = undefined;
} );

afterEach( () => {
	cleanup();
} );

describe( 'api', () => {
	it( 'maps 400 params to field paths without the section', () => {
		const result = toErrors(
			{
				code: 'rest_invalid_param',
				message: 'Invalid.',
				data: {
					status: 400,
					params: {
						'behaviour[hours][timezone]': 'Bad timezone.',
						'actions[2][config]': 'Bad config.',
						appearance: 'appearance[radius] is too big.',
						'ai[model]': 'Choose a model.',
					},
				},
			},
			[ 'behaviour', 'appearance', 'ai' ]
		);

		expect( result.message ).toBe( 'Invalid.' );
		expect( result.fields ).toEqual( {
			'hours.timezone': 'Bad timezone.',
			'actions.2.config': 'Bad config.',
			appearance: 'appearance[radius] is too big.',
			model: 'Choose a model.',
		} );
		expect(
			fieldPath( 'forms[contact][fields][0][id]', [ 'forms' ] )
		).toBe( 'contact.fields.0.id' );
		expect( toErrors( null ).message ).toBe(
			'Something went wrong. Please try again.'
		);
		expect(
			toErrors( { message: 'X', data: { params: { a: 5 } } } ).fields
		).toEqual( {} );
	} );
} );

/**
 * Test component exposing the hook.
 *
 * @param {Object}                 props
 * @param {(hook: Object) => void} props.onHook Receives the hook result on every render.
 * @return {null} Nothing.
 */
function Probe( { onHook } ) {
	onHook( useSettings( [ 'display', 'appearance' ] ) );
	return null;
}

describe( 'useSettings', () => {
	it( 'loads once, tracks dirty, saves only changed sections and discards', async () => {
		let hook;
		apiFetch.mockImplementation( async ( { method, data } ) =>
			method === 'POST'
				? {
						...SETTINGS,
						appearance: {
							...SETTINGS.appearance,
							...data.appearance,
						},
					}
				: SETTINGS
		);

		render( <Probe onHook={ ( value ) => ( hook = value ) } /> );
		await waitFor( () => expect( hook.ready ).toBe( true ) );
		expect( hook.values.appearance.brand_name ).toBe( 'Acme' );
		expect( hook.dirty ).toBe( false );

		act( () => hook.setField( 'appearance', 'brand_name', 'New' ) );
		expect( hook.dirty ).toBe( true );
		expect( hook.changes ).toEqual( {
			appearance: { ...SETTINGS.appearance, brand_name: 'New' },
		} );

		// Typing the old value back is not a change.
		act( () => hook.setField( 'appearance', 'brand_name', 'Acme' ) );
		expect( hook.dirty ).toBe( false );

		act( () => hook.setField( 'appearance', 'brand_name', 'Saved' ) );
		await act( async () => {
			expect( await hook.save() ).toBe( true );
		} );
		const post = apiFetch.mock.calls.find(
			( [ args ] ) => args.method === 'POST'
		)[ 0 ];
		expect( post.path ).toBe( '/devrivo-chatbot/v1/settings' );
		expect( Object.keys( post.data ) ).toEqual( [ 'appearance' ] );
		expect( hook.dirty ).toBe( false );
		expect( hook.values.appearance.brand_name ).toBe( 'Saved' );
		expect(
			apiFetch.mock.calls.filter( ( [ args ] ) => ! args.method ).length
		).toBe( 1 );

		act( () => hook.setField( 'display', 'position', 'left' ) );
		act( () => hook.discard() );
		expect( hook.dirty ).toBe( false );
		expect( hook.values.display.position ).toBe( 'right' );
	} );

	it( 'keeps the draft and maps errors when saving fails', async () => {
		let hook;
		apiFetch.mockImplementation( async ( { method } ) => {
			if ( method === 'POST' ) {
				throw {
					message: 'Invalid.',
					data: {
						status: 400,
						params: { 'appearance[brand_name]': 'Too long.' },
					},
				};
			}
			return SETTINGS;
		} );

		render( <Probe onHook={ ( value ) => ( hook = value ) } /> );
		await waitFor( () => expect( hook.ready ).toBe( true ) );
		act( () =>
			hook.setField( 'appearance', 'brand_name', 'x'.repeat( 90 ) )
		);
		await act( async () => {
			expect( await hook.save() ).toBe( false );
		} );

		expect( hook.dirty ).toBe( true );
		expect( hook.errors ).toEqual( {
			message: 'Invalid.',
			fields: { brand_name: 'Too long.' },
		} );
	} );
} );

describe( 'hours editor logic', () => {
	it( 'adds, edits and removes ranges, up to three per day', () => {
		let schedule = normalizeSchedule( { mon: [] } );
		expect( Object.keys( schedule ) ).toEqual( [
			'mon',
			'tue',
			'wed',
			'thu',
			'fri',
			'sat',
			'sun',
		] );

		schedule = addRange( schedule, 'mon' );
		expect( schedule.mon ).toEqual( [ { open: '09:00', close: '17:00' } ] );
		schedule = addRange( schedule, 'mon' );
		expect( schedule.mon[ 1 ] ).toEqual( {
			open: '17:00',
			close: '23:00',
		} );
		schedule = addRange( addRange( schedule, 'mon' ), 'mon' );
		expect( schedule.mon ).toHaveLength( MAX_RANGES );

		schedule = updateRange( schedule, 'mon', 0, 'close', '12:00' );
		expect( schedule.mon[ 0 ].close ).toBe( '12:00' );
		schedule = removeRange( schedule, 'mon', 2 );
		expect( schedule.mon ).toHaveLength( 2 );

		const copied = copyToWeekdays( schedule, 'mon' );
		expect( copied.fri ).toEqual( schedule.mon );
		expect( copied.sat ).toEqual( [] );
		copied.fri[ 0 ].open = '10:00';
		expect( copied.mon[ 0 ].open ).toBe( '09:00' );
	} );

	it( 'reports ranges the server would reject', () => {
		expect(
			rangeErrors( {
				mon: [ { open: '17:00', close: '09:00' } ],
				tue: [ { open: '', close: '12:00' } ],
				wed: [ { open: '09:00', close: '12:00' } ],
			} )
		).toEqual( { 'mon.0': 'order', 'tue.0': 'format' } );
	} );
} );

describe( 'quick action logic', () => {
	const TYPES = [
		{
			id: 'call',
			supported: true,
			required: [ 'phone' ],
			fields: [ { key: 'phone', type: 'string' } ],
		},
		{
			id: 'link',
			supported: true,
			required: [ 'url' ],
			fields: [
				{ key: 'url', type: 'string' },
				{ key: 'new_tab', type: 'boolean' },
			],
		},
		{
			id: 'ai_prompt',
			supported: false,
			required: [ 'prompt' ],
			fields: [],
		},
	];

	it( 'reorders, adds with unique IDs, edits and removes', () => {
		let list = addAction( [], TYPES[ 1 ], 'Our services' );
		list = addAction( list, TYPES[ 1 ], 'Our services!' );
		list = addAction( list, TYPES[ 0 ], 'Call us' );

		expect( list.map( ( a ) => a.id ) ).toEqual( [
			'our-services',
			'our-services-2',
			'call-us',
		] );
		expect( list[ 0 ].config ).toEqual( { url: '', new_tab: true } );

		expect( move( list, 2, -1 ).map( ( a ) => a.id ) ).toEqual( [
			'our-services',
			'call-us',
			'our-services-2',
		] );
		expect( move( list, 0, -1 ) ).toBe( list );
		expect( move( list, 2, 1 ) ).toBe( list );

		list = updateAction( list, 2, 'phone', '+44 20 7946 0000', true );
		expect( list[ 2 ].config.phone ).toBe( '+44 20 7946 0000' );
		list = updateAction( list, 2, 'label', 'Phone us' );
		expect( list[ 2 ].label ).toBe( 'Phone us' );

		expect( removeAction( list, 1 ).map( ( a ) => a.id ) ).toEqual( [
			'our-services',
			'call-us',
		] );
		expect( uniqueId( '  ', [] ) ).toBe( 'action' );
		expect( uniqueId( 'Café Créme', [] ) ).toBe( 'cafe-creme' );
	} );

	it( 'finds incomplete and unsupported actions', () => {
		expect(
			missing( { type: 'call', config: { phone: ' ' } }, TYPES )
		).toEqual( [ 'phone' ] );
		expect(
			missing( { type: 'call', config: { phone: '123' } }, TYPES )
		).toEqual( [] );
		expect( missing( { type: 'ai_prompt', config: {} }, TYPES ) ).toEqual( [
			'type',
		] );
		expect( missing( { type: 'gone', config: {} }, TYPES ) ).toEqual( [
			'type',
		] );
	} );
} );

describe( 'inbox', () => {
	const LEAD = {
		id: 7,
		form_id: 'contact',
		status: 'new',
		email: XSS,
		name: XSS,
		data: [
			{ id: 'name', label: XSS, value: XSS },
			{
				id: 'message',
				label: 'Message',
				value: '<script>window.__xss=2</script>',
			},
		],
		page_url: 'javascript:alert(1)',
		consent_text: XSS,
		consent_at: '2026-10-30T10:00:00Z',
		created_at: '2026-10-30T10:00:00Z',
	};

	it( 'renders lead data as text and links only to http(s) pages', () => {
		const { container } = render(
			<LeadDetail
				lead={ LEAD }
				forms={ {} }
				busy={ false }
				onStatus={ () => {} }
				onDelete={ () => {} }
				onClose={ () => {} }
			/>
		);

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container.querySelector( 'script' ) ).toBeNull();
		expect( container.querySelector( 'a[href^="javascript"]' ) ).toBeNull();
		expect( container.textContent ).toContain( XSS );
		expect( container.textContent ).toContain( 'javascript:alert(1)' );
		expect( window.__xss ).toBeUndefined();

		expect( safeLink( 'https://example.com/a' ) ).toBe(
			'https://example.com/a'
		);
		expect( safeLink( 'javascript:alert(1)' ) ).toBe( '' );
		expect( safeLink( 'data:text/html,x' ) ).toBe( '' );
	} );

	it( 'lists leads as text with pagination totals', async () => {
		apiFetch.mockImplementation( async ( { path, parse } ) => {
			if ( parse === false ) {
				return {
					json: async () => [ LEAD ],
					headers: new Headers( {
						'X-WP-Total': '41',
						'X-WP-TotalPages': '3',
					} ),
				};
			}
			return path.startsWith( '/devrivo-chatbot/v1/settings' )
				? SETTINGS
				: [];
		} );

		const { container } = render( <Inbox notify={ () => {} } /> );
		await screen.findByText( 'Page 1 of 3 (41 leads)' );

		expect( container.querySelector( 'tbody img' ) ).toBeNull();
		expect( container.querySelector( 'tbody' ).textContent ).toContain(
			XSS
		);
		const listCall = apiFetch.mock.calls.find(
			( [ args ] ) => args.parse === false
		)[ 0 ];
		expect( listCall.path ).toBe(
			'/devrivo-chatbot/v1/leads?page=1&per_page=20'
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: /View lead from/ } )
		);
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( window.__xss ).toBeUndefined();
	} );
} );

describe( 'display rules picker', () => {
	it( 'decodes REST titles to plain text', () => {
		expect(
			tokenLabel( { id: 5, title: 'Tom &amp; Jerry&#8217;s page' } )
		).toBe( 'Tom & Jerry’s page (#5)' );
		expect( tokenLabel( { id: 6, title: '' } ) ).toBe( '(no title) (#6)' );
		expect( tokenId( 'A (#1) (#42)' ) ).toBe( 42 );
		expect( tokenId( 'No id' ) ).toBe( 0 );
	} );

	it( 'shows decoded titles as text, never HTML', async () => {
		apiFetch.mockImplementation( async ( { path } ) => {
			if ( path.startsWith( '/wp/v2/search' ) ) {
				return [
					{
						id: 5,
						title: 'Caf&eacute; &amp; &lt;img src=x onerror=&quot;window.__xss=3&quot;&gt;',
					},
				];
			}
			return {
				...SETTINGS,
				display: {
					...SETTINGS.display,
					rules: {
						mode: 'include',
						post_ids: [ 5 ],
						post_types: [],
						devices: [ 'desktop', 'mobile' ],
					},
				},
			};
		} );

		const { container } = render(
			<Display notify={ () => {} } setDirty={ () => {} } />
		);
		await screen.findByText(
			'Café & <img src=x onerror="window.__xss=3"> (#5)'
		);

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( window.__xss ).toBeUndefined();
		const include = apiFetch.mock.calls.find( ( [ args ] ) =>
			args.path.startsWith( '/wp/v2/search' )
		)[ 0 ];
		expect( include.path ).toContain( 'include=5' );
	} );
} );

describe( 'widget preview mode', () => {
	it( 'renders inside the host with no network requests', async () => {
		const fetch = vi.fn();
		window.fetch = fetch;
		const host = document.createElement( 'div' );
		document.body.append( host );

		const widget = preview(
			host,
			{
				behaviour: { auto_open: true, auto_open_delay: 0 },
				actions: [
					{
						id: 'site',
						label: 'Visit',
						type: 'link',
						config: { url: 'https://example.com/' },
					},
				],
				forms: {},
				i18n: { close: 'Close' },
			},
			{ note: 'Preview only' }
		);

		const root = host.shadowRoot.querySelector( '.dc' );
		expect( root.hasAttribute( 'data-preview' ) ).toBe( true );
		expect( widget.state() ).toBe( 'panel' );

		const row = host.shadowRoot.querySelector( '[data-action="site"]' );
		expect( row.tagName ).toBe( 'BUTTON' );
		row.click();
		const note = host.shadowRoot.querySelector( '.dc-preview-note' );
		expect( note.hidden ).toBe( false );
		expect( note.textContent ).toBe( 'Preview only' );

		await new Promise( ( resolve ) => setTimeout( resolve, 20 ) );
		expect( fetch ).not.toHaveBeenCalled();
		expect( window.localStorage.length ).toBe( 0 );

		widget.destroy();
		host.remove();
	} );
} );
