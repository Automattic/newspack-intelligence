/* eslint-env jest */
/**
 * EndClientDialog asks before a client ends: it names the client, says what
 * ending records, lists the evidence it rests on, and offers the way out.
 * It writes nothing itself; its buttons hand the choice back.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import EndClientDialog from '../EndClientDialog';

const OFFBOARD = {
	item_key: 'linear:harbor-offboard',
	title: 'Harbor Ledger offboarding checklist',
	url: 'https://tracker.example/harbor-offboard',
	source: 'linear',
	day: '2026-11-06',
	role: 'subject',
};

function renderDialog( props = {} ) {
	const handlers = {
		onEnd: jest.fn(),
		onNotAbout: jest.fn(),
		onClose: jest.fn(),
	};
	render(
		<EndClientDialog
			name="Harbor Ledger"
			evidence={ [ OFFBOARD ] }
			{ ...handlers }
			{ ...props }
		/>
	);
	return handlers;
}

const dialog = () =>
	screen.getByRole( 'dialog', { name: 'End Harbor Ledger as a client?' } );

describe( 'EndClientDialog', () => {
	it( 'names the client and says what ending it records', () => {
		renderDialog();
		expect( dialog() ).toHaveTextContent(
			'This ends the client role today and records Harbor Ledger as a former client. Watches and team views will treat it as a former client from now on.'
		);
	} );

	it( 'lists each piece of evidence with its source, day and role, linking its item', () => {
		renderDialog();
		const evidence = within( dialog() ).getByRole( 'list', {
			name: 'Evidence',
		} );
		expect( evidence ).toHaveTextContent(
			'“Harbor Ledger offboarding checklist”, linear, 2026-11-06'
		);
		expect( evidence ).toHaveTextContent( 'Main subject of the item.' );
		expect(
			within( evidence ).getByRole( 'link', { name: 'Open item' } )
		).toHaveAttribute( 'href', OFFBOARD.url );
	} );

	it( 'hands each choice back and writes nothing itself', () => {
		const { onEnd, onNotAbout, onClose } = renderDialog();
		fireEvent.click(
			within( dialog() ).getByRole( 'button', {
				name: 'Not about this client',
			} )
		);
		expect( onNotAbout ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			within( dialog() ).getByRole( 'button', { name: 'End client' } )
		);
		expect( onEnd ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			within( dialog() ).getByRole( 'button', { name: 'Close' } )
		);
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'marks End client destructive', () => {
		renderDialog();
		expect(
			within( dialog() ).getByRole( 'button', { name: 'End client' } )
		).toHaveClass( 'is-danger' );
	} );

	it( 'offers no way out for evidence that is not an item’s link', () => {
		renderDialog( {
			evidence: [
				{
					item_key: null,
					title: 'harborledger.example',
					url: null,
					source: 'Client feed',
					day: '2026-11-01',
					role: null,
				},
			],
			onNotAbout: undefined,
		} );
		expect( dialog() ).toHaveTextContent(
			'“harborledger.example”, Client feed, 2026-11-01'
		);
		expect(
			within( dialog() ).queryByRole( 'button', {
				name: 'Not about this client',
			} )
		).toBeNull();
		expect(
			within( dialog() ).queryByRole( 'link', { name: 'Open item' } )
		).toBeNull();
	} );

	it( 'lists no evidence when it rests on none', () => {
		renderDialog( { evidence: [], onNotAbout: undefined } );
		expect(
			within( dialog() ).queryByRole( 'list', { name: 'Evidence' } )
		).toBeNull();
	} );

	it( 'moves focus into the dialog as it opens', () => {
		renderDialog();
		expect( dialog() ).toContainElement( document.activeElement );
	} );
} );
