/**
 * Opening hours in the business's timezone.
 */
import { describe, expect, it } from 'vitest';
import { isOpenNow, zonedParts } from '../../assets-src/widget/lib/hours';

const weekdays = ( open, close ) => ( {
	mon: [ { open, close } ],
	tue: [ { open, close } ],
	wed: [ { open, close } ],
	thu: [ { open, close } ],
	fri: [ { open, close } ],
	sat: [],
	sun: [],
} );

// Wednesday 2026-07-15 08:30 UTC.
const WED_0830_UTC = new Date( Date.UTC( 2026, 6, 15, 8, 30 ) );

describe( 'zonedParts', () => {
	it( 'reads the day and time in another timezone', () => {
		expect( zonedParts( WED_0830_UTC, 'Europe/London' ) ).toEqual( {
			day: 'wed',
			minutes: 9 * 60 + 30, // BST, UTC+1.
		} );
		expect( zonedParts( WED_0830_UTC, 'Pacific/Auckland' ) ).toEqual( {
			day: 'wed',
			minutes: 20 * 60 + 30,
		} );
		expect( zonedParts( WED_0830_UTC, 'America/Los_Angeles' ) ).toEqual( {
			day: 'wed',
			minutes: 1 * 60 + 30,
		} );
	} );

	it( 'supports UTC offsets WordPress uses without a city', () => {
		expect( zonedParts( WED_0830_UTC, '+05:30' ) ).toEqual( {
			day: 'wed',
			minutes: 14 * 60,
		} );
		expect( zonedParts( WED_0830_UTC, '-10:00' ) ).toEqual( {
			day: 'tue',
			minutes: 22 * 60 + 30,
		} );
	} );

	it( 'returns null for an unknown timezone', () => {
		expect( zonedParts( WED_0830_UTC, 'Mars/Olympus' ) ).toBeNull();
	} );
} );

describe( 'isOpenNow', () => {
	it( 'is open or closed depending on the business timezone', () => {
		const schedule = weekdays( '09:00', '17:00' );

		expect(
			isOpenNow( { timezone: 'Europe/London', schedule }, WED_0830_UTC )
		).toBe( true );
		expect(
			isOpenNow(
				{ timezone: 'America/Los_Angeles', schedule },
				WED_0830_UTC
			)
		).toBe( false );
	} );

	it( 'treats closing time as closed and supports split days', () => {
		const schedule = {
			...weekdays( '09:00', '09:30' ),
			wed: [
				{ open: '08:00', close: '09:30' },
				{ open: '13:00', close: '17:00' },
			],
		};
		const at = ( h, m ) => new Date( Date.UTC( 2026, 6, 15, h, m ) );

		expect( isOpenNow( { timezone: 'UTC', schedule }, at( 9, 29 ) ) ).toBe(
			true
		);
		expect( isOpenNow( { timezone: 'UTC', schedule }, at( 9, 30 ) ) ).toBe(
			false
		);
		expect( isOpenNow( { timezone: 'UTC', schedule }, at( 13, 0 ) ) ).toBe(
			true
		);
	} );

	it( 'is closed on a day without hours', () => {
		const saturday = new Date( Date.UTC( 2026, 6, 18, 12 ) );

		expect(
			isOpenNow(
				{ timezone: 'UTC', schedule: weekdays( '09:00', '17:00' ) },
				saturday
			)
		).toBe( false );
	} );

	it( 'is unknown with an empty schedule or a bad timezone', () => {
		expect(
			isOpenNow( { timezone: 'UTC', schedule: {} }, WED_0830_UTC )
		).toBeNull();
		expect( isOpenNow( { timezone: 'UTC', schedule: {} } ) ).toBeNull();
		expect(
			isOpenNow(
				{
					timezone: 'Mars/Olympus',
					schedule: weekdays( '09:00', '17:00' ),
				},
				WED_0830_UTC
			)
		).toBeNull();
		expect( isOpenNow( undefined ) ).toBeNull();
	} );
} );
