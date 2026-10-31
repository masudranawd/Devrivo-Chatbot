/**
 * Setup wizard: step logic, navigation (skip, back, resume, focus), scan
 * suggestions, quick action fixes, AI states, test question and go live.
 */
import {
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

import apiFetch from '@wordpress/api-fetch';
import {
	actionFixes,
	applyFixes,
	hasOwnSettings,
	initialBusiness,
	stepIndex,
	toWhatsapp,
	weeklySchedule,
} from '../../assets-src/admin/lib/setup';
import { resetSettingsCache } from '../../assets-src/admin/lib/use-settings';
import App from '../../assets-src/admin/app';
import Setup, {
	AiStep,
	BusinessStep,
	GoLiveStep,
	TestStep,
} from '../../assets-src/admin/screens/setup';

const XSS = '<img src=x onerror="window.__xss=1">';

const TYPES = [
	{ id: 'call', supported: true, required: [ 'phone' ] },
	{ id: 'whatsapp', supported: true, required: [ 'phone' ] },
	{ id: 'email', supported: true, required: [ 'email' ] },
	{ id: 'link', supported: true, required: [ 'url' ] },
	{ id: 'flow', supported: true, required: [ 'flow' ] },
];

const SCAN = {
	site_name: 'Smile Dental',
	tagline: 'Gentle care',
	plugins: [ { id: 'elementor', name: 'Elementor', kind: 'builder' } ],
	content: [ { slug: 'page', label: 'Pages', published: 4 } ],
	privacy_policy: false,
	admin_email: 'admin@example.com',
	suggestion: {
		preset: 'healthcare',
		reasons: [ 'Your site title mentions “dental”.' ],
		note: '',
	},
	contacts: {
		emails: [ 'hello@smile.example' ],
		phones: [ '020 7946 0000' ],
		sources: [],
	},
};

const SETUP = {
	completed: false,
	completed_at: 0,
	step: '',
	notice_dismissed: false,
};

/**
 * All settings with overrides.
 *
 * @param {Object} patch Sections.
 * @return {Object} Settings.
 */
function settings( patch = {} ) {
	return {
		general: { preset: '', business: {}, setup: SETUP },
		display: { enabled: false },
		actions: [],
		flows: {},
		forms: {},
		offers: [],
		knowledge: { faqs: [] },
		ai: { enabled: false, provider: '', model: '', monthly_token_limit: 0 },
		behaviour: { hours: { timezone: '', schedule: {} } },
		integrations: { notification_emails: [] },
		...patch,
	};
}

const nav = () => ( {
	onBack: vi.fn(),
	onSkip: vi.fn(),
	onNext: vi.fn(),
} );

beforeEach( () => {
	resetSettingsCache();
	apiFetch.mockReset();
	window.__xss = undefined;
	window.devrivoChatbotAdmin = {
		actionTypes: TYPES,
		homeUrl: 'https://example.com/',
		connectorsUrl: 'https://example.com/wp-admin/options-connectors.php',
		aiPluginsUrl:
			'https://example.com/wp-admin/plugin-install.php?s=AI%20Provider&tab=search&type=term',
		privacySettingsUrl: 'https://example.com/wp-admin/options-privacy.php',
	};
} );

afterEach( () => {
	cleanup();
	delete window.devrivoChatbotAdmin;
} );

describe( 'setup logic', () => {
	it( 'maps saved steps to indexes', () => {
		expect( stepIndex( '' ) ).toBe( 0 );
		expect( stepIndex( 'nope' ) ).toBe( 0 );
		expect( stepIndex( 'ai' ) ).toBe( 3 );
		expect( stepIndex( 'golive' ) ).toBe( 7 );
	} );

	it( 'keeps saved business values and marks scan suggestions', () => {
		const result = initialBusiness( { name: 'Saved name' }, SCAN );
		expect( result.values ).toMatchObject( {
			name: 'Saved name',
			phone: '020 7946 0000',
			email: 'hello@smile.example',
			whatsapp: '',
		} );
		expect( result.suggested ).toEqual( [ 'phone', 'email' ] );
		expect( initialBusiness( {}, null ).suggested ).toEqual( [] );
	} );

	it( 'converts phone numbers for WhatsApp', () => {
		expect( toWhatsapp( '+44 (20) 7946-0000' ) ).toBe( '+44 20 7946 0000' );
		expect( toWhatsapp( '12' ) ).toBe( '' );
		expect( toWhatsapp( '' ) ).toBe( '' );
	} );

	it( 'finds quick actions the profile can complete and applies ticked ones', () => {
		const actions = [
			{ id: 'call', label: 'Call', type: 'call', config: { phone: '' } },
			{ id: 'wa', label: 'WhatsApp', type: 'whatsapp', config: {} },
			{
				id: 'mail',
				label: 'Email',
				type: 'email',
				config: { email: '' },
			},
			{ id: 'book', label: 'Book', type: 'link', config: { url: '' } },
			{
				id: 'ok',
				label: 'Done',
				type: 'call',
				config: { phone: '1234' },
			},
			{ id: 'chat', label: 'Chat', type: 'flow', config: { flow: '' } },
		];
		const fixes = actionFixes( actions, TYPES, {
			phone: '+44 (20) 7946-0000',
			whatsapp: '',
			email: 'a@example.com',
			booking_url: '',
		} );

		expect( fixes.map( ( f ) => [ f.index, f.key, f.value ] ) ).toEqual( [
			[ 0, 'phone', '+44 (20) 7946-0000' ],
			[ 1, 'phone', '+44 20 7946 0000' ],
			[ 2, 'email', 'a@example.com' ],
			[ 3, 'url', '' ],
		] );

		const next = applyFixes( actions, [
			{ ...fixes[ 0 ], on: true },
			{ ...fixes[ 2 ], on: false },
			{ ...fixes[ 3 ], on: true },
		] );
		expect( next[ 0 ].config.phone ).toBe( '+44 (20) 7946-0000' );
		expect( next[ 2 ].config.email ).toBe( '' );
		expect( next[ 3 ].config.url ).toBe( '' );
		expect( actions[ 0 ].config.phone ).toBe( '' );
	} );

	it( 'builds a weekly schedule and detects own settings', () => {
		const schedule = weeklySchedule( [ 'mon', 'sat' ], '09:00', '17:00' );
		expect( schedule.mon ).toEqual( [ { open: '09:00', close: '17:00' } ] );
		expect( schedule.tue ).toEqual( [] );
		expect( Object.keys( schedule ) ).toHaveLength( 7 );

		expect( hasOwnSettings( settings() ) ).toBe( false );
		expect(
			hasOwnSettings( settings( { actions: [ { id: 'x' } ] } ) )
		).toBe( true );
	} );
} );

describe( 'wizard navigation', () => {
	/**
	 * apiFetch for the wizard.
	 *
	 * @param {Object} stored Settings.
	 * @return {Array} Step requests.
	 */
	function mockApi( stored ) {
		const steps = [];
		apiFetch.mockImplementation( async ( { path, data } ) => {
			if ( path.endsWith( '/settings' ) ) {
				return stored;
			}
			if ( path.endsWith( '/setup/scan' ) ) {
				return SCAN;
			}
			if ( path.endsWith( '/presets' ) ) {
				return [
					{ id: 'business', title: 'Business' },
					{ id: 'healthcare', title: 'Healthcare' },
				];
			}
			if ( path.endsWith( '/setup/step' ) ) {
				steps.push( data.step );
				return settings( {
					general: {
						...stored.general,
						setup: { ...SETUP, step: data.step },
					},
				} );
			}
			return new Promise( () => {} );
		} );
		return steps;
	}

	it( 'starts at the scan, skips forward, goes back and moves focus', async () => {
		const steps = mockApi( settings() );
		render( <Setup /> );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Step 1 of 8: Your site',
			} )
		).toBeTruthy();
		expect(
			await screen.findByText( 'Suggested starting point: Healthcare' )
		).toBeTruthy();
		expect(
			screen.getByText( 'Your site title mentions “dental”.' )
		).toBeTruthy();
		expect(
			screen.getByText( 'hello@smile.example, 020 7946 0000' )
		).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Back' } ) ).toBeNull();

		fireEvent.click( screen.getByRole( 'button', { name: 'Skip' } ) );
		const heading = await screen.findByRole( 'heading', {
			name: 'Step 2 of 8: Choose a starting point',
		} );
		await waitFor( () => expect( document.activeElement ).toBe( heading ) );
		expect( steps ).toEqual( [ 'preset' ] );

		fireEvent.click( screen.getByRole( 'button', { name: 'Back' } ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Step 1 of 8: Your site',
			} )
		).toBeTruthy();
		expect( steps ).toEqual( [ 'preset', 'scan' ] );
	} );

	it( 'guards unsaved step edits: Back, Skip, in-app links and leaving the page', async () => {
		mockApi(
			settings( {
				general: {
					preset: '',
					business: {},
					setup: { ...SETUP, step: 'business' },
				},
			} )
		);
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( false );
		window.location.hash = '#/setup';
		render( <App /> );

		const name = await screen.findByLabelText( 'Business name' );
		const leave = new window.Event( 'beforeunload', { cancelable: true } );
		window.dispatchEvent( leave );
		expect( leave.defaultPrevented ).toBe( false );

		fireEvent.change( name, { target: { value: 'Changed' } } );
		await waitFor( () => {
			const event = new window.Event( 'beforeunload', {
				cancelable: true,
			} );
			window.dispatchEvent( event );
			expect( event.defaultPrevented ).toBe( true );
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Back' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Skip' } ) );
		expect( confirm ).toHaveBeenCalledTimes( 2 );
		expect(
			screen.getByRole( 'heading', {
				name: 'Step 3 of 8: Your business details',
			} )
		).toBeTruthy();

		const link = screen.getByRole( 'link', { name: 'Dashboard' } );
		const click = new window.MouseEvent( 'click', {
			bubbles: true,
			cancelable: true,
		} );
		link.dispatchEvent( click );
		expect( confirm ).toHaveBeenCalledTimes( 3 );
		expect( click.defaultPrevented ).toBe( true );

		confirm.mockReturnValue( true );
		fireEvent.click( screen.getByRole( 'button', { name: 'Back' } ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'Step 2 of 8: Choose a starting point',
			} )
		).toBeTruthy();
		confirm.mockRestore();
		window.location.hash = '';
	} );

	it( 'resumes where the admin left off', async () => {
		mockApi(
			settings( {
				general: {
					preset: 'healthcare',
					business: { name: 'Smile' },
					setup: { ...SETUP, step: 'golive' },
				},
			} )
		);
		render( <Setup /> );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Step 8 of 8: Go live',
			} )
		).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Skip' } ) ).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Turn on the assistant' } )
		).toBeTruthy();
	} );
} );

