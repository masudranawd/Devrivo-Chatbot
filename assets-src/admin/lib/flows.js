/**
 * Flow editor logic (pure functions on the flows map: flow ID => { title,
 * start, nodes }).
 */
import { move, uniqueId } from './actions';

export const NODE_TYPES = [
	'message',
	'choices',
	'condition',
	'action',
	'end',
];
export const MAX_FLOWS = 30;
export const MAX_NODES = 50;
export const MAX_CHOICES = 8;
export const MAX_CTAS = 3;

// Step fields that point to another step.
const REFS = [ 'next', 'then', 'else', 'unknown' ];

/**
 * A step with every key, so nothing unused keeps an old reference.
 *
 * @param {string} type Step type.
 * @param {string} text Text.
 * @return {Object} Step.
 */
export function emptyNode( type = 'message', text = '' ) {
	return {
		type,
		text,
		next: '',
		choices: [],
		condition: type === 'condition' ? 'open_now' : '',
		then: '',
		else: '',
		unknown: '',
		actions: [],
	};
}

/**
 * The next free step ID: step-1, step-2…
 *
 * @param {Object} nodes Steps.
 * @return {string} ID.
 */
export function nextNodeId( nodes ) {
	let n = 1;
	while ( nodes && nodes[ `step-${ n }` ] ) {
		n++;
	}
	return `step-${ n }`;
}

/**
 * Adds a flow with one empty step.
 *
 * @param {Object} flows Flows.
 * @param {string} title Title.
 * @return {{flows: Object, id: string}} New flows and the new flow's ID.
 */
export function addFlow( flows, title ) {
	const id = uniqueId( title, Object.keys( flows || {} ), 'flow' );
	return {
		id,
		flows: {
			...flows,
			[ id ]: {
				title,
				start: 'step-1',
				nodes: { 'step-1': emptyNode() },
			},
		},
	};
}

/**
 * Removes a flow.
 *
 * @param {Object} flows Flows.
 * @param {string} id    Flow ID.
 * @return {Object} New flows.
 */
export function removeFlow( flows, id ) {
	const next = { ...flows };
	delete next[ id ];
	return next;
}

/**
 * Changes keys of a flow.
 *
 * @param {Object} flows Flows.
 * @param {string} id    Flow ID.
 * @param {Object} patch Keys to set.
 * @return {Object} New flows.
 */
export function updateFlow( flows, id, patch ) {
	return { ...flows, [ id ]: { ...flows[ id ], ...patch } };
}

/**
 * Adds a step to a flow.
 *
 * @param {Object} flow Flow.
 * @param {string} type Step type.
 * @return {{flow: Object, id: string}} New flow and the step ID.
 */
export function addNode( flow, type = 'message' ) {
	const id = nextNodeId( flow.nodes );
	return {
		id,
		flow: { ...flow, nodes: { ...flow.nodes, [ id ]: emptyNode( type ) } },
	};
}

/**
 * Changes one key of a step.
 *
 * @param {Object}  flow   Flow.
 * @param {string}  nodeId Step ID.
 * @param {string}  key    Key.
 * @param {unknown} value  Value.
 * @return {Object} New flow.
 */
export function updateNode( flow, nodeId, key, value ) {
	return {
		...flow,
		nodes: {
			...flow.nodes,
			[ nodeId ]: { ...flow.nodes[ nodeId ], [ key ]: value },
		},
	};
}

/**
 * Changes a step's type. The text stays; fields the new type doesn't use are
 * emptied, because the server checks every reference a step holds.
 *
 * @param {Object} flow   Flow.
 * @param {string} nodeId Step ID.
 * @param {string} type   New type.
 * @return {Object} New flow.
 */
export function setNodeType( flow, nodeId, type ) {
	const old = flow.nodes[ nodeId ] || {};
	const node = emptyNode( type, old.text || '' );
	if ( type === 'message' || type === 'action' ) {
		node.next = old.next || '';
	}
	if ( type === 'choices' ) {
		node.choices = old.choices || [];
	}
	if ( type === 'condition' ) {
		node.then = old.then || '';
		node.else = old.else || '';
		node.unknown = old.unknown || '';
	}
	if ( type === 'action' ) {
		node.actions = old.actions || [];
	}
	return { ...flow, nodes: { ...flow.nodes, [ nodeId ]: node } };
}

