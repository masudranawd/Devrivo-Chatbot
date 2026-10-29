/**
 * Conversation view: bot and visitor bubbles, typing indicator, quick replies,
 * call-to-action buttons, inline forms, search answers with link cards and
 * "Back to menu". Implements the view the flow runner talks to. All text is
 * set with textContent.
 */
import { h } from '../lib/dom';
import { actionControl } from './control';
import { formView } from './form';

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
 * @param {Object}                      options.submitter  Form submitter (lib/submit).
 * @param {Object}                      options.searcher   Searcher (lib/search).
 * @param {Object}                      options.chat       Chat client (lib/chat).
 * @return {Object} View for the flow runner, plus form(), chat(), search() and separator().
 */
export function conversationView( log, options ) {
	const {
		config,
		target,
		onAction,
		onCta,
		onBack,
		typingTime,
		submitter,
		searcher,
		chat,
	} = options;
	const { i18n } = config;

	// The conversation sent with chat messages: memory only, never stored.
	const turns = [];
	const byId = new Map( config.actions.map( ( a ) => [ a.id, a ] ) );

	const scroll = () => {
		log.scrollTop = log.scrollHeight;
	};

	// Keyboard users keep their place: while focus is in the chat view (or the
	// control they used just disappeared), it moves to the next control shown.
	// Someone typing in the composer keeps typing.
	const show = ( element, focus = true ) => {
		const active = log.getRootNode().activeElement;
		const focusIt =
			focus &&
			( ! active ||
				! active.isConnected ||
				( ( log.parentElement || log ).contains( active ) &&
					! active.closest( '.dc-composer' ) ) );
		log.append( element );
		scroll();
		const first = element.querySelector(
			'a,button,input:not([tabindex="-1"]),select,textarea'
		);
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

	const typing = () => {
		const dots = h(
			'div',
			{ class: 'dc-msg dc-bot dc-typing', 'aria-hidden': 'true' },
			[ h( 'i' ), h( 'i' ), h( 'i' ) ]
		);
		log.append( dots );
		scroll();
		return dots;
	};

	const say = ( text ) => {
		log.append( h( 'div', { class: 'dc-msg dc-bot', text } ) );
		scroll();
	};

	// "Read more on our site" cards; only http(s) links (checked by lib/search).
	const cards = ( pages ) =>
		h(
			'div',
			{ class: 'dc-links' },
			pages.map( ( page ) =>
				h(
					'a',
					{
						class: 'dc-link',
						href: page.url,
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					[
						h( 'small', { text: i18n.read_more } ),
						h( 'strong', { text: page.title } ),
						page.excerpt
							? h( 'span', { text: page.excerpt } )
							: null,
						h( 'span', {
							class: 'dc-sr',
							text: ` ${ i18n.new_tab }`,
						} ),
					]
				)
			)
		);

	const view = {
		async bot( text ) {
			const wait = typingTime( text );
			if ( wait > 0 ) {
				const dots = typing();
				await new Promise( ( resolve ) =>
					window.setTimeout( resolve, wait )
				);
				dots.remove();
			}
			say( text );
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
			// Don't pull focus away from a form the visitor still has to fill in.
			const last = log.lastElementChild;
			const formOpen =
				!! last &&
				last.matches( '.dc-form' ) &&
				! last.matches( '.is-done' );
			show(
				h(
					'div',
					{ class: 'dc-replies', role: 'group' },
					reply( config.i18n.back_to_menu, false, onBack )
				),
				! formOpen
			);
		},

		form( formId ) {
			const form = config.forms[ formId ];
			if ( ! form ) {
				view.end();
				return;
			}
			submitter.prepare();
			clearReplies();
			show(
				formView( form, {
					consent: config.consent,
					i18n: config.i18n,
					send: ( payload ) => submitter.submit( formId, payload ),
					onSuccess: async ( message ) => {
						await view.bot( message );
						view.end();
					},
				} )
			);
		},

		/**
		 * Sends a chat message and shows the answer: text, source cards and
		 * suggested quick actions (AI or Guided answer).
		 *
		 * @param {string} text Message.
		 */
		async chat( text ) {
			view.user( text );
			const dots = typing();
			let result = null;
			try {
				result = await chat.send( text, turns.slice() );
			} catch {
				result = null;
			}
			dots.remove();

			if ( ! result || ! result.ok || ! result.answer ) {
				say(
					result && result.status === 429
						? i18n.chat_limited
						: i18n.chat_error
				);
				view.end();
				return;
			}

			const { answer } = result;
			turns.push(
				{ role: 'user', text },
				{ role: 'assistant', text: answer.text }
			);
			turns.splice( 0, Math.max( 0, turns.length - 8 ) );

			say( answer.text );
			if ( answer.sources.length ) {
				show( cards( answer.sources ), false );
			}
			view.ctas( answer.actions );
			view.end();
		},

		/**
		 * A bot line shown straight away (no typing indicator).
		 *
		 * @param {string} text Text.
		 */
		prompt( text ) {
			clearReplies();
			say( text );
		},

		/**
		 * Answers a question from the site: the visitor's bubble, a typing
		 * indicator while searching, then an FAQ answer and/or link cards, or
		 * "not found" with the fallback action.
		 *
		 * @param {string}   query Question.
		 * @param {string[]} types Only these post types.
		 */
		async search( query, types = [] ) {
			view.user( query );
			const dots = typing();
			let result = null;
			try {
				result = await searcher.search( query, types );
			} catch {
				result = null;
			}
			dots.remove();

			if ( ! result || ! result.ok ) {
				say(
					result && result.status === 429
						? i18n.search_limited
						: i18n.search_error
				);
				view.end();
				return;
			}

			const [ first ] = result.results;
			if ( ! first ) {
				say( i18n.no_results );
				const fallback = byId.get( config.fallbackAction );
				show(
					h( 'div', { class: 'dc-replies', role: 'group' }, [
						fallback
							? reply( fallback.label, fallback.urgent, () =>
									onAction( fallback.id )
								)
							: null,
						reply( i18n.back_to_menu, false, onBack ),
					] )
				);
				return;
			}

			say( first.type === 'faq' ? first.excerpt : i18n.search_found );
			const pages = result.results
				.filter( ( r ) => r.type === 'page' )
				.slice( 0, 3 );
			if ( pages.length ) {
				show( cards( pages ), false );
			}
			view.end();
		},

		separator() {
			clearReplies();
			if ( log.childElementCount ) {
				log.append( h( 'hr', { class: 'dc-sep' } ) );
			}
		},
	};

	return view;
}
