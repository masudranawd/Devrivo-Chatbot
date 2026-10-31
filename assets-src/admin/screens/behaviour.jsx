/**
 * Behaviour: greeting, welcome, auto-open, opening hours, UTM tags and the
 * action offered when a search finds nothing.
 */
import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { boot } from '../lib/boot';
import {
	DAYS,
	MAX_RANGES,
	addRange,
	copyToWeekdays,
	normalizeSchedule,
	rangeErrors,
	removeRange,
	updateRange,
} from '../lib/hours';
import { useSettings } from '../lib/use-settings';

/**
 * Translated day names.
 *
 * @return {Object<string, string>} Names by key.
 */
export function dayNames() {
	return {
		mon: __( 'Monday', 'devrivo-chatbot' ),
		tue: __( 'Tuesday', 'devrivo-chatbot' ),
		wed: __( 'Wednesday', 'devrivo-chatbot' ),
		thu: __( 'Thursday', 'devrivo-chatbot' ),
		fri: __( 'Friday', 'devrivo-chatbot' ),
		sat: __( 'Saturday', 'devrivo-chatbot' ),
		sun: __( 'Sunday', 'devrivo-chatbot' ),
	};
}

/**
 * Opening hours editor.
 *
 * @param {Object}                          props
 * @param {Object}                          props.hours    behaviour.hours.
 * @param {(...args: unknown[]) => unknown} props.onChange New hours.
 * @param {Object}                          props.errors   Field errors (keys like "hours.schedule.mon.0").
 * @return {Element} Editor.
 */
export function HoursEditor( { hours, onChange, errors = {} } ) {
	const schedule = normalizeSchedule( hours.schedule );
	const local = rangeErrors( schedule );
	const names = dayNames();
	const set = ( next ) => onChange( { ...hours, schedule: next } );
	const zones = boot().timezones;

	return (
		<fieldset className="devrivo-fieldset devrivo-hours">
			<legend>{ __( 'Opening hours', 'devrivo-chatbot' ) }</legend>
			<SelectControl
				label={ __( 'Timezone', 'devrivo-chatbot' ) }
				value={ hours.timezone }
				options={ [
					{
						label: sprintf(
							/* translators: %s: Site timezone. */
							__( 'Site timezone (%s)', 'devrivo-chatbot' ),
							boot().siteTimezone || 'UTC'
						),
						value: '',
					},
					...zones.map( ( zone ) => ( {
						label: zone,
						value: zone,
					} ) ),
				] }
				onChange={ ( value ) =>
					onChange( { ...hours, timezone: value } )
				}
				__next40pxDefaultSize
			/>
			<FieldError error={ errors[ 'hours.timezone' ] } />

			{ DAYS.map( ( day ) => (
				<div
					className="devrivo-hours__day"
					key={ day }
					role="group"
					aria-label={ names[ day ] }
				>
					<h3>{ names[ day ] }</h3>
					{ schedule[ day ].length === 0 && (
						<p className="description">
							{ __( 'Closed', 'devrivo-chatbot' ) }
						</p>
					) }
					{ schedule[ day ].map( ( range, index ) => {
						const key = `${ day }.${ index }`;
						const messages = {
							order: __(
								'The opening time must be before the closing time.',
								'devrivo-chatbot'
							),
							format: __(
								'Enter both times.',
								'devrivo-chatbot'
							),
						};
						const problem =
							errors[ `hours.schedule.${ key }` ] ||
							messages[ local[ key ] ] ||
							'';
						return (
							<div className="devrivo-hours__range" key={ key }>
								<TextControl
									type="time"
									label={ sprintf(
										/* translators: 1: Day, 2: Range number. */
										__(
											'%1$s opens (%2$d)',
											'devrivo-chatbot'
										),
										names[ day ],
										index + 1
									) }
									value={ range.open }
									onChange={ ( value ) =>
										set(
											updateRange(
												schedule,
												day,
												index,
												'open',
												value
											)
										)
									}
									__next40pxDefaultSize
								/>
								<TextControl
									type="time"
									label={ sprintf(
										/* translators: 1: Day, 2: Range number. */
										__(
											'%1$s closes (%2$d)',
											'devrivo-chatbot'
										),
										names[ day ],
										index + 1
									) }
									value={ range.close }
									onChange={ ( value ) =>
										set(
											updateRange(
												schedule,
												day,
												index,
												'close',
												value
											)
										)
									}
									__next40pxDefaultSize
								/>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () =>
										set(
											removeRange( schedule, day, index )
										)
									}
									__next40pxDefaultSize
								>
									{ __( 'Remove', 'devrivo-chatbot' ) }
								</Button>
								<FieldError error={ problem } />
							</div>
						);
					} ) }
					<div className="devrivo-hours__buttons">
						<Button
							variant="secondary"
							onClick={ () => set( addRange( schedule, day ) ) }
							disabled={ schedule[ day ].length >= MAX_RANGES }
							__next40pxDefaultSize
						>
							{ __( 'Add hours', 'devrivo-chatbot' ) }
						</Button>
						{ day === 'mon' && schedule.mon.length > 0 && (
							<Button
								variant="tertiary"
								onClick={ () =>
									set( copyToWeekdays( schedule, 'mon' ) )
								}
								__next40pxDefaultSize
							>
								{ __(
									'Copy to Tuesday–Friday',
									'devrivo-chatbot'
								) }
							</Button>
						) }
					</div>
				</div>
			) ) }

			<ToggleControl
				label={ __(
					'Show “open now” / “closed” in the widget',
					'devrivo-chatbot'
				) }
				checked={ !! hours.show_status }
				onChange={ ( value ) =>
					onChange( { ...hours, show_status: value } )
				}
			/>
		</fieldset>
	);
}

