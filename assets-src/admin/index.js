/**
 * Admin app entry: renders the React app into #devrivo-chatbot-admin.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import App from './app';
import './admin.scss';

domReady( () => {
	const element = document.getElementById( 'devrivo-chatbot-admin' );
	if ( element ) {
		createRoot( element ).render( <App /> );
	}
} );
