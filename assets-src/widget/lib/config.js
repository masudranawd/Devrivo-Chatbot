/**
 * Validates the public config the widget fetches, so the views can trust its
 * shape. Unknown or malformed parts fall back to safe defaults.
 */
import { hexColor } from './boot';
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
];

const isObject = ( value ) =>
	!! value && typeof value === 'object' && ! Array.isArray( value );
const text = ( value ) => ( typeof value === 'string' ? value : '' );
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

	const i18n = {};
	for ( const key of I18N_KEYS ) {
		i18n[ key ] = text( rawI18n[ key ] );
	}

	const actions = ( Array.isArray( raw.actions ) ? raw.actions : [] )
		.filter(
			( a ) =>
				isObject( a ) &&
				text( a.id ) &&
				text( a.label ) &&
				text( a.type )
		)
		.map( ( a ) => ( {
			id: a.id,
			label: a.label,
			type: a.type,
			urgent: a.urgent === true,
			config: isObject( a.config ) ? a.config : {},
		} ) );

	const actionIds = new Set( actions.map( ( a ) => a.id ) );
	const offers = ( Array.isArray( raw.offers ) ? raw.offers : [] )
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
		actions,
		offers,
		i18n,
	};
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
