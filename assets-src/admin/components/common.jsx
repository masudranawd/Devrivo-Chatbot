/**
 * Shared admin UI: save bar, error summary, field errors, confirm dialog.
 */
import { Button, Modal, Notice } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Save and Discard buttons for a screen's settings.
 *
 * @param {Object}                                    props
 * @param {Object}                                    props.settings useSettings() result.
 * @param {(status: string, message: string) => void} props.notify   Shows a notice: ( status, message ).
 * @param {(...args: unknown[]) => unknown}           props.validate Optional local check; returns false to block saving.
 * @return {Element} Bar.
 */
export function SaveBar( { settings, notify, validate } ) {
	const onSave = async () => {
		if ( validate && ! validate() ) {
			return;
		}
		const ok = await settings.save();
		notify(
			ok ? 'success' : 'error',
			ok
				? __( 'Settings saved.', 'devrivo-chatbot' )
				: __(
						'Settings were not saved. Please check the highlighted fields.',
						'devrivo-chatbot'
					)
		);
	};

	return (
		<div className="devrivo-savebar">
			<Button
				variant="primary"
				onClick={ onSave }
				isBusy={ settings.saving }
				disabled={ ! settings.dirty || settings.saving }
				__next40pxDefaultSize
			>
				{ __( 'Save changes', 'devrivo-chatbot' ) }
			</Button>
			<Button
				variant="tertiary"
				onClick={ settings.discard }
				disabled={ ! settings.dirty || settings.saving }
				__next40pxDefaultSize
			>
				{ __( 'Discard changes', 'devrivo-chatbot' ) }
			</Button>
			{ settings.dirty && (
				<span className="devrivo-savebar__state">
					{ __( 'You have unsaved changes.', 'devrivo-chatbot' ) }
				</span>
			) }
		</div>
	);
}

/**
 * Error summary at the top of a form.
 *
 * @param {Object} props
 * @param {Object} props.errors { message, fields }.
 * @return {Element|null} Notice.
 */
export function ErrorSummary( { errors } ) {
	const fields = Object.values( errors.fields || {} );
	if ( ! errors.message && ! fields.length ) {
		return null;
	}

	return (
		<Notice status="error" isDismissible={ false }>
			<p>{ errors.message }</p>
			{ fields.length > 1 && (
				<ul className="devrivo-error-list">
					{ fields.map( ( text, index ) => (
						<li key={ index }>{ text }</li>
					) ) }
				</ul>
			) }
		</Notice>
	);
}

/**
 * Error text under a field.
 *
 * @param {Object} props
 * @param {string} props.error Message ('' for none).
 * @param {string} props.id    Element ID (for aria-describedby).
 * @return {Element|null} Error.
 */
export function FieldError( { error, id } ) {
	return error ? (
		<p className="devrivo-field-error" id={ id }>
			{ error }
		</p>
	) : null;
}

/**
 * Confirmation dialog.
 *
 * @param {Object}                          props
 * @param {string}                          props.title     Title.
 * @param {string}                          props.message   Question.
 * @param {string}                          props.confirm   Confirm button label.
 * @param {(...args: unknown[]) => unknown} props.onConfirm Confirmed.
 * @param {(...args: unknown[]) => unknown} props.onCancel  Cancelled.
 * @return {Element} Modal.
 */
export function ConfirmModal( {
	title,
	message,
	confirm,
	onConfirm,
	onCancel,
} ) {
	return (
		<Modal title={ title } onRequestClose={ onCancel } size="small">
			<p>{ message }</p>
			<div className="devrivo-modal-actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'devrivo-chatbot' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					onClick={ onConfirm }
					__next40pxDefaultSize
				>
					{ confirm }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * Reports a screen's unsaved state to the shell.
 *
 * @param {(...args: unknown[]) => unknown} setDirty From the shell.
 * @param {boolean}                         dirty    Unsaved changes.
 */
export function useDirty( setDirty, dirty ) {
	useEffect( () => {
		setDirty( dirty );
		return () => setDirty( false );
	}, [ setDirty, dirty ] );
}

/**
 * Loading and load-error state for a screen.
 *
 * @param {Object} props
 * @param {Object} props.settings useSettings() result.
 * @return {Element} Message.
 */
export function Loading( { settings } ) {
	return settings && settings.loadError ? (
		<Notice status="error" isDismissible={ false }>
			{ settings.loadError }
		</Notice>
	) : (
		<p>{ __( 'Loading…', 'devrivo-chatbot' ) }</p>
	);
}
