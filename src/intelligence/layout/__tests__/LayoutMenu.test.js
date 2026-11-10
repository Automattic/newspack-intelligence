/* eslint-env jest */
/**
 * **Edit layout** is a menu entry that reloads the page naming the edit,
 * since only such a load brings the block editor; `editSearch()`, which
 * editMode's tests hold, spells the address.
 */

import { editLayoutControl } from '../LayoutMenu';

describe( 'editLayoutControl', () => {
	it( 'is titled Edit layout and navigates when chosen', () => {
		const entry = editLayoutControl( {
			page: 'lens',
			lens_key: 'prairie-desk',
		} );
		expect( entry.title ).toBe( 'Edit layout' );
		const error = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			entry.onClick();
			// jsdom refuses the navigation with a logged "Not implemented".
			expect(
				error.mock.calls.some( ( [ e ] ) =>
					/navigation/.test( String( e ) )
				)
			).toBe( true );
		} finally {
			error.mockRestore();
		}
	} );
} );
