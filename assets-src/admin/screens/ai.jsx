/**
 * AI: provider and model (from the provider's list), monthly token limit
 * with a usage meter, custom instructions, disclaimer and the on/off switch.
 * Keys are never handled here: providers are connected in Settings →
 * Connectors.
 */
import {
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { request, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

/**
 * Usage meter.
 *
 * @param {Object} props
 * @param {number} props.used  Tokens used.
 * @param {number} props.limit Limit.
 * @return {Element} Meter.
 */
function Usage( { used, limit } ) {
	const percent =
		limit > 0 ? Math.min( 100, Math.round( ( used / limit ) * 100 ) ) : 0;
	const text =
		limit > 0
			? sprintf(
					/* translators: 1: Tokens used, 2: Monthly limit, 3: Percent. */
					__(
						'%1$s of %2$s tokens used this month (%3$d%%)',
						'devrivo-chatbot'
					),
					used.toLocaleString(),
					limit.toLocaleString(),
					percent
				)
			: sprintf(
					/* translators: %s: Tokens used. */
					__( '%s tokens used this month', 'devrivo-chatbot' ),
					used.toLocaleString()
				);

	return (
		<div className="devrivo-usage">
			<div
				className="devrivo-usage__bar"
				role="meter"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ percent }
				aria-valuetext={ text }
				aria-label={ __( 'Token usage', 'devrivo-chatbot' ) }
			>
				<span style={ { width: `${ percent }%` } } />
			</div>
			<p>{ text }</p>
		</div>
	);
}

/**
 * AI screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Ai( { notify, setDirty } ) {
	const settings = useSettings( 'ai' );
	const [ status, setStatus ] = useState( null );
	const [ statusError, setStatusError ] = useState( '' );
	useDirty( setDirty, settings.dirty );

	const provider = settings.values.ai?.provider || '';

	// Status, with the model list of the provider chosen on screen.
	useEffect( () => {
		if ( ! settings.ready ) {
			return;
		}
		let active = true;
		request(
			provider
				? `/ai/status?provider=${ encodeURIComponent( provider ) }`
				: '/ai/status'
		)
			.then(
				( result ) =>
					active && ( setStatus( result ), setStatusError( '' ) )
			)
			.catch(
				( error ) =>
					active && setStatusError( toErrors( error ).message )
			);
		return () => {
			active = false;
		};
	}, [ provider, settings.ready, settings.saved.ai?.enabled ] );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}
	if ( ! status ) {
		return statusError ? (
			<Notice status="error" isDismissible={ false }>
				{ statusError }
			</Notice>
		) : (
			<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
		);
	}

	const ai = settings.values.ai;
	const fields = settings.errors.fields;
	const set = ( key ) => ( value ) => settings.setField( 'ai', key, value );
	const connected = status.providers.filter( ( item ) => item.configured );
	const connectors = boot().connectorsUrl;

	if ( ! status.core_available || ! connected.length ) {
		return (
			<div className="devrivo-form">
				<Notice status="info" isDismissible={ false }>
					<p>
						{ ! status.core_available
							? __(
									'AI is not available on this site. WordPress AI features may be turned off by your host.',
									'devrivo-chatbot'
								)
							: __(
									'No AI provider is connected yet. Install an AI provider plugin (Anthropic, Google or OpenAI), then add your key in Settings → Connectors.',
									'devrivo-chatbot'
								) }
					</p>
					{ status.core_available && connectors && (
						<p>
							<a href={ connectors }>
								{ __(
									'Open Settings → Connectors',
									'devrivo-chatbot'
								) }
							</a>
						</p>
					) }
				</Notice>
				<p>
					{ __(
						'Until then, the assistant answers from your pages and FAQs (Guided mode).',
						'devrivo-chatbot'
					) }
				</p>
			</div>
		);
	}

	const models = status.models || [];

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />

			<SelectControl
				label={ __( 'AI provider', 'devrivo-chatbot' ) }
				value={ provider }
				options={ [
					{ label: __( 'Choose…', 'devrivo-chatbot' ), value: '' },
					...connected.map( ( item ) => ( {
						label: item.id,
						value: item.id,
					} ) ),
				] }
				onChange={ ( value ) => {
					set( 'provider' )( value );
					set( 'model' )( '' );
				} }
				help={
					<>
						{ __(
							'Only providers connected in Settings → Connectors are listed.',
							'devrivo-chatbot'
						) }{ ' ' }
						<a href={ connectors }>
							{ __( 'Manage connectors', 'devrivo-chatbot' ) }
						</a>
					</>
				}
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.provider } />

			{ provider && (
				<>
					{ status.models_error && (
						<Notice status="warning" isDismissible={ false }>
							{ status.models_error }
						</Notice>
					) }
					<SelectControl
						label={ __( 'Model', 'devrivo-chatbot' ) }
						value={ ai.model }
						options={ [
							{
								label: __( 'Choose…', 'devrivo-chatbot' ),
								value: '',
							},
							...models.map( ( model ) => ( {
								label: model.name
									? `${ model.name } (${ model.id })`
									: model.id,
								value: model.id,
							} ) ),
						] }
						onChange={ set( 'model' ) }
						help={ __(
							'Smaller “flash” or “mini” models are cheaper and fast enough for website questions.',
							'devrivo-chatbot'
						) }
						__next40pxDefaultSize
					/>
					<FieldError error={ fields.model } />
				</>
			) }

			<TextControl
				type="number"
				label={ __( 'Monthly token limit', 'devrivo-chatbot' ) }
				help={ __(
					'AI stops for the rest of the month when this is reached, and the assistant uses Guided answers. 0 turns AI off.',
					'devrivo-chatbot'
				) }
				min={ 0 }
				step={ 1000 }
				value={ String( ai.monthly_token_limit ) }
				onChange={ ( value ) =>
					set( 'monthly_token_limit' )(
						Math.max( 0, parseInt( value, 10 ) || 0 )
					)
				}
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.monthly_token_limit } />
			<Usage
				used={ status.usage?.tokens || 0 }
				limit={ ai.monthly_token_limit }
			/>

			<TextareaControl
				label={ __(
					'Extra instructions for the assistant',
					'devrivo-chatbot'
				) }
				help={ __(
					'Tone and focus, for example “Keep a warm, professional tone.” The built-in safety rules always apply.',
					'devrivo-chatbot'
				) }
				value={ ai.custom_instructions }
				maxLength={ 2000 }
				rows={ 5 }
				onChange={ set( 'custom_instructions' ) }
			/>
			<FieldError error={ fields.custom_instructions } />

			<TextControl
				label={ __(
					'Disclaimer under the chat box',
					'devrivo-chatbot'
				) }
				help={ __(
					'Leave empty for “AI assistant. General information only.”',
					'devrivo-chatbot'
				) }
				value={ ai.disclaimer }
				maxLength={ 200 }
				onChange={ set( 'disclaimer' ) }
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.disclaimer } />

			<ToggleControl
				label={ __( 'Use AI to answer questions', 'devrivo-chatbot' ) }
				help={ __(
					'Needs a provider, a model and a monthly limit. If AI fails, the assistant answers from your pages instead.',
					'devrivo-chatbot'
				) }
				checked={ !! ai.enabled }
				onChange={ set( 'enabled' ) }
			/>
			<FieldError error={ fields.enabled } />

			<p className="description">
				{ __(
					'Each AI answer sends the visitor’s message, recent messages, matching excerpts of your published content, the page title and address, and these instructions to your provider. Form contact details are never sent.',
					'devrivo-chatbot'
				) }
			</p>

			<SaveBar settings={ settings } notify={ notify } />
		</div>
	);
}
