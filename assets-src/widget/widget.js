/**
 * Full widget (budget: 60 KB). Loaded by the loader, which calls
 * window.devrivoChatbotWidget.mount( host, boot, options ).
 *
 * States (data-state on .dc):
 * - closed: launcher only.
 * - peek:   quick actions auto-opened after a delay, no overlay.
 * - pop:    quick actions opened by the visitor, with an overlay.
 * - panel:  the assistant panel (Home, Offers, Chat).
 */
import { applyTheme, baseCss, createLauncher } from './launcher';
import { actionTarget, openTarget } from './lib/actions';
import { normalizeConfig } from './lib/config';
import { h, icon, ICONS } from './lib/dom';
import { createRunner } from './lib/flow';
import { createSearcher } from './lib/search';
import { createSubmitter } from './lib/submit';
import { isOpenNow } from './lib/hours';
import { rememberDismissed, wasDismissed } from './lib/storage';
import { widgetCss } from './styles';
import { chatView } from './views/chat';
import { conversationView } from './views/conversation';
import { homeView } from './views/home';
import { offersView } from './views/offers';
import { popoverView } from './views/popover';

/**
 * Event dispatched on the host element whenever a quick action runs (menu,
 * popover, offer, flow). detail: { id, type }. For other scripts, e.g. analytics.
 */
export const ACTION_EVENT = 'devrivo-chatbot:action';

const FOCUSABLE = 'button:not([disabled]),input:not([disabled]),a[href]';

/**
 * Fetches the config and renders the widget into the loader's host.
 *
 * On any failure the loader's launcher stays as it is and nothing is shown or
 * logged to the visitor.
 *
 * @param {HTMLElement} host    Host element with an open shadow root.
 * @param {Object}      boot    Parsed boot data.
 * @param {Object}      options { open }: read after the config loads, so a click during loading still opens.
 * @return {Promise<Object|null>} Widget controller, or null.
 */
export async function mount( host, boot, options = {} ) {
	if ( ! host || ! host.shadowRoot || host.dataset.dcMounted ) {
		return null;
	}
	host.dataset.dcMounted = '1';

	let config;
	try {
		const response = await window.fetch( boot.configUrl, {
			credentials: 'omit',
			headers: { Accept: 'application/json' },
		} );
		if ( ! response.ok ) {
			throw new Error( String( response.status ) );
		}
		config = normalizeConfig( await response.json(), boot );
	} catch {
		return null;
	}

	return render( host, config, options );
}

/**
 * Renders the widget.
 *
 * @param {HTMLElement} host    Host element with an open shadow root.
 * @param {Object}      config  Normalised config.
 * @param {Object}      options { open, typingTime( text ), submitter, searcher }.
 * @return {Object} Controller: { state(), view(), destroy() }.
 */