/**
 * Removes a step and clears every reference to it in the same flow.
 *
 * @param {Object} flow   Flow.
 * @param {string} nodeId Step ID (not the start step).
 * @return {{flow: Object, cleared: Array<{node: string, field: string, index?: number, label?: string}>}} New flow and the references that were cleared.
 */
export function removeNode( flow, nodeId ) {
	const cleared = [];
	const nodes = {};

	for ( const [ id, node ] of Object.entries( flow.nodes ) ) {
		if ( id === nodeId ) {
			continue;
		}
		const copy = { ...node };
		for ( const key of REFS ) {
			if ( copy[ key ] === nodeId ) {
				copy[ key ] = '';
				cleared.push( { node: id, field: key } );
			}
		}
		copy.choices = ( node.choices || [] ).map( ( choice, index ) => {
			if ( choice.next !== nodeId ) {
				return choice;
			}
			cleared.push( {
				node: id,
				field: 'choice',
				index,
				label: choice.label,
			} );
			return { ...choice, next: '' };
		} );
		nodes[ id ] = copy;
	}

	return {
		flow: {
			...flow,
			start: flow.start === nodeId ? '' : flow.start,
			nodes,
		},
		cleared,
	};
}

/**
 * Adds an empty choice (up to MAX_CHOICES).
 *
 * @param {Object} flow   Flow.
 * @param {string} nodeId Step ID.
 * @return {Object} New flow.
 */
export function addChoice( flow, nodeId ) {
	const choices = flow.nodes[ nodeId ].choices || [];
	if ( choices.length >= MAX_CHOICES ) {
		return flow;
	}
	return updateNode( flow, nodeId, 'choices', [
		...choices,
		{ label: '', next: '', action: '', urgent: false },
	] );
}

/**
 * Changes one key of a choice.
 *
 * @param {Object}  flow   Flow.
 * @param {string}  nodeId Step ID.
 * @param {number}  index  Choice.
 * @param {string}  key    Key.
 * @param {unknown} value  Value.
 * @return {Object} New flow.
 */
export function updateChoice( flow, nodeId, index, key, value ) {
	return updateNode(
		flow,
		nodeId,
		'choices',
		( flow.nodes[ nodeId ].choices || [] ).map( ( choice, i ) =>
			i === index ? { ...choice, [ key ]: value } : choice
		)
	);
}

/**
 * Removes a choice.
 *
 * @param {Object} flow   Flow.
 * @param {string} nodeId Step ID.
 * @param {number} index  Choice.
 * @return {Object} New flow.
 */
export function removeChoice( flow, nodeId, index ) {
	return updateNode(
		flow,
		nodeId,
		'choices',
		( flow.nodes[ nodeId ].choices || [] ).filter( ( _, i ) => i !== index )
	);
}

/**
 * Moves a choice up (-1) or down (+1).
 *
 * @param {Object} flow   Flow.
 * @param {string} nodeId Step ID.
 * @param {number} index  Choice.
 * @param {number} delta  -1 or 1.
 * @return {Object} New flow.
 */
export function moveChoice( flow, nodeId, index, delta ) {
	return updateNode(
		flow,
		nodeId,
		'choices',
		move( flow.nodes[ nodeId ].choices || [], index, delta )
	);
}

/**
 * Short label for a step in selects: "step-2: Are you a new patient?".
 *
 * @param {string} id   Step ID.
 * @param {Object} node Step.
 * @return {string} Label.
 */
export function nodeLabel( id, node ) {
	const text = String( ( node && node.text ) || '' )
		.replace( /\s+/g, ' ' )
		.trim();
	return text
		? `${ id }: ${ text.length > 40 ? text.slice( 0, 39 ) + '…' : text }`
		: id;
}
