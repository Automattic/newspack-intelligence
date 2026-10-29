/* eslint-env jest */
/**
 * daysSince() names the first UTC day of a lens's rolling window, the last
 * `days` days ending today, as the server's `Graph_Query::last_days()` does.
 */

import { daysSince } from '../lensDraft';

const LATE_ON_OCT_29 = Date.UTC( 2026, 9, 29, 23, 40 );

describe( 'daysSince', () => {
	it( 'starts the window days minus one before today', () => {
		expect( daysSince( 21, LATE_ON_OCT_29 ) ).toBe( '2026-10-09' );
	} );

	it( 'starts a one-day window today', () => {
		expect( daysSince( 1, LATE_ON_OCT_29 ) ).toBe( '2026-10-29' );
	} );
} );
