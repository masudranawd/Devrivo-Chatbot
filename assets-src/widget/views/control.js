/**
 * Control for a quick action: a real link when the browser can open the
 * action directly (link, call, WhatsApp, email), otherwise a button (flows).
 */
import { h } from '../lib/dom';

/**
 * Builds the control.
 *
 * @param {{href: string, newTab: boolean}|null} target   Where the action leads, or null.
 * @param {Object}                               props    Element props (class, text, onClick, data-*…).
 * @param {Array<Node|string>}                   children Children.
 * @return {HTMLElement} Link or button.
 */
export function actionControl( target, props, children = [] ) {
	if ( target ) {
		return h(
			'a',
			{
				...props,
				href: target.href,
				target: target.newTab ? '_blank' : null,
				rel: target.newTab ? 'noopener noreferrer' : null,
			},
			children
		);
	}

	return h( 'button', { type: 'button', ...props }, children );
}
