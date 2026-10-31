/**
 * Admin app part 2: flow editor, forms builder, offers, knowledge rebuild
 * polling, import file checks, preset apply dialog, mobile preview and the
 * credit link.
 */
import {
	act,
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
import { Preview } from '../../assets-src/admin/components/preview';
import {
	addChoice,
	addFlow,
	addNode,
	MAX_CHOICES,
	moveChoice,
	removeChoice,
	removeNode,
	setNodeType,
	updateChoice,
} from '../../assets-src/admin/lib/flows';
import {
	addForm,
	addOption,
	newField,
	removeOption,
	updateField,
	updateOption,
} from '../../assets-src/admin/lib/form-builder';
import {
	MAX_IMPORT_BYTES,
	parseImport,
	replacedSections,
} from '../../assets-src/admin/lib/transfer';
import { resetSettingsCache } from '../../assets-src/admin/lib/use-settings';
import Flows from '../../assets-src/admin/screens/flows';
import Forms from '../../assets-src/admin/screens/forms';
import {
	IndexStatus,
	POLL_MS,
	STUCK_MS,
	useIndexStatus,
} from '../../assets-src/admin/screens/knowledge';
import Offers, { addOffer } from '../../assets-src/admin/screens/offers';
import { ApplyPresetModal } from '../../assets-src/admin/screens/tools';
import { CREDIT_URL, preview } from '../../assets-src/widget/widget';

const XSS = '<img src=x onerror="window.__xss=1">';

/**
 * A flow: ask -> (yes: done, no: closed check) ; check -> then done / else done.
 *
 * @return {Object} Flow.
 */
function sampleFlow() {
	const node = ( patch ) => ( {
		type: 'message',
		text: '',
		next: '',
		choices: [],
		condition: '',
		then: '',
		else: '',
		unknown: '',
		actions: [],
		...patch,
	} );
	return {
		title: 'Help',
		start: 'ask',
		nodes: {
			ask: node( {
				type: 'choices',
				text: 'Need help?',
				choices: [
					{ label: 'Yes', next: 'done', action: '', urgent: false },
					{ label: 'No', next: 'check', action: '', urgent: false },
				],
			} ),
			check: node( {
				type: 'condition',
				condition: 'open_now',
				then: 'done',
				else: 'done',
				unknown: 'done',
			} ),
			done: node( { type: 'end', text: 'Bye' } ),
		},
	};
}

/**
 * apiFetch answering GET /settings with the given settings and recording
 * everything else.
 *
 * @param {Object} settings Settings.
 */
function mockSettings( settings ) {
	apiFetch.mockImplementation( async ( { path } ) => {
		if ( path.endsWith( '/settings' ) ) {
			return settings;
		}
		return new Promise( () => {} );
	} );
}

beforeEach( () => {
	resetSettingsCache();
	apiFetch.mockReset();
	window.__xss = undefined;
} );

afterEach( () => {
	cleanup();
	vi.useRealTimers();
} );

describe( 'flow editor logic', () => {
	it( 'adds flows and steps with generated IDs', () => {
		const first = addFlow( {}, 'Book a visit' );
		expect( first.id ).toBe( 'book-a-visit' );
		expect( first.flows[ 'book-a-visit' ].start ).toBe( 'step-1' );

		const second = addFlow( first.flows, 'Book a visit' );
		expect( second.id ).toBe( 'book-a-visit-2' );
		expect( addFlow( {}, '!!!' ).id ).toBe( 'flow' );

		const result = addNode( first.flows[ 'book-a-visit' ], 'choices' );
		expect( result.id ).toBe( 'step-2' );
		expect( result.flow.nodes[ 'step-2' ].type ).toBe( 'choices' );
	} );

	it( 'deleting a step clears every reference and reports them', () => {
		const { flow, cleared } = removeNode( sampleFlow(), 'done' );

		expect( flow.nodes.done ).toBeUndefined();
		expect( flow.nodes.ask.choices[ 0 ].next ).toBe( '' );
		expect( flow.nodes.ask.choices[ 1 ].next ).toBe( 'check' );
		expect( flow.nodes.check.then ).toBe( '' );
		expect( flow.nodes.check.else ).toBe( '' );
		expect( flow.nodes.check.unknown ).toBe( '' );
		expect( cleared ).toEqual( [
			{ node: 'ask', field: 'choice', index: 0, label: 'Yes' },
			{ node: 'check', field: 'then' },
			{ node: 'check', field: 'else' },
			{ node: 'check', field: 'unknown' },
		] );
	} );

	it( 'changing the type empties fields the new type does not use', () => {
		const flow = setNodeType( sampleFlow(), 'check', 'message' );
		expect( flow.nodes.check ).toMatchObject( {
			type: 'message',
			condition: '',
			then: '',
			else: '',
			unknown: '',
		} );
		expect(
			setNodeType( sampleFlow(), 'done', 'condition' ).nodes.done
				.condition
		).toBe( 'open_now' );
		expect(
			setNodeType( sampleFlow(), 'ask', 'end' ).nodes.ask.choices
		).toEqual( [] );
	} );

	it( 'edits, moves and removes choices, up to 8', () => {
		let flow = sampleFlow();
		flow = updateChoice( flow, 'ask', 1, 'urgent', true );
		flow = moveChoice( flow, 'ask', 1, -1 );
		expect( flow.nodes.ask.choices.map( ( c ) => c.label ) ).toEqual( [
			'No',
			'Yes',
		] );
		expect( flow.nodes.ask.choices[ 0 ].urgent ).toBe( true );

		flow = removeChoice( flow, 'ask', 0 );
		expect( flow.nodes.ask.choices ).toHaveLength( 1 );

		for ( let i = 0; i < 10; i++ ) {
			flow = addChoice( flow, 'ask' );
		}
		expect( flow.nodes.ask.choices ).toHaveLength( MAX_CHOICES );
	} );
} );

describe( 'flows screen', () => {
	it( 'sets the first step and warns which links a deleted step cleared', async () => {
		mockSettings( { flows: { help: sampleFlow() }, actions: [] } );
		const setDirty = vi.fn();
		render( <Flows notify={ () => {} } setDirty={ setDirty } /> );

		const start = await screen.findByLabelText( 'First step' );
		fireEvent.change( start, { target: { value: 'check' } } );
		expect( start.value ).toBe( 'check' );
		await waitFor( () =>
			expect( setDirty ).toHaveBeenLastCalledWith( true )
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Delete done' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Delete' } )
		);

		expect(
			await screen.findByText(
				'ask: the choice “Yes” no longer has a next step'
			)
		).toBeTruthy();
		expect(
			screen.getByText( 'check: step when open cleared' )
		).toBeTruthy();
		expect( screen.queryByRole( 'heading', { name: 'done' } ) ).toBeNull();
	} );

	it( 'cannot delete the first step', async () => {
		mockSettings( { flows: { help: sampleFlow() }, actions: [] } );
		render( <Flows notify={ () => {} } setDirty={ () => {} } /> );

		const button = await screen.findByRole( 'button', {
			name: /Choose another first step/,
		} );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	} );

	it( 'renders step text as text', async () => {
		const flow = sampleFlow();
		flow.nodes.done.text = XSS;
		mockSettings( { flows: { help: flow }, actions: [] } );
		const { container } = render(
			<Flows notify={ () => {} } setDirty={ () => {} } />
		);

		await screen.findByLabelText( 'First step' );
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( window.__xss ).toBeUndefined();
	} );
} );

