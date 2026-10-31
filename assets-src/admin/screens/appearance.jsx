/**
 * Appearance: colours, position, launcher, brand, avatar, font and text size, with a
 * live preview of the unsaved changes.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	ColorPicker,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { Preview } from '../components/preview';
import { useSettings } from '../lib/use-settings';

/**
 * Thumbnail URL of an image attachment.
 *
 * @param {number} id Attachment ID.
 * @return {Promise<string>} URL or ''.
 */
async function thumbnail( id ) {
	if ( ! id ) {
		return '';
	}
	try {
		const media = await apiFetch( {
			path: `/wp/v2/media/${ id }?context=view`,
		} );
		const sizes = media?.media_details?.sizes || {};
		return ( sizes.thumbnail || {} ).source_url || media.source_url || '';
	} catch {
		return '';
	}
}

/**
 * Appearance screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Appearance( { notify, setDirty } ) {
	const settings = useSettings( [ 'display', 'appearance' ] );
	const [ avatarUrl, setAvatarUrl ] = useState( '' );
	useDirty( setDirty, settings.dirty );

	const avatarId = settings.values.appearance?.avatar_id || 0;
	useEffect( () => {
		let active = true;
		thumbnail( avatarId ).then( ( url ) => active && setAvatarUrl( url ) );
		return () => {
			active = false;
		};
	}, [ avatarId ] );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const display = settings.values.display;
	const look = settings.values.appearance;
	const fields = settings.errors.fields;
	const setDisplay = ( key ) => ( value ) =>
		settings.setField( 'display', key, value );
	const setLook = ( key ) => ( value ) =>
		settings.setField( 'appearance', key, value );

	const chooseAvatar = () => {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		const frame = window.wp.media( {
			title: __( 'Choose an avatar', 'devrivo-chatbot' ),
			button: { text: __( 'Use this image', 'devrivo-chatbot' ) },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const image = frame.state().get( 'selection' ).first().toJSON();
			setLook( 'avatar_id' )( Number( image.id ) || 0 );
		} );
		frame.open();
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />

				<BaseControl
					id="devrivo-primary-color"
					label={ __( 'Main colour', 'devrivo-chatbot' ) }
					help={ __(
						'Used for the launcher, header and buttons. Text colour is picked automatically for contrast.',
						'devrivo-chatbot'
					) }
				>
					<ColorPicker
						color={ display.primary_color }
						onChange={ setDisplay( 'primary_color' ) }
						enableAlpha={ false }
					/>
				</BaseControl>
				<FieldError error={ fields.primary_color } />

				<SelectControl
					label={ __( 'Position', 'devrivo-chatbot' ) }
					value={ display.position }
					options={ [
						{
							label: __( 'Bottom right', 'devrivo-chatbot' ),
							value: 'right',
						},
						{
							label: __( 'Bottom left', 'devrivo-chatbot' ),
							value: 'left',
						},
					] }
					onChange={ setDisplay( 'position' ) }
					__next40pxDefaultSize
				/>

				<TextControl
					label={ __( 'Launcher text', 'devrivo-chatbot' ) }
					help={ __(
						'Leave empty for the default “How can we help?”.',
						'devrivo-chatbot'
					) }
					value={ display.launcher_label }
					maxLength={ 60 }
					onChange={ setDisplay( 'launcher_label' ) }
					__next40pxDefaultSize
				/>
				<FieldError error={ fields.launcher_label } />

				<SelectControl
					label={ __( 'Colour scheme', 'devrivo-chatbot' ) }
					value={ look.color_scheme }
					options={ [
						{
							label: __(
								'Match the visitor’s device',
								'devrivo-chatbot'
							),
							value: 'auto',
						},
						{
							label: __( 'Light', 'devrivo-chatbot' ),
							value: 'light',
						},
						{
							label: __( 'Dark', 'devrivo-chatbot' ),
							value: 'dark',
						},
					] }
					onChange={ setLook( 'color_scheme' ) }
					__next40pxDefaultSize
				/>

				<RangeControl
					label={ __( 'Corner roundness', 'devrivo-chatbot' ) }
					value={ look.radius }
					min={ 0 }
					max={ 32 }
					onChange={ ( value ) =>
						setLook( 'radius' )( Number( value ) || 0 )
					}
					__next40pxDefaultSize
				/>

				<TextControl
					label={ __( 'Brand name', 'devrivo-chatbot' ) }
					help={ __(
						'Shown in the panel header. Leave empty to show “Assistant”.',
						'devrivo-chatbot'
					) }
					value={ look.brand_name }
					maxLength={ 80 }
					onChange={ setLook( 'brand_name' ) }
					__next40pxDefaultSize
				/>
				<FieldError error={ fields.brand_name } />

				<BaseControl
					id="devrivo-avatar"
					label={ __( 'Avatar', 'devrivo-chatbot' ) }
					help={ __(
						'A square image works best.',
						'devrivo-chatbot'
					) }
				>
					<div className="devrivo-avatar">
						{ avatarUrl && (
							<img
								src={ avatarUrl }
								alt=""
								width="48"
								height="48"
							/>
						) }
						<Button
							variant="secondary"
							onClick={ chooseAvatar }
							id="devrivo-avatar"
							__next40pxDefaultSize
						>
							{ avatarId
								? __( 'Change image', 'devrivo-chatbot' )
								: __( 'Choose image', 'devrivo-chatbot' ) }
						</Button>
						{ avatarId > 0 && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => setLook( 'avatar_id' )( 0 ) }
								__next40pxDefaultSize
							>
								{ __( 'Remove', 'devrivo-chatbot' ) }
							</Button>
						) }
					</div>
				</BaseControl>

				<SelectControl
					label={ __( 'Font', 'devrivo-chatbot' ) }
					value={ look.font }
					help={ __(
						'Figtree comes with the plugin and loads from your own site.',
						'devrivo-chatbot'
					) }
					options={ [
						{
							label: __( 'Figtree', 'devrivo-chatbot' ),
							value: 'figtree',
						},
						{
							label: __( 'System', 'devrivo-chatbot' ),
							value: 'system',
						},
						{
							label: __( 'Theme font', 'devrivo-chatbot' ),
							value: 'inherit',
						},
					] }
					onChange={ setLook( 'font' ) }
					__next40pxDefaultSize
				/>

				<SelectControl
					label={ __( 'Text size', 'devrivo-chatbot' ) }
					value={ look.text_size }
					options={ [
						{
							label: __( 'Small', 'devrivo-chatbot' ),
							value: 'small',
						},
						{
							label: __( 'Default', 'devrivo-chatbot' ),
							value: 'default',
						},
						{
							label: __( 'Large', 'devrivo-chatbot' ),
							value: 'large',
						},
					] }
					onChange={ setLook( 'text_size' ) }
					__next40pxDefaultSize
				/>

				<SaveBar settings={ settings } notify={ notify } />
			</div>
			<Preview changes={ settings.changes } devices />
		</div>
	);
}
