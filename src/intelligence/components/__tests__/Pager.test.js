/* eslint-env jest */
/**
 * Pager pages over a stack of starts: Next pushes the start past the rows
 * shown, so it offers itself only while that start moves forward. It tallies
 * the rows shown in the plural forms it is given, items by default, the
 * count matching choosing the form.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { _n } from '@wordpress/i18n';
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

	it( 'tallies the rows shown as items by default', () => {
		render(
			<Pager
				starts={ [ 0 ] }
				onStarts={ () => {} }
				shown={ 7 }
				total={ 19 }
			/>
		);
		expect( screen.getByText( '7 of 19 items' ) ).toBeInTheDocument();
	} );

	it( 'tallies the rows shown in the forms it is given, by the count matching', () => {
		const forms = ( total ) =>
			/* translators: 1: leads shown, 2: leads matching. */
			_n(
				'%1$d of %2$d lead',
				'%1$d of %2$d leads',
				total,
				'newspack-intelligence'
			);
		const { rerender } = render(
			<Pager
				starts={ [ 0 ] }
				onStarts={ () => {} }
				shown={ 1 }
				total={ 1 }
				forms={ forms }
			/>
		);
		expect( screen.getByText( '1 of 1 lead' ) ).toBeInTheDocument();
		rerender(
			<Pager
				starts={ [ 0 ] }
				onStarts={ () => {} }
				shown={ 5 }
				total={ 23 }
				forms={ forms }
			/>
		);
		expect( screen.getByText( '5 of 23 leads' ) ).toBeInTheDocument();
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

	it( 'pushes the start past this page on Next and pops it on Previous', () => {
		const onStarts = jest.fn();
		render(
			<Pager
				starts={ [ 0, 37 ] }
				onStarts={ onStarts }
				shown={ 37 }
				total={ 119 }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onStarts ).toHaveBeenLastCalledWith( [ 0, 37, 74 ] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( onStarts ).toHaveBeenLastCalledWith( [ 0 ] );
	} );

	it( 'offers no Previous on the first page', () => {
		render(
			<Pager
				starts={ [ 0 ] }
				onStarts={ () => {} }
				shown={ 37 }
				total={ 119 }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'Previous' } )
		).toBeNull();
	} );
} );
