/**
 * Page-specific actions: ordered rules that change the quick actions on
 * certain pages (by page or path). Pinned actions always come first; the
 * first matching rule wins.
 */
import {
	Button,
	Card,
	CardBody,
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
import { PostPicker } from '../components/post-picker';
import { Preview } from '../components/preview';
import { move } from '../lib/actions';
import { boot } from '../lib/boot';
import {
	addQuestion,
	addRule,
	MAX_RULE_ACTIONS,
	MAX_RULES,
	parsePaths,
	updateRule,
} from '../lib/page-rules';
import { useSettings } from '../lib/use-settings';
import { labels as actionLabels } from './actions';

/**
 * One rule's editor.
 *
 * @param {Object}                     props
 * @param {Object}                     props.rule     Rule.
 * @param {number}                     props.index    Position.
 * @param {number}                     props.count    Number of rules.
 * @param {Array}                      props.actions  Quick actions (draft).
 * @param {Object}                     props.errors   Field errors.
 * @param {(patch: Object) => void}    props.onChange Changes keys of the rule.
 * @param {(delta: number) => void}    props.onMove   Moves the rule.
 * @param {() => void}                 props.onDelete Asks to delete.
 * @param {(question: string) => void} props.onAsk    Adds a question action to the rule.
 * @return {Element} Card.
 */
export function RuleCard( {
	rule,
	index,
	count,
	actions,
	errors,
	onChange,
	onMove,
	onDelete,
	onAsk,
} ) {
	const [ paths, setPaths ] = useState( ( rule.paths || [] ).join( '\n' ) );
	const [ question, setQuestion ] = useState( '' );
	const names = actionLabels().types;
	const byId = new Map( actions.map( ( action ) => [ action.id, action ] ) );
	const chosen = rule.actions || [];
	const available = actions.filter(
		( action ) => ! chosen.includes( action.id )
	);
	const full = chosen.length >= MAX_RULE_ACTIONS;
	const name = rule.name || rule.id;
	const error = ( key ) => errors[ `${ index }.${ key }` ] || '';
	const actionName = ( id ) => byId.get( id )?.label || id;

	return (
		<Card size="small">
			<CardBody>
				<div
					className="devrivo-actions__edit"
					role="group"
					aria-label={ name }
				>
					<div className="devrivo-subcard__head">
						<h3>
							{ sprintf(
								/* translators: 1: Rule number, 2: Rule name. */
								__( '%1$d. %2$s', 'devrivo-chatbot' ),
								index + 1,
								name
							) }
						</h3>
						<span className="devrivo-actions__buttons">
							<Button
								size="small"
								icon="arrow-up-alt2"
								label={ sprintf(
									/* translators: %s: Rule name. */
									__( 'Move “%s” up', 'devrivo-chatbot' ),
									name
								) }
								disabled={ index === 0 }
								accessibleWhenDisabled
								onClick={ () => onMove( -1 ) }
							/>
							<Button
								size="small"
								icon="arrow-down-alt2"
								label={ sprintf(
									/* translators: %s: Rule name. */
									__( 'Move “%s” down', 'devrivo-chatbot' ),
									name
								) }
								disabled={ index === count - 1 }
								accessibleWhenDisabled
								onClick={ () => onMove( 1 ) }
							/>
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								onClick={ onDelete }
							>
								{ __( 'Delete', 'devrivo-chatbot' ) }
							</Button>
						</span>
					</div>

					<TextControl
						label={ __(
							'Name (only you see it)',
							'devrivo-chatbot'
						) }
						value={ rule.name }
						maxLength={ 80 }
						onChange={ ( value ) => onChange( { name: value } ) }
						__next40pxDefaultSize
					/>
					<FieldError error={ error( 'name' ) || error( 'id' ) } />

					<PostPicker
						label={ __( 'Pages', 'devrivo-chatbot' ) }
						ids={ rule.post_ids || [] }
						onChange={ ( ids ) =>
							onChange( { post_ids: ids.slice( 0, 50 ) } )
						}
					/>
					<TextareaControl
						label={ __(
							'Paths (one per line)',
							'devrivo-chatbot'
						) }
						help={ sprintf(
							/* translators: %s: Site home path, e.g. "/". */
							__(
								'Relative to your site home (%s). For example /services/implants/',
								'devrivo-chatbot'
							),
							boot().homePath || '/'
						) }
						value={ paths }
						rows={ 3 }
						onChange={ ( value ) => {
							setPaths( value );
							onChange( { paths: parsePaths( value ) } );
						} }
					/>
					<FieldError error={ error( 'paths' ) } />
					<ToggleControl
						label={ __( 'Also match subpages', 'devrivo-chatbot' ) }
						help={ __(
							'For paths: /services/ also matches /services/implants/.',
							'devrivo-chatbot'
						) }
						checked={ rule.include_children !== false }
						onChange={ ( value ) =>
							onChange( { include_children: value } )
						}
					/>

					<fieldset className="devrivo-fieldset">
						<legend>
							{ __(
								'Actions on these pages',
								'devrivo-chatbot'
							) }
						</legend>
						<p className="description">
							{ __(
								'Shown after your pinned actions, in this order (up to 5).',
								'devrivo-chatbot'
							) }
						</p>
						<ol className="devrivo-list">
							{ chosen.map( ( id, i ) => (
								<li key={ id } className="devrivo-row">
									<span>
										{ actionName( id ) }{ ' ' }
										<span className="devrivo-badge">
											{ names[ byId.get( id )?.type ] ||
												byId.get( id )?.type ||
												'?' }
										</span>
									</span>
									<span className="devrivo-actions__buttons">
										<Button
											size="small"
											icon="arrow-up-alt2"
											label={ sprintf(
												/* translators: %s: Action label. */
												__(
													'Move “%s” up',
													'devrivo-chatbot'
												),
												actionName( id )
											) }
											disabled={ i === 0 }
											accessibleWhenDisabled
											onClick={ () =>
												onChange( {
													actions: move(
														chosen,
														i,
														-1
													),
												} )
											}
										/>
										<Button
											size="small"
											icon="arrow-down-alt2"
											label={ sprintf(
												/* translators: %s: Action label. */
												__(
													'Move “%s” down',
													'devrivo-chatbot'
												),
												actionName( id )
											) }
											disabled={ i === chosen.length - 1 }
											accessibleWhenDisabled
											onClick={ () =>
												onChange( {
													actions: move(
														chosen,
														i,
														1
													),
												} )
											}
										/>
										<Button
											size="small"
											variant="tertiary"
											isDestructive
											onClick={ () =>
												onChange( {
													actions: chosen.filter(
														( item ) => item !== id
													),
												} )
											}
										>
											{ sprintf(
												/* translators: %s: Action label. */
												__(
													'Remove “%s”',
													'devrivo-chatbot'
												),
												actionName( id )
											) }
										</Button>
									</span>
									<FieldError
										error={ error( `actions.${ i }` ) }
									/>
								</li>
							) ) }
						</ol>
						<FieldError error={ error( 'actions' ) } />
						<SelectControl
							label={ __( 'Add an action', 'devrivo-chatbot' ) }
							value=""
							disabled={ full || ! available.length }
							options={ [
								{
									label: __( 'Choose…', 'devrivo-chatbot' ),
									value: '',
								},
								...available.map( ( action ) => ( {
									label: action.label || action.id,
									value: action.id,
								} ) ),
							] }
							onChange={ ( id ) =>
								id &&
								onChange( {
									actions: [ ...chosen, id ].slice(
										0,
										MAX_RULE_ACTIONS
									),
								} )
							}
							help={
								! actions.length ? (
									<a href="#/actions">
										{ __(
											'No quick actions yet. Create one under Quick actions.',
											'devrivo-chatbot'
										) }
									</a>
								) : undefined
							}
							__next40pxDefaultSize
						/>
						<div className="devrivo-row">
							<TextControl
								label={ __(
									'Add a question',
									'devrivo-chatbot'
								) }
								help={ __(
									'A button that asks the assistant this question (shown only on these pages).',
									'devrivo-chatbot'
								) }
								value={ question }
								maxLength={ 300 }
								disabled={ full }
								onChange={ setQuestion }
								__next40pxDefaultSize
							/>
							<Button
								variant="secondary"
								disabled={ full || ! question.trim() }
								accessibleWhenDisabled
								onClick={ () => {
									onAsk( question );
									setQuestion( '' );
								} }
								__next40pxDefaultSize
							>
								{ __( 'Add question', 'devrivo-chatbot' ) }
							</Button>
						</div>
						{ full && (
							<p className="description">
								{ __(
									'A rule can show up to 5 actions.',
									'devrivo-chatbot'
								) }
							</p>
						) }
					</fieldset>
				</div>
			</CardBody>
		</Card>
	);
}

/**
 * Page-specific actions screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function PageActions( { notify, setDirty } ) {
	const settings = useSettings( [ 'page_actions', 'actions' ] );
	const [ newName, setNewName ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const rules = settings.values.page_actions || [];
	const actions = settings.values.actions || [];
	const setRules = ( next ) => settings.setSection( 'page_actions', next );

	const add = () => {
		const name = newName.trim();
		if ( ! name || rules.length >= MAX_RULES ) {
			return;
		}
		setRules( addRule( rules, name ) );
		setNewName( '' );
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />
				<p className="description">
					{ __(
						'Show different quick actions on certain pages, for example questions about the service a visitor is reading about. Pinned actions (Quick actions screen) always come first. The first rule that matches a page wins, so put the most specific rules at the top.',
						'devrivo-chatbot'
					) }
				</p>

				{ rules.length === 0 && (
					<p>{ __( 'No page rules yet.', 'devrivo-chatbot' ) }</p>
				) }
				<ol className="devrivo-list">
					{ rules.map( ( rule, index ) => (
						<li key={ rule.id + index }>
							<RuleCard
								rule={ rule }
								index={ index }
								count={ rules.length }
								actions={ actions }
								errors={ settings.errors.fields }
								onChange={ ( patch ) =>
									setRules(
										updateRule( rules, index, patch )
									)
								}
								onMove={ ( delta ) =>
									setRules( move( rules, index, delta ) )
								}
								onDelete={ () => setConfirm( index ) }
								onAsk={ ( question ) => {
									const result = addQuestion(
										actions,
										rules,
										index,
										question
									);
									if ( result ) {
										settings.setSection(
											'actions',
											result.actions
										);
										setRules( result.rules );
									}
								} }
							/>
						</li>
					) ) }
				</ol>

				<fieldset className="devrivo-fieldset">
					<legend>{ __( 'Add a rule', 'devrivo-chatbot' ) }</legend>
					<div className="devrivo-row">
						<TextControl
							label={ __( 'Name', 'devrivo-chatbot' ) }
							value={ newName }
							maxLength={ 80 }
							onChange={ setNewName }
							__next40pxDefaultSize
						/>
						<Button
							variant="secondary"
							onClick={ add }
							disabled={
								! newName.trim() || rules.length >= MAX_RULES
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
			<Preview changes={ settings.changes } pages />

			{ confirm !== null && rules[ confirm ] && (
				<ConfirmModal
					title={ __( 'Delete page rule?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Rule name. */
						__(
							'“%s” will be removed when you save. Its actions stay under Quick actions.',
							'devrivo-chatbot'
						),
						rules[ confirm ].name
					) }
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						setRules( rules.filter( ( _, i ) => i !== confirm ) );
						setConfirm( null );
					} }
				/>
			) }
		</div>
	);
}
