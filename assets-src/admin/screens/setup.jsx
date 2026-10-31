/**
 * Setup wizard (#/setup): eight short steps from a site scan to going live.
 * Progress is saved after each step, so the admin can leave and resume.
 * Suggestions from the scan are only saved when the admin confirms a step.
 */
import {
	BaseControl,
	Button,
	CheckboxControl,
	ColorPicker,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { createChat } from '../../widget/lib/chat';
import {
	ErrorSummary,
	FieldError,
	Loading,
	useDirty,
} from '../components/common';
import { Preview } from '../components/preview';
import { request, toErrors } from '../lib/api';
import { boot } from '../lib/boot';
import { DAYS } from '../lib/hours';
import {
	actionFixes,
	applyFixes,
	BUSINESS_KEYS,
	DEFAULT_TOKEN_LIMIT,
	hasHours,
	hasOwnSettings,
	initialBusiness,
	STEPS,
	stepIndex,
	weeklySchedule,
} from '../lib/setup';
import { publish, useSettings } from '../lib/use-settings';
import { IndexStatus, useIndexStatus } from './knowledge';
import { PresetMode } from './tools';

const NO_ERRORS = { message: '', fields: {} };
const NOOP = () => {};

/**
 * Translated step titles.
 *
 * @return {Object<string, string>} Step => title.
 */
export function stepTitles() {
	return {
		scan: __( 'Your site', 'devrivo-chatbot' ),
		preset: __( 'Choose a starting point', 'devrivo-chatbot' ),
		business: __( 'Your business details', 'devrivo-chatbot' ),
		ai: __( 'AI answers', 'devrivo-chatbot' ),
		appearance: __( 'Look and feel', 'devrivo-chatbot' ),
		knowledge: __( 'Learn from your site', 'devrivo-chatbot' ),
		test: __( 'Test a question', 'devrivo-chatbot' ),
		golive: __( 'Go live', 'devrivo-chatbot' ),
	};
}

/**
 * Back, Skip and Next buttons.
 *
 * @param {Object}     props
 * @param {() => void} props.onBack    Back (none on the first step).
 * @param {() => void} props.onSkip    Skip (none on the last step).
 * @param {() => void} props.onNext    Next.
 * @param {string}     props.nextLabel Next label.
 * @param {boolean}    props.busy      Saving.
 * @return {Element} Buttons.
 */
function StepNav( { onBack, onSkip, onNext, nextLabel, busy } ) {
	return (
		<div className="devrivo-savebar devrivo-setup__nav">
			{ onBack && (
				<Button
					variant="tertiary"
					onClick={ onBack }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Back', 'devrivo-chatbot' ) }
				</Button>
			) }
			{ onSkip && (
				<Button
					variant="secondary"
					onClick={ onSkip }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Skip', 'devrivo-chatbot' ) }
				</Button>
			) }
			{ onNext && (
				<Button
					variant="primary"
					onClick={ onNext }
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ nextLabel || __( 'Next', 'devrivo-chatbot' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * Saves sections like a screen's Save button and publishes the result.
 *
 * @param {Object}   data     Sections.
 * @param {string[]} sections Section names (for field errors).
 * @return {Promise<Object|null>} Errors, or null when saved.
 */
async function saveSettings( data, sections ) {
	try {
		publish( await request( '/settings', { method: 'POST', data } ) );
		return null;
	} catch ( error ) {
		return toErrors( error, sections );
	}
}

/**
 * Step 1: what the scan found.
 *
 * @param {Object} props
 * @param {Object} props.scan    Scan result or null.
 * @param {string} props.error   Scan error.
 * @param {Array}  props.presets Presets list or null.
 * @param {Object} props.nav     StepNav props.
 * @return {Element} Step.
 */
function ScanStep( { scan, error, presets, nav } ) {
	if ( error ) {
		return (
			<>
				<Notice status="warning" isDismissible={ false }>
					{ error }
				</Notice>
				<StepNav { ...nav } />
			</>
		);
	}
	if ( ! scan ) {
		return <p>{ __( 'Looking at your site…', 'devrivo-chatbot' ) }</p>;
	}
	const preset = ( presets || [] ).find(
		( item ) => item.id === scan.suggestion.preset
	);
	const content = scan.content.filter( ( item ) => item.published > 0 );

	return (
		<>
			<dl className="devrivo-setup__facts">
				<dt>{ __( 'Site name', 'devrivo-chatbot' ) }</dt>
				<dd>{ scan.site_name || '–' }</dd>
				<dt>{ __( 'Tagline', 'devrivo-chatbot' ) }</dt>
				<dd>{ scan.tagline || '–' }</dd>
				<dt>{ __( 'Published content', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ content.length
						? content
								.map( ( item ) =>
									sprintf(
										/* translators: 1: Number, 2: Post type name. */
										__( '%1$d %2$s', 'devrivo-chatbot' ),
										item.published,
										item.label
									)
								)
								.join( ', ' )
						: __( 'None yet', 'devrivo-chatbot' ) }
				</dd>
				<dt>{ __( 'Plugins we work with', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ scan.plugins.length
						? scan.plugins.map( ( item ) => item.name ).join( ', ' )
						: __( 'None found', 'devrivo-chatbot' ) }
				</dd>
				<dt>{ __( 'Privacy policy page', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ scan.privacy_policy ? (
						__( 'Set', 'devrivo-chatbot' )
					) : (
						<>
							{ __(
								'Not set. Forms link to it next to the consent box.',
								'devrivo-chatbot'
							) }{ ' ' }
							<a href={ boot().privacySettingsUrl }>
								{ __( 'Set one', 'devrivo-chatbot' ) }
							</a>
						</>
					) }
				</dd>
				<dt>{ __( 'Contact details found', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ [ ...scan.contacts.emails, ...scan.contacts.phones ]
						.length
						? [
								...scan.contacts.emails,
								...scan.contacts.phones,
							].join( ', ' )
						: __( 'None', 'devrivo-chatbot' ) }
				</dd>
			</dl>
			<Notice status="info" isDismissible={ false }>
				<p>
					<strong>
						{ sprintf(
							/* translators: %s: Preset name. */
							__(
								'Suggested starting point: %s',
								'devrivo-chatbot'
							),
							preset ? preset.title : scan.suggestion.preset
						) }
					</strong>
				</p>
				<ul className="devrivo-error-list">
					{ scan.suggestion.reasons.map( ( reason, index ) => (
						<li key={ index }>{ reason }</li>
					) ) }
				</ul>
				{ scan.suggestion.note && <p>{ scan.suggestion.note }</p> }
			</Notice>
			<p className="description">
				{ __(
					'Nothing has been changed yet. You confirm each step.',
					'devrivo-chatbot'
				) }
			</p>
			<StepNav { ...nav } />
		</>
	);
}

/**
 * Step 2: apply a preset (Merge by default).
 *
 * @param {Object} props
 * @param {Object} props.scan    Scan result or null.
 * @param {Array}  props.presets Presets list or null.
 * @param {Object} props.all     All settings.
 * @param {Object} props.nav     StepNav props.
 * @return {Element} Step.
 */
function PresetStep( { scan, presets, all, nav } ) {
	const [ chosen, setChosen ] = useState( '' );
	const [ mode, setMode ] = useState( 'merge' );
	const [ detail, setDetail ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ errors, setErrors ] = useState( NO_ERRORS );
	const id =
		chosen ||
		scan?.suggestion?.preset ||
		all.general?.preset ||
		presets?.[ 0 ]?.id ||
		'';
	const own = hasOwnSettings( all );

	useEffect( () => {
		setDetail( null );
		if ( ! id ) {
			return;
		}
		let active = true;
		request( `/presets/${ id }` )
			.then( ( result ) => active && setDetail( result ) )
			.catch( () => {} );
		return () => {
			active = false;
		};
	}, [ id ] );

	if ( ! presets ) {
		return <p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>;
	}

	const apply = async () => {
		setBusy( true );
		setErrors( NO_ERRORS );
		try {
			const result = await request( `/presets/${ id }/apply`, {
				method: 'POST',
				data: { mode },
			} );
			publish( result.settings );
			nav.onNext();
		} catch ( error ) {
			setErrors( toErrors( error ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<>
			<ErrorSummary errors={ errors } />
			<SelectControl
				label={ __( 'Preset', 'devrivo-chatbot' ) }
				value={ id }
				options={ presets.map( ( item ) => ( {
					label:
						item.id === scan?.suggestion?.preset
							? sprintf(
									/* translators: %s: Preset name. */
									__( '%s (suggested)', 'devrivo-chatbot' ),
									item.title
								)
							: item.title,
					value: item.id,
				} ) ) }
				onChange={ setChosen }
				help={ presets.find( ( item ) => item.id === id )?.description }
				__next40pxDefaultSize
			/>
			{ own && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Your site already has its own settings. Merge keeps them; Replace overwrites the sections listed.',
						'devrivo-chatbot'
					) }
				</Notice>
			) }
			<PresetMode preset={ detail } mode={ mode } setMode={ setMode } />
			<StepNav
				{ ...nav }
				onNext={ apply }
				busy={ busy || ( mode === 'replace' && ! detail ) }
				nextLabel={ __( 'Apply and continue', 'devrivo-chatbot' ) }
			/>
		</>
	);
}

/**
 * Step 3: business profile, quick actions it can complete, opening hours and
 * the notification email.
 *
 * @param {Object}                   props
 * @param {Object}                   props.scan     Scan result or null.
 * @param {Object}                   props.all      All settings.
 * @param {Object}                   props.nav      StepNav props.
 * @param {(dirty: boolean) => void} props.setDirty Unsaved state.
 * @return {Element} Step.
 */
export function BusinessStep( { scan, all, nav, setDirty = NOOP } ) {
	const start = initialBusiness( all.general?.business, scan );
	const [ values, setValues ] = useState( start.values );
	const [ suggested ] = useState( start.suggested );
	const [ overrides, setOverrides ] = useState( {} );
	const savedHours = all.behaviour?.hours?.schedule;
	const [ setHours, setSetHours ] = useState( ! hasHours( savedHours ) );
	const [ days, setDays ] = useState( DAYS.slice( 0, 5 ) );
	const [ open, setOpen ] = useState( '09:00' );
	const [ close, setClose ] = useState( '17:00' );
	const savedEmails = all.integrations?.notification_emails || [];
	const [ email, setEmail ] = useState(
		savedEmails[ 0 ] || scan?.admin_email || ''
	);
	const [ busy, setBusy ] = useState( false );
	const [ errors, setErrors ] = useState( NO_ERRORS );
	const snapshot = JSON.stringify( [
		values,
		overrides,
		setHours,
		days,
		open,
		close,
		email,
	] );
	const [ initial ] = useState( snapshot );
	useDirty( setDirty, snapshot !== initial );

	const labels = {
		name: __( 'Business name', 'devrivo-chatbot' ),
		phone: __( 'Phone number', 'devrivo-chatbot' ),
		whatsapp: __( 'WhatsApp number', 'devrivo-chatbot' ),
		email: __( 'Contact email', 'devrivo-chatbot' ),
		booking_url: __( 'Booking page (https://…)', 'devrivo-chatbot' ),
		address: __( 'Address', 'devrivo-chatbot' ),
	};
	const fixes = actionFixes( all.actions, boot().actionTypes, values ).map(
		( fix ) => ( {
			...fix,
			value: overrides[ fix.index ]?.value ?? fix.value,
			on:
				overrides[ fix.index ]?.on ??
				Boolean(
					( overrides[ fix.index ]?.value ?? fix.value ).trim()
				),
		} )
	);
	const fieldNames = {
		phone: __( 'phone number', 'devrivo-chatbot' ),
		email: __( 'email address', 'devrivo-chatbot' ),
		url: __( 'link', 'devrivo-chatbot' ),
	};
	const override = ( index, patch ) =>
		setOverrides( ( current ) => ( {
			...current,
			[ index ]: { ...current[ index ], ...patch },
		} ) );
	const timeError =
		setHours &&
		( ! /^\d{2}:\d{2}$/.test( open ) ||
			! /^\d{2}:\d{2}$/.test( close ) ||
			open >= close );

	const save = async () => {
		if ( timeError ) {
			setErrors( {
				message: __(
					'The opening time must be before the closing time.',
					'devrivo-chatbot'
				),
				fields: {},
			} );
			return;
		}
		const data = {
			general: { business: values },
		};
		if ( fixes.some( ( fix ) => fix.on && fix.value.trim() ) ) {
			data.actions = applyFixes( all.actions, fixes );
		}
		if ( setHours && days.length ) {
			data.behaviour = {
				hours: {
					...( all.behaviour?.hours || {} ),
					schedule: weeklySchedule( days, open, close ),
				},
			};
		}
		const trimmed = email.trim();
		if (
			trimmed &&
			trimmed !== savedEmails[ 0 ] &&
			! ( ! savedEmails.length && trimmed === scan?.admin_email )
		) {
			data.integrations = {
				notification_emails: [
					trimmed,
					...savedEmails.slice( 1 ).filter( ( e ) => e !== trimmed ),
				],
			};
		}
		setBusy( true );
		const problem = await saveSettings( data, Object.keys( data ) );
		setBusy( false );
		if ( problem ) {
			setErrors( problem );
			return;
		}
		nav.onNext();
	};

	return (
		<>
			<ErrorSummary errors={ errors } />
			<p className="description">
				{ __(
					'The assistant shares these with visitors, and they fill in your quick actions.',
					'devrivo-chatbot'
				) }
			</p>
			{ BUSINESS_KEYS.map( ( key ) => {
				const Control =
					key === 'address' ? TextareaControl : TextControl;
				const found = suggested.includes( key );
				return (
					<div key={ key }>
						<Control
							label={ labels[ key ] }
							type={
								{
									phone: 'tel',
									whatsapp: 'tel',
									email: 'email',
									booking_url: 'url',
								}[ key ] || 'text'
							}
							value={ values[ key ] }
							onChange={ ( value ) =>
								setValues( { ...values, [ key ]: value } )
							}
							help={
								found
									? __(
											'Found on your site, please check.',
											'devrivo-chatbot'
										)
									: undefined
							}
							className={
								found ? 'devrivo-setup__found' : undefined
							}
							rows={ key === 'address' ? 2 : undefined }
							__next40pxDefaultSize
						/>
						<FieldError
							error={ errors.fields[ `business.${ key }` ] }
						/>
					</div>
				);
			} ) }

			{ fixes.length > 0 && (
				<fieldset className="devrivo-fieldset">
					<legend>
						{ __(
							'Complete your quick actions',
							'devrivo-chatbot'
						) }
					</legend>
					<p className="description">
						{ __(
							'These quick actions are hidden from visitors until they have a value. Untick any you want to leave as they are.',
							'devrivo-chatbot'
						) }
					</p>
					{ fixes.map( ( fix ) => (
						<div
							key={ fix.index }
							className="devrivo-row"
							data-testid={ `fix-${ fix.index }` }
						>
							<CheckboxControl
								label={ sprintf(
									/* translators: 1: Quick action label, 2: Field name. */
									__(
										'Fill in the %2$s for “%1$s”',
										'devrivo-chatbot'
									),
									fix.label,
									fieldNames[ fix.key ] || fix.key
								) }
								checked={ fix.on }
								onChange={ ( on ) =>
									override( fix.index, { on } )
								}
							/>
							<TextControl
								label={ sprintf(
									/* translators: %s: Quick action label. */
									__( 'Value for “%s”', 'devrivo-chatbot' ),
									fix.label
								) }
								hideLabelFromVision
								value={ fix.value }
								onChange={ ( value ) =>
									override( fix.index, { value } )
								}
								__next40pxDefaultSize
							/>
						</div>
					) ) }
				</fieldset>
			) }

			<fieldset className="devrivo-fieldset">
				<legend>{ __( 'Opening hours', 'devrivo-chatbot' ) }</legend>
				<ToggleControl
					label={ __(
						'Set the same hours for the days below',
						'devrivo-chatbot'
					) }
					help={
						hasHours( savedHours )
							? __(
									'You already have opening hours. Turn this on only to replace them.',
									'devrivo-chatbot'
								)
							: __(
									'You can fine-tune them later under Behaviour.',
									'devrivo-chatbot'
								)
					}
					checked={ setHours }
					onChange={ setSetHours }
				/>
				{ setHours && (
					<>
						<div className="devrivo-setup__days">
							{ DAYS.map( ( day ) => (
								<CheckboxControl
									key={ day }
									label={ dayName( day ) }
									checked={ days.includes( day ) }
									onChange={ ( on ) =>
										setDays(
											on
												? DAYS.filter(
														( d ) =>
															d === day ||
															days.includes( d )
													)
												: days.filter(
														( d ) => d !== day
													)
										)
									}
								/>
							) ) }
						</div>
						<div className="devrivo-row">
							<TextControl
								type="time"
								label={ __( 'Opens', 'devrivo-chatbot' ) }
								value={ open }
								onChange={ setOpen }
								__next40pxDefaultSize
							/>
							<TextControl
								type="time"
								label={ __( 'Closes', 'devrivo-chatbot' ) }
								value={ close }
								onChange={ setClose }
								__next40pxDefaultSize
							/>
						</div>
						{ timeError && (
							<FieldError
								error={ __(
									'The opening time must be before the closing time.',
									'devrivo-chatbot'
								) }
							/>
						) }
					</>
				) }
			</fieldset>

			<TextControl
				type="email"
				label={ __(
					'Send new enquiries to this email',
					'devrivo-chatbot'
				) }
				value={ email }
				onChange={ setEmail }
				__next40pxDefaultSize
			/>
			<FieldError
				error={
					errors.fields[ 'notification_emails.0' ] ||
					errors.fields.notification_emails
				}
			/>

			<StepNav
				{ ...nav }
				onNext={ save }
				busy={ busy }
				nextLabel={ __( 'Save and continue', 'devrivo-chatbot' ) }
			/>
		</>
	);
}

/**
 * Translated day name.
 *
 * @param {string} day Day key.
 * @return {string} Name.
 */
function dayName( day ) {
	return {
		mon: __( 'Monday', 'devrivo-chatbot' ),
		tue: __( 'Tuesday', 'devrivo-chatbot' ),
		wed: __( 'Wednesday', 'devrivo-chatbot' ),
		thu: __( 'Thursday', 'devrivo-chatbot' ),
		fri: __( 'Friday', 'devrivo-chatbot' ),
		sat: __( 'Saturday', 'devrivo-chatbot' ),
		sun: __( 'Sunday', 'devrivo-chatbot' ),
	}[ day ];
}

/**
 * Step 4: AI. Three states: no provider plugin, provider not connected, or
 * ready (provider, model, monthly limit, on). Guided mode is always offered.
 *
 * @param {Object}                   props
 * @param {Object}                   props.all      All settings.
 * @param {Object}                   props.nav      StepNav props.
 * @param {(dirty: boolean) => void} props.setDirty Unsaved state.
 * @return {Element} Step.
 */
export function AiStep( { all, nav, setDirty = NOOP } ) {
	const saved = all.ai || {};
	const [ provider, setProvider ] = useState( saved.provider || '' );
	const [ model, setModel ] = useState( saved.model || '' );
	const [ limit, setLimit ] = useState(
		saved.monthly_token_limit > 0
			? saved.monthly_token_limit
			: DEFAULT_TOKEN_LIMIT
	);
	const [ enabled, setEnabled ] = useState( true );
	const snapshot = JSON.stringify( [ provider, model, limit, enabled ] );
	const [ initial ] = useState( snapshot );
	useDirty( setDirty, snapshot !== initial );
	const [ status, setStatus ] = useState( null );
	const [ loadError, setLoadError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ errors, setErrors ] = useState( NO_ERRORS );

	useEffect( () => {
		let active = true;
		request(
			provider
				? `/ai/status?provider=${ encodeURIComponent( provider ) }`
				: '/ai/status'
		)
			.then( ( result ) => active && setStatus( result ) )
			.catch(
				( error ) => active && setLoadError( toErrors( error ).message )
			);
		return () => {
			active = false;
		};
	}, [ provider ] );

	const guided = async () => {
		if ( saved.enabled ) {
			setBusy( true );
			const problem = await saveSettings( { ai: { enabled: false } }, [
				'ai',
			] );
			setBusy( false );
			if ( problem ) {
				setErrors( problem );
				return;
			}
		}
		nav.onNext();
	};
	const guidedButton = (
		<Button
			variant="secondary"
			onClick={ guided }
			disabled={ busy }
			__next40pxDefaultSize
		>
			{ __( 'Use Guided mode for now', 'devrivo-chatbot' ) }
		</Button>
	);
	const guidedText = __(
		'In Guided mode the assistant answers from your pages and FAQs, with buttons and forms. No AI account is needed, and you can add AI later.',
		'devrivo-chatbot'
	);

	if ( ! status ) {
		return loadError ? (
			<>
				<Notice status="warning" isDismissible={ false }>
					{ loadError }
				</Notice>
				<p>{ guidedText }</p>
				<p>{ guidedButton }</p>
				<StepNav { ...nav } onNext={ null } />
			</>
		) : (
			<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
		);
	}

	const providers = status.providers || [];
	const connected = providers.filter( ( item ) => item.configured );
	let state = 'ready';
	if ( ! status.core_available ) {
		state = 'unavailable';
	} else if ( ! providers.length ) {
		state = 'no-plugin';
	} else if ( ! connected.length ) {
		state = 'not-connected';
	}

	if ( state !== 'ready' ) {
		return (
			<>
				<Notice status="info" isDismissible={ false }>
					{ state === 'unavailable' && (
						<p>
							{ __(
								'AI is not available on this site. WordPress AI features may be turned off by your host.',
								'devrivo-chatbot'
							) }
						</p>
					) }
					{ state === 'no-plugin' && (
						<>
							<p>
								{ __(
									'To use AI answers, install an AI provider plugin (Anthropic, Google or OpenAI), then come back to this step.',
									'devrivo-chatbot'
								) }
							</p>
							<p>
								<a href={ boot().aiPluginsUrl }>
									{ __(
										'Find AI provider plugins',
										'devrivo-chatbot'
									) }
								</a>
							</p>
						</>
					) }
					{ state === 'not-connected' && (
						<>
							<p>
								{ __(
									'An AI provider plugin is installed but not connected yet. Add your key in Settings → Connectors, then come back to this step.',
									'devrivo-chatbot'
								) }
							</p>
							<p>
								<a href={ boot().connectorsUrl }>
									{ __(
										'Open Settings → Connectors',
										'devrivo-chatbot'
									) }
								</a>
							</p>
						</>
					) }
				</Notice>
				<ErrorSummary errors={ errors } />
				<p>{ guidedText }</p>
				<p>{ guidedButton }</p>
				<StepNav { ...nav } onNext={ null } />
			</>
		);
	}

	const save = async () => {
		setBusy( true );
		const problem = await saveSettings(
			{
				ai: {
					provider,
					model,
					monthly_token_limit: limit,
					enabled,
				},
			},
			[ 'ai' ]
		);
		setBusy( false );
		if ( problem ) {
			setErrors( problem );
			return;
		}
		nav.onNext();
	};

	return (
		<>
			<ErrorSummary errors={ errors } />
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
					setProvider( value );
					setModel( '' );
				} }
				__next40pxDefaultSize
			/>
			<FieldError error={ errors.fields.provider } />
			{ provider && (
				<>
					{ status.models_error && (
						<Notice status="warning" isDismissible={ false }>
							{ status.models_error }
						</Notice>
					) }
					<SelectControl
						label={ __( 'Model', 'devrivo-chatbot' ) }
						value={ model }
						options={ [
							{
								label: __( 'Choose…', 'devrivo-chatbot' ),
								value: '',
							},
							...( status.models || [] ).map( ( item ) => ( {
								label: item.name
									? `${ item.name } (${ item.id })`
									: item.id,
								value: item.id,
							} ) ),
						] }
						onChange={ setModel }
						help={ __(
							'Smaller “flash” or “mini” models are cheaper and fast enough for website questions.',
							'devrivo-chatbot'
						) }
						__next40pxDefaultSize
					/>
					<FieldError error={ errors.fields.model } />
				</>
			) }
			<TextControl
				type="number"
				label={ __( 'Monthly token limit', 'devrivo-chatbot' ) }
				help={ __(
					'AI stops for the rest of the month at this limit, and Guided answers take over.',
					'devrivo-chatbot'
				) }
				min={ 0 }
				step={ 1000 }
				value={ String( limit ) }
				onChange={ ( value ) =>
					setLimit( Math.max( 0, parseInt( value, 10 ) || 0 ) )
				}
				__next40pxDefaultSize
			/>
			<FieldError error={ errors.fields.monthly_token_limit } />
			<ToggleControl
				label={ __( 'Turn on AI answers', 'devrivo-chatbot' ) }
				checked={ enabled }
				onChange={ setEnabled }
			/>
			<p>{ guidedButton }</p>
			<StepNav
				{ ...nav }
				onNext={ save }
				busy={ busy }
				nextLabel={ __( 'Save and continue', 'devrivo-chatbot' ) }
			/>
		</>
	);
}

/**
 * Step 5: main colour, launcher text and position, with the live preview.
 *
 * @param {Object}                   props
 * @param {Object}                   props.nav      StepNav props.
 * @param {(dirty: boolean) => void} props.setDirty Unsaved state.
 * @return {Element} Step.
 */
function AppearanceStep( { nav, setDirty = NOOP } ) {
	const settings = useSettings( 'display' );
	useDirty( setDirty, settings.dirty );
	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}
	const display = settings.values.display;
	const set = ( key ) => ( value ) =>
		settings.setField( 'display', key, value );
	const save = async () => {
		if ( await settings.save() ) {
			nav.onNext();
		}
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<ErrorSummary errors={ settings.errors } />
				<BaseControl
					id="devrivo-setup-color"
					label={ __( 'Main colour', 'devrivo-chatbot' ) }
				>
					<ColorPicker
						color={ display.primary_color }
						onChange={ set( 'primary_color' ) }
						enableAlpha={ false }
					/>
				</BaseControl>
				<FieldError error={ settings.errors.fields.primary_color } />
				<TextControl
					label={ __( 'Launcher text', 'devrivo-chatbot' ) }
					help={ __(
						'Leave empty for the default “How can we help?”.',
						'devrivo-chatbot'
					) }
					value={ display.launcher_label }
					maxLength={ 60 }
					onChange={ set( 'launcher_label' ) }
					__next40pxDefaultSize
				/>
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
					onChange={ set( 'position' ) }
					__next40pxDefaultSize
				/>
				<StepNav
					{ ...nav }
					onNext={ save }
					busy={ settings.saving }
					nextLabel={ __( 'Save and continue', 'devrivo-chatbot' ) }
				/>
			</div>
			<Preview changes={ settings.changes } devices />
		</div>
	);
}

/**
 * Step 6: build the knowledge index (runs in the background).
 *
 * @param {Object} props
 * @param {Object} props.nav StepNav props.
 * @return {Element} Step.
 */
function KnowledgeStep( { nav } ) {
	const index = useIndexStatus();
	const started = useRef( false );
	const { status, rebuild } = index;

	// Start once when the step opens, unless a rebuild is already running.
	useEffect( () => {
		if ( status && ! started.current ) {
			started.current = true;
			if ( status.rebuild?.status !== 'running' ) {
				rebuild();
			}
		}
	}, [ status, rebuild ] );

	return (
		<>
			<p>
				{ __(
					'The assistant learns from your published pages, posts and FAQs. This runs in the background: you can continue straight away.',
					'devrivo-chatbot'
				) }
			</p>
			<IndexStatus index={ index } />
			<StepNav { ...nav } />
		</>
	);
}

/**
 * Step 7: ask the real chat endpoint a question, next to the live preview.
 *
 * @param {Object} props
 * @param {Object} props.nav    StepNav props.
 * @param {Object} props.client Chat client (tests); default: the public chat.
 * @return {Element} Step.
 */
export function TestStep( { nav, client } ) {
	const chat = useRef( client || null );
	const [ question, setQuestion ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ answer, setAnswer ] = useState( null );
	const [ error, setError ] = useState( '' );

	const ask = async ( event ) => {
		event.preventDefault();
		const message = question.trim();
		if ( ! message || busy ) {
			return;
		}
		if ( ! chat.current ) {
			const data = boot();
			chat.current = createChat( {
				chatUrl: data.chatUrl,
				sessionUrl: data.sessionUrl,
				pageUrl: () => data.homeUrl,
			} );
		}
		setBusy( true );
		setError( '' );
		setAnswer( null );
		try {
			const result = await chat.current.send( message, [] );
			if ( result.ok && result.answer ) {
				setAnswer( result.answer );
			} else {
				setError(
					result.status === 429
						? __(
								'Too many test questions in a short time. Please wait a moment.',
								'devrivo-chatbot'
							)
						: __(
								'The assistant could not answer. Please try again.',
								'devrivo-chatbot'
							)
				);
			}
		} catch {
			setError(
				__(
					'The question could not be sent. Please check your connection.',
					'devrivo-chatbot'
				)
			);
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="devrivo-split">
			<div className="devrivo-form">
				<form onSubmit={ ask } className="devrivo-row">
					<TextControl
						label={ __( 'Your question', 'devrivo-chatbot' ) }
						help={ __(
							'Ask what a visitor would ask, e.g. about your services or opening hours.',
							'devrivo-chatbot'
						) }
						value={ question }
						maxLength={ 500 }
						onChange={ setQuestion }
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! question.trim() }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Ask', 'devrivo-chatbot' ) }
					</Button>
				</form>
				<div aria-live="polite">
					{ busy && <p>{ __( 'Thinking…', 'devrivo-chatbot' ) }</p> }
					{ error && (
						<Notice status="warning" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ answer && (
						<div className="devrivo-setup__answer">
							<p>
								<span className="devrivo-badge">
									{ answer.mode === 'ai'
										? __(
												'Answered by AI',
												'devrivo-chatbot'
											)
										: __(
												'Guided mode (from your pages and FAQs)',
												'devrivo-chatbot'
											) }
								</span>
							</p>
							<p className="devrivo-setup__reply">
								{ answer.text }
							</p>
							{ answer.sources.length > 0 && (
								<ul>
									{ answer.sources.map( ( source ) => (
										<li key={ source.url }>
											<a
												href={ source.url }
												target="_blank"
												rel="noopener noreferrer"
											>
												{ source.title || source.url }
											</a>
										</li>
									) ) }
								</ul>
							) }
						</div>
					) }
				</div>
				<StepNav { ...nav } />
			</div>
			<Preview changes={ {} } />
		</div>
	);
}

/**
 * Step 8: summary, then turn the assistant on (or finish without).
 *
 * @param {Object} props
 * @param {Object} props.all     All settings.
 * @param {Array}  props.presets Presets list or null.
 * @param {Object} props.nav     StepNav props.
 * @return {Element} Step.
 */
export function GoLiveStep( { all, presets, nav } ) {
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( null );
	const [ error, setError ] = useState( '' );
	const preset = ( presets || [] ).find(
		( item ) => item.id === all.general?.preset
	);
	const business = all.general?.business || {};
	const shown = ( all.actions || [] ).length;

	const finish = async ( enable ) => {
		setBusy( true );
		setError( '' );
		try {
			publish(
				await request( '/setup/complete', {
					method: 'POST',
					data: { enable_widget: enable },
				} )
			);
			setDone( enable ? 'on' : 'off' );
		} catch ( problem ) {
			setError( toErrors( problem ).message );
		} finally {
			setBusy( false );
		}
	};

	if ( done ) {
		return (
			<>
				<Notice status="success" isDismissible={ false }>
					{ done === 'on'
						? __(
								'Your assistant is live. Visitors can see it now.',
								'devrivo-chatbot'
							)
						: __(
								'Setup is finished. The assistant stays off until you turn it on in the Dashboard.',
								'devrivo-chatbot'
							) }
				</Notice>
				<p>
					<a
						href={ boot().homeUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __(
							'View your site (opens in a new tab)',
							'devrivo-chatbot'
						) }
					</a>
				</p>
				<p>
					<a href="#/dashboard">
						{ __( 'Go to the dashboard', 'devrivo-chatbot' ) }
					</a>
				</p>
			</>
		);
	}

	return (
		<>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<dl className="devrivo-setup__facts">
				<dt>{ __( 'Starting point', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ preset
						? preset.title
						: all.general?.preset ||
							__( 'None', 'devrivo-chatbot' ) }
				</dd>
				<dt>{ __( 'Business', 'devrivo-chatbot' ) }</dt>
				<dd>{ business.name || '–' }</dd>
				<dt>{ __( 'Quick actions', 'devrivo-chatbot' ) }</dt>
				<dd>{ String( shown ) }</dd>
				<dt>{ __( 'Answers', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ all.ai?.enabled
						? __(
								'AI, with Guided mode as backup',
								'devrivo-chatbot'
							)
						: __( 'Guided mode', 'devrivo-chatbot' ) }
				</dd>
				<dt>{ __( 'Chat widget', 'devrivo-chatbot' ) }</dt>
				<dd>
					{ all.display?.enabled
						? __( 'On', 'devrivo-chatbot' )
						: __( 'Off', 'devrivo-chatbot' ) }
				</dd>
			</dl>
			<div className="devrivo-savebar">
				<Button
					variant="primary"
					onClick={ () => finish( true ) }
					isBusy={ busy }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Turn on the assistant', 'devrivo-chatbot' ) }
				</Button>
				<Button
					variant="secondary"
					onClick={ () => finish( false ) }
					disabled={ busy }
					__next40pxDefaultSize
				>
					{ __( 'Finish without turning it on', 'devrivo-chatbot' ) }
				</Button>
				<a
					href={ boot().homeUrl }
					target="_blank"
					rel="noopener noreferrer"
				>
					{ __(
						'View your site (opens in a new tab)',
						'devrivo-chatbot'
					) }
				</a>
			</div>
			<StepNav onBack={ nav.onBack } busy={ busy } />
		</>
	);
}

/**
 * Setup wizard screen.
 *
 * @param {Object}                   props
 * @param {Object}                   props.testClient Chat client for the test step (tests).
 * @param {(dirty: boolean) => void} props.setDirty   Unsaved state.
 * @return {Element} Screen.
 */
export default function Setup( { testClient, setDirty = NOOP } ) {
	const settings = useSettings( 'general' );
	// Unsaved edits in the current step: the shell guards in-app links and
	// leaving the page; Back and Skip ask here.
	const [ stepDirty, setStepDirty ] = useState( false );
	const report = useCallback(
		( value ) => {
			setStepDirty( !! value );
			setDirty( value );
		},
		[ setDirty ]
	);
	const [ index, setIndex ] = useState( null );
	const [ scan, setScan ] = useState( null );
	const [ scanError, setScanError ] = useState( '' );
	const [ presets, setPresets ] = useState( null );
	const heading = useRef( null );
	const moved = useRef( false );

	useEffect( () => {
		request( '/setup/scan' )
			.then( setScan )
			.catch( ( error ) => setScanError( toErrors( error ).message ) );
		request( '/presets' )
			.then( setPresets )
			.catch( () => setPresets( [] ) );
	}, [] );

	// Resume at the saved step once settings are loaded.
	const savedStep = settings.ready
		? settings.saved.general?.setup?.step
		: null;
	useEffect( () => {
		if ( index === null && savedStep !== null ) {
			setIndex( stepIndex( savedStep ) );
		}
	}, [ index, savedStep ] );

	useEffect( () => {
		if ( index === null ) {
			return;
		}
		if ( moved.current && heading.current ) {
			heading.current.focus();
		}
	}, [ index ] );

	if ( ! settings.ready || index === null ) {
		return <Loading settings={ settings } />;
	}

	const go = ( next, saved = false ) => {
		if (
			! saved &&
			stepDirty &&
			// eslint-disable-next-line no-alert -- Same native confirm as the shell's navigation guard.
			! window.confirm(
				__(
					'You have unsaved changes. Leave this screen without saving?',
					'devrivo-chatbot'
				)
			)
		) {
			return;
		}
		moved.current = true;
		setIndex( next );
		request( '/setup/step', {
			method: 'POST',
			data: { step: STEPS[ next ] },
		} )
			.then( publish )
			.catch( () => {} );
	};
	const last = STEPS.length - 1;
	const nav = {
		onBack: index > 0 ? () => go( index - 1 ) : null,
		onSkip: index < last ? () => go( index + 1 ) : null,
		// Steps call this after saving (or when there is nothing to save).
		onNext: index < last ? () => go( index + 1, true ) : null,
	};
	const step = STEPS[ index ];
	const all = settings.all;
	const props = { scan, presets, all, nav, setDirty: report };

	return (
		<div className="devrivo-setup">
			<h2
				ref={ heading }
				tabIndex={ -1 }
				className="devrivo-setup__heading"
			>
				{ sprintf(
					/* translators: 1: Step number, 2: Number of steps, 3: Step title. */
					__( 'Step %1$d of %2$d: %3$s', 'devrivo-chatbot' ),
					index + 1,
					STEPS.length,
					stepTitles()[ step ]
				) }
			</h2>
			<div
				className="devrivo-progress"
				role="progressbar"
				aria-label={ __( 'Setup progress', 'devrivo-chatbot' ) }
				aria-valuemin={ 1 }
				aria-valuemax={ STEPS.length }
				aria-valuenow={ index + 1 }
			>
				<span
					style={ {
						width: `${ ( ( index + 1 ) / STEPS.length ) * 100 }%`,
					} }
				/>
			</div>
			<div className="devrivo-setup__body" key={ step }>
				{ step === 'scan' && (
					<ScanStep { ...props } error={ scanError } />
				) }
				{ step === 'preset' && <PresetStep { ...props } /> }
				{ step === 'business' &&
					// Wait for the scan, so its suggestions can fill empty fields.
					( scan || scanError ? (
						<BusinessStep { ...props } />
					) : (
						<p>
							{ __( 'Looking at your site…', 'devrivo-chatbot' ) }
						</p>
					) ) }
				{ step === 'ai' && <AiStep { ...props } /> }
				{ step === 'appearance' && <AppearanceStep { ...props } /> }
				{ step === 'knowledge' && <KnowledgeStep { ...props } /> }
				{ step === 'test' && (
					<TestStep { ...props } client={ testClient } />
				) }
				{ step === 'golive' && <GoLiveStep { ...props } /> }
			</div>
		</div>
	);
}