describe( 'forms builder', () => {
	it( 'adds a form with a required email field', () => {
		const { forms, id } = addForm( {}, 'Quote request', 'Email' );
		expect( id ).toBe( 'quote-request' );
		expect( forms[ id ].fields ).toEqual( [
			expect.objectContaining( {
				id: 'email',
				type: 'email',
				required: true,
				options: [],
			} ),
		] );
	} );

	it( 'never generates reserved or duplicate field IDs', () => {
		const fields = [ newField( [], 'text', 'Name' ) ];
		expect( newField( fields, 'text', 'Name' ).id ).toBe( 'name-2' );
		expect( newField( fields, 'text', 'Consent' ).id ).toBe( 'consent-2' );
		expect( newField( fields, 'text', 'Website' ).id ).toBe( 'website-2' );
	} );

	it( 'manages dropdown options', () => {
		let fields = [ newField( [], 'text', 'Service' ) ];
		fields = updateField( fields, 0, 'type', 'select', 'Option 1' );
		expect( fields[ 0 ].options ).toEqual( [ 'Option 1' ] );

		fields = addOption( fields, 0, 'Option 2' );
		fields = updateOption( fields, 0, 1, 'Cleaning' );
		expect( fields[ 0 ].options ).toEqual( [ 'Option 1', 'Cleaning' ] );
		fields = removeOption( fields, 0, 0 );
		expect( fields[ 0 ].options ).toEqual( [ 'Cleaning' ] );

		for ( let i = 0; i < 30; i++ ) {
			fields = addOption( fields, 0, `More ${ i }` );
		}
		expect( fields[ 0 ].options ).toHaveLength( 20 );

		fields = updateField( fields, 0, 'type', 'text' );
		expect( fields[ 0 ].options ).toEqual( [] );
	} );

	it( 'adds, moves and removes fields in the screen', async () => {
		mockSettings( {
			forms: {
				contact: {
					title: 'Contact',
					submit_label: '',
					success_message: '',
					fields: [
						{
							id: 'email',
							type: 'email',
							label: 'Email',
							required: true,
							placeholder: '',
							max_length: 200,
							options: [],
						},
					],
				},
			},
		} );
		render( <Forms notify={ () => {} } setDirty={ () => {} } /> );

		await screen.findByText( 'ID: contact' );
		const add = screen.getByRole( 'group', { name: 'Add a field' } );
		fireEvent.change( within( add ).getByLabelText( 'Type' ), {
			target: { value: 'select' },
		} );
		fireEvent.change( within( add ).getByLabelText( 'Label' ), {
			target: { value: 'Service' },
		} );
		fireEvent.click(
			within( add ).getByRole( 'button', { name: 'Add field' } )
		);

		const service = await screen.findByRole( 'group', { name: 'Service' } );
		expect( within( service ).getByText( 'ID: service' ) ).toBeTruthy();
		expect(
			within( service ).getByDisplayValue( 'Option 1' )
		).toBeTruthy();
		fireEvent.click(
			within( service ).getByRole( 'button', { name: 'Add an option' } )
		);
		expect(
			within( service ).getByDisplayValue( 'Option 2' )
		).toBeTruthy();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move “Service” up' } )
		);
		const headings = screen
			.getAllByRole( 'heading', { level: 3 } )
			.map( ( h ) => h.textContent );
		expect( headings[ 0 ] ).toContain( 'Service' );

		fireEvent.click(
			within( screen.getByRole( 'group', { name: 'Email' } ) ).getByRole(
				'button',
				{ name: 'Remove' }
			)
		);
		expect( screen.queryByRole( 'group', { name: 'Email' } ) ).toBeNull();
		expect(
			screen.getByText( /A consent checkbox is added to every form/ )
		).toBeTruthy();
	} );
} );

