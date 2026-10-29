/**
 * Chat view: header (with an "AI" tag in AI mode), message list (flows,
 * forms, answers) and, when search or AI is available, the composer, with
 * the AI disclaimer under it in AI mode.
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
		// Page searches lower this to 100 (see widget.js).
		maxlength: '500',
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
		config.aiAvailable || config.searchEnabled
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
				config.aiAvailable
					? h( 'span', { class: 'dc-ai-tag', text: i18n.ai_tag } )
					: null,
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
			composer && config.aiAvailable && config.disclaimer
				? h( 'p', {
						class: 'dc-disclaimer',
						text: config.disclaimer,
					} )
				: null,
		]
	);
}
