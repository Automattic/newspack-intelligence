/* eslint-env jest */
/**
 * metaLine joins a row's meta parts, leaving out only the absent ones, so a
 * zero still shows.
 */

import { metaLine } from '../format';

describe( 'metaLine', () => {
	it( 'keeps a zero and drops null, undefined and blank parts', () => {
		expect(
			metaLine( 'mention', null, 0, undefined, '', 'ner_fuzzy' )
		).toBe( 'mention · 0 · ner_fuzzy' );
	} );
} );
