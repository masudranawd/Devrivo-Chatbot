/**
 * Unit tests for the widget helpers: boot parsing, device check, dismissal
 * storage, URL validation, colours and config normalisation.
 */
import { describe, expect, it, vi } from 'vitest';
import {
	currentDevice,
	deviceAllowed,
	MOBILE_QUERY,
	parseBoot,
} from '../../assets-src/widget/lib/boot';
import {
	rememberDismissed,
	wasDismissed,
} from '../../assets-src/widget/lib/storage';
import { safeUrl } from '../../assets-src/widget/lib/url';
import { textOn } from '../../assets-src/widget/lib/color';
import {
	normalizeConfig,
	timeGreeting,
} from '../../assets-src/widget/lib/config';

const BOOT = {
	configUrl: 'https://example.com/wp-json/devrivo-chatbot/v1/config?v=3',
	widgetUrl: 'https://example.com/wp-content/plugins/x/build/widget.js?ver=1',
	position: 'left',
	primaryColor: '#123456',
	launcherLabel: '  Talk to us  ',
	devices: [ 'mobile', 'tablet' ],
};

describe( 'parseBoot', () => {
	it( 'returns null when boot data is missing or unusable', () => {
		expect( parseBoot( undefined ) ).toBeNull();
		expect( parseBoot( 'nope' ) ).toBeNull();
		expect(
			parseBoot( { ...BOOT, configUrl: 'javascript:alert(1)' } )
		).toBeNull();
		expect( parseBoot( { ...BOOT, widgetUrl: '' } ) ).toBeNull();
	} );

	it( 'validates every field', () => {
		expect( parseBoot( BOOT ) ).toEqual( {
			configUrl: BOOT.configUrl,
			widgetUrl: BOOT.widgetUrl,
			position: 'left',
			primaryColor: '#123456',
			launcherLabel: 'Talk to us',
			devices: [ 'mobile' ],
		} );
	} );

	it( 'falls back to safe defaults', () => {
		const boot = parseBoot( {
			...BOOT,
			position: 'top',
			primaryColor: 'red;}body{display:none',
			launcherLabel: 42,
			devices: 'all',
		} );

		expect( boot.position ).toBe( 'right' );
		expect( boot.primaryColor ).toBe( '#1e3b36' );
		expect( boot.launcherLabel ).toBe( '' );
		expect( boot.devices ).toEqual( [ 'desktop', 'mobile' ] );
	} );
} );

describe( 'device check', () => {
	it( 'treats narrow viewports as mobile', () => {
		const matchMedia = vi.fn( ( query ) => ( {
			matches: query === MOBILE_QUERY,
		} ) );

		expect( currentDevice( matchMedia ) ).toBe( 'mobile' );
		expect( matchMedia ).toHaveBeenCalledWith( '(max-width: 768px)' );
	} );

	it( 'treats wide viewports, or no matchMedia, as desktop', () => {
		expect( currentDevice( () => ( { matches: false } ) ) ).toBe(
			'desktop'
		);
		expect( currentDevice( undefined ) ).toBe( 'desktop' );
	} );

	it( 'only allows the configured devices', () => {
		expect( deviceAllowed( [ 'mobile' ], 'mobile' ) ).toBe( true );
		expect( deviceAllowed( [ 'mobile' ], 'desktop' ) ).toBe( false );
		expect( deviceAllowed( [], 'desktop' ) ).toBe( false );
	} );
} );

describe( 'dismissal storage', () => {
	it( 'remembers a dismissal for the visit', () => {
		window.sessionStorage.clear();

		expect( wasDismissed() ).toBe( false );
		rememberDismissed();
		expect( wasDismissed() ).toBe( true );
		expect(
			window.sessionStorage.getItem( 'devrivoChatbotDismissed' )
		).toBe( '1' );
	} );

	it( 'never throws when storage is blocked', () => {
		const blocked = {
			getItem() {
				throw new Error( 'SecurityError' );
			},
			setItem() {
				throw new Error( 'QuotaExceededError' );
			},
		};

		expect( () => rememberDismissed( blocked ) ).not.toThrow();
		expect( wasDismissed( blocked ) ).toBe( false );
		expect( wasDismissed( null ) ).toBe( false );
	} );
} );

