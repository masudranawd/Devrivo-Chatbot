/**
 * Conversation view: bot and visitor bubbles, typing indicator, quick replies,
 * call-to-action buttons and "Back to menu". Implements the view the flow
 * runner talks to. All text is set with textContent.
 */
import { h } from '../lib/dom';
import { actionControl } from './control';

/**
 * Builds the conversation view on a message list.
 *
 * @param {HTMLElement}                 log                Message list (role="log", aria-live="polite").
 * @param {Object}                      options            Options.
 * @param {Object}                      options.config     Normalised config.
 * @param {(action: Object) => ?Object} options.target     Where an action leads, or null.
 * @param {(id: string) => void}        options.onAction   Runs an action from a button (flows, choices).
 * @param {(id: string) => void}        options.onCta      Called when a link is used (it opens by itself).
 * @param {() => void}                  options.onBack     Back to the menu.
 * @param {(text: string) => number}    options.typingTime Typing indicator time in ms (0 = none).
 * @return {Object} View for the flow runner, plus separator().
 */
export function conversationView( log, options ) {
	const { config, target, onAction, onCta, onBack, typingTime } = options;
	const byId = new Map( config.actions.map( ( a ) => [ a.id, a ] ) );

	const scroll = () => {
		log.scrollTop = log.scrollHeight;
	};

	// Keyboard users keep their place: while focus is in the chat view (or the
	// control they used just disappeared), it moves to the next control shown.
	const show = ( element ) => {
		const active = log.getRootNode().activeElement;
		const focusIt =
			! active ||
			! active.isConnected ||
			( log.parentElement || log ).contains( active );
		log.append( element );
		scroll();
		const first = element.querySelector( 'a,button' );
		if ( focusIt && first ) {
			first.focus();
		}
	};

	const clearReplies = () => {
		for ( const replies of log.querySelectorAll( '.dc-replies' ) ) {
			replies.remove();
		}
	};

	const reply = ( label, urgent, onClick ) =>
		h( 'button', {
			type: 'button',
			class: `dc-reply${ urgent ? ' dc-urgent' : '' }`,
			text: label,
			onClick,
		} );

	return {
		async bot( text ) {
			const wait = typingTime( text );
			if ( wait > 0 ) {
				const typing = h(
					'div',
					{ class: 'dc-msg dc-bot dc-typing', 'aria-hidden': 'true' },
					[ h( 'i' ), h( 'i' ), h( 'i' ) ]
				);
				log.append( typing );
				scroll();
				await new Promise( ( resolve ) =>
					window.setTimeout( resolve, wait )
				);
				typing.remove();
			}
			log.append( h( 'div', { class: 'dc-msg dc-bot', text } ) );
			scroll();
		},

		user( text ) {
			clearReplies();
			log.append( h( 'div', { class: 'dc-msg dc-user', text } ) );
			scroll();
		},

		choices( choices, onPick ) {
			clearReplies();
			show(
				h(
					'div',
					{ class: 'dc-replies', role: 'group' },
					choices.map( ( choice ) =>
						reply( choice.label, choice.urgent, () =>
							onPick( choice )
						)
					)
				)
			);
		},

		ctas( ids ) {
			const actions = ids
				.map( ( id ) => byId.get( id ) )
				.filter( Boolean );
			if ( ! actions.length ) {
				return;
			}
			show(
				h(
					'div',
					{ class: 'dc-ctas' },
					actions.map( ( action ) => {
						const to = target( action );
						return actionControl( to, {
							class: `dc-cta${ action.urgent ? ' is-urgent' : '' }`,
							'data-action': action.id,
							text: action.label,
							onClick: () =>
								to ? onCta( action.id ) : onAction( action.id ),
						} );
					} )
				)
			);
		},

		action( id ) {
			onAction( id );
		},

		end() {
			clearReplies();
			show(
				h(
					'div',
					{ class: 'dc-replies', role: 'group' },
					reply( config.i18n.back_to_menu, false, onBack )
				)
			);
		},

		separator() {
			clearReplies();
			if ( log.childElementCount ) {
				log.append( h( 'hr', { class: 'dc-sep' } ) );
			}
		},
	};
}