describe( 'offers', () => {
	it( 'adds offers with unique IDs', () => {
		const list = addOffer( addOffer( [], 'Spring sale' ), 'Spring sale' );
		expect( list.map( ( o ) => o.id ) ).toEqual( [
			'spring-sale',
			'spring-sale-2',
		] );
		expect( list[ 0 ] ).toMatchObject( {
			text: '',
			cta_label: '',
			action: '',
			featured: false,
		} );
	} );

	it( 'edits, moves and limits offers to 6', async () => {
		const offers = [ 'A', 'B' ].map( ( title ) => ( {
			id: title.toLowerCase(),
			title,
			text: '',
			cta_label: '',
			action: '',
			featured: false,
		} ) );
		mockSettings( {
			offers,
			actions: [ { id: 'call', label: 'Call us', type: 'call' } ],
		} );
		render( <Offers notify={ () => {} } setDirty={ () => {} } /> );

		await screen.findByRole( 'group', { name: 'A' } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Move “B” up' } )
		);
		expect(
			screen
				.getAllByRole( 'heading', { level: 3 } )
				.map( ( h ) => h.textContent )
		).toEqual( [ 'B', 'A' ] );

		const a = screen.getByRole( 'group', { name: 'A' } );
		fireEvent.change( within( a ).getByLabelText( 'Button runs' ), {
			target: { value: 'call' },
		} );
		expect( within( a ).getByLabelText( 'Button runs' ).value ).toBe(
			'call'
		);

		const add = screen.getByRole( 'group', { name: 'Add an offer' } );
		for ( const title of [ 'C', 'D', 'E', 'F' ] ) {
			fireEvent.change( within( add ).getByLabelText( 'Title' ), {
				target: { value: title },
			} );
			fireEvent.click(
				within( add ).getByRole( 'button', { name: 'Add' } )
			);
		}
		expect( screen.getAllByRole( 'heading', { level: 3 } ) ).toHaveLength(
			6
		);
		expect(
			within( add )
				.getByRole( 'button', { name: 'Add' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );
		expect(
			screen.getByText( 'You can have up to 6 offers.' )
		).toBeTruthy();
	} );
} );

describe( 'knowledge rebuild polling', () => {
	/**
	 * Renders the status card with its hook.
	 */
	function Probe() {
		const index = useIndexStatus();
		return <IndexStatus index={ index } />;
	}

	const status = ( rebuild ) => ( {
		driver: 'fulltext',
		posts: 3,
		chunks: 9,
		faqs: 1,
		rebuild: {
			status: 'idle',
			total: 0,
			processed: 0,
			last_id: 0,
			started_at: null,
			finished_at: null,
			...rebuild,
		},
	} );

	it( 'polls every 3 s while running and explains a stuck rebuild', async () => {
		vi.useFakeTimers( {
			toFake: [ 'setTimeout', 'clearTimeout', 'Date' ],
		} );
		let calls = 0;
		apiFetch.mockImplementation( async ( { path, method } ) => {
			calls++;
			expect( path ).toMatch( /\/knowledge\/(status|rebuild)$/ );
			if ( method === 'POST' ) {
				return status( { status: 'running' } );
			}
			return status( { status: calls === 1 ? 'idle' : 'running' } );
		} );

		render( <Probe /> );
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 0 );
		} );
		expect(
			screen.getByText( '3 pages and posts, 1 FAQs indexed.' )
		).toBeTruthy();
		expect( calls ).toBe( 1 );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Rebuild the index' } )
			);
			await vi.advanceTimersByTimeAsync( 0 );
		} );
		expect( calls ).toBe( 2 );
		expect( screen.getByText( 'Rebuilding: starting…' ) ).toBeTruthy();

		await act( async () => {
			await vi.advanceTimersByTimeAsync( POLL_MS );
		} );
		expect( calls ).toBe( 3 );
		expect( screen.queryByText( /WP-Cron/ ) ).toBeNull();

		// Each poll schedules the next after React re-renders.
		for ( let waited = POLL_MS; waited < STUCK_MS; waited += POLL_MS ) {
			await act( async () => {
				await vi.advanceTimersByTimeAsync( POLL_MS );
			} );
		}
		expect( calls ).toBeGreaterThanOrEqual( 3 + STUCK_MS / POLL_MS - 1 );
		expect( screen.getAllByText( /WP-Cron/ ).length ).toBeGreaterThan( 0 );
	} );

	it( 'shows progress and stops polling when done', async () => {
		vi.useFakeTimers( {
			toFake: [ 'setTimeout', 'clearTimeout', 'Date' ],
		} );
		const answers = [
			status( { status: 'running', total: 10, processed: 4 } ),
			status( { status: 'done', total: 10, processed: 10 } ),
		];
		let calls = 0;
		apiFetch.mockImplementation(
			async () => answers[ Math.min( calls++, answers.length - 1 ) ]
		);

		const { container } = render( <Probe /> );
		await act( async () => {
			await vi.advanceTimersByTimeAsync( 0 );
		} );
		expect( screen.getByText( 'Rebuilding: 4 of 10 done…' ) ).toBeTruthy();
		expect(
			screen.getByRole( 'progressbar' ).getAttribute( 'aria-valuenow' )
		).toBe( '40' );

		await act( async () => {
			await vi.advanceTimersByTimeAsync( POLL_MS );
		} );
		expect( screen.getByText( 'The last rebuild finished.' ) ).toBeTruthy();

		await act( async () => {
			await vi.advanceTimersByTimeAsync( POLL_MS * 5 );
		} );
		expect( calls ).toBe( 2 );
		expect( container.textContent ).not.toContain( 'WP-Cron' );
	} );
} );

