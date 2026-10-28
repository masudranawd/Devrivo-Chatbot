/**
 * Chat view shell: header, messages area and composer. Only built when chat
 * is enabled; sending messages arrives in Steps 6-7.
 */
import { h, icon, ICONS } from '../lib/dom';
import { avatar } from '../launcher';

/**
 * Builds the chat view.
 *
 * @param {Object} config   Normalised config.
 * @param {Object} handlers { onBack(), onClose(), onSend( text ) }.
 * @return {HTMLElement} View.
 */
export function chatView( config, handlers ) {
	const { i18n } = config;

	const input = h( 'input', {
		type: 'text',
		name: 'message',
		maxlength: '400',
		autocomplete: 'off',
		placeholder: i18n.placeholder,
		'aria-label': i18n.message_label,
	} );

	const composer = h(
		'form',
		{
			class: 'dc-composer',
			onSubmit: ( event ) => {
				event.preventDefault();
				const value = input.value.trim();
				if ( value ) {
					handlers.onSend( value );
				}
			},
		},
		[
			input,
			h(
				'button',
				{ type: 'submit', class: 'dc-send', 'aria-label': i18n.send },
				icon( ICONS.send, 18, 'dc-flip' )
			),
		]
	);

	return h(
		'div',
		{ class: 'dc-view', id: 'dc-view-chat', 'data-view': 'chat' },
		[
			h( 'div', { class: 'dc-chat-head' }, [
				h(
					'button',
					{
						type: 'button',
						class: 'dc-icon-btn',
						'aria-label': i18n.back,
						onClick: handlers.onBack,
					},
					icon( ICONS.back, 16, 'dc-flip' )
				),
				avatar( config.avatarUrl, 'sm' ),
				h( 'span', {
					class: 'dc-chat-title',
					text: config.brandName || i18n.assistant,
				} ),
				h(
					'button',
					{
						type: 'button',
						class: 'dc-icon-btn',
						'aria-label': i18n.close,
						onClick: handlers.onClose,
					},
					icon( ICONS.close )
				),
			] ),
			h( 'div', {
				class: 'dc-msgs',
				role: 'log',
				'aria-live': 'polite',
			} ),
			composer,
		]
	);
}
