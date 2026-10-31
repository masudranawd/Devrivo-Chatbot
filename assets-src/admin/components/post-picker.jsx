/**
 * Pages and posts picker: FormTokenField with /wp/v2/search suggestions.
 * Titles are decoded to plain text and rendered as text.
 */
import apiFetch from '@wordpress/api-fetch';
import { FormTokenField } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

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
 * Picker.
 *
 * @param {Object}                  props
 * @param {string}                  props.label    Label.
 * @param {number[]}                props.ids      Selected post IDs.
 * @param {(ids: number[]) => void} props.onChange New IDs.
 * @return {Element} Field.
 */
export function PostPicker( { label, ids, onChange } ) {
	const [ labels, setLabels ] = useState( {} );
	const [ suggestions, setSuggestions ] = useState( [] );
	const timer = useRef( 0 );

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

	useEffect( () => () => window.clearTimeout( timer.current ), [] );

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

	return (
		<div>
			<FormTokenField
				label={ label }
				value={ ids.map(
					( id ) => labels[ id ] || `#${ id } (#${ id })`
				) }
				suggestions={ suggestions }
				onInputChange={ search }
				onChange={ ( tokens ) =>
					onChange( [
						...new Set( tokens.map( tokenId ).filter( Boolean ) ),
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
		</div>
	);
}
