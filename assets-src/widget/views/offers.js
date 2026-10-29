/**
 * Offers view.
 */
import { h, icon, ICONS } from '../lib/dom';
import { actionControl } from './control';

/**
 * Builds the Offers view.
 *
 * @param {Object} config   Normalised config.
 * @param {Object} handlers { onClose(), onAction( id ), target( action ) }.
 * @return {HTMLElement} View.
 */
export function offersView( config, handlers ) {
	const { i18n } = config;
	const byId = new Map( config.actions.map( ( a ) => [ a.id, a ] ) );

	const items = config.offers.map( ( offer ) =>
		h(
			'li',
			{
				class: `dc-offer${ offer.featured ? ' is-featured' : '' }`,
				'data-offer': offer.id,
			},
			[
				h( 'h3', { text: offer.title } ),
				offer.text ? h( 'p', { text: offer.text } ) : null,
				offer.action
					? actionControl(
							handlers.target( byId.get( offer.action ) ),
							{
								class: 'dc-cta',
								'data-action': offer.action,
								text: offer.ctaLabel || i18n.offer_cta,
								onClick: () =>
									handlers.onAction( offer.action ),
							}
						)
					: null,
			]
		)
	);

	return h(
		'div',
		{ class: 'dc-view', id: 'dc-view-offers', 'data-view': 'offers' },
		[
			h( 'div', { class: 'dc-view-head' }, [
				h( 'h2', { text: i18n.offers } ),
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
			h( 'ul', { class: 'dc-offers' }, items ),
		]
	);
}
