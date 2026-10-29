/**
 * Action executors and URL building.
 */
import { describe, expect, it, vi } from 'vitest';
import {
	actionTarget,
	mailtoHref,
	openTarget,
	telHref,
	whatsappHref,
	withUtm,
} from '../../assets-src/widget/lib/actions';

const action = ( type, config, id = 'book' ) => ( { id, type, config } );

describe( 'withUtm', () => {
	it( 'adds UTM parameters and keeps the query and hash', () => {
		const url = new URL(
			withUtm( 'https://example.com/book?ref=home&x=1#slots', 'book' )
		);

		expect( url.searchParams.get( 'ref' ) ).toBe( 'home' );
		expect( url.searchParams.get( 'x' ) ).toBe( '1' );
		expect( url.searchParams.get( 'utm_source' ) ).toBe(
			'devrivo-chatbot'
		);
		expect( url.searchParams.get( 'utm_medium' ) ).toBe( 'chat' );
		expect( url.searchParams.get( 'utm_campaign' ) ).toBe( 'quick-action' );
		expect( url.searchParams.get( 'utm_content' ) ).toBe( 'book' );
		expect( url.hash ).toBe( '#slots' );
	} );

	it( 'never overwrites UTM values the link already has', () => {
		const url = new URL(
			withUtm( 'https://example.com/?utm_source=newsletter', 'book' )
		);

		expect( url.searchParams.get( 'utm_source' ) ).toBe( 'newsletter' );
		expect( url.searchParams.get( 'utm_content' ) ).toBe( 'book' );
	} );
} );

describe( 'actionTarget', () => {
	it( 'opens links in a new tab by default, with UTM when enabled', () => {
		const target = actionTarget(
			action( 'link', { url: 'https://example.com/book' } ),
			true
		);

		expect( target.newTab ).toBe( true );
		expect( target.href ).toContain( 'utm_content=book' );
	} );

	it( 'respects new_tab false and utm off', () => {
		expect(
			actionTarget(
				action( 'link', {
					url: 'https://example.com/book',
					new_tab: false,
				} ),
				false
			)
		).toEqual( { href: 'https://example.com/book', newTab: false } );
	} );

	it( 'rejects unsafe or empty links', () => {
		for ( const url of [
			'javascript:alert(1)',
			'data:text/html,hi',
			'tel:123',
			'mailto:a@b.co',
			'',
			undefined,
		] ) {
			expect(
				actionTarget( action( 'link', { url } ), true )
			).toBeNull();
		}
	} );

	it( 'builds call, WhatsApp and email links', () => {
		expect(
			actionTarget( action( 'call', { phone: '+44 20 7946 0000' } ) )
		).toEqual( { href: 'tel:+442079460000', newTab: false } );

		expect(
			actionTarget(
				action( 'whatsapp', {
					phone: '+44 7700 900123',
					message: 'Hi & hello?',
				} )
			)
		).toEqual( {
			href: 'https://wa.me/447700900123?text=Hi%20%26%20hello%3F',
			newTab: true,
		} );

		expect(
			actionTarget(
				action( 'email', {
					email: 'team@example.com',
					subject: 'Book & pay?',
				} )
			)
		).toEqual( {
			href: 'mailto:team@example.com?subject=Book%20%26%20pay%3F',
			newTab: false,
		} );
	} );

	it( 'returns null for flows, unknown types and unusable values', () => {
		expect(
			actionTarget( action( 'flow', { flow: 'urgent' } ) )
		).toBeNull();
		expect( actionTarget( action( 'custom', {} ) ) ).toBeNull();
		expect( telHref( 'call me' ) ).toBe( '' );
		expect( telHref( '' ) ).toBe( '' );
		expect( whatsappHref( '12' ) ).toBe( '' );
		expect( whatsappHref( '+44 7700 900123' ) ).toBe(
			'https://wa.me/447700900123'
		);
		expect( mailtoHref( 'not-an-email' ) ).toBe( '' );
		expect( mailtoHref( 'a@b.co?cc=evil@x.co' ) ).toBe( '' );
	} );
} );

describe( 'openTarget', () => {
	it( 'opens new tabs without opener access', () => {
		const open = vi.spyOn( window, 'open' ).mockReturnValue( null );

		openTarget( { href: 'https://wa.me/123456', newTab: true } );

		expect( open ).toHaveBeenCalledWith(
			'https://wa.me/123456',
			'_blank',
			'noopener'
		);
		open.mockRestore();
	} );
} );
