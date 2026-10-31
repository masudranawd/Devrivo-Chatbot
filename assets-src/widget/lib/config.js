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
	'required',
	'submit',
	'sending',
	'sent',
	'form_success',
	'field_required',
	'invalid_email',
	'invalid_phone',
	'invalid_date',
	'invalid_option',
	'too_long',
	'consent_needed',
	'form_error',
	'network_error',
	'rate_limited',
	'new_tab',
	'choose',
	'ask_prompt',
	'search_found',
	'no_results',
	'read_more',
	'search_limited',
	'search_error',
	'ai_tag',
	'chat_limited',
	'chat_error',
	'powered_by',
];

const FIELD_TYPES = [ 'text', 'email', 'tel', 'textarea', 'select', 'date' ];

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
	const forms = normalizeForms( raw.forms );
	const consent = isObject( raw.consent ) ? raw.consent : {};
	const searchUrl = safeUrl( raw.search_url, HTTP_PROTOCOLS );
	const searchEnabled = raw.search_enabled === true && !! searchUrl;
	const chatUrl = safeUrl( raw.chat_url, HTTP_PROTOCOLS );

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
				// Flow and form actions need their flow or form.
				( a.type !== 'flow' ||
					( isObject( a.config ) &&
						isObject( rawFlows[ a.config.flow ] ) ) ) &&
				( a.type !== 'form' ||
					( isObject( a.config ) && !! forms[ a.config.form ] ) ) &&
				// Search actions need something to search.
				( a.type !== 'page_search' || searchEnabled )
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
		// AI answers in the composer (otherwise the composer gives Guided answers).
		aiAvailable: raw.ai_available === true && !! chatUrl,
		disclaimer: text( raw.disclaimer ),
		chatUrl,
		searchEnabled,
		searchUrl,
		fallbackAction: actionIds.has( raw.fallback_action )
			? raw.fallback_action
			: '',
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
		forms,
		consent: {
			text: text( consent.text ),
			policyUrl: safeUrl( consent.policy_url, HTTP_PROTOCOLS ),
			policyLabel: text( consent.policy_label ),
		},
		sessionUrl: safeUrl( raw.session_url, HTTP_PROTOCOLS ),
		submitUrl: safeUrl( raw.submit_url, HTTP_PROTOCOLS ),
		offers,
		// "Powered by DevRivo" link in the panel footer (opt-in).
		creditLink: raw.credit_link === true,
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
 * Forms. Null-prototype map, like flows.
 *
 * @param {unknown} raw Forms from the config.
 * @return {Object} Forms keyed by ID.
 */
function normalizeForms( raw ) {
	const forms = Object.create( null );

	for ( const [ id, form ] of Object.entries( isObject( raw ) ? raw : {} ) ) {
		if ( ! isObject( form ) || ! text( form.title ) ) {
			continue;
		}
		const fields = list( form.fields )
			.filter(
				( f ) =>
					isObject( f ) &&
					text( f.id ) &&
					text( f.label ) &&
					FIELD_TYPES.includes( f.type )
			)
			.map( ( f ) => ( {
				id: f.id,
				type: f.type,
				label: f.label,
				required: f.required === true,
				placeholder: text( f.placeholder ),
				maxLength: clamp( f.max_length, 1, 2000, 200 ),
				options: list( f.options ).filter(
					( o ) => typeof o === 'string'
				),
			} ) );

		if ( fields.length ) {
			forms[ id ] = {
				id,
				title: form.title,
				submitLabel: text( form.submit_label ),
				successMessage: text( form.success_message ),
				fields,
			};
		}
	}

	return forms;
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
