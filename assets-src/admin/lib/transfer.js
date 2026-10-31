/**
 * Tools logic: section names, preset "replace" preview, settings file
 * parsing and download.
 */
import { __ } from '@wordpress/i18n';

// Marker in exported files (TransferController::PLUGIN).
export const PLUGIN = 'devrivo-chatbot';
// TransferController::MAX_BYTES.
export const MAX_IMPORT_BYTES = 1048576;
// Settings::VERSION.
export const SETTINGS_VERSION = 1;

/**
 * Translated section names, in screen order.
 *
 * @return {Object<string, string>} Section => label.
 */
export function sectionLabels() {
	return {
		display: __( 'Display rules and launcher', 'devrivo-chatbot' ),
		appearance: __( 'Appearance', 'devrivo-chatbot' ),
		behaviour: __( 'Behaviour and opening hours', 'devrivo-chatbot' ),
		actions: __( 'Quick actions', 'devrivo-chatbot' ),
		flows: __( 'Guided conversations', 'devrivo-chatbot' ),
		forms: __( 'Forms', 'devrivo-chatbot' ),
		offers: __( 'Offers', 'devrivo-chatbot' ),
		knowledge: __( 'Knowledge and FAQs', 'devrivo-chatbot' ),
		ai: __( 'AI', 'devrivo-chatbot' ),
		integrations: __( 'Integrations', 'devrivo-chatbot' ),
		privacy: __( 'Privacy', 'devrivo-chatbot' ),
		general: __( 'Active preset name', 'devrivo-chatbot' ),
	};
}

/**
 * Sections a preset replaces, like PresetApplier in "replace" mode: every
 * section in the preset, plus the flows when it replaces the actions
 * without bringing flows.
 *
 * @param {Object} preset Preset from GET /presets/{id}.
 * @return {string[]} Section names.
 */
export function replacedSections( preset ) {
	const sections = Object.keys( ( preset && preset.settings ) || {} ).filter(
		( name ) => name !== 'general'
	);
	if ( sections.includes( 'actions' ) && ! sections.includes( 'flows' ) ) {
		sections.push( 'flows' );
	}
	return sections;
}

/**
 * Checks a settings file before it is sent.
 *
 * @param {string} text File contents.
 * @param {number} size File size in bytes.
 * @return {{ok: true, data: Object, sections: string[]}|{ok: false, error: string}} Result; error is
 *     "too_large", "invalid_json", "wrong_plugin", "newer" or "empty".
 */
export function parseImport( text, size ) {
	if ( size > MAX_IMPORT_BYTES ) {
		return { ok: false, error: 'too_large' };
	}
	let data;
	try {
		data = JSON.parse( text );
	} catch {
		return { ok: false, error: 'invalid_json' };
	}
	const meta = data && typeof data === 'object' ? data.meta : null;
	if ( ! meta || typeof meta !== 'object' || meta.plugin !== PLUGIN ) {
		return { ok: false, error: 'wrong_plugin' };
	}
	if ( Number( meta.settings_version ) > SETTINGS_VERSION ) {
		return { ok: false, error: 'newer' };
	}
	const known = Object.keys( sectionLabels() );
	const settings =
		data.settings && typeof data.settings === 'object' ? data.settings : {};
	const sections = known.filter( ( name ) => name in settings );
	if ( ! sections.length ) {
		return { ok: false, error: 'empty' };
	}
	return { ok: true, data, sections };
}

/**
 * Message for a parseImport() error.
 *
 * @param {string} error Error code.
 * @return {string} Message.
 */
export function importError( error ) {
	return (
		{
			too_large: __(
				'The file is too large. Settings files are at most 1 MB.',
				'devrivo-chatbot'
			),
			invalid_json: __(
				'This file is not valid JSON. Choose a file exported from DevRivo Chatbot.',
				'devrivo-chatbot'
			),
			wrong_plugin: __(
				'This is not a DevRivo Chatbot settings file.',
				'devrivo-chatbot'
			),
			newer: __(
				'This file comes from a newer version of DevRivo Chatbot. Update the plugin, then import it again.',
				'devrivo-chatbot'
			),
			empty: __(
				'The settings file is damaged: it contains no settings.',
				'devrivo-chatbot'
			),
		}[ error ] || __( 'The file could not be read.', 'devrivo-chatbot' )
	);
}

/**
 * Saves data as a JSON file in the browser.
 *
 * @param {Object} data     Data.
 * @param {string} filename File name.
 */
export function downloadJson( data, filename ) {
	const blob = new window.Blob( [ JSON.stringify( data, null, 2 ) ], {
		type: 'application/json',
	} );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.append( link );
	link.click();
	link.remove();
	window.URL.revokeObjectURL( url );
}
