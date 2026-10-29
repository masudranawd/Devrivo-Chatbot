/**
 * Chat view: header, message list (flows, forms, answers) and, when search or
 * chat is enabled, the composer.
 */
import { h, icon, ICONS } from '../lib/dom';
import { avatar } from '../launcher';

/**
 * Builds the chat view.
 *
 * @param {Object} config   Normalised config.
 * @param {Object} handlers { onBack(), onClose(), onSend( text ): Promise }.
 * @return {HTMLElement} View.
 */
export function chatView( config, handlers ) {
	const { i18n } = config;

	const input = h( 'input', {
		type: 'text',
		name: 'message',
		// Search questions are short; AI chat (Step 7) allows longer messages.
		maxlength: config.chatEnabled ? '400' : '100',
		autocomplete: 'off',
		placeholder: i18n.placeholder,
		'aria-label': i18n.message_label,
	} );

	const send = h(
		'button',
		{
			type: 'submit',
			class: 'dc-send',
			'aria-label': i18n.send,
		},
		icon( ICONS.send, 18, 'dc-flip' )
	);

	const composer =
		config.chatEnabled || config.searchEnabled
			? h(
					'form',
					{
						class: 'dc-composer',
						onSubmit: async ( event ) => {
							event.preventDefault();
							const value = input.value.trim();
							if ( value.length < 2 || send.disabled ) {
								return;
							}
							input.value = '';
							send.disabled = true;
							try {
								await handlers.onSend( value );
							} finally {
								send.disabled = false;
							}
						},
					},
					[ input, send ]
				)
			: null;

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
