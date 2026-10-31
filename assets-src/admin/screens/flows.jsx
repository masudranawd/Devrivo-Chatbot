/**
 * Guided conversations (flows): list-based editor. A flow is a set of steps
 * with auto-generated IDs; each step's fields depend on its type. "Try this
 * flow" runs the unsaved flow in the live preview.
 */
import {
	Button,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
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
import { Preview } from '../components/preview';
import {
	addChoice,
	addFlow,
	addNode,
	MAX_CHOICES,
	MAX_CTAS,
	MAX_FLOWS,
	MAX_NODES,
	moveChoice,
	NODE_TYPES,
	nodeLabel,
	removeChoice,
	removeFlow,
	removeNode,
	setNodeType,
	updateChoice,
	updateFlow,
	updateNode,
} from '../lib/flows';
import { useSettings } from '../lib/use-settings';

/**
 * Translated step type names.
 *
 * @return {Object<string, string>} Type => label.
 */
export function nodeTypeLabels() {
	return {
		message: __( 'Message', 'devrivo-chatbot' ),
		choices: __( 'Question with choices', 'devrivo-chatbot' ),
		condition: __( 'Are we open now?', 'devrivo-chatbot' ),
		action: __( 'Buttons (quick actions)', 'devrivo-chatbot' ),
		end: __( 'End', 'devrivo-chatbot' ),
	};
}

/**
 * Text describing a cleared reference.
 *
 * @param {Object} item { node, field, label }.
 * @return {string} Text.
 */
export function clearedText( item ) {
	if ( item.field === 'choice' ) {
		return sprintf(
			/* translators: 1: Step ID, 2: Choice label. */
			__(
				'%1$s: the choice “%2$s” no longer has a next step',
				'devrivo-chatbot'
			),
			item.node,
			item.label || '…'
		);
	}
	const names = {
		next: __( 'next step', 'devrivo-chatbot' ),
		then: __( 'step when open', 'devrivo-chatbot' ),
		else: __( 'step when closed', 'devrivo-chatbot' ),
		unknown: __( 'step when hours are unknown', 'devrivo-chatbot' ),
	};
	return sprintf(
		/* translators: 1: Step ID, 2: Field name, e.g. "next step". */
		__( '%1$s: %2$s cleared', 'devrivo-chatbot' ),
		item.node,
		names[ item.field ] || item.field
	);
}

/**
 * Flows screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Flows( { notify, setDirty } ) {
	const settings = useSettings( 'flows' );
	const [ selected, setSelected ] = useState( '' );
	const [ newTitle, setNewTitle ] = useState( '' );
	const [ newType, setNewType ] = useState( 'message' );
	const [ confirm, setConfirm ] = useState( null );
	const [ cleared, setCleared ] = useState( [] );
	const [ play, setPlay ] = useState( null );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const types = nodeTypeLabels();
	const flows = settings.values.flows || {};
	const ids = Object.keys( flows );
	const current = flows[ selected ] ? selected : ids[ 0 ] || '';
	const flow = flows[ current ];
	const fields = settings.errors.fields;
	const setFlows = ( next ) => settings.setSection( 'flows', next );
	const setFlow = ( next ) => setFlows( { ...flows, [ current ]: next } );
	const error = ( path ) => fields[ `${ current }.${ path }` ] || '';

	const actions = settings.all.actions || [];
	const actionOptions = [
		{ label: __( 'None', 'devrivo-chatbot' ), value: '' },
		...actions.map( ( action ) => ( {
			label: action.label || action.id,
			value: action.id,
		} ) ),
	];
	const nodeIds = flow ? Object.keys( flow.nodes || {} ) : [];
	const stepOptions = ( exclude, empty ) => [
		{ label: empty, value: '' },
		...nodeIds
			.filter( ( id ) => id !== exclude )
			.map( ( id ) => ( {
				label: nodeLabel( id, flow.nodes[ id ] ),
				value: id,
			} ) ),
	];
	const endHere = __( '— End the conversation —', 'devrivo-chatbot' );

	const onAddFlow = () => {
		const title = newTitle.trim();
		if ( ! title || ids.length >= MAX_FLOWS ) {
			return;
		}
		const result = addFlow( flows, title );
		setFlows( result.flows );
		setSelected( result.id );
		setNewTitle( '' );
		setCleared( [] );
	};

	const onAddNode = () => {
		const result = addNode( flow, newType );
		setFlow( result.flow );
	};

	const onConfirm = () => {
		if ( confirm.kind === 'flow' ) {
			setFlows( removeFlow( flows, confirm.id ) );
			setSelected( '' );
			setCleared( [] );
		} else {
			const result = removeNode( flow, confirm.id );
			setFlow( result.flow );
			setCleared( result.cleared );
		}
		setConfirm( null );
	};

	const renderNode = ( nodeId ) => {
		const node = flow.nodes[ nodeId ];
		const isStart = flow.start === nodeId;
		const set = ( key ) => ( value ) =>
			setFlow( updateNode( flow, nodeId, key, value ) );
		const path = `nodes.${ nodeId }`;
		const headingId = `devrivo-node-${ current }-${ nodeId }`;
		const choices = node.choices || [];
		const ctas = node.actions || [];

		return (
			<li key={ nodeId }>
				<div
					className="devrivo-subcard"
					role="group"
					aria-labelledby={ headingId }
				>
					<div className="devrivo-subcard__head">
						<h3 id={ headingId }>
							{ nodeId }
							{ isStart && (
								<span className="devrivo-badge">
									{ __( 'First step', 'devrivo-chatbot' ) }
								</span>
							) }
						</h3>
						<Button
							size="small"
							variant="tertiary"
							isDestructive
							disabled={ isStart }
							accessibleWhenDisabled
							label={
								isStart
									? __(
											'Choose another first step before deleting this one.',
											'devrivo-chatbot'
										)
									: undefined
							}
							showTooltip={ isStart }
							onClick={ () =>
								setConfirm( { kind: 'node', id: nodeId } )
							}
						>
							{ sprintf(
								/* translators: %s: Step ID. */
								__( 'Delete %s', 'devrivo-chatbot' ),
								nodeId
							) }
						</Button>
					</div>

					<SelectControl
						label={ __( 'Type', 'devrivo-chatbot' ) }
						value={ node.type }
						options={ NODE_TYPES.map( ( type ) => ( {
							label: types[ type ],
							value: type,
						} ) ) }
						onChange={ ( type ) =>
							setFlow( setNodeType( flow, nodeId, type ) )
						}
						__next40pxDefaultSize
					/>

					<TextareaControl
						label={ __( 'Text', 'devrivo-chatbot' ) }
						help={
							node.type === 'condition'
								? __(
										'Optional. Shown before the conversation continues.',
										'devrivo-chatbot'
									)
								: undefined
						}
						value={ node.text || '' }
						maxLength={ 500 }
						rows={ 2 }
						onChange={ set( 'text' ) }
					/>
					<FieldError error={ error( `${ path }.text` ) } />

					{ ( node.type === 'message' || node.type === 'action' ) && (
						<>
							<SelectControl
								label={ __( 'Next step', 'devrivo-chatbot' ) }
								value={ node.next || '' }
								options={ stepOptions( nodeId, endHere ) }
								onChange={ set( 'next' ) }
								__next40pxDefaultSize
							/>
							<FieldError error={ error( `${ path }.next` ) } />
						</>
					) }

					{ node.type === 'condition' && (
						<>
							<p className="description">
								{ __(
									'Uses your opening hours (Behaviour screen).',
									'devrivo-chatbot'
								) }
							</p>
							{ [
								[
									'then',
									__(
										'If we are open now',
										'devrivo-chatbot'
									),
								],
								[
									'else',
									__( 'If we are closed', 'devrivo-chatbot' ),
								],
								[
									'unknown',
									__(
										'If no opening hours are set (empty: same as closed)',
										'devrivo-chatbot'
									),
								],
							].map( ( [ key, label ] ) => (
								<div key={ key }>
									<SelectControl
										label={ label }
										value={ node[ key ] || '' }
										options={ stepOptions(
											nodeId,
											endHere
										) }
										onChange={ set( key ) }
										__next40pxDefaultSize
									/>
									<FieldError
										error={ error( `${ path }.${ key }` ) }
									/>
								</div>
							) ) }
						</>
					) }

					{ node.type === 'choices' && (
						<fieldset className="devrivo-fieldset">
							<legend>
								{ __( 'Choices', 'devrivo-chatbot' ) }
							</legend>
							<ol className="devrivo-list">
								{ choices.map( ( choice, index ) => {
									const label =
										choice.label ||
										sprintf(
											/* translators: %d: Choice number. */
											__(
												'Choice %d',
												'devrivo-chatbot'
											),
											index + 1
										);
									return (
										<li
											key={ index }
											className="devrivo-subcard"
										>
											<TextControl
												label={ sprintf(
													/* translators: %d: Choice number. */
													__(
														'Choice %d label',
														'devrivo-chatbot'
													),
													index + 1
												) }
												value={ choice.label }
												maxLength={ 60 }
												onChange={ ( value ) =>
													setFlow(
														updateChoice(
															flow,
															nodeId,
															index,
															'label',
															value
														)
													)
												}
												__next40pxDefaultSize
											/>
											<div className="devrivo-row">
												<SelectControl
													label={ __(
														'Then go to',
														'devrivo-chatbot'
													) }
													value={ choice.next || '' }
													options={ stepOptions(
														'',
														__(
															'— No next step —',
															'devrivo-chatbot'
														)
													) }
													onChange={ ( value ) =>
														setFlow(
															updateChoice(
																flow,
																nodeId,
																index,
																'next',
																value
															)
														)
													}
													__next40pxDefaultSize
												/>
												<SelectControl
													label={ __(
														'And run',
														'devrivo-chatbot'
													) }
													value={
														choice.action || ''
													}
													options={ actionOptions }
													onChange={ ( value ) =>
														setFlow(
															updateChoice(
																flow,
																nodeId,
																index,
																'action',
																value
															)
														)
													}
													__next40pxDefaultSize
												/>
											</div>
											<ToggleControl
												label={ __(
													'Urgent (shown in red)',
													'devrivo-chatbot'
												) }
												checked={ !! choice.urgent }
												onChange={ ( value ) =>
													setFlow(
														updateChoice(
															flow,
															nodeId,
															index,
															'urgent',
															value
														)
													)
												}
											/>
											<FieldError
												error={
													error(
														`${ path }.choices.${ index }`
													) ||
													error(
														`${ path }.choices.${ index }.label`
													) ||
													error(
														`${ path }.choices.${ index }.next`
													) ||
													error(
														`${ path }.choices.${ index }.action`
													)
												}
											/>
											<div className="devrivo-actions__buttons">
												<Button
													size="small"
													icon="arrow-up-alt2"
													label={ sprintf(
														/* translators: %s: Choice label. */
														__(
															'Move “%s” up',
															'devrivo-chatbot'
														),
														label
													) }
													disabled={ index === 0 }
													accessibleWhenDisabled
													onClick={ () =>
														setFlow(
															moveChoice(
																flow,
																nodeId,
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
														/* translators: %s: Choice label. */
														__(
															'Move “%s” down',
															'devrivo-chatbot'
														),
														label
													) }
													disabled={
														index ===
														choices.length - 1
													}
													accessibleWhenDisabled
													onClick={ () =>
														setFlow(
															moveChoice(
																flow,
																nodeId,
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
													onClick={ () =>
														setFlow(
															removeChoice(
																flow,
																nodeId,
																index
															)
														)
													}
												>
													{ sprintf(
														/* translators: %s: Choice label. */
														__(
															'Remove “%s”',
															'devrivo-chatbot'
														),
														label
													) }
												</Button>
											</div>
										</li>
									);
								} ) }
							</ol>
							<Button
								variant="secondary"
								onClick={ () =>
									setFlow( addChoice( flow, nodeId ) )
								}
								disabled={ choices.length >= MAX_CHOICES }
								accessibleWhenDisabled
								__next40pxDefaultSize
							>
								{ __( 'Add a choice', 'devrivo-chatbot' ) }
							</Button>
							{ choices.length >= MAX_CHOICES && (
								<p className="description">
									{ __(
										'A step can have up to 8 choices.',
										'devrivo-chatbot'
									) }
								</p>
							) }
						</fieldset>
					) }

					{ node.type === 'action' && (
						<fieldset className="devrivo-fieldset">
							<legend>
								{ __(
									'Buttons (up to 3 quick actions)',
									'devrivo-chatbot'
								) }
							</legend>
							{ Array.from( { length: MAX_CTAS } ).map(
								( _, index ) => (
									<div key={ index }>
										<SelectControl
											label={ sprintf(
												/* translators: %d: Button number. */
												__(
													'Button %d',
													'devrivo-chatbot'
												),
												index + 1
											) }
											value={ ctas[ index ] || '' }
											options={ actionOptions }
											disabled={ index > ctas.length }
											onChange={ ( value ) => {
												const next = [ ...ctas ];
												next[ index ] = value;
												set( 'actions' )(
													next.filter( Boolean )
												);
											} }
											__next40pxDefaultSize
										/>
										<FieldError
											error={ error(
												`${ path }.actions.${ index }`
											) }
										/>
									</div>
								)
							) }
						</fieldset>
					) }
				</div>
			</li>
		);
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />
				<p className="description">
					{ __(
						'A guided conversation asks visitors simple questions with buttons. Start one from a quick action of the type “Guided conversation”.',
						'devrivo-chatbot'
					) }
				</p>

				{ ids.length > 0 && (
					<div className="devrivo-flowbar">
						<SelectControl
							label={ __( 'Conversation', 'devrivo-chatbot' ) }
							value={ current }
							options={ ids.map( ( id ) => ( {
								label: flows[ id ].title || id,
								value: id,
							} ) ) }
							onChange={ ( id ) => {
								setSelected( id );
								setCleared( [] );
								setPlay( null );
							} }
							__next40pxDefaultSize
						/>
						<Button
							variant="secondary"
							onClick={ () =>
								setPlay( {
									type: 'flow',
									id: current,
									n: Date.now(),
								} )
							}
							__next40pxDefaultSize
						>
							{ __( 'Try this flow', 'devrivo-chatbot' ) }
						</Button>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								setConfirm( { kind: 'flow', id: current } )
							}
							__next40pxDefaultSize
						>
							{ __( 'Delete', 'devrivo-chatbot' ) }
						</Button>
					</div>
				) }

				{ ids.length === 0 && (
					<p>
						{ __(
							'There are no guided conversations yet.',
							'devrivo-chatbot'
						) }
					</p>
				) }

				{ flow && (
					<>
						<p className="description">
							{ sprintf(
								/* translators: %s: Flow ID. */
								__( 'ID: %s', 'devrivo-chatbot' ),
								current
							) }
						</p>
						<TextControl
							label={ __( 'Title', 'devrivo-chatbot' ) }
							value={ flow.title || '' }
							maxLength={ 80 }
							onChange={ ( value ) =>
								setFlows(
									updateFlow( flows, current, {
										title: value,
									} )
								)
							}
							__next40pxDefaultSize
						/>
						<SelectControl
							label={ __( 'First step', 'devrivo-chatbot' ) }
							value={ flow.start || '' }
							options={ stepOptions(
								'',
								__( 'Choose…', 'devrivo-chatbot' )
							) }
							onChange={ ( value ) =>
								setFlows(
									updateFlow( flows, current, {
										start: value,
									} )
								)
							}
							__next40pxDefaultSize
						/>
						<FieldError error={ error( 'start' ) } />

						{ cleared.length > 0 && (
							<Notice
								status="warning"
								onRemove={ () => setCleared( [] ) }
							>
								<p>
									{ __(
										'The step was deleted. These links pointed to it and were cleared:',
										'devrivo-chatbot'
									) }
								</p>
								<ul className="devrivo-error-list">
									{ cleared.map( ( item, index ) => (
										<li key={ index }>
											{ clearedText( item ) }
										</li>
									) ) }
								</ul>
							</Notice>
						) }

						<ol className="devrivo-list">
							{ nodeIds.map( renderNode ) }
						</ol>

						<fieldset className="devrivo-fieldset">
							<legend>
								{ __( 'Add a step', 'devrivo-chatbot' ) }
							</legend>
							<div className="devrivo-row">
								<SelectControl
									label={ __( 'Type', 'devrivo-chatbot' ) }
									value={ newType }
									options={ NODE_TYPES.map( ( type ) => ( {
										label: types[ type ],
										value: type,
									} ) ) }
									onChange={ setNewType }
									__next40pxDefaultSize
								/>
								<Button
									variant="secondary"
									onClick={ onAddNode }
									disabled={ nodeIds.length >= MAX_NODES }
									accessibleWhenDisabled
									__next40pxDefaultSize
								>
									{ __( 'Add step', 'devrivo-chatbot' ) }
								</Button>
							</div>
						</fieldset>
					</>
				) }

				<fieldset className="devrivo-fieldset">
					<legend>
						{ __( 'Add a guided conversation', 'devrivo-chatbot' ) }
					</legend>
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
							onClick={ onAddFlow }
							disabled={
								! newTitle.trim() || ids.length >= MAX_FLOWS
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
			<Preview changes={ settings.changes } play={ play } />

			{ confirm && (
				<ConfirmModal
					title={
						confirm.kind === 'flow'
							? __(
									'Delete guided conversation?',
									'devrivo-chatbot'
								)
							: __( 'Delete step?', 'devrivo-chatbot' )
					}
					message={
						confirm.kind === 'flow'
							? sprintf(
									/* translators: %s: Flow title. */
									__(
										'“%s” will be removed when you save. If a quick action still uses it, saving will tell you which one to change first.',
										'devrivo-chatbot'
									),
									flows[ confirm.id ]?.title || confirm.id
								)
							: sprintf(
									/* translators: %s: Step ID. */
									__(
										'%s will be removed. Steps and choices that lead to it will be cleared.',
										'devrivo-chatbot'
									),
									confirm.id
								)
					}
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ onConfirm }
				/>
			) }
		</div>
	);
}