export function render( host, config, options = {} ) {
	const shadow = host.shadowRoot;
	const launcherHadFocus = !! shadow.activeElement;
	const { i18n } = config;

	let state = 'closed';
	let current = 'home';
	// Post types the composer searches (set by page_search actions).
	let searchTypes = [];

	const target = ( action ) =>
		action ? actionTarget( action, config.utm ) : null;
	const byId = new Map( config.actions.map( ( a ) => [ a.id, a ] ) );
	const menu = config.actions.filter( ( a ) => a.showInMenu );

	const handlers = {
		onClose: () => setState( 'closed' ),
		onAction: ( id ) => runAction( id ),
		target,
		onAsk: () => ask(),
		onBack: () => showView( 'home', true ),
		onSend: ( text ) => conversation.search( text, searchTypes ),
	};

	const root = h( 'div', {
		class: 'dc',
		'data-state': state,
		'data-scheme': config.colorScheme,
		'data-font': config.font,
	} );
	applyTheme( root, config );

	const popover = menu.length ? popoverView( config, handlers ) : null;

	const launcher = createLauncher( config.launcherLabel, config.avatarUrl );
	launcher.setAttribute( 'aria-haspopup', popover ? 'menu' : 'dialog' );
	launcher.setAttribute( 'aria-controls', popover ? 'dc-pop' : 'dc-panel' );
	launcher.addEventListener( 'click', onLauncher );

	// Open/closed status: a dot on the launcher plus a line of text on Home.
	const dot = h( 'span', {
		class: 'dc-dot',
		'aria-hidden': 'true',
		hidden: true,
	} );
	const status = h( 'p', { class: 'dc-status', hidden: true } );
	launcher.querySelector( '.dc-avatar' ).append( dot );

	const views = {
		home: homeView( config, handlers, status ),
		chat: chatView( config, handlers ),
	};
	if ( config.offers.length ) {
		views.offers = offersView( config, handlers );
	}

	const conversation = conversationView(
		views.chat.querySelector( '.dc-msgs' ),
		{
			config,
			target,
			// Buttons: flows, or choices that run an action.
			onAction: ( id ) => runAction( id, true, true ),
			// Links open by themselves.
			onCta: ( id ) => runAction( id ),
			onBack: () => showView( 'home', true ),
			typingTime: options.typingTime || typingTime,
			submitter:
				options.submitter ||
				createSubmitter( {
					sessionUrl: config.sessionUrl,
					submitUrl: config.submitUrl,
				} ),
			searcher:
				options.searcher || createSearcher( { url: config.searchUrl } ),
		}
	);
	const runner = createRunner( {
		flows: config.flows,
		isOpen: () => isOpenNow( config.hours ),
		view: conversation,
	} );

	const tabs = views.offers ? tabBar() : null;

	const panel = h(
		'section',
		{
			class: 'dc-panel',
			id: 'dc-panel',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-label': config.brandName || i18n.assistant,
		},
		[ ...Object.values( views ), tabs ]
	);

	// Only a popover the visitor opened gets the overlay, never the auto-opened one.
	const overlay = h( 'div', {
		class: 'dc-overlay',
		hidden: true,
		onClick: handlers.onClose,
	} );

	root.append( ...[ overlay, popover, launcher, panel ].filter( Boolean ) );
	shadow.replaceChildren( h( 'style', { text: baseCss + widgetCss } ), root );
	showView( 'home' );

	const onKeydown = ( event ) => {
		if ( event.key === 'Escape' && state !== 'closed' ) {
			setState( 'closed' );
		}
	};
	document.addEventListener( 'keydown', onKeydown );

	refreshStatus();
	const statusTimer = config.hours.showStatus
		? window.setInterval( refreshStatus, 60000 )
		: 0;

	let timer = 0;
	if ( options.open ) {
		onLauncher();
	} else {
		if ( launcherHadFocus ) {
			launcher.focus();
		}
		if ( config.autoOpen && popover && ! wasDismissed() ) {
			timer = window.setTimeout( () => {
				if ( state === 'closed' ) {
					setState( 'peek' );
				}
			}, config.autoOpenDelay );
		}
	}

	/**
	 * Launcher: closed -> quick actions; quick actions -> panel.
	 */
	function onLauncher() {
		if ( state === 'closed' && popover ) {
			setState( 'pop' );
		} else {
			showView( 'home' );
			setState( 'panel' );
		}
	}

	/**
	 * Moves to a state and manages focus.
	 *
	 * @param {string} next closed|peek|pop|panel.
	 */
	function setState( next ) {
		const focusInside =
			!! shadow.activeElement && shadow.activeElement !== launcher;
		const previous = state;

		state = next;
		root.dataset.state = next;
		overlay.hidden = next !== 'pop';
		launcher.setAttribute( 'aria-expanded', String( next !== 'closed' ) );

		if ( next === 'pop' ) {
			(
				popover.querySelector( '.dc-chip' ) || popover.firstChild
			).focus();
		} else if ( next === 'panel' ) {
			focusFirst( views[ current ] );
		} else if ( next === 'closed' && previous !== 'closed' ) {
			rememberDismissed();
			if ( focusInside ) {
				launcher.focus();
			}
		}
	}

	/**
	 * Shows a panel view.
	 *
	 * @param {string}  name  home|offers|chat.
	 * @param {boolean} focus Move focus into the view.
	 */
	function showView( name, focus = false ) {
		current = views[ name ] ? name : 'home';

		for ( const [ key, element ] of Object.entries( views ) ) {
			element.classList.toggle( 'is-active', key === current );
		}

		if ( tabs ) {
			tabs.hidden = current === 'chat';
			for ( const tab of tabs.querySelectorAll( '[role="tab"]' ) ) {
				const selected = tab.dataset.tab === current;
				tab.setAttribute( 'aria-selected', String( selected ) );
				tab.tabIndex = selected ? 0 : -1;
			}
		}

		if ( focus ) {
			focusFirst( views[ current ] );
		}
	}

	/**
	 * Home / Offers tab bar.
	 *
	 * @return {HTMLElement} Tab list.
	 */
	function tabBar() {
		const names = [ 'home', 'offers' ];
		const list = h(
			'div',
			{ class: 'dc-tabs', role: 'tablist' },
			names.map( ( name ) => {
				views[ name ].setAttribute( 'role', 'tabpanel' );
				views[ name ].setAttribute(
					'aria-labelledby',
					`dc-tab-${ name }`
				);

				return h(
					'button',
					{
						type: 'button',
						class: 'dc-tab',
						role: 'tab',
						id: `dc-tab-${ name }`,
						'aria-controls': `dc-view-${ name }`,
						'data-tab': name,
						onClick: () => showView( name ),
					},
					[
						icon( ICONS[ name ], 22 ),
						h( 'span', { text: i18n[ name ] } ),
					]
				);
			} )
		);

		list.addEventListener( 'keydown', ( event ) => {
			if ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) {
				return;
			}
			event.preventDefault();
			const next = current === 'home' ? 'offers' : 'home';
			showView( next );
			list.querySelector( `[data-tab="${ next }"]` ).focus();
		} );

		return list;
	}

	/**
	 * Runs a quick action. Links, calls, WhatsApp and email are real links that
	 * the browser opens itself, unless `navigate` is set (flow choices are
	 * buttons). Flows open in the chat view.
	 *
	 * @param {string}  id       Action ID.
	 * @param {boolean} navigate Open the action's URL from script.
	 * @param {boolean} fromFlow Chosen inside a flow (same conversation).
	 */
	function runAction( id, navigate = false, fromFlow = false ) {
		const action = byId.get( id );
		if ( ! action ) {
			return;
		}

		host.dispatchEvent(
			new window.CustomEvent( ACTION_EVENT, {
				bubbles: true,
				composed: true,
				detail: { id: action.id, type: action.type },
			} )
		);

		if ( action.type === 'page_search' ) {
			ask( action.config.post_types );
			return;
		}

		if ( action.type === 'flow' || action.type === 'form' ) {
			openConversation( ! fromFlow );
			if ( action.type === 'flow' ) {
				runner.start( action.config.flow, ! fromFlow );
			} else {
				conversation.form( action.config.form );
			}
			return;
		}

		const to = target( action );
		if ( navigate && to ) {
			openTarget( to );
		}
		if ( state === 'pop' || state === 'peek' ) {
			setState( 'closed' );
		}
	}

	/**
	 * Opens the chat view for a flow or a form.
	 *
	 * @param {boolean} fresh Started from the menu (a new conversation).
	 */
	function openConversation( fresh ) {
		if ( fresh ) {
			conversation.separator();
		}
		showView( 'chat' );
		if ( state !== 'panel' ) {
			setState( 'panel' );
		}
	}

	/**
	 * Opens the chat view for a question and focuses the composer.
	 *
	 * @param {unknown} types Only these post types (page_search), or all.
	 */
	function ask( types = [] ) {
		searchTypes = Array.isArray( types )
			? types.filter( ( t ) => typeof t === 'string' )
			: [];
		openConversation( true );
		conversation.prompt( i18n.ask_prompt );
		const input = views.chat.querySelector( '.dc-composer input' );
		if ( input ) {
			input.focus();
		}
	}

	/**
	 * Shows whether the business is open, when the admin turned it on.
	 */
	function refreshStatus() {
		const open = config.hours.showStatus ? isOpenNow( config.hours ) : null;

		dot.hidden = open === null;
		status.hidden = open === null;
		dot.classList.toggle( 'is-closed', open === false );
		status.textContent = open ? i18n.open_now : i18n.closed_now;
	}

	return {
		state: () => state,
		view: () => current,
		destroy() {
			window.clearTimeout( timer );
			window.clearInterval( statusTimer );
			document.removeEventListener( 'keydown', onKeydown );
		},
	};
}

/**
 * How long the typing indicator shows before a bot message (none when the
 * visitor prefers reduced motion).
 *
 * @param {string} text Message.
 * @return {number} Milliseconds.
 */
function typingTime( text ) {
	const reduce =
		typeof window.matchMedia === 'function' &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	return reduce ? 0 : Math.min( 1400, 450 + text.length * 6 );
}

/**
 * Focuses the first focusable element in a container.
 *
 * @param {HTMLElement} container Container.
 */
function focusFirst( container ) {
	const target = container && container.querySelector( FOCUSABLE );
	if ( target ) {
		target.focus();
	}
}

window.devrivoChatbotWidget = { mount };
