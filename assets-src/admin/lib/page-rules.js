/**
 * Page-specific actions editor logic (pure functions on the page_actions
 * list and the actions list).
 */
import { normalizePath } from '../../widget/lib/page-actions';
import { MAX_ACTIONS, uniqueId } from './actions';

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
 * @return {{actions: Array, rules: Array, id: string}|null} New lists, or null when the question is empty, the rule is full or there are already MAX_ACTIONS actions.
 */
export function addQuestion( actions, rules, index, question ) {
	const text = String( question || '' ).trim();
	if (
		! text ||
		actions.length >= MAX_ACTIONS ||
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

/**
 * Whether an earlier rule's path always matches a path.
 *
 * @param {Object} earlier Earlier rule.
 * @param {string} path    Normalised path.
 * @return {boolean} Covered.
 */
function pathCovered( earlier, path ) {
	return ( earlier.paths || [] ).some( ( value ) => {
		const target = normalizePath( value );
		return (
			path === target ||
			( earlier.include_children !== false && path.startsWith( target ) )
		);
	} );
}

/**
 * Problems the order of the rules causes, per rule:
 * - catchAll: the path "/" with subpages matches every page, so no rule
 *   below it can apply.
 * - shadowedBy: numbers (1-based) of earlier rules that already cover every
 *   page ID and path of this rule, so it can never match; [] when it can.
 *
 * Only certain coverage counts: a page ID is covered only by the same ID in
 * an earlier rule (its path isn't known here).
 *
 * @param {Array} rules Rules.
 * @return {Array<{catchAll: boolean, shadowedBy: number[]}>} Warnings.
 */
export function ruleWarnings( rules ) {
	return rules.map( ( rule, index ) => {
		const earlier = rules.slice( 0, index );
		const ids = rule.post_ids || [];
		const paths = ( rule.paths || [] ).map( normalizePath );
		const covering = new Set();
		let covered = ids.length > 0 || paths.length > 0;

		for ( const id of ids ) {
			const by = earlier.findIndex( ( other ) =>
				( other.post_ids || [] ).includes( id )
			);
			const all = earlier.findIndex( isCatchAll );
			const found = all >= 0 ? all : by;
			if ( found < 0 ) {
				covered = false;
			} else {
				covering.add( found + 1 );
			}
		}
		for ( const path of paths ) {
			const found = earlier.findIndex( ( other ) =>
				pathCovered( other, path )
			);
			if ( found < 0 ) {
				covered = false;
			} else {
				covering.add( found + 1 );
			}
		}

		return {
			catchAll: isCatchAll( rule ),
			shadowedBy: covered
				? [ ...covering ].sort( ( a, b ) => a - b )
				: [],
		};
	} );
}

/**
 * Whether a rule matches every page ("/" with subpages).
 *
 * @param {Object} rule Rule.
 * @return {boolean} Catch-all.
 */
export function isCatchAll( rule ) {
	return (
		rule.include_children !== false &&
		( rule.paths || [] ).some( ( path ) => normalizePath( path ) === '/' )
	);
}
