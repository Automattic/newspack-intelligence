/* eslint-env jest */
/**
 * Pager pages over a stack of starts: Next pushes the start past the rows
 * shown, so it offers itself only while that start moves forward.
 */

import { render, screen } from '@testing-library/react';
import Pager from '../Pager';

describe( 'Pager', () => {
	it( 'offers Next while rows past this page match', () => {
		render(
			<Pager
				starts={ [ 0, 37 ] }
				onStarts={ () => {} }
				shown={ 37 }
				total={ 119 }
			/>
		);
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeTruthy();
	} );

	it( 'offers no Next when a page shows no rows', () => {
		render(
			<Pager
				starts={ [ 0, 37 ] }
				onStarts={ () => {} }
				shown={ 0 }
				total={ 119 }
			/>
		);
		expect( screen.queryByRole( 'button', { name: 'Next' } ) ).toBeNull();
	} );
} );
