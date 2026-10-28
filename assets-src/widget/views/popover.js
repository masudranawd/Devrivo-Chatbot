/**
 * Quick-action popover above the launcher.
 */
import { h, icon, ICONS } from '../lib/dom';

/**
 * Builds the popover.
 *
 * @param {Object} config   Normalised config.
 * @param {Object} handlers { onClose(), onAction( id ) }.
 * @return {HTMLElement} Popover (role="menu").
 */
export function popoverView( config, handlers ) {
	const close = h(
		'button',
		{
			type: 'button',
			class: 'dc-pop-close',
			role: 'menuitem',
			'aria-label': config.i18n.close_menu,
			onClick: handlers.onClose,
		},
		icon( ICONS.close, 18 )
	);

	const chips = config.actions.map( ( action ) =>
		h( 'button', {
			type: 'button',
			class: `dc-chip${ action.urgent ? ' dc-urgent' : '' }`,
			role: 'menuitem',
			'data-action': action.id,
			text: action.label,
			onClick: () => handlers.onAction( action.id ),
		} )
	);

	const menu = h(
		'div',
		{
			class: 'dc-pop',
			id: 'dc-pop',
			role: 'menu',
			'aria-label': config.i18n.menu,
		},
		[ close, ...chips ]
	);

	// Arrow keys move between items, as a menu should.
	menu.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'ArrowDown' && event.key !== 'ArrowUp' ) {
			return;
		}
		event.preventDefault();
		const items = [ close, ...chips ];
		const index = items.indexOf( menu.getRootNode().activeElement );
		const step = event.key === 'ArrowDown' ? 1 : -1;
		items[ ( index + step + items.length ) % items.length ].focus();
	} );

	return menu;
}
