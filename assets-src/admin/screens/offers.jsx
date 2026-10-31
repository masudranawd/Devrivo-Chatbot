/**
 * Offers: up to 6 cards in the widget's Offers tab, each with an optional
 * call-to-action that runs a quick action.
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
import { Preview } from '../components/preview';
import { move, uniqueId } from '../lib/actions';
import { useSettings } from '../lib/use-settings';

export const MAX_OFFERS = 6;

/**
 * Adds an offer.
 *
 * @param {Array}  list  Offers.
 * @param {string} title Title.
 * @return {Array} New list.
 */
export function addOffer( list, title ) {
	return [
		...list,
		{
			id: uniqueId(
				title,
				list.map( ( offer ) => offer.id ),
				'offer'
			),
			title,
			text: '',
			cta_label: '',
			action: '',
			featured: false,
		},
	];
}

/**
 * Offers screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Offers( { notify, setDirty } ) {
	const settings = useSettings( 'offers' );
	const [ newTitle, setNewTitle ] = useState( '' );
	const [ confirm, setConfirm ] = useState( null );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const list = settings.values.offers || [];
	const fields = settings.errors.fields;
	const setList = ( next ) => settings.setSection( 'offers', next );
	const set = ( index, key ) => ( value ) =>
		setList(
			list.map( ( offer, i ) =>
				i === index ? { ...offer, [ key ]: value } : offer
			)
		);
	const actionOptions = [
		{ label: __( 'None', 'devrivo-chatbot' ), value: '' },
		...( settings.all.actions || [] ).map( ( action ) => ( {
			label: action.label || action.id,
			value: action.id,
		} ) ),
	];

	const add = () => {
		const title = newTitle.trim();
		if ( ! title || list.length >= MAX_OFFERS ) {
			return;
		}
		setList( addOffer( list, title ) );
		setNewTitle( '' );
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />
				<p className="description">
					{ __(
						'Offers appear in the widget’s Offers tab. The tab is hidden when there are none.',
						'devrivo-chatbot'
					) }
				</p>

				<ol className="devrivo-list">
					{ list.map( ( offer, index ) => {
						const name = offer.title || offer.id;
						return (
							<li key={ offer.id + index }>
								<Card size="small">
									<CardBody>
										<div
											className="devrivo-actions__edit"
											role="group"
											aria-label={ name }
										>
											<div className="devrivo-subcard__head">
												<h3>{ name }</h3>
												<span className="devrivo-actions__buttons">
													<Button
														size="small"
														icon="arrow-up-alt2"
														label={ sprintf(
															/* translators: %s: Offer title. */
															__(
																'Move “%s” up',
																'devrivo-chatbot'
															),
															name
														) }
														disabled={ index === 0 }
														accessibleWhenDisabled
														onClick={ () =>
															setList(
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
															/* translators: %s: Offer title. */
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
															setList(
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
											<TextControl
												label={ __(
													'Title',
													'devrivo-chatbot'
												) }
												value={ offer.title }
												maxLength={ 80 }
												onChange={ set(
													index,
													'title'
												) }
												__next40pxDefaultSize
											/>
											<FieldError
												error={
													fields[ `${ index }.title` ]
												}
											/>
											<TextareaControl
												label={ __(
													'Text',
													'devrivo-chatbot'
												) }
												value={ offer.text || '' }
												maxLength={ 300 }
												rows={ 3 }
												onChange={ set(
													index,
													'text'
												) }
											/>
											<div className="devrivo-row">
												<TextControl
													label={ __(
														'Button label',
														'devrivo-chatbot'
													) }
													help={ __(
														'Leave empty for “Find out more”.',
														'devrivo-chatbot'
													) }
													value={
														offer.cta_label || ''
													}
													maxLength={ 40 }
													onChange={ set(
														index,
														'cta_label'
													) }
													__next40pxDefaultSize
												/>
												<SelectControl
													label={ __(
														'Button runs',
														'devrivo-chatbot'
													) }
													value={ offer.action || '' }
													options={ actionOptions }
													onChange={ set(
														index,
														'action'
													) }
													__next40pxDefaultSize
												/>
											</div>
											<ToggleControl
												label={ __(
													'Featured (highlighted)',
													'devrivo-chatbot'
												) }
												checked={ !! offer.featured }
												onChange={ set(
													index,
													'featured'
												) }
											/>
										</div>
									</CardBody>
								</Card>
							</li>
						);
					} ) }
				</ol>

				<fieldset className="devrivo-fieldset">
					<legend>{ __( 'Add an offer', 'devrivo-chatbot' ) }</legend>
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
							onClick={ add }
							disabled={
								! newTitle.trim() || list.length >= MAX_OFFERS
							}
							accessibleWhenDisabled
							__next40pxDefaultSize
						>
							{ __( 'Add', 'devrivo-chatbot' ) }
						</Button>
					</div>
					{ list.length >= MAX_OFFERS && (
						<p className="description">
							{ __(
								'You can have up to 6 offers.',
								'devrivo-chatbot'
							) }
						</p>
					) }
				</fieldset>

				<SaveBar settings={ settings } notify={ notify } />
			</div>
			<Preview changes={ settings.changes } />

			{ confirm !== null && list[ confirm ] && (
				<ConfirmModal
					title={ __( 'Delete offer?', 'devrivo-chatbot' ) }
					message={ sprintf(
						/* translators: %s: Offer title. */
						__(
							'“%s” will be removed when you save.',
							'devrivo-chatbot'
						),
						list[ confirm ].title
					) }
					confirm={ __( 'Delete', 'devrivo-chatbot' ) }
					onCancel={ () => setConfirm( null ) }
					onConfirm={ () => {
						setList( list.filter( ( _, i ) => i !== confirm ) );
						setConfirm( null );
					} }
				/>
			) }
		</div>
	);
}
