/* eslint-env jest */
/**
 * ListKeys is the one single-key implementation every list uses: j and k
 * move between its rows, a letter presses the focused row's control whose
 * `aria-keyshortcuts` names it, z undoes, and ? shows the legend in a
 * popover until Escape. Within the list alone, or from anywhere on the page
 * given `page`; never in a field, a dialog or with a modifier. A click on a
 * control that takes its row out moves focus to the row in its place, keys
 * or none. One switch, remembered per browser, turns the keys off.
 */

import { act, fireEvent, render, screen } from '@testing-library/react';
import { useRef, useState } from '@wordpress/element';
import ListKeys, { LEAVES } from '../ListKeys';

const LEGEND = 'Keys: J and K to move, A archive, Z undo.';

/**
 * Three rows, each with a control the key `a` presses, which takes the row
 * out of the list.
 *
 * @param {Object}  props
 * @param {boolean} [props.page]   Whether the keys work from anywhere.
 * @param {?string} [props.legend] The legend, or null for a list taking none.
 * @param {Object}  props.onUndo   Undoes.
 * @param {boolean} [props.hidden] Whether the list sits in a hidden view.
 * @return {import('react').ReactElement} The harness.
 */
function Harness( { page = false, legend = LEGEND, onUndo, hidden = false } ) {
	const heading = useRef( null );
	const [ rows, setRows ] = useState( [
		'Harbor Ledger',
		'Prairie Signal',
		'Coastline Weekly',
	] );
	return (
		<>
			<button type="button">Outside</button>
			<input aria-label="Notes" />
			<div role="dialog" aria-label="Pick a folder">
				<button type="button">Inside a dialog</button>
			</div>
			<div hidden={ hidden }>
				<h3 ref={ heading } tabIndex={ -1 }>
					Desk heading
				</h3>
				<ListKeys
					rows="[data-row]"
					onUndo={ onUndo }
					legend={ legend }
					page={ page }
					heading={ () => heading.current }
				>
					{ ( describedBy ) => (
						<ul aria-label="Desk" aria-describedby={ describedBy }>
							{ rows.map( ( name ) => (
								<li key={ name } data-row="" tabIndex={ -1 }>
									{ name }
									<button
										type="button"
										aria-keyshortcuts="a"
										{ ...LEAVES }
										onClick={ () =>
											setRows( ( was ) =>
												was.filter(
													( one ) => one !== name
												)
											)
										}
									>
										{ `Archive ${ name }` }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</ListKeys>
			</div>
		</>
	);
}

const row = ( name ) => screen.getByText( name ).closest( '[data-row]' );
const key = ( target, k, more = {} ) =>
	fireEvent.keyDown( target, { key: k, ...more } );

beforeEach( () => {
	window.localStorage.clear();
} );

describe( 'ListKeys', () => {
	it( 'describes the list by its legend', () => {
		render( <Harness onUndo={ jest.fn() } /> );
		expect(
			screen.getByRole( 'list', { name: 'Desk' } )
		).toHaveAccessibleDescription( LEGEND );
	} );

	it( 'presses the focused row’s control a letter names, and focuses the row in its place', async () => {
		render( <Harness onUndo={ jest.fn() } /> );
		row( 'Prairie Signal' ).focus();
		await act( async () => key( document.activeElement, 'a' ) );
		expect( screen.queryByText( 'Prairie Signal' ) ).toBeNull();
		expect( document.activeElement ).toBe( row( 'Coastline Weekly' ) );
	} );

	it( 'focuses the row above once the last is taken out by a click', async () => {
		render( <Harness onUndo={ jest.fn() } legend={ null } /> );
		await act( async () =>
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Archive Coastline Weekly',
				} )
			)
		);
		expect( document.activeElement ).toBe( row( 'Prairie Signal' ) );
	} );

	it( 'focuses the list’s heading once its last row is taken out', async () => {
		render( <Harness onUndo={ jest.fn() } legend={ null } /> );
		for ( const name of [
			'Harbor Ledger',
			'Prairie Signal',
			'Coastline Weekly',
		] ) {
			await act( async () =>
				fireEvent.click(
					screen.getByRole( 'button', { name: `Archive ${ name }` } )
				)
			);
		}
		expect( document.activeElement ).toBe(
			screen.getByRole( 'heading', { name: 'Desk heading' } )
		);
	} );

	it( 'takes no key from the page while its view is hidden', () => {
		const onUndo = jest.fn();
		const { rerender } = render(
			<Harness onUndo={ onUndo } page hidden />
		);
		key( document.body, 'z' );
		key( document.body, '?' );
		key( document.body, 'j' );
		expect( onUndo ).not.toHaveBeenCalled();
		expect( document.querySelector( '.components-popover' ) ).toBeNull();
		expect( document.activeElement ).toBe( document.body );

		rerender( <Harness onUndo={ onUndo } page /> );
		key( document.body, 'z' );
		expect( onUndo ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'takes keys only within the list, unless it takes them from the page', () => {
		const onUndo = jest.fn();
		const { unmount } = render( <Harness onUndo={ onUndo } /> );
		const outside = screen.getByRole( 'button', { name: 'Outside' } );
		outside.focus();
		key( outside, 'j' );
		key( outside, 'z' );
		expect( document.activeElement ).toBe( outside );
		expect( onUndo ).not.toHaveBeenCalled();
		unmount();

		render( <Harness onUndo={ onUndo } page /> );
		const again = screen.getByRole( 'button', { name: 'Outside' } );
		again.focus();
		key( again, 'k' );
		expect( document.activeElement ).toBe( row( 'Harbor Ledger' ) );
		key( document.activeElement, 'j' );
		expect( document.activeElement ).toBe( row( 'Prairie Signal' ) );
		key( document.body, 'z' );
		expect( onUndo ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves a key alone in a field, in a dialog and with a modifier', () => {
		const onUndo = jest.fn();
		render( <Harness onUndo={ onUndo } page /> );
		for ( const target of [
			screen.getByRole( 'textbox', { name: 'Notes' } ),
			screen.getByRole( 'button', { name: 'Inside a dialog' } ),
		] ) {
			target.focus();
			key( target, 'j' );
			key( target, 'z' );
			expect( document.activeElement ).toBe( target );
		}
		row( 'Harbor Ledger' ).focus();
		key( document.activeElement, 'j', { ctrlKey: true } );
		key( document.activeElement, 'a', { metaKey: true } );
		expect( document.activeElement ).toBe( row( 'Harbor Ledger' ) );
		expect( screen.getByText( 'Harbor Ledger' ) ).toBeInTheDocument();
		expect( onUndo ).not.toHaveBeenCalled();
	} );

	it( 'shows the legend in a popover on ?, and hides it on Escape', () => {
		render( <Harness onUndo={ jest.fn() } /> );
		row( 'Harbor Ledger' ).focus();
		expect( screen.getAllByText( LEGEND ) ).toHaveLength( 1 );
		key( document.activeElement, '?' );
		const shown = screen.getAllByText( LEGEND );
		expect( shown ).toHaveLength( 2 );
		expect( shown[ 1 ].closest( '.components-popover' ) ).not.toBeNull();
		expect( document.activeElement ).toBe( row( 'Harbor Ledger' ) );
		key( document.activeElement, 'Escape' );
		expect( screen.getAllByText( LEGEND ) ).toHaveLength( 1 );
	} );

	it( 'takes no key and shows no legend or switch for a list naming no legend', () => {
		const onUndo = jest.fn();
		render( <Harness onUndo={ onUndo } legend={ null } page /> );
		expect(
			screen.queryByRole( 'checkbox', {
				name: 'Turn off single-key shortcuts',
			} )
		).toBeNull();
		key( document.body, 'j' );
		key( document.body, 'z' );
		expect( document.activeElement ).toBe( document.body );
		expect( onUndo ).not.toHaveBeenCalled();
	} );
} );