describe( 'import file checks', () => {
	const file = ( meta, settings = { appearance: { brand_name: 'X' } } ) =>
		JSON.stringify( { meta, settings } );
	const meta = { plugin: 'devrivo-chatbot', settings_version: 1 };

	it( 'accepts an exported file and lists its sections', () => {
		const result = parseImport(
			file( meta, { forms: {}, appearance: {}, unknown: {} } ),
			100
		);
		expect( result.ok ).toBe( true );
		expect( result.sections ).toEqual( [ 'appearance', 'forms' ] );
	} );

	it( 'rejects invalid JSON, large files and other plugins', () => {
		expect( parseImport( '{nope', 10 ) ).toEqual( {
			ok: false,
			error: 'invalid_json',
		} );
		expect( parseImport( file( meta ), MAX_IMPORT_BYTES + 1 ) ).toEqual( {
			ok: false,
			error: 'too_large',
		} );
		expect(
			parseImport( file( { ...meta, plugin: 'other' } ), 10 ).error
		).toBe( 'wrong_plugin' );
		expect( parseImport( '[]', 2 ).error ).toBe( 'wrong_plugin' );
		expect( parseImport( 'null', 4 ).error ).toBe( 'wrong_plugin' );
		expect(
			parseImport( file( { ...meta, settings_version: 2 } ), 10 ).error
		).toBe( 'newer' );
		expect( parseImport( file( meta, {} ), 10 ).error ).toBe( 'empty' );
	} );
} );

