/**
 * Flow runner: walks a guided conversation and asks a view to show it.
 *
 * The conversation lives in memory only; nothing is stored or sent to the
 * server. A step guard stops runaway loops (e.g. two message steps that point
 * at each other).
 */

export const MAX_STEPS = 100;

/**
 * Creates a runner.
 *
 * The view must implement:
 * - bot( text ): Promise, shows a bot message.
 * - user( text ): shows the visitor's choice.
 * - choices( choices, onPick( choice ) ): shows quick replies.
 * - ctas( actionIds ): shows call-to-action buttons.
 * - action( actionId ): runs a quick action (may start another flow).
 * - end(): offers "Back to menu".
 *
 * @param {Object}         options          Options.
 * @param {Object}         options.flows    Normalised flows.
 * @param {() => ?boolean} options.isOpen   Whether the business is open (null = unknown).
 * @param {Object}         options.view     View (see above).
 * @param {number}         options.maxSteps Step guard.
 * @return {{start: (flowId: string, fresh?: boolean) => Promise<void>, steps: () => number}} Runner.
 */
export function createRunner( { flows, isOpen, view, maxSteps = MAX_STEPS } ) {
	let steps = 0;
	// Each start gets a new run ID, so a flow that is still "typing" stops when another starts.
	let run = 0;

	/**
	 * Starts a flow.
	 *
	 * @param {string}  flowId Flow ID.
	 * @param {boolean} fresh  New conversation (resets the step guard).
	 * @return {Promise<void>} Resolves when the flow waits for the visitor or ends.
	 */
	function start( flowId, fresh = true ) {
		run++;
		if ( fresh ) {
			steps = 0;
		}

		const flow = flows[ flowId ];
		if ( ! flow ) {
			view.end();
			return Promise.resolve();
		}

		return visit( flow, flow.start, run );
	}

	/**
	 * Runs steps until the flow waits for the visitor or ends.
	 *
	 * @param {Object} flow   Flow.
	 * @param {string} nodeId Step to run.
	 * @param {number} id     Run ID.
	 */
	async function visit( flow, nodeId, id ) {
		while ( id === run ) {
			const node = nodeId ? flow.nodes[ nodeId ] : null;
			steps++;
			if ( ! node || steps > maxSteps ) {
				view.end();
				return;
			}

			if ( node.text ) {
				await view.bot( node.text );
				if ( id !== run ) {
					return;
				}
			}

			if ( node.type === 'message' ) {
				nodeId = node.next;
			} else if ( node.type === 'condition' ) {
				nodeId = branch( node, isOpen() );
			} else if ( node.type === 'choices' ) {
				view.choices( node.choices, ( choice ) =>
					pick( flow, choice, id )
				);
				return;
			} else if ( node.type === 'action' ) {
				view.ctas( node.actions );
				if ( ! node.next ) {
					view.end();
					return;
				}
				nodeId = node.next;
			} else {
				view.end();
				return;
			}
		}
	}

	/**
	 * Handles a quick reply.
	 *
	 * @param {Object} flow   Flow.
	 * @param {Object} choice Choice.
	 * @param {number} id     Run ID the replies belong to.
	 * @return {Promise<void>|undefined} The continued flow, if any.
	 */
	function pick( flow, choice, id ) {
		if ( id !== run ) {
			return;
		}

		view.user( choice.label );

		if ( choice.action ) {
			view.action( choice.action );
		}
		// The action may have started another flow.
		if ( id !== run ) {
			return;
		}
		if ( choice.next ) {
			return visit( flow, choice.next, id );
		}
		view.end();
	}

	return { start, steps: () => steps };
}

/**
 * Next step of a condition: then (open), else (closed) or unknown (falls back to else).
 *
 * @param {Object}       node Condition step.
 * @param {boolean|null} open Whether the business is open, or null if unknown.
 * @return {string} Next step ID.
 */
export function branch( node, open ) {
	if ( open === true ) {
		return node.then;
	}
	if ( open === false ) {
		return node.else;
	}
	return node.unknown || node.else;
}
