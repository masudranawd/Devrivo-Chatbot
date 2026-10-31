/**
 * Quick actions: ordered list with move up/down, add, edit and delete.
 * Config fields come from the action types in the boot data.
 */
import {
	Button,
	Card,
	CardBody,
	CheckboxControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ConfirmModal,
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import {
	addAction,
	missing,
	move,
	removeAction,
	updateAction,
} from '../lib/actions';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

/**
 * Translated names of action types and config fields.
 *
 * @return {{types: Object<string, string>, fields: Object<string, string>}} Labels.
 */
export function labels() {
	return {
		types: {
			link: __( 'Link', 'devrivo-chatbot' ),
			call: __( 'Phone call', 'devrivo-chatbot' ),
			whatsapp: __( 'WhatsApp', 'devrivo-chatbot' ),
			email: __( 'Email', 'devrivo-chatbot' ),
			flow: __( 'Guided conversation', 'devrivo-chatbot' ),
			form: __( 'Form', 'devrivo-chatbot' ),
			page_search: __( 'Search the site', 'devrivo-chatbot' ),
			ai_prompt: __( 'AI question', 'devrivo-chatbot' ),
			custom_hook: __( 'Custom (developers)', 'devrivo-chatbot' ),
		},
		fields: {
			url: __( 'Link (https://…)', 'devrivo-chatbot' ),
			new_tab: __( 'Open in a new tab', 'devrivo-chatbot' ),
			phone: __( 'Phone number', 'devrivo-chatbot' ),
			message: __( 'Pre-filled message', 'devrivo-chatbot' ),
			email: __( 'Email address', 'devrivo-chatbot' ),
			subject: __( 'Subject', 'devrivo-chatbot' ),
			flow: __( 'Conversation', 'devrivo-chatbot' ),
			form: __( 'Form', 'devrivo-chatbot' ),
			post_types: __( 'Only search these post types', 'devrivo-chatbot' ),
			prompt: __( 'Question', 'devrivo-chatbot' ),
			hook: __( 'Hook name', 'devrivo-chatbot' ),
		},
	};
}

/**
 * Config field control.
 *
 * @param {Object}                          props
 * @param {Object}                          props.field    Field from the action type (key, type, maxLength).
 * @param {unknown}                         props.value    Value.
 * @param {(...args: unknown[]) => unknown} props.onChange New value.
 * @param {Object}                          props.all      All settings (flows and forms for selects).
 * @param {boolean}                         props.required The action needs this value.
 * @return {Element} Control.
 */
function ConfigField( { field, value, onChange, all, required } ) {
	const names = labels().fields;
	const label =
		( names[ field.key ] || field.key ) +
		( required ? ` (${ __( 'required', 'devrivo-chatbot' ) })` : '' );

	if ( field.type === 'boolean' ) {
		return (
			<ToggleControl
				label={ label }
				checked={ !! value }
				onChange={ onChange }
			/>
		);
	}
	if ( field.type === 'array' ) {
		const list = Array.isArray( value ) ? value : [];
		return (
			<fieldset className="devrivo-fieldset">
				<legend>{ label }</legend>
				{ boot().postTypes.map( ( type ) => (
					<CheckboxControl
						key={ type.slug }
						label={ type.label }
						checked={ list.includes( type.slug ) }
						onChange={ ( on ) =>
							onChange(
								on
									? [ ...list, type.slug ]
									: list.filter(
											( slug ) => slug !== type.slug
										)
							)
						}
					/>
				) ) }
			</fieldset>
		);
	}
	if ( field.key === 'flow' || field.key === 'form' ) {
		const map = ( field.key === 'flow' ? all.flows : all.forms ) || {};
		return (
			<SelectControl
				label={ label }
				value={ value || '' }
				options={ [
					{ label: __( 'Choose…', 'devrivo-chatbot' ), value: '' },
					...Object.entries( map ).map( ( [ id, item ] ) => ( {
						label: item.title || id,
						value: id,
					} ) ),
				] }
				onChange={ onChange }
				__next40pxDefaultSize
			/>
		);
	}

	return (
		<TextControl
			label={ label }
			type={
				{ url: 'url', phone: 'tel', email: 'email' }[ field.key ] ||
				'text'
			}
			value={ value || '' }
			maxLength={ field.maxLength || undefined }
			onChange={ onChange }
			__next40pxDefaultSize
		/>
	);
}

/**
 * Quick actions screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Actions( { notify, setDirty } ) {
	const settings = useSettings( 'actions' );
	const [ open, setOpen ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const [ newType, setNewType ] = useState( '' );
	const [ newLabel, setNewLabel ] = useState( '' );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const types = boot().actionTypes;
	const names = labels().types;
	const list = settings.values.actions || [];
	const fields = settings.errors.fields;
	const setList = ( next ) => settings.setSection( 'actions', next );
	const supported = types.filter( ( type ) => type.supported );

	const add = () => {
		const type = types.find( ( t ) => t.id === newType );
		if ( ! type || ! newLabel.trim() ) {
			return;
		}
		const next = addAction( list, type, newLabel.trim() );
		setList( next );
		setOpen( next.length - 1 );
		setNewLabel( '' );
	};

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />
			<p className="description">
				{ __(
					'Quick actions appear in the menu and can be used by conversations. The first ones are shown first.',
					'devrivo-chatbot'
				) }
			</p>

			<ol className="devrivo-actions">
				{ list.map( ( action, index ) => {
					const gaps = missing( action, types );
					const isOpen = open === index;
					const headingId = `devrivo-action-${ index }`;
					return (
						<li key={ action.id + index }>
							<Card size="small">
								<CardBody>
									<div className="devrivo-actions__row">
										<span
											className="devrivo-actions__label"
											id={ headingId }
										>
											<strong>{ action.label }</strong>{ ' ' }
											<span className="devrivo-badge">
												{ names[ action.type ] ||
													action.type }
											</span>
											{ gaps.length > 0 && (
												<span className="devrivo-badge devrivo-badge--warning">
													{ gaps.includes( 'type' )
														? __(
																'Not available yet',
																'devrivo-chatbot'
															)
														: __(
																'Incomplete: hidden from visitors',
																'devrivo-chatbot'
															) }
												</span>
											) }
											{ action.show_in_menu === false && (
												<span className="devrivo-badge">
													{ __(
														'Not in menu',
														'devrivo-chatbot'
													) }
												</span>
											) }
										</span>
										<span className="devrivo-actions__buttons">
											<Button
												size="small"
												icon="arrow-up-alt2"
												label={ sprintf(
													/* translators: %s: Action label. */ __(
														'Move “%s” up',
														'devrivo-chatbot'
													),
													action.label
												) }
												disabled={ index === 0 }
												accessibleWhenDisabled
												onClick={ () =>
													setList(
														move( list, index, -1 )
													)
												}
											/>
											<Button
												size="small"
												icon="arrow-down-alt2"
												label={ sprintf(
													/* translators: %s: Action label. */ __(
														'Move “%s” down',
														'devrivo-chatbot'
													),
													action.label
												) }
												disabled={
													index === list.length - 1
												}
												accessibleWhenDisabled
												onClick={ () =>
													setList(
														move( list, index, 1 )
													)
												}
											/>
											<Button
												size="small"
												variant="secondary"
												aria-expanded={ isOpen }
												aria-controls={ `${ headingId }-edit` }
												onClick={ () =>
													setOpen(
														isOpen ? null : index
													)
												}
											>
												{ isOpen
													? __(
															'Close',
															'devrivo-chatbot'
														)
													: __(
															'Edit',
															'devrivo-chatbot'
														) }
											</Button>
											<Button
												size="small"
												variant="tertiary"
												isDestructive
												onClick={ () =>
													setConfirm( index )
												}
											>
												{ __(
													'Delete',
													'devrivo-chatbot'
												) }
											</Button>
										</span>
									</div>
									<FieldError
										error={
											fields[ `${ index }.id` ] ||
											fields[ `${ index }.config` ]
										}
									/>

									{ isOpen && (
										<div
											id={ `${ headingId }-edit` }
											className="devrivo-actions__edit"
											role="group"
											aria-labelledby={ headingId }
										>
											<p className="description">
												{ sprintf(
													/* translators: %s: Action ID. */ __(
														'ID: %s',
														'devrivo-chatbot'
													),
													action.id
												) }
											</p>
											<TextControl
												label={ __(
													'Label',
													'devrivo-chatbot'
												) }
												value={ action.label }
												maxLength={ 80 }
												onChange={ ( value ) =>
													setList(
														updateAction(
															list,
															index,
															'label',
															value
														)
													)
												}
												__next40pxDefaultSize
											/>
											<ToggleControl
												label={ __(
													'Mark as urgent (shown in red)',
													'devrivo-chatbot'
												) }
												checked={ !! action.urgent }
												onChange={ ( value ) =>
													setList(
														updateAction(
															list,
															index,
															'urgent',
															value
														)
													)
												}
											/>
											<ToggleControl
												label={ __(
													'Show in the menu',
													'devrivo-chatbot'
												) }
												help={ __(
													'Turn off for actions used only inside conversations.',
													'devrivo-chatbot'
												) }
												checked={
													action.show_in_menu !==
													false
												}
												onChange={ ( value ) =>
													setList(
														updateAction(
															list,
															index,
															'show_in_menu',
															value
														)
													)
												}
											/>
											{ (
												types.find(
													( t ) =>
														t.id === action.type
												)?.fields || []
											).map( ( field ) => (
												<ConfigField
													key={ field.key }
													field={ field }
													value={
														action.config?.[
															field.key
														]
													}
													all={ settings.all }
													required={ (
														types.find(
															( t ) =>
																t.id ===
																action.type
														)?.required || []
													).includes( field.key ) }
													onChange={ ( value ) =>
														setList(
															updateAction(
																list,
																index,
																field.key,
																value,
																true
															)
														)
													}
												/>
											) ) }
										</div>
									) }
								</CardBody>
							</Card>
						</li>
					);
				} ) }
			</ol>

			<fieldset className="devrivo-fieldset devrivo-actions__add">
				<legend>
					{ __( 'Add a quick action', 'devrivo-chatbot' ) }
				</legend>
				<SelectControl
					label={ __( 'Type', 'devrivo-chatbot' ) }
					value={ newType }
					options={ [
						{
							label: __( 'Choose…', 'devrivo-chatbot' ),
							value: '',
						},
						...supported.map( ( type ) => ( {
							label: names[ type.id ] || type.id,
							value: type.id,
						} ) ),
					] }
					onChange={ setNewType }
					__next40pxDefaultSize
				/>
				<TextControl
					label={ __( 'Label', 'devrivo-chatbot' ) }
					value={ newLabel }
					maxLength={ 80 }
					onChange={ setNewLabel }
					__next40pxDefaultSize
				/>
				<Button
					variant="secondary"
					onClick={ add }
					disabled={
						! newType || ! newLabel.trim() || list.length >= 20
					}
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ __( 'Add', 'devrivo-chatbot' ) }
				</Button>
				{ list.length >= 20 && (
					<p className="description">
						{ __(
							'You can have up to 20 quick actions.',
							'devrivo-chatbot'
						) }
					</p>
				) }
			</fieldset>

			<SaveBar settings={ settings } notify={ notify } />

			{ confirm !== null && list[ confirm ] && (
				<ConfirmModal
					title={ __( 'Delete quick action?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Action label. */
						__(
							'“%s” will be removed when you save. If a conversation still uses it, saving will tell you which one to change first.',
							'devrivo-chatbot'
						),
						list[ confirm ].label
					) }
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						setList( removeAction( list, confirm ) );
						setOpen( null );
						setConfirm( null );
					} }
				/>
			) }
		</div>
	);
}
