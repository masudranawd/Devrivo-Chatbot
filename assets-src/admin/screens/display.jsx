/**
 * Display rules: where the widget loads (all pages, only some, all but
 * some), post types and devices.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	CheckboxControl,
	FormTokenField,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

/**
 * Token label for a post. REST titles contain HTML entities (&#8217;);
 * they are decoded to plain text, which React renders as text.
 *
 * @param {Object} item Search result { id, title }.
 * @return {string} Label "Title (#12)".
 */
export function tokenLabel( item ) {
	const title =
		decodeEntities( String( item.title || '' ) ).trim() ||
		__( '(no title)', 'devrivo-chatbot' );
	return `${ title } (#${ item.id })`;
}

/**
 * Post ID from a token label, or 0.
 *
 * @param {string} token Token.
 * @return {number} ID.
 */
export function tokenId( token ) {
	const match = /\(#(\d+)\)$/.exec( String( token ) );
	return match ? Number( match[ 1 ] ) : 0;
}

/**
 * Display rules screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Display( { notify, setDirty } ) {
	const settings = useSettings( 'display' );
	const [ labels, setLabels ] = useState( {} );
	const [ suggestions, setSuggestions ] = useState( [] );
	const timer = useRef( 0 );
	useDirty( setDirty, settings.dirty );

	const rules = settings.values.display?.rules;
	const ids = rules ? rules.post_ids : [];

	// Titles for the saved IDs.
	useEffect( () => {
		const unknown = ids.filter( ( id ) => ! labels[ id ] );
		if ( ! unknown.length ) {
			return;
		}
		apiFetch( {
			path: addQueryArgs( '/wp/v2/search', {
				include: unknown.join( ',' ),
				type: 'post',
				subtype: 'any',
				per_page: 100,
			} ),
		} )
			.then( ( items ) => {
				const next = { ...labels };
				for ( const item of items ) {
					next[ item.id ] = tokenLabel( item );
				}
				setLabels( next );
			} )
			.catch( () => {} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ids.join( ',' ) ] );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const setRules = ( key, value ) =>
		settings.setField( 'display', 'rules', { ...rules, [ key ]: value } );

	const search = ( term ) => {
		window.clearTimeout( timer.current );
		if ( term.trim().length < 2 ) {
			return;
		}
		timer.current = window.setTimeout( () => {
			apiFetch( {
				path: addQueryArgs( '/wp/v2/search', {
					search: term,
					type: 'post',
					subtype: 'any',
					per_page: 20,
				} ),
			} )
				.then( ( items ) => {
					const next = { ...labels };
					for ( const item of items ) {
						next[ item.id ] = tokenLabel( item );
					}
					setLabels( next );
					setSuggestions( items.map( tokenLabel ) );
				} )
				.catch( () => setSuggestions( [] ) );
		}, 300 );
	};

	const toggleIn = ( list, value, on ) =>
		on
			? [ ...new Set( [ ...list, value ] ) ]
			: list.filter( ( item ) => item !== value );

	const fields = settings.errors.fields;

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />

			<SelectControl
				label={ __( 'Show the widget on', 'devrivo-chatbot' ) }
				value={ rules.mode }
				options={ [
					{
						label: __( 'All pages', 'devrivo-chatbot' ),
						value: 'all',
					},
					{
						label: __(
							'Only the pages and post types below',
							'devrivo-chatbot'
						),
						value: 'include',
					},
					{
						label: __(
							'All pages except those below',
							'devrivo-chatbot'
						),
						value: 'exclude',
					},
				] }
				onChange={ ( value ) => setRules( 'mode', value ) }
				__next40pxDefaultSize
			/>

			{ rules.mode !== 'all' && (
				<>
					<FormTokenField
						label={ __( 'Pages and posts', 'devrivo-chatbot' ) }
						value={ ids.map(
							( id ) => labels[ id ] || `#${ id } (#${ id })`
						) }
						suggestions={ suggestions }
						onInputChange={ search }
						onChange={ ( tokens ) =>
							setRules( 'post_ids', [
								...new Set(
									tokens.map( tokenId ).filter( Boolean )
								),
							] )
						}
						__next40pxDefaultSize
					/>
					<p className="description">
						{ __(
							'Type at least two letters of a title and choose from the list.',
							'devrivo-chatbot'
						) }
					</p>
					<FieldError error={ fields[ 'rules.post_ids' ] } />

					<fieldset className="devrivo-fieldset">
						<legend>
							{ __( 'Post types', 'devrivo-chatbot' ) }
						</legend>
						{ boot().postTypes.map( ( type ) => (
							<CheckboxControl
								key={ type.slug }
								label={ type.label }
								checked={ rules.post_types.includes(
									type.slug
								) }
								onChange={ ( on ) =>
									setRules(
										'post_types',
										toggleIn(
											rules.post_types,
											type.slug,
											on
										)
									)
								}
							/>
						) ) }
					</fieldset>
				</>
			) }

			<fieldset className="devrivo-fieldset">
				<legend>{ __( 'Devices', 'devrivo-chatbot' ) }</legend>
				<CheckboxControl
					label={ __( 'Desktop', 'devrivo-chatbot' ) }
					checked={ rules.devices.includes( 'desktop' ) }
					onChange={ ( on ) =>
						setRules(
							'devices',
							toggleIn( rules.devices, 'desktop', on )
						)
					}
				/>
				<CheckboxControl
					label={ __( 'Mobile', 'devrivo-chatbot' ) }
					checked={ rules.devices.includes( 'mobile' ) }
					onChange={ ( on ) =>
						setRules(
							'devices',
							toggleIn( rules.devices, 'mobile', on )
						)
					}
				/>
				{ rules.devices.length === 0 && (
					<p className="devrivo-field-error">
						{ __(
							'With no device ticked, the widget shows nowhere.',
							'devrivo-chatbot'
						) }
					</p>
				) }
			</fieldset>

			<SaveBar settings={ settings } notify={ notify } />
		</div>
	);
}
