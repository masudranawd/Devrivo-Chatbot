/**
 * Page-specific quick actions: matching, menu order, the question action,
 * preview as page, and the admin rule editor.
 */
import {
	cleanup,
	fireEvent,
	render as renderUi,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

import apiFetch from '@wordpress/api-fetch';
import {
	addQuestion,
	addRule,
	addRuleAction,
	parsePaths,
} from '../../assets-src/admin/lib/page-rules';
import { resetSettingsCache } from '../../assets-src/admin/lib/use-settings';
import PageActions from '../../assets-src/admin/screens/page-actions';
import { normalizeConfig } from '../../assets-src/widget/lib/config';
import {
	composeMenu,
	matchRule,
	normalizePath,
	relativePath,
} from '../../assets-src/widget/lib/page-actions';
import { mount, preview, render } from '../../assets-src/widget/widget';

const action = ( id, patch = {} ) => ( {
	id,
	label: id.toUpperCase(),
	type: 'link',
	urgent: false,
	show_in_menu: true,
	config: { url: `https://example.com/${ id }` },
	...patch,
} );

const RAW = {
	actions: [
		action( 'book', { pinned: true } ),
		action( 'call', { type: 'call', config: { phone: '123 456' } } ),
		action( 'about' ),
		action( 'cost', {
			type: 'ai_prompt',
			show_in_menu: false,
			config: { prompt: 'How much do implants cost?' },
		} ),
		action( 'time', {
			type: 'ai_prompt',
			show_in_menu: false,
			config: { prompt: 'How long does it take?' },
		} ),
	],
	page_actions: [
		{
			post_ids: [ 42 ],
			paths: [ '/services/implants/' ],
			include_children: true,
			actions: [ 'cost', 'book', 'time' ],
		},
		{
			post_ids: [],
			paths: [ '/services/' ],
			include_children: false,
			actions: [ 'about' ],
		},
		{
			post_ids: [],
			paths: [ '/services/' ],
			include_children: true,
			actions: [ 'call' ],
		},
	],
	i18n: {},
};

const config = () => normalizeConfig( RAW, { position: 'right' } );
const chips = ( host ) =>
	[ ...host.shadowRoot.querySelectorAll( '.dc-chip' ) ].map(
		( chip ) => chip.dataset.action
	);

beforeEach( () => {
	resetSettingsCache();
	apiFetch.mockReset();
	window.devrivoChatbotAdmin = {
		homePath: '/pearldentalcare/',
		actionTypes: [],
	};
} );

afterEach( () => {
	cleanup();
	document.body.replaceChildren();
	delete window.devrivoChatbotAdmin;
	vi.restoreAllMocks();
	window.history.replaceState( null, '', '/' );
} );

describe( 'matching', () => {
	it( 'normalises paths', () => {
		expect( normalizePath( '/Services/Implants' ) ).toBe(
			'/services/implants/'
		);
		expect( normalizePath( 'services//implants/?a=1#x' ) ).toBe(
			'/services/implants/'
		);
		expect( normalizePath( '/caf%C3%A9/' ) ).toBe( '/café/' );
		expect( normalizePath( '/bad%E0%A4%A/' ) ).toBe( '/bad%e0%a4%a/' );
		expect( normalizePath( '' ) ).toBe( '/' );
	} );

	it( 'makes paths relative to a home in a subdirectory', () => {
		expect(
			relativePath(
				'/pearldentalcare/services/implants',
				'/pearldentalcare/'
			)
		).toBe( '/services/implants/' );
		expect( relativePath( '/PearlDentalCare', '/pearldentalcare/' ) ).toBe(
			'/'
		);
		expect( relativePath( '/other/page/', '/pearldentalcare/' ) ).toBe(
			'/other/page/'
		);
		expect( relativePath( '/services/', '/' ) ).toBe( '/services/' );
	} );

	it( 'matches by post ID, exact path and subpages; the first match wins', () => {
		const rules = config().pageActions;
		const ids = ( page ) => matchRule( rules, page )?.actions || null;

		expect( ids( { postId: 42, path: '/somewhere/' } ) ).toEqual( [
			'cost',
			'book',
			'time',
		] );
		expect( ids( { postId: 0, path: '/services/implants' } ) ).toEqual( [
			'cost',
			'book',
			'time',
		] );
		expect(
			ids( { postId: 0, path: '/SERVICES/Implants/aftercare/' } )
		).toEqual( [ 'cost', 'book', 'time' ] );
		expect( ids( { postId: 0, path: '/services/' } ) ).toEqual( [
			'about',
		] );
		expect( ids( { postId: 0, path: '/services/whitening/' } ) ).toEqual( [
			'call',
		] );
		expect( ids( { postId: 7, path: '/contact/' } ) ).toBeNull();
		expect( ids( { postId: 0, path: '/services-old/' } ) ).toBeNull();
	} );

	it( 'drops rules without known actions or anything to match', () => {
		const rules = normalizeConfig(
			{
				actions: [ action( 'a' ) ],
				page_actions: [
					{ paths: [ '/x/' ], actions: [ 'gone' ] },
					{ paths: [], post_ids: [], actions: [ 'a' ] },
					{ paths: [ 'relative/' ], actions: [ 'a' ] },
					{ post_ids: [ 3, -1, '4' ], actions: [ 'a', 'a' ] },
				],
			},
			{}
		).pageActions;

		expect( rules ).toEqual( [
			{
				postIds: [ 3 ],
				paths: [],
				includeChildren: true,
				actions: [ 'a' ],
			},
		] );
	} );
} );

describe( 'menu composition', () => {
	it( 'puts pinned actions first, then the rule, without repeats', () => {
		const { actions, pageActions } = config();
		const menu = composeMenu(
			actions,
			matchRule( pageActions, { postId: 42 } )
		);
		expect( menu.map( ( a ) => a.id ) ).toEqual( [
			'book',
			'cost',
			'time',
		] );
	} );

	it( 'falls back to the normal menu, pinned first', () => {
		const { actions } = config();
		const reordered = [ actions[ 1 ], actions[ 2 ], actions[ 0 ] ];
		expect( composeMenu( reordered, null ).map( ( a ) => a.id ) ).toEqual( [
			'book',
			'call',
			'about',
		] );
	} );

	it( 'previews a page by post ID or path', () => {
		const host = document.createElement( 'div' );
		document.body.append( host );

		let widget = preview( host, RAW, { page: { postId: 42 } } );
		expect( chips( host ) ).toEqual( [ 'book', 'cost', 'time' ] );
		const home = [
			...host.shadowRoot.querySelectorAll( '.dc-list [data-action]' ),
		].map( ( row ) => row.dataset.action );
		expect( home ).toEqual( [ 'book', 'cost', 'time' ] );
		widget.destroy();

		widget = preview( host, RAW, {
			page: { path: '/services/whitening' },
		} );
		expect( chips( host ) ).toEqual( [ 'book', 'call' ] );
		widget.destroy();

		widget = preview( host, RAW, {} );
		expect( chips( host ) ).toEqual( [ 'book', 'call', 'about' ] );
		widget.destroy();
	} );

	it( 'mounting uses the boot post ID and the path below the home', async () => {
		vi.spyOn( window, 'fetch' ).mockResolvedValue( {
			ok: true,
			json: async () => RAW,
		} );
		window.history.replaceState(
			null,
			'',
			'/pearldentalcare/Services/Implants/'
		);
		const host = document.createElement( 'div' );
		host.attachShadow( { mode: 'open' } );
		document.body.append( host );

		await mount( host, {
			configUrl: 'https://example.com/config',
			postId: 0,
			homePath: '/pearldentalcare/',
			position: 'right',
			primaryColor: '#123456',
			launcherLabel: '',
		} );

		expect( chips( host ) ).toEqual( [ 'book', 'cost', 'time' ] );
	} );
} );

describe( 'question action', () => {
	it( 'opens the chat and sends the question like a typed message', async () => {
		const send = vi.fn( async () => ( {
			ok: true,
			status: 200,
			answer: {
				mode: 'guided',
				text: 'Implants start at…',
				sources: [],
				actions: [],
			},
		} ) );
		const host = document.createElement( 'div' );
		host.attachShadow( { mode: 'open' } );
		document.body.append( host );
		const widget = render( host, config(), {
			chat: { send },
			typingTime: () => 0,
			page: { postId: 42, path: '/' },
		} );

		host.shadowRoot.querySelector( '.dc-chip[data-action="cost"]' ).click();

		expect( widget.view() ).toBe( 'chat' );
		await waitFor( () =>
			expect( send ).toHaveBeenCalledWith(
				'How much do implants cost?',
				expect.any( Array )
			)
		);
		await waitFor( () =>
			expect( host.shadowRoot.textContent ).toContain(
				'Implants start at…'
			)
		);
		expect( host.shadowRoot.querySelector( '.dc-user' ).textContent ).toBe(
			'How much do implants cost?'
		);
		widget.destroy();
	} );
} );

describe( 'admin rule editing', () => {
	it( 'adds rules, parses paths and adds questions (up to 5 actions)', () => {
		let rules = addRule( [], 'Dental implants' );
		rules = addRule( rules, 'Dental implants' );
		expect( rules.map( ( r ) => r.id ) ).toEqual( [
			'dental-implants',
			'dental-implants-2',
		] );
		expect( rules[ 0 ] ).toMatchObject( {
			include_children: true,
			post_ids: [],
			paths: [],
			actions: [],
		} );

		expect(
			parsePaths( ' services/implants/ \n\n/services/implants/\n/a/' )
		).toEqual( [ '/services/implants/', '/a/' ] );
		expect(
			parsePaths(
				Array.from( { length: 12 }, ( _, i ) => `/p${ i }/` ).join(
					'\n'
				)
			)
		).toHaveLength( 10 );

		const result = addQuestion(
			[],
			rules,
			0,
			'  How much does it cost?  '
		);
		expect( result.actions ).toEqual( [
			{
				id: 'how-much-does-it-cost',
				label: 'How much does it cost?',
				type: 'ai_prompt',
				urgent: false,
				show_in_menu: false,
				pinned: false,
				config: { prompt: 'How much does it cost?' },
			},
		] );
		expect( result.rules[ 0 ].actions ).toEqual( [
			'how-much-does-it-cost',
		] );
		expect( addQuestion( [], rules, 0, '   ' ) ).toBeNull();

		let full = rules;
		for ( const id of [ 'a', 'b', 'c', 'd', 'e', 'f' ] ) {
			full = addRuleAction( full, 0, id );
		}
		expect( full[ 0 ].actions ).toEqual( [ 'a', 'b', 'c', 'd', 'e' ] );
		expect( addQuestion( [], full, 0, 'More?' ) ).toBeNull();
	} );

	it( 'edits a rule, adds an inline question and saves both sections', async () => {
		let posted = null;
		apiFetch.mockImplementation( async ( { path, data } ) => {
			if ( path.endsWith( '/settings' ) && data ) {
				posted = data;
				return { ...data };
			}
			if ( path.endsWith( '/settings' ) ) {
				return {
					actions: [ action( 'book' ), action( 'call' ) ],
					page_actions: [
						{
							id: 'implants',
							name: 'Implants',
							post_ids: [],
							paths: [ '/services/implants/' ],
							include_children: true,
							actions: [ 'book' ],
						},
					],
				};
			}
			return new Promise( () => {} );
		} );
		renderUi( <PageActions notify={ () => {} } setDirty={ () => {} } /> );

		const rule = await screen.findByRole( 'group', { name: 'Implants' } );
		expect(
			within( rule ).getByText(
				/Relative to your site home \(\/pearldentalcare\/\)/
			)
		).toBeTruthy();

		fireEvent.change(
			within( rule ).getByLabelText( 'Paths (one per line)' ),
			{
				target: { value: '/services/implants/\nservices/bridges' },
			}
		);
		fireEvent.change( within( rule ).getByLabelText( 'Add an action' ), {
			target: { value: 'call' },
		} );
		fireEvent.change( within( rule ).getByLabelText( 'Add a question' ), {
			target: { value: 'Does it hurt?' },
		} );
		fireEvent.click(
			within( rule ).getByRole( 'button', { name: 'Add question' } )
		);
		fireEvent.click(
			within( rule ).getByRole( 'button', { name: 'Move “CALL” up' } )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		await waitFor( () => expect( posted ).not.toBeNull() );

		expect( posted.page_actions[ 0 ] ).toMatchObject( {
			paths: [ '/services/implants/', '/services/bridges' ],
			actions: [ 'call', 'book', 'does-it-hurt' ],
		} );
		expect( posted.actions[ 2 ] ).toMatchObject( {
			id: 'does-it-hurt',
			type: 'ai_prompt',
			show_in_menu: false,
			config: { prompt: 'Does it hurt?' },
		} );
		expect(
			screen.getByText( /The first rule that matches a page wins/ )
		).toBeTruthy();
		expect( screen.getByLabelText( 'Or a path' ) ).toBeTruthy();
	} );

	it( 'links to Quick actions when there are none yet', async () => {
		apiFetch.mockImplementation( async ( { path } ) =>
			path.endsWith( '/settings' )
				? {
						actions: [],
						page_actions: [
							{
								id: 'r',
								name: 'Rule',
								paths: [ '/a/' ],
								actions: [],
							},
						],
					}
				: new Promise( () => {} )
		);
		renderUi( <PageActions notify={ () => {} } setDirty={ () => {} } /> );

		const link = await screen.findByRole( 'link', {
			name: /Create one under Quick actions/,
		} );
		expect( link.getAttribute( 'href' ) ).toBe( '#/actions' );
	} );
} );