describe( 'preset apply dialog', () => {
	const preset = {
		id: 'agency',
		settings: {
			appearance: { brand_name: 'Agency' },
			actions: [],
			general: {},
		},
	};

	it( 'lists replaced sections like the server does', () => {
		expect( replacedSections( preset ) ).toEqual( [
			'appearance',
			'actions',
			'flows',
		] );
		expect(
			replacedSections( {
				settings: { actions: [], flows: {} },
			} )
		).toEqual( [ 'actions', 'flows' ] );
		expect( replacedSections( null ) ).toEqual( [] );
	} );

	it( 'merges by default and lists exactly what Replace overwrites', () => {
		const onApply = vi.fn();
		render(
			<ApplyPresetModal
				preset={ preset }
				title="Agency"
				busy={ false }
				onApply={ onApply }
				onCancel={ () => {} }
			/>
		);

		expect( screen.getByLabelText( 'Merge (recommended)' ).checked ).toBe(
			true
		);
		expect( screen.queryByTestId( 'replaced-sections' ) ).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Merge' } ) );
		expect( onApply ).toHaveBeenLastCalledWith( 'merge' );

		fireEvent.click( screen.getByLabelText( 'Replace' ) );
		const list = screen.getByTestId( 'replaced-sections' );
		expect(
			within( list )
				.getAllByRole( 'listitem' )
				.map( ( li ) => li.textContent )
		).toEqual( [ 'Appearance', 'Quick actions', 'Guided conversations' ] );
		expect(
			screen.getAllByText( /will be overwritten/ ).length
		).toBeGreaterThan( 0 );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Replace sections' } )
		);
		expect( onApply ).toHaveBeenLastCalledWith( 'replace' );
	} );
} );

