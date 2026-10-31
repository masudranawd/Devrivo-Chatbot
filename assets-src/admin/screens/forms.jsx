/**
 * Forms builder: form list, form texts and fields (type, label, required,
 * placeholder, max length, dropdown options). The selected form is shown in
 * the live preview.
 */
import {
	Button,
	CheckboxControl,
	SelectControl,
	TextControl,
	TextareaControl,
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
import { Preview } from '../components/preview';
import { move } from '../lib/actions';
import {
	addForm,
	addOption,
	FIELD_TYPES,
	MAX_FIELDS,
	MAX_FORMS,
	MAX_OPTIONS,
	newField,
	removeForm,
	removeOption,
	updateField,
	updateForm,
	updateOption,
} from '../lib/form-builder';
import { useSettings } from '../lib/use-settings';

/**
 * Translated field type names.
 *
 * @return {Object<string, string>} Type => label.
 */
export function fieldTypeLabels() {
	return {
		text: __( 'Short text', 'devrivo-chatbot' ),
		email: __( 'Email', 'devrivo-chatbot' ),
		tel: __( 'Phone', 'devrivo-chatbot' ),
		textarea: __( 'Long text', 'devrivo-chatbot' ),
		select: __( 'Dropdown', 'devrivo-chatbot' ),
		date: __( 'Date', 'devrivo-chatbot' ),
	};
}

/**
 * Forms screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Forms( { notify, setDirty } ) {
	const settings = useSettings( 'forms' );
	const [ selected, setSelected ] = useState( '' );
	const [ newTitle, setNewTitle ] = useState( '' );
	const [ newType, setNewType ] = useState( 'text' );
	const [ newLabel, setNewLabel ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const types = fieldTypeLabels();
	const forms = settings.values.forms || {};
	const ids = Object.keys( forms );
	const current = forms[ selected ] ? selected : ids[ 0 ] || '';
	const form = forms[ current ];
	const list = form ? form.fields || [] : [];
	const errors = settings.errors.fields;
	const error = ( path ) => errors[ `${ current }.${ path }` ] || '';
	const firstOption = __( 'Option 1', 'devrivo-chatbot' );
	const setFlat = ( patch ) =>
		settings.setSection( 'forms', updateForm( forms, current, patch ) );
	const setFields = ( fields ) => setFlat( { fields } );

	const onAddForm = () => {
		const title = newTitle.trim();
		if ( ! title || ids.length >= MAX_FORMS ) {
			return;
		}
		const result = addForm(
			forms,
			title,
			__( 'Email', 'devrivo-chatbot' )
		);
		settings.setSection( 'forms', result.forms );
		setSelected( result.id );
		setNewTitle( '' );
	};

	const onAddField = () => {
		const label = newLabel.trim();
		if ( ! label || list.length >= MAX_FIELDS ) {
			return;
		}
		setFields( [ ...list, newField( list, newType, label, firstOption ) ] );
		setNewLabel( '' );
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />
				<p className="description">
					{ __(
						'Forms collect enquiries in the chat. Open one from a quick action of the type “Form”. A consent checkbox is added to every form automatically (Privacy screen).',
						'devrivo-chatbot'
					) }
				</p>

				{ ids.length > 0 ? (
					<div className="devrivo-flowbar">
						<SelectControl
							label={ __( 'Form', 'devrivo-chatbot' ) }
							value={ current }
							options={ ids.map( ( id ) => ( {
								label: forms[ id ].title || id,
								value: id,
							} ) ) }
							onChange={ setSelected }
							__next40pxDefaultSize
						/>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () => setConfirm( current ) }
							__next40pxDefaultSize
						>
							{ __( 'Delete', 'devrivo-chatbot' ) }
						</Button>
					</div>
				) : (
					<p>
						{ __( 'There are no forms yet.', 'devrivo-chatbot' ) }
					</p>
				) }

				{ form && (
					<>
						<p className="description">
							{ sprintf(
								/* translators: %s: Form ID. */
								__( 'ID: %s', 'devrivo-chatbot' ),
								current
							) }
						</p>
						<TextControl
							label={ __( 'Title', 'devrivo-chatbot' ) }
							value={ form.title || '' }
							maxLength={ 80 }
							onChange={ ( value ) =>
								setFlat( { title: value } )
							}
							__next40pxDefaultSize
						/>
						<FieldError error={ error( 'title' ) } />
						<TextControl
							label={ __(
								'Send button label',
								'devrivo-chatbot'
							) }
							help={ __(
								'Leave empty for “Send”.',
								'devrivo-chatbot'
							) }
							value={ form.submit_label || '' }
							maxLength={ 40 }
							onChange={ ( value ) =>
								setFlat( { submit_label: value } )
							}
							__next40pxDefaultSize
						/>
						<TextareaControl
							label={ __(
								'Thank-you message',
								'devrivo-chatbot'
							) }
							help={ __(
								'Shown after sending. Leave empty for the default message.',
								'devrivo-chatbot'
							) }
							value={ form.success_message || '' }
							maxLength={ 300 }
							rows={ 2 }
							onChange={ ( value ) =>
								setFlat( { success_message: value } )
							}
						/>

						<h2>{ __( 'Fields', 'devrivo-chatbot' ) }</h2>
						<FieldError error={ error( 'fields' ) } />
						<ol className="devrivo-list">
							{ list.map( ( field, index ) => {
								const path = `fields.${ index }`;
								const name = field.label || field.id;
								const set = ( key ) => ( value ) =>
									setFields(
										updateField(
											list,
											index,
											key,
											value,
											firstOption
										)
									);
								return (
									<li
										key={ field.id + index }
										className="devrivo-subcard"
										role="group"
										aria-label={ name }
									>
										<div className="devrivo-subcard__head">
											<h3>
												{ name }{ ' ' }
												<span className="devrivo-badge">
													{ types[ field.type ] ||
														field.type }
												</span>
											</h3>
											<span className="devrivo-actions__buttons">
												<Button
													size="small"
													icon="arrow-up-alt2"
													label={ sprintf(
														/* translators: %s: Field label. */
														__(
															'Move “%s” up',
															'devrivo-chatbot'
														),
														name
													) }
													disabled={ index === 0 }
													accessibleWhenDisabled
													onClick={ () =>
														setFields(
															move(
																list,
																index,
																-1
															)
														)
													}
												/>
												<Button
													size="small"
													icon="arrow-down-alt2"
													label={ sprintf(
														/* translators: %s: Field label. */
														__(
															'Move “%s” down',
															'devrivo-chatbot'
														),
														name
													) }
													disabled={
														index ===
														list.length - 1
													}
													accessibleWhenDisabled
													onClick={ () =>
														setFields(
															move(
																list,
																index,
																1
															)
														)
													}
												/>
												<Button
													size="small"
													variant="tertiary"
													isDestructive
													disabled={
														list.length === 1
													}
													accessibleWhenDisabled
													onClick={ () =>
														setFields(
															list.filter(
																( _, i ) =>
																	i !== index
															)
														)
													}
												>
													{ __(
														'Remove',
														'devrivo-chatbot'
													) }
												</Button>
											</span>
										</div>
										<p className="description">
											{ sprintf(
												/* translators: %s: Field ID. */
												__(
													'ID: %s',
													'devrivo-chatbot'
												),
												field.id
											) }
										</p>
										<FieldError
											error={ error( `${ path }.id` ) }
										/>
										<div className="devrivo-row">
											<SelectControl
												label={ __(
													'Type',
													'devrivo-chatbot'
												) }
												value={ field.type }
												options={ FIELD_TYPES.map(
													( type ) => ( {
														label: types[ type ],
														value: type,
													} )
												) }
												onChange={ set( 'type' ) }
												__next40pxDefaultSize
											/>
											<TextControl
												label={ __(
													'Label',
													'devrivo-chatbot'
												) }
												value={ field.label }
												maxLength={ 80 }
												onChange={ set( 'label' ) }
												__next40pxDefaultSize
											/>
										</div>
										<FieldError
											error={ error( `${ path }.label` ) }
										/>
										<CheckboxControl
											label={ __(
												'Required',
												'devrivo-chatbot'
											) }
											checked={ !! field.required }
											onChange={ set( 'required' ) }
										/>
										<div className="devrivo-row">
											<TextControl
												label={ __(
													'Placeholder',
													'devrivo-chatbot'
												) }
												value={
													field.placeholder || ''
												}
												maxLength={ 80 }
												onChange={ set(
													'placeholder'
												) }
												__next40pxDefaultSize
											/>
											<TextControl
												type="number"
												label={ __(
													'Maximum length',
													'devrivo-chatbot'
												) }
												min={ 1 }
												max={ 2000 }
												value={ String(
													field.max_length ?? 200
												) }
												onChange={ ( value ) =>
													set( 'max_length' )(
														Number( value ) || 1
													)
												}
												__next40pxDefaultSize
											/>
										</div>
										<FieldError
											error={ error(
												`${ path }.max_length`
											) }
										/>
										{ field.type === 'select' && (
											<fieldset className="devrivo-fieldset">
												<legend>
													{ __(
														'Options',
														'devrivo-chatbot'
													) }
												</legend>
												{ ( field.options || [] ).map(
													( option, o ) => (
														<div
															key={ o }
															className="devrivo-row"
														>
															<TextControl
																label={ sprintf(
																	/* translators: %d: Option number. */
																	__(
																		'Option %d',
																		'devrivo-chatbot'
																	),
																	o + 1
																) }
																value={ option }
																maxLength={ 80 }
																onChange={ (
																	value
																) =>
																	setFields(
																		updateOption(
																			list,
																			index,
																			o,
																			value
																		)
																	)
																}
																__next40pxDefaultSize
															/>
															<Button
																variant="tertiary"
																isDestructive
																label={ sprintf(
																	/* translators: %d: Option number. */
																	__(
																		'Remove option %d',
																		'devrivo-chatbot'
																	),
																	o + 1
																) }
																icon="no-alt"
																disabled={
																	field
																		.options
																		.length ===
																	1
																}
																accessibleWhenDisabled
																onClick={ () =>
																	setFields(
																		removeOption(
																			list,
																			index,
																			o
																		)
																	)
																}
																__next40pxDefaultSize
															/>
														</div>
													)
												) }
												<Button
													variant="secondary"
													disabled={
														( field.options || [] )
															.length >=
														MAX_OPTIONS
													}
													accessibleWhenDisabled
													onClick={ () =>
														setFields(
															addOption(
																list,
																index,
																sprintf(
																	/* translators: %d: Option number. */
																	__(
																		'Option %d',
																		'devrivo-chatbot'
																	),
																	(
																		field.options ||
																		[]
																	).length + 1
																)
															)
														)
													}
													__next40pxDefaultSize
												>
													{ __(
														'Add an option',
														'devrivo-chatbot'
													) }
												</Button>
												<FieldError
													error={ error(
														`${ path }.options`
													) }
												/>
											</fieldset>
										) }
									</li>
								);
							} ) }
						</ol>

						<fieldset className="devrivo-fieldset">
							<legend>
								{ __( 'Add a field', 'devrivo-chatbot' ) }
							</legend>
							<div className="devrivo-row">
								<SelectControl
									label={ __( 'Type', 'devrivo-chatbot' ) }
									value={ newType }
									options={ FIELD_TYPES.map( ( type ) => ( {
										label: types[ type ],
										value: type,
									} ) ) }
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
									onClick={ onAddField }
									disabled={
										! newLabel.trim() ||
										list.length >= MAX_FIELDS
									}
									accessibleWhenDisabled
									__next40pxDefaultSize
								>
									{ __( 'Add field', 'devrivo-chatbot' ) }
								</Button>
							</div>
							{ list.length >= MAX_FIELDS && (
								<p className="description">
									{ __(
										'A form can have up to 12 fields.',
										'devrivo-chatbot'
									) }
								</p>
							) }
						</fieldset>
					</>
				) }

				<fieldset className="devrivo-fieldset">
					<legend>{ __( 'Add a form', 'devrivo-chatbot' ) }</legend>
					<div className="devrivo-row">
						<TextControl
							label={ __( 'Title', 'devrivo-chatbot' ) }
							value={ newTitle }
							maxLength={ 80 }
							onChange={ setNewTitle }
							__next40pxDefaultSize
						/>
						<Button
							variant="secondary"
							onClick={ onAddForm }
							disabled={
								! newTitle.trim() || ids.length >= MAX_FORMS
							}
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Add', 'devrivo-chatbot' ) }
						</Button>
					</div>
				</fieldset>

				<SaveBar settings={ settings } notify={ notify } />
			</div>
			<Preview
				changes={ settings.changes }
				play={ current ? { type: 'form', id: current, n: 0 } : null }
			/>

			{ confirm && forms[ confirm ] && (
				<ConfirmModal
					title={ __( 'Delete form?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Form title. */
						__(
							'“%s” will be removed when you save. If a quick action still uses it, saving will tell you which one to change first. Leads already received are kept.',
							'devrivo-chatbot'
						),
						forms[ confirm ].title || confirm
					) }
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						settings.setSection(
							'forms',
							removeForm( forms, confirm )
						);
						setSelected( '' );
						setConfirm( null );
					} }
				/>
			) }
		</div>
	);
}
