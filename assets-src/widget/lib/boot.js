/**
 * Boot data (window.devrivoChatbotBoot) parsing and the device check.
 */
import { HTTP_PROTOCOLS, safeUrl } from './url';

export const MOBILE_QUERY = '(max-width: 768px)';
export const DEFAULT_COLOR = '#1e3b36';
const HEX_COLOR = /^#(?:[0-9a-f]{3}){1,2}$/i;

/**
 * Valid hex colour or the fallback.
 *
 * @param {unknown} value    Candidate.
 * @param {string}  fallback Fallback colour.
 * @return {string} Colour.
 */
export function hexColor( value, fallback = DEFAULT_COLOR ) {
	return typeof value === 'string' && HEX_COLOR.test( value )
		? value
		: fallback;
}

/**
 * Validates boot data. Returns null when it is missing or unusable.
 *
 * @param {unknown} raw window.devrivoChatbotBoot.
 * @return {Object|null} Boot data.
 */
export function parseBoot( raw ) {
	if ( ! raw || typeof raw !== 'object' ) {
		return null;
	}

	const configUrl = safeUrl( raw.configUrl, HTTP_PROTOCOLS );
	const widgetUrl = safeUrl( raw.widgetUrl, HTTP_PROTOCOLS );
	if ( ! configUrl || ! widgetUrl ) {
		return null;
	}

	const devices = Array.isArray( raw.devices )
		? raw.devices.filter( ( d ) => d === 'desktop' || d === 'mobile' )
		: [ 'desktop', 'mobile' ];

	return {
		configUrl,
		widgetUrl,
		position: raw.position === 'left' ? 'left' : 'right',
		primaryColor: hexColor( raw.primaryColor ),
		launcherLabel:
			typeof raw.launcherLabel === 'string'
				? raw.launcherLabel.trim().slice( 0, 60 )
				: '',
		devices,
	};
}

/**
 * The visitor's device class, from the viewport width.
 *
 * @param {((query: string) => {matches: boolean})|undefined} matchMedia window.matchMedia.
 * @return {string} "mobile" or "desktop".
 */
export function currentDevice( matchMedia ) {
	return typeof matchMedia === 'function' &&
		matchMedia( MOBILE_QUERY ).matches
		? 'mobile'
		: 'desktop';
}

/**
 * Whether the widget may show on this device.
 *
 * @param {string[]} devices Allowed devices.
 * @param {string}   device  Current device.
 * @return {boolean} Allowed.
 */
export function deviceAllowed( devices, device ) {
	return devices.includes( device );
}