describe( 'business step', () => {
	it( 'marks scan suggestions, prefills quick actions and saves only ticked fixes', async () => {
		let posted = null;
		apiFetch.mockImplementation( async ( { path, data } ) => {
			if ( path.endsWith( '/settings' ) ) {
				posted = data;
				return settings();
			}
			return {};
		} );
		const all = settings( {
			general: { business: { name: 'Saved name' }, setup: SETUP },
			actions: [
				{
					id: 'call',
					label: 'Call us',
					type: 'call',
					config: { phone: '' },
				},
				{
					id: 'mail',
					label: 'Email us',
					type: 'email',
					config: { email: '' },
				},
			],
		} );
		const steps = nav();
		render( <BusinessStep scan={ SCAN } all={ all } nav={ steps } /> );

		expect( screen.getByLabelText( 'Business name' ).value ).toBe(
			'Saved name'
		);
		const phone = screen.getByLabelText( 'Phone number' );
		expect( phone.value ).toBe( '020 7946 0000' );
		expect(
			screen.getAllByText( 'Found on your site, please check.' )
		).toHaveLength( 2 );

		fireEvent.change( phone, { target: { value: '+44 20 1111 2222' } } );
		const call = screen.getByTestId( 'fix-0' );
		expect( within( call ).getByRole( 'textbox' ).value ).toBe(
			'+44 20 1111 2222'
		);
		expect( within( call ).getByRole( 'checkbox' ).checked ).toBe( true );

		const mail = screen.getByTestId( 'fix-1' );
		fireEvent.click( within( mail ).getByRole( 'checkbox' ) );
		expect( within( mail ).getByRole( 'checkbox' ).checked ).toBe( false );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save and continue' } )
		);
		await waitFor( () => expect( steps.onNext ).toHaveBeenCalled() );

		expect( posted.general.business ).toMatchObject( {
			name: 'Saved name',
			phone: '+44 20 1111 2222',
			email: 'hello@smile.example',
		} );
		expect( posted.actions[ 0 ].config.phone ).toBe( '+44 20 1111 2222' );
		expect( posted.actions[ 1 ].config.email ).toBe( '' );
		expect( posted.behaviour.hours.schedule.mon ).toEqual( [
			{ open: '09:00', close: '17:00' },
		] );
		expect( posted.behaviour.hours.schedule.sat ).toEqual( [] );
		expect( posted.integrations ).toBeUndefined();
	} );

	it( 'reports unsaved edits to the shell', () => {
		const setDirty = vi.fn();
		render(
			<BusinessStep
				scan={ SCAN }
				all={ settings() }
				nav={ nav() }
				setDirty={ setDirty }
			/>
		);
		expect( setDirty ).toHaveBeenLastCalledWith( false );

		fireEvent.change( screen.getByLabelText( 'Business name' ), {
			target: { value: 'Edited' },
		} );
		expect( setDirty ).toHaveBeenLastCalledWith( true );
	} );

	it( 'shows server field errors and stays on the step', async () => {
		apiFetch.mockImplementation( async () => {
			throw {
				code: 'rest_invalid_param',
				message: 'Invalid phone.',
				data: {
					status: 400,
					params: { 'general[business][phone]': 'Bad phone.' },
				},
			};
		} );
		const steps = nav();
		render(
			<BusinessStep scan={ SCAN } all={ settings() } nav={ steps } />
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save and continue' } )
		);

		expect( await screen.findByText( 'Bad phone.' ) ).toBeTruthy();
		expect( steps.onNext ).not.toHaveBeenCalled();
	} );
} );

