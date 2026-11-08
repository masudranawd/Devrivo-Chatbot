/**
 * Page-specific actions editor logic (pure functions on the page_actions
 * list and the actions list).
 */
import { uniqueId } from './actions';

export const MAX_RULES = 30;
export const MAX_RULE_ACTIONS = 5;
export const MAX_PATHS = 10;

/**
 * Adds an empty rule (it needs pages or paths and an action before saving).
 *
 * @param {Array}  rules Rules.
 * @param {string} name  Admin label.
 * @return {Array} New rules.
 */
export function addRule( rules, name ) {
	return [
		...rules,
		{
			id: uniqueId(
				name,
				rules.map( ( rule ) => rule.id ),
				'rule'
			),
			name,
			post_ids: [],
			paths: [],
			include_children: true,
			actions: [],
		},
	];
}

/**
 * Changes keys of one rule.
 *
 * @param {Array}  rules Rules.
 * @param {number} index Rule.
 * @param {Object} patch Keys to set.
 * @return {Array} New rules.
 */
export function updateRule( rules, index, patch ) {
	return rules.map( ( rule, i ) =>
		i === index ? { ...rule, ...patch } : rule
	);
}

/**
 * Paths from a textarea: one per line, trimmed, with a leading "/", without
 * repeats, at most MAX_PATHS.
 *
 * @param {string} text Text.
 * @return {string[]} Paths.
 */
export function parsePaths( text ) {
	const paths = String( text || '' )
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.map( ( line ) => ( line.startsWith( '/' ) ? line : `/${ line }` ) );
	return [ ...new Set( paths ) ].slice( 0, MAX_PATHS );
}

/**
 * Adds an action to a rule (once, up to MAX_RULE_ACTIONS).
 *
 * @param {Array}  rules Rules.
 * @param {number} index Rule.
 * @param {string} id    Action ID.
 * @return {Array} New rules.
 */
export function addRuleAction( rules, index, id ) {
	const actions = rules[ index ].actions || [];
	if (
		! id ||
		actions.includes( id ) ||
		actions.length >= MAX_RULE_ACTIONS
	) {
		return rules;
	}
	return updateRule( rules, index, { actions: [ ...actions, id ] } );
}

/**
 * Creates a question action (hidden from the normal menu) and adds it to a
 * rule.
 *
 * @param {Array}  actions  Quick actions.
 * @param {Array}  rules    Rules.
 * @param {number} index    Rule.
 * @param {string} question Question.
 * @return {{actions: Array, rules: Array, id: string}|null} New lists, or null when the question is empty or the rule is full.
 */
export function addQuestion( actions, rules, index, question ) {
	const text = String( question || '' ).trim();
	if (
		! text ||
		( rules[ index ].actions || [] ).length >= MAX_RULE_ACTIONS
	) {
		return null;
	}
	const id = uniqueId(
		text,
		actions.map( ( action ) => action.id ),
		'question'
	);
	return {
		id,
		actions: [
			...actions,
			{
				id,
				label: text.slice( 0, 80 ),
				type: 'ai_prompt',
				urgent: false,
				show_in_menu: false,
				pinned: false,
				config: { prompt: text.slice( 0, 300 ) },
			},
		],
		rules: addRuleAction( rules, index, id ),
	};
}