/**
 * Behaviour screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Behaviour( { notify, setDirty } ) {
	const settings = useSettings( 'behaviour' );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const behaviour = settings.values.behaviour;
	const fields = settings.errors.fields;
	const set = ( key ) => ( value ) =>
		settings.setField( 'behaviour', key, value );
	const actions = settings.all.actions || [];

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />

			<TextControl
				label={ __( 'Greeting', 'devrivo-chatbot' ) }
				help={ __(
					'Leave empty to greet by time of day (“Good morning”).',
					'devrivo-chatbot'
				) }
				value={ behaviour.greeting }
				maxLength={ 200 }
				onChange={ set( 'greeting' ) }
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.greeting } />

			<TextControl
				label={ __( 'Welcome message', 'devrivo-chatbot' ) }
				value={ behaviour.welcome }
				maxLength={ 200 }
				onChange={ set( 'welcome' ) }
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.welcome } />

			<ToggleControl
				label={ __(
					'Open the quick actions automatically',
					'devrivo-chatbot'
				) }
				help={ __(
					'Only once per visitor; closing them is remembered.',
					'devrivo-chatbot'
				) }
				checked={ !! behaviour.auto_open }
				onChange={ set( 'auto_open' ) }
			/>
			{ behaviour.auto_open && (
				<TextControl
					type="number"
					label={ __(
						'Delay before opening (milliseconds)',
						'devrivo-chatbot'
					) }
					min={ 0 }
					max={ 60000 }
					step={ 100 }
					value={ String( behaviour.auto_open_delay ) }
					onChange={ ( value ) =>
						set( 'auto_open_delay' )(
							Math.max(
								0,
								Math.min( 60000, parseInt( value, 10 ) || 0 )
							)
						)
					}
					__next40pxDefaultSize
				/>
			) }

			<HoursEditor
				hours={ behaviour.hours }
				onChange={ set( 'hours' ) }
				errors={ fields }
			/>

			<ToggleControl
				label={ __(
					'Add UTM tags to quick-action links',
					'devrivo-chatbot'
				) }
				help={ __(
					'Lets your analytics see visits that came from the chat.',
					'devrivo-chatbot'
				) }
				checked={ !! behaviour.utm }
				onChange={ set( 'utm' ) }
			/>

			<SelectControl
				label={ __(
					'When a search finds nothing, offer',
					'devrivo-chatbot'
				) }
				value={ behaviour.fallback_action }
				options={ [
					{ label: __( 'Nothing', 'devrivo-chatbot' ), value: '' },
					...actions.map( ( action ) => ( {
						label: action.label,
						value: action.id,
					} ) ),
				] }
				onChange={ set( 'fallback_action' ) }
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.fallback_action } />

			<SaveBar
				settings={ settings }
				notify={ notify }
				validate={ () => {
					if (
						Object.keys( rangeErrors( behaviour.hours.schedule ) )
							.length
					) {
						notify(
							'error',
							__(
								'Please fix the opening hours first.',
								'devrivo-chatbot'
							)
						);
						return false;
					}
					return true;
				} }
			/>
		</div>
	);
}
