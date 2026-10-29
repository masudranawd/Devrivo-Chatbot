/**
 * Flow runner paths and the step guard.
 */
import { describe, expect, it, vi } from 'vitest';
import {
	branch,
	createRunner,
	MAX_STEPS,
} from '../../assets-src/widget/lib/flow';

/**
 * A view that records what the runner shows.
 *
 * @return {Object} View with a `log` of calls and the last `onPick`.
 */
function recorder() {
	const view = {
		log: [],
		pick: null,
		bot: vi.fn( async ( text ) => view.log.push( [ 'bot', text ] ) ),
		user: ( text ) => view.log.push( [ 'user', text ] ),
		choices: ( choices, onPick ) => {
			view.log.push( [ 'choices', choices.map( ( c ) => c.label ) ] );
			view.pick = ( label ) =>
				onPick( choices.find( ( c ) => c.label === label ) );
		},
		ctas: ( ids ) => view.log.push( [ 'ctas', ids ] ),
		action: vi.fn( ( id ) => view.log.push( [ 'action', id ] ) ),
		end: () => view.log.push( [ 'end' ] ),
	};
	return view;
}

const node = ( type, extra = {} ) => ( {
	type,
	text: '',
	next: '',
	then: '',
	else: '',
	unknown: '',
	choices: [],
	actions: [],
	...extra,
} );

const FLOWS = {
	help: {
		title: 'Help',
		start: 'hi',
		nodes: {
			hi: node( 'message', { text: 'Hello', next: 'ask' } ),
			ask: node( 'choices', {
				text: 'What do you need?',
				choices: [
					{
						label: 'Urgent',
						next: 'check',
						action: '',
						urgent: true,
					},
					{ label: 'Call', next: '', action: 'call', urgent: false },
					{
						label: 'Both',
						next: 'bye',
						action: 'call',
						urgent: false,
					},
				],
			} ),
			check: node( 'condition', {
				then: 'open',
				else: 'closed',
				unknown: '',
			} ),
			open: node( 'action', { text: 'Call now', actions: [ 'call' ] } ),
			closed: node( 'action', {
				text: 'We are closed',
				actions: [ 'whatsapp' ],
				next: 'bye',
			} ),
			bye: node( 'end', { text: 'Take care' } ),
		},
	},
	loop: {
		start: 'a',
		nodes: {
			a: node( 'message', { text: 'A', next: 'b' } ),
			b: node( 'message', { text: 'B', next: 'a' } ),
		},
	},
};

const run = async ( isOpen, steps = [] ) => {
	const view = recorder();
	const runner = createRunner( { flows: FLOWS, isOpen: () => isOpen, view } );
	await runner.start( 'help' );
	for ( const label of steps ) {
		await view.pick( label );
	}
	return { view, runner };
};

describe( 'flow runner', () => {
	it( 'shows messages, then choices, and waits for the visitor', async () => {
		const { view } = await run( true );

		expect( view.log ).toEqual( [
			[ 'bot', 'Hello' ],
			[ 'bot', 'What do you need?' ],
			[ 'choices', [ 'Urgent', 'Call', 'Both' ] ],
		] );
	} );

	it( 'condition: open goes to "then"', async () => {
		const { view } = await run( true, [ 'Urgent' ] );

		expect( view.log.slice( 3 ) ).toEqual( [
			[ 'user', 'Urgent' ],
			[ 'bot', 'Call now' ],
			[ 'ctas', [ 'call' ] ],
			[ 'end' ],
		] );
	} );

	it( 'condition: closed goes to "else", then continues to the end step', async () => {
		const { view } = await run( false, [ 'Urgent' ] );

		expect( view.log.slice( 4 ) ).toEqual( [
			[ 'bot', 'We are closed' ],
			[ 'ctas', [ 'whatsapp' ] ],
			[ 'bot', 'Take care' ],
			[ 'end' ],
		] );
	} );

	it( 'condition: unknown falls back to "else" unless set', () => {
		expect( branch( { then: 'a', else: 'b', unknown: '' }, null ) ).toBe(
			'b'
		);
		expect( branch( { then: 'a', else: 'b', unknown: 'c' }, null ) ).toBe(
			'c'
		);
		expect( branch( { then: 'a', else: 'b', unknown: 'c' }, false ) ).toBe(
			'b'
		);
	} );

	it( 'a choice with only an action runs it and ends', async () => {
		const { view } = await run( true, [ 'Call' ] );

		expect( view.action ).toHaveBeenCalledWith( 'call' );
		expect( view.log.slice( 3 ) ).toEqual( [
			[ 'user', 'Call' ],
			[ 'action', 'call' ],
			[ 'end' ],
		] );
	} );

	it( 'a choice with an action and a next step does both', async () => {
		const { view } = await run( true, [ 'Both' ] );

		expect( view.log.slice( 3 ) ).toEqual( [
			[ 'user', 'Both' ],
			[ 'action', 'call' ],
			[ 'bot', 'Take care' ],
			[ 'end' ],
		] );
	} );

	it( 'ends safely for a missing flow or step', async () => {
		const view = recorder();
		const runner = createRunner( {
			flows: {
				broken: { start: 'nowhere', nodes: {} },
			},
			isOpen: () => null,
			view,
		} );

		await runner.start( 'missing' );
		await runner.start( 'broken' );

		expect( view.log ).toEqual( [ [ 'end' ], [ 'end' ] ] );
	} );

	it( 'stops a loop after the step guard', async () => {
		const view = recorder();
		const runner = createRunner( {
			flows: FLOWS,
			isOpen: () => null,
			view,
		} );

		await runner.start( 'loop' );

		expect( view.bot ).toHaveBeenCalledTimes( MAX_STEPS );
		expect( view.log.at( -1 ) ).toEqual( [ 'end' ] );
		expect( runner.steps() ).toBe( MAX_STEPS + 1 );
	} );

	it( 'the guard counts the whole conversation until a fresh start', async () => {
		const view = recorder();
		const runner = createRunner( {
			flows: FLOWS,
			isOpen: () => null,
			view,
			maxSteps: 3,
		} );

		await runner.start( 'help' ); // 2 steps: hi, ask.
		await runner.start( 'help', false ); // Same conversation: guard hits.
		expect( view.log.at( -1 ) ).toEqual( [ 'end' ] );

		await runner.start( 'help' ); // Fresh: allowed again.
		expect( view.log.at( -1 )[ 0 ] ).toBe( 'choices' );
	} );

	it( 'a newer flow cancels one that is still typing', async () => {
		let release;
		const view = recorder();
		view.bot = vi.fn(
			( text ) =>
				new Promise( ( resolve ) => {
					view.log.push( [ 'bot', text ] );
					release = resolve;
				} )
		);
		const runner = createRunner( {
			flows: FLOWS,
			isOpen: () => true,
			view,
		} );

		runner.start( 'help' );
		const first = release;
		runner.start( 'loop' );
		first();
		await Promise.resolve();

		expect( view.log ).toEqual( [
			[ 'bot', 'Hello' ],
			[ 'bot', 'A' ],
		] );
	} );
} );
