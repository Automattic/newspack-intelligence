/* eslint-env jest */
/**
 * useReturnFocus hands focus to a control that stays once a dialog closes
 * with nothing focused, as when the button that opened it left the page;
 * focus the dialog handed back to its opener stays where it went.
 */

import { useRef, useState } from '@wordpress/element';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { useReturnFocus } from '../useReturnFocus';

/**
 * A page whose dialog its empty state's button opens, and whose header's
 * button stays.
 *
 * @param {Object}  props
 * @param {boolean} props.keepOpener Whether the empty state outlives the dialog.
 * @return {import('react').ReactElement} The page.
 */
function Page( { keepOpener } ) {
	const [ open, setOpen ] = useState( false );
	const [ filled, setFilled ] = useState( false );
	const stays = useRef( null );
	useReturnFocus( open, stays );
	return (
		<>
			<button type="button" ref={ stays }>
				New heron
			</button>
			{ ( keepOpener || ! filled ) && (
				<button type="button" onClick={ () => setOpen( true ) }>
					Open heron
				</button>
			) }
			{ open && (
				<button
					type="button"
					onClick={ () => {
						setFilled( true );
						setOpen( false );
					} }
				>
					Save heron
				</button>
			) }
		</>
	);
}

/**
 * Open the dialog from its opener and save it.
 */
function openAndSave() {
	const opener = screen.getByRole( 'button', { name: 'Open heron' } );
	act( () => opener.focus() );
	fireEvent.click( opener );
	const save = screen.getByRole( 'button', { name: 'Save heron' } );
	act( () => save.focus() );
	fireEvent.click( save );
}

describe( 'useReturnFocus', () => {
	it( 'focuses the control that stays once the closing leaves nothing focused', () => {
		render( <Page keepOpener={ false } /> );
		openAndSave();
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'New heron' } )
		);
	} );

	it( 'leaves focus that landed on a control still on the page', () => {
		render( <Page keepOpener={ true } /> );
		const opener = screen.getByRole( 'button', { name: 'Open heron' } );
		act( () => opener.focus() );
		fireEvent.click( opener );
		act( () => opener.focus() );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save heron' } ) );
		expect( document.activeElement ).toBe( opener );
	} );
} );
