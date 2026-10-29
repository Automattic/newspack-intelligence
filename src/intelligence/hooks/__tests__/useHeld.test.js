/* eslint-env jest */
/**
 * useHeld shows a write's effect on a read's rows from the moment it is
 * sent: a row taken out until a read no longer lists it, a row put in until
 * a read lists one it matches, and either undone when its send is refused.
 */

import { render, screen, act } from '@testing-library/react';
import { useHeld } from '../useHeld';

const keyOf = ( row ) => row.key;
const matches = ( listed, held ) => listed.label === held.label;

let held;

function Rows( { read } ) {
	held = useHeld( read, 'rows', keyOf, matches );
	const { answer } = held.read;
	return (
		<p>
			{ answer
				? answer.rows.map( ( row ) => row.label ).join( '|' )
				: 'owed' }
		</p>
	);
}

const answered = ( ...rows ) => ( {
	answer: { rows, available: true },
	error: null,
	loading: false,
} );

const ALPHA = { key: 'alpha', label: 'Alpha' };
const BRAVO = { key: 'bravo', label: 'Bravo' };

describe( 'useHeld', () => {
	it( 'passes a read without its rows through untouched', () => {
		render(
			<Rows read={ { answer: null, error: null, loading: true } } />
		);
		expect( screen.getByText( 'owed' ) ).toBeInTheDocument();
	} );

	it( 'takes a row out until a read no longer lists it', () => {
		const { rerender } = render(
			<Rows read={ answered( ALPHA, BRAVO ) } />
		);
		act( () => held.hold( 'alpha', null, () => [ '--key=alpha' ] ) );
		expect( screen.getByText( 'Bravo' ) ).toBeInTheDocument();
		rerender( <Rows read={ answered( BRAVO ) } /> );
		rerender( <Rows read={ answered( ALPHA, BRAVO ) } /> );
		expect( screen.getByText( 'Alpha|Bravo' ) ).toBeInTheDocument();
	} );

	it( 'puts a row in place of its key until a read lists one it matches', () => {
		const renamed = { key: 'alpha', label: 'Alpha prime' };
		const { rerender } = render(
			<Rows read={ answered( ALPHA, BRAVO ) } />
		);
		act( () => held.hold( 'alpha', renamed, () => [ '--key=alpha' ] ) );
		expect( screen.getByText( 'Alpha prime|Bravo' ) ).toBeInTheDocument();
		rerender( <Rows read={ answered( ALPHA, BRAVO ) } /> );
		expect( screen.getByText( 'Alpha prime|Bravo' ) ).toBeInTheDocument();
		rerender( <Rows read={ answered( renamed, BRAVO ) } /> );
		rerender( <Rows read={ answered( ALPHA, BRAVO ) } /> );
		expect( screen.getByText( 'Alpha|Bravo' ) ).toBeInTheDocument();
	} );

	it( 'puts a row no read lists after the rows', () => {
		const charlie = { key: 'charlie', label: 'Charlie' };
		render( <Rows read={ answered( ALPHA ) } /> );
		act( () => held.hold( 'charlie', charlie, () => [ '--key=charlie' ] ) );
		expect( screen.getByText( 'Alpha|Charlie' ) ).toBeInTheDocument();
	} );

	it( 'undoes the hold whose send was refused, and every hold for a bare refusal', () => {
		render( <Rows read={ answered( ALPHA, BRAVO ) } /> );
		act( () => {
			held.hold( 'alpha', null, () => [ '--key=alpha' ] );
			held.hold( 'bravo', null, () => [ '--key=bravo' ] );
		} );
		expect( screen.queryByText( /Alpha|Bravo/ ) ).toBeNull();
		act( () => held.refused( [ '--key=bravo' ] ) );
		expect( screen.getByText( 'Bravo' ) ).toBeInTheDocument();
		act( () => held.hold( 'bravo', null, () => [ '--key=bravo' ] ) );
		act( () => held.refused( [] ) );
		expect( screen.getByText( 'Alpha|Bravo' ) ).toBeInTheDocument();
	} );
} );