describe( 'AI step', () => {
	const status = ( patch ) => ( {
		core_available: true,
		providers: [],
		models: [],
		models_error: '',
		...patch,
	} );

	it( 'links to provider plugins when none is installed', async () => {
		apiFetch.mockResolvedValue( status() );
		render( <AiStep all={ settings() } nav={ nav() } /> );

		const link = await screen.findByRole( 'link', {
			name: 'Find AI provider plugins',
		} );
		expect( link.getAttribute( 'href' ) ).toContain(
			'plugin-install.php?s=AI%20Provider'
		);
		expect( screen.queryByRole( 'button', { name: 'Next' } ) ).toBeNull();
	} );

	it( 'links to Connectors when a provider is not connected', async () => {
		apiFetch.mockResolvedValue(
			status( { providers: [ { id: 'google', configured: false } ] } )
		);
		render( <AiStep all={ settings() } nav={ nav() } /> );

		const link = await screen.findByRole( 'link', {
			name: 'Open Settings → Connectors',
		} );
		expect( link.getAttribute( 'href' ) ).toContain(
			'options-connectors.php'
		);
	} );

	it( 'offers provider, model and a 200000 limit when ready, and saves', async () => {
		let posted = null;
		apiFetch.mockImplementation( async ( { path, data } ) => {
			if ( path.endsWith( '/settings' ) ) {
				posted = data;
				return settings();
			}
			return status( {
				providers: [ { id: 'google', configured: true } ],
				models: [ { id: 'gemini-flash', name: 'Gemini Flash' } ],
			} );
		} );
		const steps = nav();
		render( <AiStep all={ settings() } nav={ steps } /> );

		const provider = await screen.findByLabelText( 'AI provider' );
		fireEvent.change( provider, { target: { value: 'google' } } );
		const model = await screen.findByLabelText( 'Model' );
		await screen.findByRole( 'option', {
			name: 'Gemini Flash (gemini-flash)',
		} );
		fireEvent.change( model, { target: { value: 'gemini-flash' } } );
		expect( screen.getByLabelText( 'Monthly token limit' ).value ).toBe(
			'200000'
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save and continue' } )
		);
		await waitFor( () => expect( steps.onNext ).toHaveBeenCalled() );
		expect( posted ).toEqual( {
			ai: {
				provider: 'google',
				model: 'gemini-flash',
				monthly_token_limit: 200000,
				enabled: true,
			},
		} );
	} );

	it( 'Guided mode turns AI off only when it was on', async () => {
		const calls = [];
		apiFetch.mockImplementation( async ( options ) => {
			calls.push( options );
			if ( options.path.endsWith( '/settings' ) ) {
				return settings();
			}
			return status( {
				providers: [ { id: 'google', configured: true } ],
			} );
		} );

		const off = nav();
		render( <AiStep all={ settings() } nav={ off } /> );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Use Guided mode for now',
			} )
		);
		await waitFor( () => expect( off.onNext ).toHaveBeenCalled() );
		expect( calls.some( ( c ) => c.method === 'POST' ) ).toBe( false );
		cleanup();

		const on = nav();
		render(
			<AiStep
				all={ settings( {
					ai: {
						enabled: true,
						provider: 'google',
						model: 'x',
						monthly_token_limit: 5,
					},
				} ) }
				nav={ on }
			/>
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Use Guided mode for now',
			} )
		);
		await waitFor( () => expect( on.onNext ).toHaveBeenCalled() );
		expect( calls.find( ( c ) => c.method === 'POST' ).data ).toEqual( {
			ai: { enabled: false },
		} );
	} );
} );

