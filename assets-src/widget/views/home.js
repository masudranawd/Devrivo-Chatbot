/**
 * Home view: greeting, welcome, "Ask a question" (when search or AI is on) and the
 * quick actions list, plus an optional open/closed status line.
 */
import { h, icon, ICONS } from '../lib/dom';
import { timeGreeting } from '../lib/config';
import { avatar } from '../launcher';
import { actionControl } from './control';

/**
 * Builds the Home view.
 *
 * @param {Object} config   Normalised config.
 * @param {Object} handlers { onClose(), onAsk(), onAction( id ), target( action ) }.
 * @param {Node}   status   Status line (shown by the widget when enabled).
 * @param {Date}   now      Current time (for the default greeting).
 * @return {HTMLElement} View.
 */
export function homeView( config, handlers, status = null, now = new Date() ) {
	const { i18n } = config;
	const menu = config.actions.filter( ( action ) => action.showInMenu );

	const hero = h( 'div', { class: 'dc-hero' }, [
		h( 'div', { class: 'dc-hero-top' }, [
			h( 'span', { class: 'dc-brand', text: config.brandName } ),
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
		h( 'p', {
			class: 'dc-greet',
			text: config.greeting || timeGreeting( now.getHours(), i18n ),
		} ),
		h( 'h2', {
			class: 'dc-welcome',
			text: config.welcome || i18n.welcome,
		} ),
	] );

	const ask =
		config.aiAvailable || config.searchEnabled
			? h(
					'button',
					{
						type: 'button',
						class: 'dc-ask',
						onClick: handlers.onAsk,
					},
					[
						avatar( config.avatarUrl, 'sm' ),
						h( 'span', {}, [
							h( 'strong', { text: i18n.ask } ),
							h( 'span', { text: i18n.ask_hint } ),
						] ),
						icon( ICONS.send, 20 ),
					]
				)
			: null;

	const list = menu.length
		? h(
				'ul',
				{ class: 'dc-list' },
				menu.map( ( action ) =>
					h(
						'li',
						{},
						actionControl(
							handlers.target( action ),
							{
								class: `dc-row${ action.urgent ? ' dc-urgent' : '' }`,
								'data-action': action.id,
								onClick: () => handlers.onAction( action.id ),
							},
							[
								h( 'span', { text: action.label } ),
								icon( ICONS.chevron, 16, 'dc-flip' ),
							]
						)
					)
				)
			)
		: null;

	return h(
		'div',
		{
			class: 'dc-view dc-home',
			id: 'dc-view-home',
			'data-view': 'home',
		},
		[ hero, h( 'div', { class: 'dc-home-body' }, [ ask, list, status ] ) ]
	);
}
