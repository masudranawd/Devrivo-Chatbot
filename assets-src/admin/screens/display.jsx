/**
 * Display rules: where the widget loads (all pages, only some, all but
 * some), post types and devices.
 */
import { CheckboxControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { PostPicker } from '../components/post-picker';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

// Kept here too for existing imports.
export { tokenId, tokenLabel } from '../components/post-picker';

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
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const rules = settings.values.display.rules;
	const setRules = ( key, value ) =>
		settings.setField( 'display', 'rules', { ...rules, [ key ]: value } );

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
					<PostPicker
						label={ __( 'Pages and posts', 'devrivo-chatbot' ) }
						ids={ rules.post_ids }
						onChange={ ( ids ) => setRules( 'post_ids', ids ) }
					/>
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