describe( 'safeUrl', () => {
	const base = 'https://example.com/page/';

	it( 'allows http, https, tel and mailto', () => {
		expect( safeUrl( 'https://a.test/x', undefined, base ) ).toBe(
			'https://a.test/x'
		);
		expect( safeUrl( 'http://a.test/', undefined, base ) ).toBe(
			'http://a.test/'
		);
		expect( safeUrl( 'tel:+441234', undefined, base ) ).toBe(
			'tel:+441234'
		);
		expect( safeUrl( 'mailto:a@b.test', undefined, base ) ).toBe(
			'mailto:a@b.test'
		);
	} );

	it( 'resolves relative URLs against the page', () => {
		expect( safeUrl( '/contact', undefined, base ) ).toBe(
			'https://example.com/contact'
		);
	} );

	it( 'rejects dangerous or empty values', () => {
		for ( const value of [
			'javascript:alert(1)',
			' JaVaScRiPt:alert(1)',
			'data:text/html,<script>alert(1)</script>',
			'vbscript:msgbox',
			'',
			'   ',
			null,
			42,
		] ) {
			expect( safeUrl( value, undefined, base ) ).toBe( '' );
		}
	} );

	it( 'can be limited to http and https', () => {
		expect( safeUrl( 'tel:1', [ 'http:', 'https:' ], base ) ).toBe( '' );
	} );
} );

describe( 'textOn', () => {
	it( 'picks white on dark colours and dark text on light colours', () => {
		expect( textOn( '#1e3b36' ) ).toBe( '#ffffff' );
		expect( textOn( '#000' ) ).toBe( '#ffffff' );
		expect( textOn( '#ffeb3b' ) ).toBe( '#17201e' );
		expect( textOn( '#fff' ) ).toBe( '#17201e' );
	} );
} );

describe( 'normalizeConfig', () => {
	const boot = parseBoot( BOOT );

	it( 'rejects a response that is not a config object', () => {
		expect( () => normalizeConfig( null, boot ) ).toThrow( TypeError );
		expect( () => normalizeConfig( [], boot ) ).toThrow( TypeError );
	} );

	it( 'keeps valid actions and offers and drops broken ones', () => {
		const config = normalizeConfig(
			{
				actions: [
					{
						id: 'a',
						label: 'A',
						type: 'link',
						urgent: true,
						config: { url: '/x' },
					},
					{ id: 'b', label: '', type: 'link' },
					'junk',
				],
				offers: [
					{ id: 'o1', title: 'One', action: 'a', featured: true },
					{ id: 'o2', title: 'Two', action: 'missing' },
					{ id: 'o3' },
				],
			},
			boot
		);

		expect( config.actions ).toEqual( [
			{
				id: 'a',
				label: 'A',
				type: 'link',
				urgent: true,
				showInMenu: true,
				config: { url: '/x' },
			},
		] );
		expect( config.offers.map( ( o ) => [ o.id, o.action ] ) ).toEqual( [
			[ 'o1', 'a' ],
			[ 'o2', '' ],
		] );
	} );

	it( 'falls back to boot values and safe defaults', () => {
		const config = normalizeConfig(
			{
				display: { primary_color: 'nope' },
				appearance: {
					radius: 999,
					color_scheme: 'neon',
					avatar_url: 'javascript:alert(1)',
				},
				behaviour: { auto_open_delay: -5 },
			},
			boot
		);

		expect( config.primaryColor ).toBe( '#123456' );
		expect( config.launcherLabel ).toBe( 'Talk to us' );
		expect( config.position ).toBe( 'left' );
		expect( config.radius ).toBe( 32 );
		expect( config.colorScheme ).toBe( 'auto' );
		expect( config.avatarUrl ).toBe( '' );
		expect( config.autoOpenDelay ).toBe( 0 );
		expect( config.chatEnabled ).toBe( false );
	} );

	it( 'greets by time of day', () => {
		const i18n = {
			good_morning: 'Morning',
			good_afternoon: 'Afternoon',
			good_evening: 'Evening',
		};

		expect( timeGreeting( 8, i18n ) ).toBe( 'Morning' );
		expect( timeGreeting( 13, i18n ) ).toBe( 'Afternoon' );
		expect( timeGreeting( 21, i18n ) ).toBe( 'Evening' );
	} );
} );
