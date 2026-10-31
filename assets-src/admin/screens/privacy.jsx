/**
 * Privacy: lead retention, consent text, the opt-in credit link, data
 * removal on uninstall, and links to the WordPress privacy tools.
 */
import {
	Button,
	Modal,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	ErrorSummary,
	FieldError,
	Loading,
	SaveBar,
	useDirty,
} from '../components/common';
import { boot } from '../lib/boot';
import { useSettings } from '../lib/use-settings';

/**
 * Privacy screen.
 *
 * @param {Object}                                    props
 * @param {(status: string, message: string) => void} props.notify   Notice.
 * @param {(dirty: boolean) => void}                  props.setDirty Unsaved state.
 * @return {Element} Screen.
 */
export default function Privacy( { notify, setDirty } ) {
	const settings = useSettings( 'privacy' );
	const [ warn, setWarn ] = useState( false );
	useDirty( setDirty, settings.dirty );

	if ( ! settings.ready ) {
		return <Loading settings={ settings } />;
	}

	const data = boot();
	const privacy = settings.values.privacy;
	const fields = settings.errors.fields;
	const set = ( key ) => ( value ) =>
		settings.setField( 'privacy', key, value );

	return (
		<div className="devrivo-form">
			<ErrorSummary errors={ settings.errors } />

			<TextControl
				type="number"
				label={ __( 'Keep leads for (days)', 'devrivo-chatbot' ) }
				help={ __(
					'Older leads are deleted automatically once a day. 1 to 3650 days.',
					'devrivo-chatbot'
				) }
				min={ 1 }
				max={ 3650 }
				value={ String( privacy.retention_days ) }
				onChange={ ( value ) =>
					set( 'retention_days' )( Number( value ) || 0 )
				}
				__next40pxDefaultSize
			/>
			<FieldError error={ fields.retention_days } />

			<TextareaControl
				label={ __( 'Consent text', 'devrivo-chatbot' ) }
				help={ __(
					'Shown next to the checkbox on every form, as plain text (formatting is removed). A link to your privacy policy is added after it automatically. Leave empty for the default text.',
					'devrivo-chatbot'
				) }
				value={ privacy.consent_text || '' }
				maxLength={ 500 }
				rows={ 3 }
				onChange={ set( 'consent_text' ) }
			/>
			<FieldError error={ fields.consent_text } />

			<ToggleControl
				label={ __( 'Show “Powered by DevRivo”', 'devrivo-chatbot' ) }
				help={ __(
					'A small link at the bottom of the chat panel. Off by default; turning it on helps others find this free plugin.',
					'devrivo-chatbot'
				) }
				checked={ !! privacy.credit_link }
				onChange={ set( 'credit_link' ) }
			/>

			<ToggleControl
				label={ __(
					'Delete all plugin data when the plugin is deleted',
					'devrivo-chatbot'
				) }
				help={ __(
					'Settings, leads and the knowledge index are removed when you delete the plugin from the Plugins screen. Deactivating keeps everything.',
					'devrivo-chatbot'
				) }
				checked={ !! privacy.delete_data_on_uninstall }
				onChange={ ( value ) =>
					value
						? setWarn( true )
						: set( 'delete_data_on_uninstall' )( false )
				}
			/>

			<fieldset className="devrivo-fieldset">
				<legend>
					{ __( 'WordPress privacy tools', 'devrivo-chatbot' ) }
				</legend>
				<p className="description">
					{ __(
						'Leads are included when you export or erase a person’s data by email address.',
						'devrivo-chatbot'
					) }
				</p>
				<ul className="devrivo-list">
					<li>
						<a href={ data.privacyExportUrl }>
							{ __( 'Export Personal Data', 'devrivo-chatbot' ) }
						</a>
					</li>
					<li>
						<a href={ data.privacyEraseUrl }>
							{ __( 'Erase Personal Data', 'devrivo-chatbot' ) }
						</a>
					</li>
					<li>
						<a href={ data.privacySettingsUrl }>
							{ __(
								'Privacy settings (privacy policy page and suggested text)',
								'devrivo-chatbot'
							) }
						</a>
					</li>
				</ul>
			</fieldset>

			<SaveBar settings={ settings } notify={ notify } />

			{ warn && (
				<Modal
					title={ __(
						'Delete data on uninstall?',
						'devrivo-chatbot'
					) }
					onRequestClose={ () => setWarn( false ) }
					size="small"
				>
					<p>
						{ __(
							'When the plugin is deleted, all its settings, every lead and the knowledge index will be permanently removed. This cannot be undone. Export your leads and settings first if you may need them.',
							'devrivo-chatbot'
						) }
					</p>
					<div className="devrivo-modal-actions">
						<Button
							variant="tertiary"
							onClick={ () => setWarn( false ) }
							__next40pxDefaultSize
						>
							{ __( 'Cancel', 'devrivo-chatbot' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ () => {
								set( 'delete_data_on_uninstall' )( true );
								setWarn( false );
							} }
							__next40pxDefaultSize
						>
							{ __( 'Turn on', 'devrivo-chatbot' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
}
