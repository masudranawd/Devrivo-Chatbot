/**
 * Action executors: turn an action into the URL the browser should open.
 * Every URL is validated; anything unsafe returns null and does nothing.
 */
import { HTTP_PROTOCOLS, safeUrl } from './url';

const UTM = {
	utm_source: 'devrivo-chatbot',
	utm_medium: 'chat',
	utm_campaign: 'quick-action',
};

const EMAIL = /^[^\s@?&#]+@[^\s@?&#]+\.[^\s@?&#]+$/;

/**
 * Adds UTM parameters to a URL, keeping its query, hash and any UTM values
 * it already has.
 *
 * @param {string} href     Absolute http(s) URL.
 * @param {string} actionId Action ID (utm_content).
 * @return {string} URL with UTM parameters.
 */
export function withUtm( href, actionId ) {
	const url = new URL( href );
	const params = { ...UTM, utm_content: actionId };

	for ( const [ key, value ] of Object.entries( params ) ) {
		if ( ! url.searchParams.has( key ) ) {
			url.searchParams.set( key, value );
		}
	}

	return url.href;
}

/**
 * tel: URL with spaces removed, or '' when the number is unusable.
 *
 * @param {string} phone Phone number.
 * @return {string} URL.
 */
export function telHref( phone ) {
	const number = String( phone || '' ).replace( /\s+/g, '' );

	return /^\+?[0-9()-]{3,30}$/.test( number ) ? `tel:${ number }` : '';
}

/**
 * WhatsApp click-to-chat URL, or '' when the number is unusable.
 *
 * @param {string} phone   Phone number (digits are kept).
 * @param {string} message Optional pre-filled message.
 * @return {string} URL.
 */
export function whatsappHref( phone, message = '' ) {
	const digits = String( phone || '' ).replace( /\D+/g, '' );
	if ( digits.length < 6 ) {
		return '';
	}

	return message
		? `https://wa.me/${ digits }?text=${ encodeURIComponent( message ) }`
		: `https://wa.me/${ digits }`;
}

/**
 * mailto: URL, or '' when the address is unusable.
 *
 * @param {string} email   Email address.
 * @param {string} subject Optional subject.
 * @return {string} URL.
 */
export function mailtoHref( email, subject = '' ) {
	const address = String( email || '' ).trim();
	if ( ! EMAIL.test( address ) ) {
		return '';
	}

	return subject
		? `mailto:${ address }?subject=${ encodeURIComponent( subject ) }`
		: `mailto:${ address }`;
}

/**
 * Where an action leads, for actions the browser opens directly.
 *
 * @param {Object}  action Normalised action.
 * @param {boolean} utm    Add UTM parameters to links.
 * @return {{href: string, newTab: boolean}|null} Target, or null (flows, unsafe or empty values).
 */
export function actionTarget( action, utm = false ) {
	const config = action.config || {};
	let href = '';
	let newTab = false;

	switch ( action.type ) {
		case 'link':
			href = safeUrl( config.url, HTTP_PROTOCOLS );
			if ( href && utm ) {
				href = withUtm( href, action.id );
			}
			newTab = config.new_tab !== false;
			break;
		case 'call':
			href = telHref( config.phone );
			break;
		case 'whatsapp':
			href = whatsappHref( config.phone, config.message );
			newTab = true;
			break;
		case 'email':
			href = mailtoHref( config.email, config.subject );
			break;
	}

	return href ? { href, newTab } : null;
}

/**
 * Opens a target from script (used for flow choices, which are buttons).
 *
 * @param {{href: string, newTab: boolean}} target Target.
 */
export function openTarget( target ) {
	if ( target.newTab ) {
		window.open( target.href, '_blank', 'noopener' );
	} else {
		window.location.assign( target.href );
	}
}
