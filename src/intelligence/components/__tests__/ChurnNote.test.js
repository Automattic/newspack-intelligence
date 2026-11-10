/* eslint-env jest */
/**
 * A churn-risk note reads as one line: the risk, then its owner and its
 * next step, each only when given, a step's due day beside it or alone,
 * every part ending in one full stop; **Edit note** shows only where the
 * reader may edit it.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import ChurnNote, { noteText } from '../ChurnNote';

const NOTE = {
	text: 'Board weighing a cheaper host...',
	owner: 'Ines Okafor',
	next_step: 'pricing review.',
	day: '2026-12-03',
};

describe( 'noteText', () => {
	it( 'names the risk, its owner and the step due on its day', () => {
		expect( noteText( NOTE ) ).toBe(
			'Churn risk: Board weighing a cheaper host. Owner: Ines Okafor. Next: pricing review, by 3 Dec.'
		);
	} );

	it( 'names a step given no day without one', () => {
		expect( noteText( { ...NOTE, owner: '', day: '' } ) ).toBe(
			'Churn risk: Board weighing a cheaper host. Next: pricing review.'
		);
	} );

	it( 'names the day alone when no step is given', () => {
		expect( noteText( { ...NOTE, next_step: '' } ) ).toBe(
			'Churn risk: Board weighing a cheaper host. Owner: Ines Okafor. By 3 Dec.'
		);
	} );

	it( 'names the risk alone when nothing else is given', () => {
		expect(
			noteText( { ...NOTE, owner: '', next_step: '', day: '' } )
		).toBe( 'Churn risk: Board weighing a cheaper host.' );
	} );
} );

describe( 'ChurnNote', () => {
	it( 'offers Edit note to a reader who may edit it, and none to another', () => {
		const onEdit = jest.fn();
		const { rerender } = render(
			<ChurnNote note={ NOTE } onEdit={ onEdit } />
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Edit note' } ) );
		expect( onEdit ).toHaveBeenCalledTimes( 1 );

		rerender( <ChurnNote note={ NOTE } onEdit={ null } /> );
		expect( screen.getByText( noteText( NOTE ) ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );
} );
