/**
 * Inline enquiry form, shown in the conversation (like the demo).
 *
 * Accessible: real labels, required markers with text for screen readers,
 * errors linked with aria-describedby and the first error focused. Text is set
 * with textContent; the policy link only accepts http(s) URLs. A visually
 * hidden "website" field catches bots.
 */
import { h } from '../lib/dom';
import { formErrors } from '../lib/forms';
import { HTTP_PROTOCOLS, safeUrl } from '../lib/url';

let count = 0;

/**
 * Builds a form.
 *
 * @param {Object}                               form              Normalised form (with id).
 * @param {Object}                               options           Options.
 * @param {Object}                               options.consent   { text, policyUrl, policyLabel }.
 * @param {Object}                               options.i18n      Interface strings.
 * @param {(payload: Object) => Promise<Object>} options.send      Sends the payload; resolves with the server result, rejects on network errors.
 * @param {(message: string) => void}            options.onSuccess Called with the success message.
 * @return {HTMLFormElement} Form.
 */
export function formView( form, { consent, i18n, send, onSuccess } ) {
	const uid = `dc-f${ ++count }`;
	const controls = {};
	const errors = {};

	const errorFor = ( key ) => {
		errors[ key ] = h( 'p', {
			class: 'dc-error',
			id: `${ uid }-${ key }-error`,
			hidden: true,
		} );
		return errors[ key ];
	};

	const label = ( field, forId ) =>
		h( 'label', { for: forId }, [
			field.label,
			field.required
				? h( 'span', { class: 'dc-req' }, [
						h( 'span', { 'aria-hidden': 'true', text: ' *' } ),
						h( 'span', {
							class: 'dc-sr',
							text: ` (${ i18n.required })`,
						} ),
					] )
				: null,
		] );

	const rows = form.fields.map( ( field ) => {
		const id = `${ uid }-${ field.id }`;
		const common = {
			id,
			name: field.id,
			required: field.required,
			'aria-required': field.required ? 'true' : null,
		};
		let control;

		if ( field.type === 'select' ) {
			control = h( 'select', common, [
				h( 'option', {
					value: '',
					text: field.placeholder || i18n.choose,
				} ),
				...field.options.map( ( option ) =>
					h( 'option', { value: option, text: option } )
				),
			] );
		} else if ( field.type === 'textarea' ) {
			control = h( 'textarea', {
				...common,
				rows: '3',
				maxlength: String( field.maxLength ),
				placeholder: field.placeholder || null,
			} );
		} else {
			control = h( 'input', {
				...common,
				type: field.type,
				maxlength: String( field.maxLength ),
				placeholder: field.placeholder || null,
				autocomplete:
					{ email: 'email', tel: 'tel' }[ field.type ] ||
					( field.id === 'name' ? 'name' : null ),
			} );
		}

		controls[ field.id ] = control;
		return h( 'div', { class: 'dc-field' }, [
			label( field, id ),
			control,
			errorFor( field.id ),
		] );
	} );

	const policyUrl = safeUrl( consent.policyUrl, HTTP_PROTOCOLS );
	const consentBox = h( 'input', {
		type: 'checkbox',
		id: `${ uid }-consent`,
		name: 'consent',
		required: true,
		'aria-required': 'true',
	} );
	controls.consent = consentBox;
	const consentRow = h( 'div', { class: 'dc-field dc-consent' }, [
		consentBox,
		h(
			'label',
			{ for: `${ uid }-consent` },
			[
				consent.text,
				policyUrl
					? [
							' ',
							h( 'a', {
								href: policyUrl,
								target: '_blank',
								rel: 'noopener noreferrer',
								text: `${ consent.policyLabel } ${ i18n.new_tab }`,
							} ),
						]
					: [],
			].flat()
		),
		errorFor( 'consent' ),
	] );

	// Bots fill every field; people never see this one.
	const honeypot = h( 'input', {
		type: 'text',
		id: `${ uid }-website`,
		name: 'website',
		tabindex: '-1',
		autocomplete: 'off',
	} );
	const trap = h( 'div', { class: 'dc-hp', 'aria-hidden': 'true' }, [
		h( 'label', { for: `${ uid }-website`, text: 'Website' } ),
		honeypot,
	] );

	const status = h( 'p', { class: 'dc-form-status', role: 'alert' } );
	const button = h( 'button', {
		type: 'submit',
		class: 'dc-submit',
		text: form.submitLabel || i18n.submit,
	} );

	const element = h(
		'form',
		{
			class: 'dc-form',
			novalidate: true,
			'aria-labelledby': `${ uid }-title`,
			'data-form': form.id,
		},
		[
			h( 'h3', { id: `${ uid }-title`, text: form.title } ),
			...rows,
			consentRow,
			trap,
			status,
			button,
		]
	);

	const showErrors = ( messages ) => {
		let first = null;
		for ( const [ key, box ] of Object.entries( errors ) ) {
			const message = messages[ key ] || '';
			const control = controls[ key ];
			box.textContent = message;
			box.hidden = ! message;
			control.toggleAttribute( 'aria-invalid', !! message );
			if ( message ) {
				control.setAttribute( 'aria-describedby', box.id );
				first = first || control;
			} else {
				control.removeAttribute( 'aria-describedby' );
			}
		}
		if ( first ) {
			first.focus();
		}
		return !! first;
	};

	const setBusy = ( busy ) => {
		button.disabled = busy;
		button.textContent = busy
			? i18n.sending
			: form.submitLabel || i18n.submit;
		element.setAttribute( 'aria-busy', String( busy ) );
	};

	element.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		if ( button.disabled ) {
			return;
		}
		status.textContent = '';

		const values = {};
		for ( const field of form.fields ) {
			values[ field.id ] = controls[ field.id ].value;
		}
		const local = formErrors( form, values, consentBox.checked );
		const messages = {};
		for ( const [ key, error ] of Object.entries( local ) ) {
			messages[ key ] = i18n[ error ];
		}
		if ( showErrors( messages ) ) {
			return;
		}

		setBusy( true );
		let result;
		try {
			result = await send( {
				fields: values,
				consent: true,
				website: honeypot.value,
				page_url: window.location.href,
			} );
		} catch {
			setBusy( false );
			status.textContent = i18n.network_error;
			return;
		}

		if ( result.ok && result.data.success ) {
			for ( const control of element.querySelectorAll(
				'input,select,textarea,button'
			) ) {
				control.disabled = true;
			}
			button.textContent = i18n.sent;
			element.classList.add( 'is-done' );
			onSuccess(
				result.data.message || form.successMessage || i18n.form_success
			);
			return;
		}

		setBusy( false );
		const params =
			result.data && result.data.data && result.data.data.params;
		if ( result.status === 400 && params && showErrors( params ) ) {
			return;
		}
		status.textContent =
			result.status === 429 ? i18n.rate_limited : i18n.form_error;
	} );

	return element;
}
