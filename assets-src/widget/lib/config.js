/**
 * Validates the public config the widget fetches, so the views can trust its
 * shape. Unknown or malformed parts fall back to safe defaults.
 */
import { hexColor } from './boot';
import { DAYS } from './hours';
import { HTTP_PROTOCOLS, safeUrl } from './url';

const I18N_KEYS = [
	'assistant',
	'close',
	'close_menu',
	'back',
	'home',
	'offers',
	'menu',
	'ask',
	'ask_hint',
	'send',
	'message_label',
	'placeholder',
	'welcome',
	'offer_cta',
	'good_morning',
	'good_afternoon',
	'good_evening',
	'back_to_menu',
	'open_now',
	'closed_now',
];

const NODE_TYPES = [ 'message', 'choices', 'condition', 'action', 'end' ];
const TIME = /^([01][0-9]|2[0-3]):[0-5][0-9]$/;

const isObject = ( value ) =>
	!! value && typeof value === 'object' && ! Array.isArray( value );
const text = ( value ) => ( typeof value === 'string' ? value : '' );
const list = ( value ) => ( Array.isArray( value ) ? value : [] );
const clamp = ( value, min, max, fallback ) =>
	Number.isFinite( value )
		? Math.min( max, Math.max( min, value ) )
		: fallback;

/**
 * Normalised config.
 *
 * @param {unknown} raw  Parsed JSON from the config endpoint.
 * @param {Object}  boot Parsed boot data (fallbacks for display values).
 * @return {Object} Config.
 * @throws {TypeError} When the response is not a config object.
 */
export function normalizeConfig( raw, boot ) {
	if ( ! isObject( raw ) ) {
		throw new TypeError( 'Invalid config' );
	}

	const display = isObject( raw.display ) ? raw.display : {};
	const appearance = isObject( raw.appearance ) ? raw.appearance : {};
	const behaviour = isObject( raw.behaviour ) ? raw.behaviour : {};
	const rawI18n = isObject( raw.i18n ) ? raw.i18n : {};
	const rawFlows = isObject( raw.flows ) ? raw.flows : {};

	const i18n = {};
	for ( const key of I18N_KEYS ) {
		i18n[ key ] = text( rawI18n[ key ] );
	}

	const actions = list( raw.actions )
		.filter(
			( a ) =>
				isObject( a ) &&
				text( a.id ) &&
				text( a.label ) &&
				text( a.type ) &&
				// A flow action needs its flow.
				( a.type !== 'flow' ||
					( isObject( a.config ) &&
						isObject( rawFlows[ a.config.flow ] ) ) )
		)
		.map( ( a ) => ( {
			id: a.id,
			label: a.label,
			type: a.type,
			urgent: a.urgent === true,
			showInMenu: a.show_in_menu !== false,
			config: isObject( a.config ) ? a.config : {},
		} ) );

	const actionIds = new Set( actions.map( ( a ) => a.id ) );
	const offers = list( raw.offers )
		.filter( ( o ) => isObject( o ) && text( o.id ) && text( o.title ) )
		.map( ( o ) => ( {
			id: o.id,
			title: o.title,
			text: text( o.text ),
			ctaLabel: text( o.cta_label ),
			action: actionIds.has( o.action ) ? o.action : '',
			featured: o.featured === true,
		} ) );

	return {
		chatEnabled: raw.chat_enabled === true,
		position: [ 'left', 'right' ].includes( display.position )
			? display.position
			: boot.position,
		primaryColor: hexColor( display.primary_color, boot.primaryColor ),
		launcherLabel: text( display.launcher_label ) || boot.launcherLabel,
		colorScheme: [ 'light', 'dark' ].includes( appearance.color_scheme )
			? appearance.color_scheme
			: 'auto',
		radius: clamp( appearance.radius, 0, 32, 22 ),
		brandName: text( appearance.brand_name ),
		font: appearance.font === 'inherit' ? 'inherit' : 'system',
		avatarUrl: safeUrl( appearance.avatar_url, HTTP_PROTOCOLS ),
		greeting: text( behaviour.greeting ),
		welcome: text( behaviour.welcome ),
		autoOpen: behaviour.auto_open === true,
		autoOpenDelay: clamp( behaviour.auto_open_delay, 0, 60000, 800 ),
		hours: normalizeHours( behaviour.hours ),
		utm: behaviour.utm !== false,
		actions,
		flows: normalizeFlows( rawFlows, actionIds ),
		offers,
		i18n,
	};
}

/**
 * Opening hours.
 *
 * @param {unknown} raw Hours from the config.
 * @return {{timezone: string, schedule: Object, showStatus: boolean}} Hours.
 */
function normalizeHours( raw ) {
	const hours = isObject( raw ) ? raw : {};
	const schedule = isObject( hours.schedule ) ? hours.schedule : {};
	const days = {};

	for ( const day of DAYS ) {
		days[ day ] = list( schedule[ day ] )
			.filter(
				( r ) =>
					isObject( r ) &&
					TIME.test( text( r.open ) ) &&
					TIME.test( text( r.close ) )
			)
			.map( ( r ) => ( { open: r.open, close: r.close } ) );
	}

	return {
		timezone: text( hours.timezone ),
		schedule: days,
		showStatus: hours.show_status === true,
	};
}

/**
 * Flows. Maps use null-prototype objects, so IDs can never touch
 * Object.prototype. Choices and buttons that point to unknown actions are
 * dropped (a choice with a next step keeps it).
 *
 * @param {Object}      raw       Flows from the config.
 * @param {Set<string>} actionIds Known action IDs.
 * @return {Object} Flows.
 */
function normalizeFlows( raw, actionIds ) {
	const flows = Object.create( null );

	for ( const [ id, flow ] of Object.entries( raw ) ) {
		if ( ! isObject( flow ) || ! isObject( flow.nodes ) ) {
			continue;
		}

		const nodes = Object.create( null );
		for ( const [ nodeId, node ] of Object.entries( flow.nodes ) ) {
			if ( ! isObject( node ) || ! NODE_TYPES.includes( node.type ) ) {
				continue;
			}
			nodes[ nodeId ] = {
				type: node.type,
				text: text( node.text ),
				next: text( node.next ),
				then: text( node.then ),
				else: text( node.else ),
				unknown: text( node.unknown ),
				choices: list( node.choices )
					.filter( ( c ) => isObject( c ) && text( c.label ) )
					.map( ( c ) => ( {
						label: c.label,
						next: text( c.next ),
						action: actionIds.has( c.action ) ? c.action : '',
						urgent: c.urgent === true,
					} ) )
					.filter( ( c ) => c.next || c.action ),
				actions: list( node.actions ).filter( ( a ) =>
					actionIds.has( a )
				),
			};
		}

		flows[ id ] = {
			title: text( flow.title ),
			start: text( flow.start ),
			nodes,
		};
	}

	return flows;
}

/**
 * Time-of-day greeting, used when the admin set none.
 *
 * @param {number} hour Local hour 0-23.
 * @param {Object} i18n Interface strings.
 * @return {string} Greeting.
 */
export function timeGreeting( hour, i18n ) {
	if ( hour < 12 ) {
		return i18n.good_morning;
	}
	return hour < 18 ? i18n.good_afternoon : i18n.good_evening;
}
