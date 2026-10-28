/**
 * Widget loader (budget: 15 KB). Draws the launcher in a Shadow DOM and loads
 * the full widget (build/widget.js) on the first interaction or when the
 * browser is idle.
 */
import { currentDevice, deviceAllowed, parseBoot } from './lib/boot';
import { applyTheme, baseCss, createLauncher } from './launcher';

( function () {
	const boot = parseBoot( window.devrivoChatbotBoot );
	// Bound: browsers throw "Illegal invocation" for a detached matchMedia.
	const matchMedia = window.matchMedia && window.matchMedia.bind( window );
	if (
		! boot ||
		! deviceAllowed( boot.devices, currentDevice( matchMedia ) ) ||
		document.getElementById( 'devrivo-chatbot' )
	) {
		return;
	}

	const host = document.createElement( 'div' );
	host.id = 'devrivo-chatbot';
	const shadow = host.attachShadow( { mode: 'open' } );

	const style = document.createElement( 'style' );
	style.textContent = baseCss;

	const root = document.createElement( 'div' );
	root.className = 'dc';
	applyTheme( root, boot );

	const launcher = createLauncher( boot.launcherLabel );
	root.append( launcher );
	shadow.append( style, root );
	document.body.append( host );

	// Shared with the widget: it opens the quick actions once ready if this is true.
	const options = { open: false };
	let requested = false;

	const load = () => {
		if ( requested ) {
			return;
		}
		requested = true;

		const script = document.createElement( 'script' );
		script.src = boot.widgetUrl;
		script.async = true;
		script.addEventListener( 'load', () => {
			if ( window.devrivoChatbotWidget ) {
				window.devrivoChatbotWidget.mount( host, boot, options );
			}
		} );
		document.head.append( script );
	};

	launcher.addEventListener( 'pointerenter', load );
	launcher.addEventListener( 'focus', load );
	launcher.addEventListener( 'click', () => {
		options.open = true;
		load();
	} );

	if ( 'requestIdleCallback' in window ) {
		window.requestIdleCallback( load, { timeout: 3000 } );
	} else {
		window.setTimeout( load, 3000 );
	}
} )();
