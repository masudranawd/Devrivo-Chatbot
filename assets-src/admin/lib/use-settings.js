/**
 * Settings for one or more sections: loaded once and shared by all screens,
 * with a draft per screen, a dirty flag, save (only the changed sections)
 * and discard. Server errors are mapped to fields.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { request, toErrors } from './api';

let cache = null;
let loading = null;
const listeners = new Set();

/**
 * Loads all settings once.
 *
 * @param {boolean} force Reload even when cached.
 * @return {Promise<Object>} All settings.
 */
export function loadSettings( force = false ) {
	if ( cache && ! force ) {
		return Promise.resolve( cache );
	}
	if ( ! loading || force ) {
		loading = request( '/settings' ).then( ( settings ) => {
			publish( settings );
			return settings;
		} );
		loading.catch( () => {
			loading = null;
		} );
	}
	return loading;
}

/**
 * Stores settings returned by the server and tells every hook.
 *
 * @param {Object} settings All settings.
 */
export function publish( settings ) {
	cache = settings;
	for ( const listener of listeners ) {
		listener( settings );
	}
}

/**
 * Forgets the cache (tests).
 */
export function resetSettingsCache() {
	cache = null;
	loading = null;
}

const same = ( a, b ) => JSON.stringify( a ) === JSON.stringify( b );

/**
 * Settings hook.
 *
 * @param {string|string[]} sections Section name(s).
 * @return {Object} { ready, loadError, values, saved, all, dirty, saving, errors, setField, setSection, save, discard }.
 */
export function useSettings( sections ) {
	const names = Array.isArray( sections ) ? sections : [ sections ];
	const key = names.join( ',' );

	const [ all, setAll ] = useState( cache );
	const [ loadError, setLoadError ] = useState( '' );
	const [ drafts, setDrafts ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ errors, setErrors ] = useState( { message: '', fields: {} } );

	useEffect( () => {
		listeners.add( setAll );
		loadSettings()
			.then( setAll )
			.catch( ( error ) => setLoadError( toErrors( error ).message ) );
		return () => listeners.delete( setAll );
	}, [] );

	const values = {};
	const saved = {};
	for ( const name of names ) {
		saved[ name ] = all ? all[ name ] : undefined;
		values[ name ] =
			drafts[ name ] !== undefined ? drafts[ name ] : saved[ name ];
	}
	const changed = names.filter(
		( name ) =>
			drafts[ name ] !== undefined &&
			! same( drafts[ name ], saved[ name ] )
	);

	const setSection = useCallback( ( name, value ) => {
		setDrafts( ( current ) => ( { ...current, [ name ]: value } ) );
	}, [] );

	const setField = useCallback(
		( name, field, value ) => {
			setDrafts( ( current ) => {
				const base =
					current[ name ] !== undefined
						? current[ name ]
						: ( cache && cache[ name ] ) || {};
				return { ...current, [ name ]: { ...base, [ field ]: value } };
			} );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ key ]
	);

	const save = async () => {
		if ( ! changed.length ) {
			return true;
		}
		const body = {};
		for ( const name of changed ) {
			body[ name ] = drafts[ name ];
		}
		setSaving( true );
		try {
			const result = await request( '/settings', {
				method: 'POST',
				data: body,
			} );
			publish( result );
			setDrafts( {} );
			setErrors( { message: '', fields: {} } );
			return true;
		} catch ( error ) {
			setErrors( toErrors( error, names ) );
			return false;
		} finally {
			setSaving( false );
		}
	};

	const discard = () => {
		setDrafts( {} );
		setErrors( { message: '', fields: {} } );
	};

	return {
		ready: !! all,
		loadError,
		values,
		saved,
		all,
		changes: Object.fromEntries(
			changed.map( ( name ) => [ name, drafts[ name ] ] )
		),
		dirty: changed.length > 0,
		saving,
		errors,
		setField,
		setSection,
		save,
		discard,
	};
}
