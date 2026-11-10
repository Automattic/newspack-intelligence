/* eslint-env jest */
/**
 * Sheet is a right-hand drawer, or a bottom sheet when the page is narrow,
 * named by its heading: opening it moves focus to its first field, else to
 * that heading, Escape
 * closes it, and closing it returns focus to whatever opened it.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { useState } from '@wordpress/element';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import Sheet from '../Sheet';

let viewport;

afterEach( () => {
	viewport.restore();
} );

/**
 * An opener and the sheet it opens.
 *
 * @return {import('react').ReactElement} The harness.
 */
function Opener() {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<button type="button" onClick={ () => setOpen( true ) }>
				Pick filters
			</button>
			{ open && (
				<Sheet
					title="Desk filters"
					closeLabel="Shut"
					onClose={ () => setOpen( false ) }
				>
					<button type="button">Show 41 items</button>
				</Sheet>
			) }
		</>
	);
}

describe( 'Sheet', () => {
	it( 'opens beside the page, named by its heading, which takes focus', () => {
		viewport = installViewport( false );
		render( <Opener /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Pick filters' } )
		);

		const sheet = screen.getByRole( 'dialog', { name: 'Desk filters' } );
		expect( sheet ).toHaveClass(
			'newspack-intelligence__sheet',
			'is-side'
		);
		expect(
			screen.getByRole( 'heading', { name: 'Desk filters' } )
		).toHaveFocus();
	} );

	it( 'moves focus to its first field over its heading', () => {
		viewport = installViewport( false );
		render(
			<Sheet title="Desk filters" closeLabel="Shut" onClose={ jest.fn() }>
				<button type="button">Show 41 items</button>
				<select aria-label="Desk">
					<option>Metro</option>
				</select>
				<input aria-label="Words" />
			</Sheet>
		);
		expect(
			screen.getByRole( 'combobox', { name: 'Desk' } )
		).toHaveFocus();
	} );

	it( 'rises from the bottom when the page is narrow', () => {
		viewport = installViewport( true );
		render( <Opener /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Pick filters' } )
		);

		expect( screen.getByRole( 'dialog' ) ).toHaveClass( 'is-bottom' );
		expect( screen.getByRole( 'dialog' ) ).not.toHaveClass( 'is-side' );
	} );

	it( 'closes on Escape and on its close button, handing focus back to its opener', () => {
		viewport = installViewport( true );
		render( <Opener /> );
		const opener = screen.getByRole( 'button', { name: 'Pick filters' } );

		opener.focus();
		fireEvent.click( opener );
		fireEvent.keyDown( document, { key: 'Escape' } );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( opener ).toHaveFocus();

		fireEvent.click( opener );
		fireEvent.click( screen.getByRole( 'button', { name: 'Shut' } ) );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( opener ).toHaveFocus();
	} );
} );
