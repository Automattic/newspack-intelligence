/* eslint-env jest */
/**
 * ItemRow renders one item from the graph: its title as a link to its own
 * URL when that URL is http(s), every string as text, each linked entity as a
 * chip opening it, and the save control only for a caller who may curate.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import ItemRow, { ItemPage } from '../ItemRow';

const ITEM = {
	item_key: 'feed:inkfold-review-17',
	title: 'Acme Quillsheet wins <b>award</b>',
	url: 'https://inkfold-review.example/acme',
	source: 'feed',
	tier: 'public',
	item_ts: 1790035200,
	summary: 'The Acme Quillsheet took the regional prize.',
	kind: 'award',
	entities: [
		{
			entity_key: 'organization:acme-quillsheet',
			name: 'Acme Quillsheet',
			state: 'asserted',
		},
	],
};

describe( 'ItemRow', () => {
	it( 'links the title to the item URL and renders it as text', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const link = screen.getByRole( 'link', {
			name: 'Acme Quillsheet wins <b>award</b>',
		} );
		expect( link ).toHaveAttribute( 'href', ITEM.url );
		expect( link.querySelector( 'b' ) ).toBeNull();
	} );

	it( 'renders the source, kind and UTC day', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		expect(
			screen.getByText( /feed · award · 2026-09-22/ )
		).toBeInTheDocument();
	} );

	it( 'never links a URL that is not http or https', () => {
		render(
			<ItemRow
				item={ { ...ITEM, url: 'javascript:alert(1)' } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( screen.queryByRole( 'link' ) ).toBeNull();
		expect(
			screen.getByText( 'Acme Quillsheet wins <b>award</b>' )
		).toBeInTheDocument();
	} );

	it( 'opens a linked entity from its chip', () => {
		const onOpenEntity = jest.fn();
		render( <ItemRow item={ ITEM } onOpenEntity={ onOpenEntity } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-quillsheet'
		);
	} );

	it( 'offers save to collection only when given a save handler', () => {
		const onSave = jest.fn();
		const { rerender } = render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() } />
		);
		expect(
			screen.queryByRole( 'button', { name: /save to collection/i } )
		).toBeNull();
		rerender(
			<ItemRow
				item={ ITEM }
				onOpenEntity={ jest.fn() }
				onSave={ onSave }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /save to collection/i } )
		);
		expect( onSave ).toHaveBeenCalledWith( ITEM );
	} );

	it( 'heads the row with the controls it is given', () => {
		render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() }>
				<button type="button">Mark seen</button>
			</ItemRow>
		);
		expect(
			screen
				.getByRole( 'button', { name: 'Mark seen' } )
				.closest( '.newspack-intelligence__item-head' )
		).not.toBeNull();
	} );
} );

describe( 'ItemPage', () => {
	const page = ( sort, onSort ) =>
		render(
			<ItemPage
				answer={ { items: [ ITEM ], total: 1, sort } }
				starts={ [ 0 ] }
				onStarts={ jest.fn() }
				onOpenEntity={ jest.fn() }
				onSort={ onSort }
			/>
		);

	it( 'says a page is ranked by importance and switches it to newest first', () => {
		const onSort = jest.fn();
		page( 'importance', onSort );
		expect(
			screen.getByText( 'Ranked by importance' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show newest first' } )
		);
		expect( onSort ).toHaveBeenCalledWith( 'time' );
	} );

	it( 'says a page is newest first and switches it to importance', () => {
		const onSort = jest.fn();
		page( 'time', onSort );
		expect( screen.getByText( 'Newest first' ) ).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rank by importance' } )
		);
		expect( onSort ).toHaveBeenCalledWith( 'importance' );
	} );

	it( 'offers no switch to a page that does not sort', () => {
		page( undefined, undefined );
		expect( screen.queryByText( 'Ranked by importance' ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Show newest first' } )
		).toBeNull();
	} );
} );
