/* eslint-env jest */
/**
 * metaLine joins a row's meta parts, leaving out only the absent ones, so a
 * zero still shows; utcTime renders a Unix time as its UTC day and minute.
 */

import { metaLine, utcTime } from '../format';

describe( 'metaLine', () => {
	it( 'keeps a zero and drops null, undefined and blank parts', () => {
		expect(
			metaLine( 'mention', null, 0, undefined, '', 'ner_fuzzy' )
		).toBe( 'mention · 0 · ner_fuzzy' );
	} );
} );

describe( 'utcTime', () => {
	it( 'renders the UTC day and minute, and nothing for no time', () => {
		expect( utcTime( Date.UTC( 2026, 9, 30, 14, 5, 59 ) / 1000 ) ).toBe(
			'2026-10-30 14:05 UTC'
		);
		expect( utcTime( null ) ).toBe( '' );
		expect( utcTime( 0 ) ).toBe( '' );
	} );
} );
