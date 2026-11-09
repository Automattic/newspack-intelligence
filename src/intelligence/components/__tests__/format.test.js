/* eslint-env jest */
/**
 * metaLine joins a row's meta parts, leaving out only the absent ones, so a
 * zero still shows. sinceLabel names an instant by the reader's calendar, in
 * the zone the suite pins (Pacific/Auckland), whatever the page's clock.
 * dayLabel names a UTC day by its day and month, adding the year only when
 * it is not today's, always in UTC. usualPhrase says a usual count in
 * whole words.
 */

import { dayLabel, metaLine, sinceLabel, usualPhrase } from '../format';

/**
 * A local wall time as UTC seconds.
 *
 * @param {...number} parts Year, month from 0, day, hours, minutes.
 * @return {number} The instant.
 */
const local = ( ...parts ) =>
	Math.floor( new Date( ...parts ).getTime() / 1000 );

describe( 'metaLine', () => {
	it( 'keeps a zero and drops null, undefined and blank parts', () => {
		expect(
			metaLine( 'mention', null, 0, undefined, '', 'ner_fuzzy' )
		).toBe( 'mention · 0 · ner_fuzzy' );
	} );
} );

describe( 'sinceLabel', () => {
	const friday = local( 2026, 10, 6, 10, 17 );

	it( 'names a weekday this week by its name and time', () => {
		expect( sinceLabel( local( 2026, 10, 3, 14, 31 ), friday ) ).toBe(
			'Tuesday at 14:31'
		);
	} );

	it( 'names the day before as yesterday', () => {
		expect( sinceLabel( local( 2026, 10, 5, 9, 5 ), friday ) ).toBe(
			'yesterday at 09:05'
		);
	} );

	it( 'names the same day as today', () => {
		expect( sinceLabel( local( 2026, 10, 6, 7, 42 ), friday ) ).toBe(
			'today at 07:42'
		);
	} );

	it( 'names a mark six days back by its weekday', () => {
		expect( sinceLabel( local( 2026, 9, 31, 11, 22 ), friday ) ).toBe(
			'Saturday at 11:22'
		);
	} );

	it( 'names a mark seven days back by its date', () => {
		expect( sinceLabel( local( 2026, 9, 30, 11, 22 ), friday ) ).toBe(
			'30 Oct'
		);
	} );

	it( 'names a mark ahead of now, from a skewed clock, as today', () => {
		expect( sinceLabel( local( 2026, 10, 7, 8, 15 ), friday ) ).toBe(
			'today at 08:15'
		);
	} );

	it( 'names a mark more than a week back by its date', () => {
		expect( sinceLabel( local( 2026, 9, 28, 16, 8 ), friday ) ).toBe(
			'28 Oct'
		);
	} );

	it( 'counts calendar days across the 23-hour day of a DST change', () => {
		expect(
			sinceLabel(
				local( 2026, 8, 26, 23, 40 ),
				local( 2026, 8, 27, 23, 10 )
			)
		).toBe( 'yesterday at 23:40' );
	} );
} );

describe( 'dayLabel', () => {
	it( 'names a day this year by its day and short month', () => {
		expect( dayLabel( '2026-11-06', '2026-11-08' ) ).toBe( '6 Nov' );
	} );

	it( 'adds the year to a day of another year', () => {
		expect( dayLabel( '2025-11-06', '2026-11-08' ) ).toBe( '6 Nov 2025' );
	} );

	/**
	 * Jest hands a test a copy of `process.env`, so setting `TZ` there moves
	 * no clock; the case makes the reader's zone America/Los_Angeles, behind
	 * UTC, by defaulting every date format built while it runs to that zone.
	 */
	it( 'reads both days in UTC, even in a zone behind it', () => {
		const Real = Intl.DateTimeFormat;
		const behind = jest.spyOn( Intl, 'DateTimeFormat' ).mockImplementation(
			( locale, options ) =>
				new Real( locale, {
					timeZone: 'America/Los_Angeles',
					...options,
				} )
		);
		try {
			jest.isolateModules( () => {
				const fresh = require( '../format' );
				expect( fresh.dayLabel( '2026-11-06', '2026-11-08' ) ).toBe(
					'6 Nov'
				);
				expect( fresh.dayLabel( '2027-01-01', '2026-11-08' ) ).toBe(
					'1 Jan 2027'
				);
			} );
		} finally {
			behind.mockRestore();
		}
	} );
} );

describe( 'the date formats', () => {
	it( 'builds each format once, however many days it names', () => {
		const built = jest.spyOn( Intl, 'DateTimeFormat' );
		try {
			jest.isolateModules( () => {
				const fresh = require( '../format' );
				fresh.dayLabel( '2026-11-06', '2026-11-08' );
				fresh.sinceLabel(
					local( 2026, 9, 1, 9, 0 ),
					local( 2026, 10, 6, 10, 0 )
				);
				const once = built.mock.calls.length;
				fresh.dayLabel( '2026-03-02', '2026-11-08' );
				fresh.dayLabel( '2025-07-19', '2026-11-08' );
				fresh.sinceLabel(
					local( 2026, 8, 1, 9, 0 ),
					local( 2026, 10, 6, 10, 0 )
				);
				expect( built.mock.calls.length ).toBe( once );
			} );
		} finally {
			built.mockRestore();
		}
	} );
} );

describe( 'usualPhrase', () => {
	it.each( [
		[ 0, 'usually under 1' ],
		[ 0.4, 'usually under 1' ],
		[ 1, 'usually about 1' ],
		[ 2.5, 'usually about 3' ],
		[ 6.25, 'usually about 6' ],
	] )( 'says a mean of %s as "%s"', ( mean, words ) => {
		expect( usualPhrase( mean ) ).toBe( words );
	} );
} );
