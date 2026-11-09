/* eslint-env jest */
/**
 * metaLine joins a row's meta parts, leaving out only the absent ones, so a
 * zero still shows. sinceLabel names an instant by the reader's calendar, in
 * the zone the suite pins (Pacific/Auckland), whatever the page's clock.
 */

import { metaLine, sinceLabel } from '../format';

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
