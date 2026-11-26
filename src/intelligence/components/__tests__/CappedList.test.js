/* eslint-env jest */
/**
 * A capped list shows its first `limit` rows under the class it is given,
 * else the facts list's, and offers "Show %d more" for the rest it holds. A
 * caller may hold whether all are shown.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import CappedList from '../CappedList';

const ROWS = [ 'kestrel', 'heron', 'plover', 'avocet', 'curlew', 'dunlin' ];

const list = ( props ) =>
	render(
		<CappedList rows={ ROWS } limit={ 4 } { ...props }>
			{ ( row ) => <li key={ row }>{ row }</li> }
		</CappedList>
	);

describe( 'CappedList', () => {
	it( 'lists its rows under the class it is given', () => {
		list( { className: 'shorebird-roll' } );
		expect( screen.getByRole( 'list' ) ).toHaveClass( 'shorebird-roll' );
		expect( screen.getByRole( 'list' ) ).not.toHaveClass(
			'newspack-intelligence__facts'
		);
	} );

	it( 'lists them under the facts list’s class when given none', () => {
		list();
		expect( screen.getByRole( 'list' ) ).toHaveClass(
			'newspack-intelligence__facts'
		);
	} );

	it( 'shows its limit, then the rest the list holds on "Show %d more"', () => {
		list();
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 4 );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 2 more' } )
		);
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 6 );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'leaves to its caller whether it shows them all, when told', () => {
		const onAll = jest.fn();
		const { unmount } = list( { all: false, onAll } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 2 more' } )
		);
		expect( onAll ).toHaveBeenCalledTimes( 1 );
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 4 );
		unmount();
		list( { all: true, onAll } );
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 6 );
	} );
} );
