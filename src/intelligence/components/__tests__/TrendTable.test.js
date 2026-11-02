/* eslint-env jest */
/**
 * A signal's value names its axis and kind in the page's own language, then
 * the value it counts, when it counts one.
 */

import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import { signalValue } from '../TrendTable';

afterEach( () => resetLocaleData( undefined, 'newspack-intelligence' ) );

describe( 'signalValue', () => {
	it( 'translates the axis and the kind', () => {
		setLocaleData(
			{
				'': { domain: 'newspack-intelligence' },
				publisher: [ 'éditeur' ],
				drop: [ 'baisse' ],
				about: [ 'sujet' ],
				burst: [ 'rafale' ],
			},
			'newspack-intelligence'
		);
		expect(
			signalValue( { axis: 'publisher', signal: 'drop', value: null } )
		).toBe( 'éditeur baisse' );
		expect(
			signalValue( {
				axis: 'about',
				signal: 'burst',
				value: 'partnership',
			} )
		).toBe( 'sujet rafale: partnership' );
	} );

	it( 'names an axis or a kind it does not know as the server spelled it', () => {
		expect(
			signalValue( { axis: 'harbor', signal: 'swell', value: null } )
		).toBe( 'harbor swell' );
	} );
} );