describe( 'widget preview: mobile, flows, forms and credit link', () => {
	const config = ( extra = {} ) => ( {
		actions: [],
		forms: {
			contact: {
				title: 'Contact',
				fields: [
					{
						id: 'email',
						type: 'email',
						label: 'Your email',
						required: true,
						max_length: 200,
						options: [],
					},
				],
			},
		},
		flows: {
			help: {
				title: 'Help',
				start: 'hi',
				nodes: {
					hi: {
						type: 'end',
						text: 'Hello from the flow',
						choices: [],
						actions: [],
					},
				},
			},
		},
		i18n: {
			powered_by: 'Powered by DevRivo',
			new_tab: '(opens in a new tab)',
		},
		...extra,
	} );

	const mount = ( raw, options = {} ) => {
		const host = document.createElement( 'div' );
		document.body.append( host );
		return {
			host,
			widget: preview( host, raw, { note: 'Preview only', ...options } ),
		};
	};

	it( 'marks the phone-sized preview', () => {
		const { host, widget } = mount( config(), { mobile: true } );
		expect(
			host.shadowRoot.querySelector( '.dc' ).hasAttribute( 'data-mobile' )
		).toBe( true );
		widget.destroy();

		const desktop = mount( config() );
		expect(
			desktop.host.shadowRoot
				.querySelector( '.dc' )
				.hasAttribute( 'data-mobile' )
		).toBe( false );
		desktop.widget.destroy();
	} );

	it( 'runs a flow and shows a form without taking focus', async () => {
		const outside = document.createElement( 'input' );
		document.body.append( outside );
		outside.focus();

		const { host, widget } = mount( config(), {} );
		widget.form( 'contact' );
		expect( widget.view() ).toBe( 'chat' );
		expect( host.shadowRoot.textContent ).toContain( 'Your email' );
		expect( document.activeElement ).toBe( outside );

		widget.flow( 'help' );
		await waitFor( () =>
			expect( host.shadowRoot.textContent ).toContain(
				'Hello from the flow'
			)
		);
		expect( document.activeElement ).toBe( outside );
		widget.destroy();
		outside.remove();
	} );

	it( 'shows the credit link only when turned on, as text', () => {
		const off = mount( config() );
		expect( off.host.shadowRoot.querySelector( '.dc-credit' ) ).toBeNull();
		off.widget.destroy();

		const truthy = mount( config( { credit_link: 'true' } ) );
		expect(
			truthy.host.shadowRoot.querySelector( '.dc-credit' )
		).toBeNull();
		truthy.widget.destroy();

		const on = mount(
			config( {
				credit_link: true,
				i18n: { powered_by: XSS, new_tab: '(new tab)' },
			} )
		);
		const link = on.host.shadowRoot.querySelector( '.dc-credit a' );
		expect( link.getAttribute( 'href' ) ).toBe( CREDIT_URL );
		expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
		expect( link.getAttribute( 'rel' ) ).toBe( 'noopener' );
		expect( link.textContent ).toBe( `${ XSS } (new tab)` );
		expect( on.host.shadowRoot.querySelector( 'img' ) ).toBeNull();
		expect( window.__xss ).toBeUndefined();
		on.widget.destroy();
	} );
} );

describe( 'preview panel size toggle', () => {
	it( 'switches the stage to 375 px mobile', async () => {
		apiFetch.mockImplementation( () => new Promise( () => {} ) );
		render( <Preview changes={ {} } devices /> );

		const desktop = screen.getByRole( 'button', { name: 'Desktop' } );
		const mobile = screen.getByRole( 'button', { name: 'Mobile' } );
		expect( desktop.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( mobile.getAttribute( 'aria-pressed' ) ).toBe( 'false' );

		fireEvent.click( mobile );
		expect( mobile.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect(
			document
				.querySelector( '.devrivo-preview__stage' )
				.classList.contains( 'devrivo-preview__stage--mobile' )
		).toBe( true );
	} );

	it( 'has no toggle unless asked for', () => {
		apiFetch.mockImplementation( () => new Promise( () => {} ) );
		render( <Preview changes={ {} } /> );
		expect( screen.queryByRole( 'button', { name: 'Mobile' } ) ).toBeNull();
	} );
} );