describe( 'test a question', () => {
	beforeEach( () => {
		apiFetch.mockImplementation( () => new Promise( () => {} ) );
	} );

	it( 'shows an AI answer as text with its sources', async () => {
		const client = {
			send: vi.fn( async () => ( {
				ok: true,
				status: 200,
				answer: {
					mode: 'ai',
					text: XSS,
					sources: [
						{ title: 'Prices', url: 'https://example.com/prices/' },
					],
					actions: [],
				},
			} ) ),
		};
		const { container } = render(
			<TestStep nav={ nav() } client={ client } />
		);

		fireEvent.change( screen.getByLabelText( 'Your question' ), {
			target: { value: 'How much is a check-up?' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );

		expect( await screen.findByText( 'Answered by AI' ) ).toBeTruthy();
		expect( screen.getByText( XSS ) ).toBeTruthy();
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( window.__xss ).toBeUndefined();
		expect(
			screen
				.getByRole( 'link', { name: 'Prices' } )
				.getAttribute( 'href' )
		).toBe( 'https://example.com/prices/' );
		expect( client.send ).toHaveBeenCalledWith(
			'How much is a check-up?',
			[]
		);
	} );

	it( 'labels Guided answers and errors', async () => {
		const client = {
			send: vi
				.fn()
				.mockResolvedValueOnce( {
					ok: true,
					status: 200,
					answer: {
						mode: 'guided',
						text: 'From your pages',
						sources: [],
						actions: [],
					},
				} )
				.mockResolvedValueOnce( {
					ok: false,
					status: 429,
					answer: null,
				} ),
		};
		render( <TestStep nav={ nav() } client={ client } /> );

		const input = screen.getByLabelText( 'Your question' );
		fireEvent.change( input, { target: { value: 'Hours?' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );
		expect(
			await screen.findByText( 'Guided mode (from your pages and FAQs)' )
		).toBeTruthy();

		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );
		expect(
			( await screen.findAllByText( /Too many test questions/ ) ).length
		).toBeGreaterThan( 0 );
	} );
} );

describe( 'go live', () => {
	it( 'completes setup, turns the widget on and links to the site', async () => {
		let posted = null;
		apiFetch.mockImplementation( async ( { path, data } ) => {
			posted = { path, data };
			return settings( { display: { enabled: true } } );
		} );
		render(
			<GoLiveStep
				all={ settings( {
					general: {
						preset: 'healthcare',
						business: { name: XSS },
						setup: SETUP,
					},
				} ) }
				presets={ [ { id: 'healthcare', title: 'Healthcare' } ] }
				nav={ nav() }
			/>
		);

		expect( screen.getByText( 'Healthcare' ) ).toBeTruthy();
		expect( screen.getByText( XSS ) ).toBeTruthy();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Turn on the assistant' } )
		);

		expect(
			( await screen.findAllByText( /Your assistant is live/ ) ).length
		).toBeGreaterThan( 0 );
		expect( posted ).toEqual( {
			path: '/devrivo-chatbot/v1/setup/complete',
			data: { enable_widget: true },
		} );
		expect(
			screen
				.getByRole( 'link', { name: /View your site/ } )
				.getAttribute( 'href' )
		).toBe( 'https://example.com/' );
	} );

	it( 'can finish without turning the widget on', async () => {
		let posted = null;
		apiFetch.mockImplementation( async ( { data } ) => {
			posted = data;
			return settings();
		} );
		render(
			<GoLiveStep all={ settings() } presets={ [] } nav={ nav() } />
		);

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Finish without turning it on',
			} )
		);
		expect(
			( await screen.findAllByText( /stays off until you turn it on/ ) )
				.length
		).toBeGreaterThan( 0 );
		expect( posted ).toEqual( { enable_widget: false } );
	} );
} );

describe( 'chat client page URL', () => {
	it( 'sends the given page URL with the session token flow', async () => {
		const bodies = [];
		const fetch = vi.fn( async ( url, options ) => {
			if ( url === '/session' ) {
				return { ok: true, json: async () => ( { token: 't1' } ) };
			}
			bodies.push( JSON.parse( options.body ) );
			expect( options.credentials ).toBe( 'omit' );
			return {
				ok: true,
				status: 200,
				json: async () => ( { mode: 'guided', text: 'Hi' } ),
			};
		} );
		const { createSession } =
			await import( '../../assets-src/widget/lib/session' );
		const { createChat } =
			await import( '../../assets-src/widget/lib/chat' );
		const chat = createChat( {
			chatUrl: '/chat',
			session: createSession( {
				sessionUrl: '/session',
				fetch,
				wait: async () => {},
			} ),
			pageUrl: () => 'https://example.com/',
			fetch,
		} );

		const result = await chat.send( 'Hello' );

		expect( result.answer.text ).toBe( 'Hi' );
		expect( bodies[ 0 ] ).toMatchObject( {
			message: 'Hello',
			page_url: 'https://example.com/',
			token: 't1',
		} );
	} );
} );
