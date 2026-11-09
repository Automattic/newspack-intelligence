/* eslint-env jest */
/**
 * UndoSnackbar shows one message at the foot of the page: a pending write's,
 * with the Undo it offers, until it is sent, undone or dismissed, or a
 * settled one with no Undo.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import UndoSnackbar from '../UndoSnackbar';

const snackbar = () => document.querySelector( '.components-snackbar' );

describe( 'UndoSnackbar', () => {
	it( 'shows a pending write’s message with its Undo', () => {
		const onUndo = jest.fn();
		render(
			<UndoSnackbar
				latest={ { id: 7, message: 'Rejected Plover Quillpost.' } }
				onUndo={ onUndo }
			/>
		);
		expect( snackbar() ).toHaveTextContent( 'Rejected Plover Quillpost.' );
		fireEvent.click(
			within( snackbar() ).getByRole( 'button', { name: 'Undo' } )
		);
		expect( onUndo ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows a settled message with no Undo', () => {
		render(
			<UndoSnackbar
				latest={ {
					id: 'ended',
					message: 'Plover Quillpost is now a former client.',
				} }
			/>
		);
		expect( snackbar() ).toHaveTextContent(
			'Plover Quillpost is now a former client.'
		);
		expect(
			within( snackbar() ).queryByRole( 'button', { name: 'Undo' } )
		).toBeNull();
	} );

	it( 'shows nothing with nothing to say', () => {
		render( <UndoSnackbar latest={ null } onUndo={ jest.fn() } /> );
		expect( snackbar() ).toBeNull();
	} );

	it( 'stays dismissed until another message arrives', () => {
		const { rerender } = render(
			<UndoSnackbar
				latest={ { id: 3, message: 'Confirmed Sedgewick Inkfold.' } }
				onUndo={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Dismiss this notice' ) );
		expect( snackbar() ).toBeNull();
		rerender(
			<UndoSnackbar
				latest={ { id: 4, message: 'Rejected Sedgewick Inkfold.' } }
				onUndo={ jest.fn() }
			/>
		);
		expect( snackbar() ).toHaveTextContent( 'Rejected Sedgewick Inkfold.' );
	} );
} );
