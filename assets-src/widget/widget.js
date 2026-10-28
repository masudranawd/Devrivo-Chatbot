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
import { normalizeConfig } from './lib/config';
import { h, icon, ICONS } from './lib/dom';
import { rememberDismissed, wasDismissed } from './lib/storage';
import { widgetCss } from './styles';
import { chatView } from './views/chat';
import { homeView } from './views/home';
import { offersView } from './views/offers';
import { popoverView } from './views/popover';

/**
 * Event dispatched on the host element when a quick action or offer is chosen.
 * detail: { id, type }. Step 4 (Action engine) handles it.
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
 * @param {Object}      options { open }.
 * @return {Object} Controller: { state(), view(), destroy() }.
 */
export function render( host, config, options = {} ) {
	const shadow = host.shadowRoot;
	const launcherHadFocus = !! shadow.activeElement;
	const { i18n } = config;

	let state = 'closed';
	let current = 'home';

	const handlers = {
		onClose: () => setState( 'closed' ),
		onAction: runAction,
		onAsk: () => showView( 'chat', true ),
		onBack: () => showView( 'home', true ),
		onSend: () => {},
	};

	const root = h( 'div', {
		class: 'dc',
		'data-state': state,
		'data-scheme': config.colorScheme,
		'data-font': config.font,
	} );
	applyTheme( root, config );

	const popover = config.actions.length
		? popoverView( config, handlers )
		: null;

	const launcher = createLauncher( config.launcherLabel, config.avatarUrl );
	launcher.setAttribute( 'aria-haspopup', popover ? 'menu' : 'dialog' );
	launcher.setAttribute( 'aria-controls', popover ? 'dc-pop' : 'dc-panel' );
	launcher.addEventListener( 'click', onLauncher );

	const views = { home: homeView( config, handlers ) };
	if ( config.offers.length ) {
		views.offers = offersView( config, handlers );
	}
	if ( config.chatEnabled ) {
		views.chat = chatView( config, handlers );
	}

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

	root.append(
		...[
			h( 'div', { class: 'dc-overlay', onClick: handlers.onClose } ),
			popover,
			launcher,
			panel,
		].filter( Boolean )
	);
	shadow.replaceChildren( h( 'style', { text: baseCss + widgetCss } ), root );
	showView( 'home' );

	const onKeydown = ( event ) => {
		if ( event.key === 'Escape' && state !== 'closed' ) {
			setState( 'closed' );
		}
	};
	document.addEventListener( 'keydown', onKeydown );

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
	 * Tells listeners a quick action or offer was chosen, then closes the popover.
	 *
	 * @param {string} id Action ID.
	 */
	function runAction( id ) {
		const action = config.actions.find( ( a ) => a.id === id );
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

		if ( state === 'pop' || state === 'peek' ) {
			setState( 'closed' );
		}
	}

	return {
		state: () => state,
		view: () => current,
		destroy() {
			window.clearTimeout( timer );
			document.removeEventListener( 'keydown', onKeydown );
		},
	};
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
