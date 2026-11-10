/* eslint-env jest */
/**
 * FolderCard is one saved item on the Folders board: its title citing its
 * URL, the day it was saved, each use mark a badge naming the place and the
 * day, and for a curator a card that drags and a menu that marks it used
 * through a dialog, moves it into another folder or removes it.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import FolderCard from '../FolderCard';
import { pageData } from '../../views/__tests__/fixtures';

const YEAR = new Date().getUTCFullYear();

const ITEM = {
	item_key: 'feed:inkfold-review-17',
	url: 'https://inkfold-review.example/acme',
	title: 'Coastline Weekly wins regional press award',
	actor: 'user:editor',
	saved_ts: Date.UTC( YEAR, 1, 3 ) / 1000,
	marks: [],
};

const USED = {
	...ITEM,
	marks: [
		{ mark: 'newsletter', day: `${ YEAR }-11-05` },
		{ mark: 'site', day: `${ YEAR }-03-14` },
	],
};

const OTHERS = [
	{ collection_key: 'prairie-picks', name: 'Prairie picks' },
	{ collection_key: 'harbor-ledger-notes', name: 'Harbor Ledger notes' },
];

/**
 * The reader's own day, Y-m-d.
 *
 * @return {string} Today.
 */
function localToday() {
	const now = new Date();
	return [
		now.getFullYear(),
		String( now.getMonth() + 1 ).padStart( 2, '0' ),
		String( now.getDate() ).padStart( 2, '0' ),
	].join( '-' );
}

let calls;

beforeEach( () => {
	window.NewspackNodesData = pageData();
	calls = {
		onPickUp: jest.fn(),
		onMove: jest.fn(),
		onMark: jest.fn(),
		onRemove: jest.fn(),
	};
} );

const renderCard = ( props = {} ) =>
	render(
		<FolderCard
			item={ ITEM }
			others={ OTHERS }
			canTune
			focused={ false }
			refusal={ null }
			{ ...calls }
			{ ...props }
		/>
	);

const openMenu = () =>
	fireEvent.click(
		screen.getByRole( 'button', {
			name: `Actions for ${ ITEM.title }`,
		} )
	);

describe( 'FolderCard', () => {
	it( 'cites its item and the day it was saved, not yet used', () => {
		renderCard();
		expect(
			screen.getByRole( 'link', { name: ITEM.title } )
		).toHaveAttribute( 'href', ITEM.url );
		expect( screen.getByText( 'Saved 3 Feb' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Not yet used' ) ).toBeInTheDocument();
	} );

	it( 'shows each use as a badge naming the place in its word and the day', () => {
		renderCard( { item: USED } );
		expect(
			[
				...document.querySelectorAll( '.newspack-nodes-status-badge' ),
			].map( ( badge ) => badge.textContent )
		).toEqual( [ 'Digest, 5 Nov', 'Homepage, 14 Mar' ] );
		expect( screen.queryByText( 'Not yet used' ) ).toBeNull();
	} );

	it( 'moves into the folder its menu names, in one action', () => {
		renderCard();
		openMenu();
		const move = screen.getByRole( 'group', { name: 'Move to' } );
		expect(
			within( move )
				.getAllByRole( 'menuitem' )
				.map( ( one ) => one.textContent )
		).toEqual( [ 'Prairie picks', 'Harbor Ledger notes' ] );
		fireEvent.click(
			within( move ).getByRole( 'menuitem', {
				name: 'Harbor Ledger notes',
			} )
		);
		expect( calls.onMove ).toHaveBeenCalledWith( 'harbor-ledger-notes' );
	} );

	it( 'opens the mark dialog on the place picked, today by default', () => {
		renderCard();
		openMenu();
		fireEvent.click(
			within(
				screen.getByRole( 'group', { name: 'Mark as used in' } )
			).getByRole( 'menuitem', { name: 'LinkedIn post' } )
		);
		const dialog = screen.getByRole( 'dialog', { name: 'Mark as used' } );
		expect(
			within( dialog ).getByRole( 'radio', { name: 'LinkedIn post' } )
		).toBeChecked();
		expect( within( dialog ).getByLabelText( 'On' ) ).toHaveValue(
			localToday()
		);
		fireEvent.click(
			within( dialog ).getByRole( 'radio', { name: 'Homepage' } )
		);
		fireEvent.change( within( dialog ).getByLabelText( 'On' ), {
			target: { value: `${ YEAR }-10-23` },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Mark as used' } )
		);
		expect( calls.onMark ).toHaveBeenCalledWith(
			'site',
			`${ YEAR }-10-23`
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'closes the mark dialog on Cancel, marking nothing', () => {
		renderCard();
		openMenu();
		fireEvent.click( screen.getByRole( 'menuitem', { name: 'Digest' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( calls.onMark ).not.toHaveBeenCalled();
	} );

	it( 'removes itself from the folder from its menu', () => {
		renderCard();
		openMenu();
		fireEvent.click(
			screen.getByRole( 'menuitem', { name: 'Remove from folder' } )
		);
		expect( calls.onRemove ).toHaveBeenCalled();
	} );

	it( 'opens its source from its menu', async () => {
		renderCard();
		openMenu();
		expect(
			await screen.findByRole( 'menuitem', { name: 'Open source' } )
		).toHaveAttribute( 'href', ITEM.url );
	} );

	it( 'is picked up when dragged', () => {
		renderCard();
		const card = screen.getByRole( 'article', { name: ITEM.title } );
		expect( card ).toHaveAttribute( 'draggable', 'true' );
		fireEvent.dragStart( card, {
			dataTransfer: { setData: jest.fn(), effectAllowed: '' },
		} );
		expect( calls.onPickUp ).toHaveBeenCalled();
	} );

	it( 'takes focus when it is the card a move landed', () => {
		const { rerender } = renderCard();
		const card = screen.getByRole( 'article', { name: ITEM.title } );
		expect( card ).not.toHaveFocus();
		rerender(
			<FolderCard
				item={ ITEM }
				others={ OTHERS }
				canTune
				focused
				refusal={ null }
				{ ...calls }
			/>
		);
		expect( card ).toHaveFocus();
	} );

	it( 'shows the refusal of a write on it', () => {
		renderCard( { refusal: 'feed:inkfold-review-17 is not saved here' } );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'feed:inkfold-review-17 is not saved here'
		);
	} );

	it( 'offers a reader no menu and does not drag', () => {
		renderCard( { canTune: false } );
		expect(
			screen.queryByRole( 'button', {
				name: `Actions for ${ ITEM.title }`,
			} )
		).toBeNull();
		expect(
			screen.getByRole( 'article', { name: ITEM.title } )
		).not.toHaveAttribute( 'draggable', 'true' );
	} );
} );
