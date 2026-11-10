/* eslint-env jest */
/**
 * WatchRow is one watch as the Briefing lists it: a plain button named by
 * the watch alone, which opens it, then, when asked, its new count; a watch
 * whose inbox is off says it is not listed here; one still saving shows its
 * name as saving, with no button.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import WatchRow from '../WatchRow';

/**
 * A watch as `watches` answers it, every value distinct from a default.
 *
 * @param {Object} over What the case changes.
 * @return {Object} The watch.
 */
const watch = ( over ) => ( {
	watch_key: '4e7b19c0a3d25f68',
	name: 'Harbor Ledger desk',
	target: { kind: 'entity', key: 'organization:harbor-ledger' },
	digest: true,
	instant: false,
	inbox: true,
	unseen: 37,
	...over,
} );

/**
 * The row inside the list it belongs to.
 *
 * @param {Object} props The row's props.
 * @return {import('@testing-library/react').RenderResult} The render.
 */
const row = ( props ) =>
	render(
		<ul>
			<WatchRow { ...props } />
		</ul>
	);

describe( 'WatchRow', () => {
	it( 'names its button by the watch alone, its new count after it', () => {
		const onOpen = jest.fn();
		row( { watch: watch(), count: true, onOpen } );

		const button = screen.getByRole( 'button', {
			name: 'Harbor Ledger desk',
		} );
		expect( screen.getByRole( 'listitem' ) ).toHaveTextContent(
			'Harbor Ledger desk37 new'
		);
		expect( button ).not.toHaveTextContent( '37 new' );

		fireEvent.click( button );
		expect( onOpen ).toHaveBeenCalledWith( '4e7b19c0a3d25f68' );
	} );

	it( 'shows no count unless asked', () => {
		row( { watch: watch(), onOpen: jest.fn() } );
		expect( screen.queryByText( '37 new' ) ).toBeNull();
	} );

	it( 'says a watch whose inbox is off is not listed here', () => {
		row( {
			watch: watch( { inbox: false, unseen: 0 } ),
			count: true,
			onOpen: jest.fn(),
		} );
		expect(
			screen.getByText( 'Not listed here; email only' )
		).toBeInTheDocument();
	} );

	it( 'marks the watch opened as current', () => {
		row( { watch: watch(), active: true, onOpen: jest.fn() } );
		expect(
			screen.getByRole( 'button', { name: 'Harbor Ledger desk' } )
		).toHaveAttribute( 'aria-current', 'true' );
	} );

	it( 'shows a watch still saving by its name, with no button', () => {
		row( {
			watch: watch( { watch_key: '' } ),
			count: true,
			onOpen: jest.fn(),
		} );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( screen.getByRole( 'listitem' ) ).toHaveTextContent(
			'Harbor Ledger deskSaving…'
		);
	} );
} );
