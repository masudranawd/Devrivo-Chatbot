/**
 * Integrations: who is emailed about new leads, and a test email.
 */
import { Button, Notice, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { request, toErrors } from '../lib/api';
import { useSettings } from '../lib/use-settings';

export const MAX_EMAILS = 10;
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Whether a value looks like an email address (the server checks again).
 *
 * @param {string} value Value.
 * @return {boolean} Valid.
 */
export function isEmail( value ) {
	return EMAIL.test( String( value ).trim() );
}

/**
 * Integrations screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Integrations( { notify, setDirty } ) {
	const settings = useSettings( 'integrations' );
	const [ email, setEmail ] = useState( '' );
	const [ invalid, setInvalid ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ result, setResult ] = useState( null );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const emails = settings.values.integrations.notification_emails || [];
	const fields = settings.errors.fields;
	const setEmails = ( value ) =>
		settings.setField( 'integrations', 'notification_emails', value );

	const add = () => {
		const value = email.trim();
		if ( ! isEmail( value ) ) {
			setInvalid(
				__(
					'Enter an email address like name@example.com.',
					'devrivo-chatbot'
				)
			);
			return;
		}
		if ( emails.some( ( e ) => e.toLowerCase() === value.toLowerCase() ) ) {
			setInvalid(
				__( 'This address is already in the list.', 'devrivo-chatbot' )
			);
			return;
		}
		setEmails( [ ...emails, value ] );
		setEmail( '' );
		setInvalid( '' );
	};

	const sendTest = async () => {
		setSending( true );
		setResult( null );
		try {
			const data = await request( '/integrations/test-email', {
				method: 'POST',
			} );
			setResult( {
				status: 'success',
				message: sprintf(
					/* translators: %s: Email addresses. */
					__( 'Test email sent to: %s', 'devrivo-chatbot' ),
					( data.recipients || [] ).join( ', ' )
				),
			} );
		} catch ( error ) {
			setResult( {
				status: 'error',
				message: toErrors( error ).message,
			} );
		} finally {
			setSending( false );
		}
	};

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />

			<fieldset className="devrivo-fieldset">
				<legend>
					{ __( 'Notification emails', 'devrivo-chatbot' ) }
				</legend>
				<p className="description">
					{ __(
						'New enquiries are emailed to these addresses. With none, they go to the site admin email.',
						'devrivo-chatbot'
					) }
				</p>
				<ul className="devrivo-list">
					{ emails.map( ( value, index ) => (
						<li key={ value + index } className="devrivo-row">
							<span>{ value }</span>
							<Button
								size="small"
								variant="tertiary"
								isDestructive
								label={ sprintf(
									/* translators: %s: Email address. */
									__( 'Remove %s', 'devrivo-chatbot' ),
									value
								) }
								onClick={ () =>
									setEmails(
										emails.filter( ( _, i ) => i !== index )
									)
								}
							>
								{ __( 'Remove', 'devrivo-chatbot' ) }
							</Button>
							<FieldError
								error={
									fields[ `notification_emails.${ index }` ]
								}
							/>
						</li>
					) ) }
				</ul>
				<div className="devrivo-row">
					<TextControl
						type="email"
						label={ __( 'Email address', 'devrivo-chatbot' ) }
						value={ email }
						onChange={ ( value ) => {
							setEmail( value );
							setInvalid( '' );
						} }
						aria-describedby={
							invalid ? 'devrivo-email-error' : undefined
						}
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						onClick={ add }
						disabled={
							! email.trim() || emails.length >= MAX_EMAILS
						}
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Add', 'devrivo-chatbot' ) }
					</Button>
				</div>
				<FieldError error={ invalid } id="devrivo-email-error" />
				<FieldError error={ fields.notification_emails } />
				{ emails.length >= MAX_EMAILS && (
					<p className="description">
						{ __(
							'You can add up to 10 addresses.',
							'devrivo-chatbot'
						) }
					</p>
				) }
			</fieldset>

			<SaveBar settings={ settings } notify={ notify } />

			<fieldset className="devrivo-fieldset">
				<legend>{ __( 'Test email', 'devrivo-chatbot' ) }</legend>
				<p className="description">
					{ settings.dirty
						? __(
								'Save your changes first. The test goes to the saved addresses.',
								'devrivo-chatbot'
							)
						: __(
								'Sends a test to the saved addresses (or the site admin email), so you can check that emails arrive.',
								'devrivo-chatbot'
							) }
				</p>
				<div>
					<Button
						variant="secondary"
						onClick={ sendTest }
						isBusy={ sending }
						disabled={ sending || settings.dirty }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ __( 'Send test email', 'devrivo-chatbot' ) }
					</Button>
				</div>
				<div aria-live="polite">
					{ result && (
						<Notice
							status={ result.status }
							isDismissible={ false }
						>
							{ result.message }
						</Notice>
					) }
				</div>
			</fieldset>
		</div>
	);
}
