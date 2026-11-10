/* eslint-env jest */
/**
 * ItemList is the one DataViews list every item list draws: each item by its
 * row component, ItemRow unless the caller names its own, in the layout the
 * view names; the caller's empty words in place of DataViews' own; and a
 * pager reading "Page %1$d of %2$d" only when there is a second page.
 */

import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from '@wordpress/element';
import ItemList from '../ItemList';
import { ITEM, pageData } from '../../views/__tests__/fixtures';

const SECOND = {
	...ITEM,
	item_key: 'feed:prairie-signal-4',
	title: 'Prairie Signal opens a second bureau',
};

const view = ( over = {} ) => ( {
	type: 'list',
	page: 3,
	perPage: 7,
	...over,
} );

/**
 * A row naming its item, so a test reads which row component drew it.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @param {string} props.tone A row prop the caller passed through.
 * @return {import('react').ReactElement} The row.
 */
const Plain = ( { item, tone } ) => (
	<span>{ `${ tone }: ${ item.title }` }</span>
);

describe( 'ItemList', () => {
	beforeEach( () => {
		window.NewspackNodesData = pageData();
	} );

	it( 'draws each item through the row the caller names, with its row props', async () => {
		render(
			<ItemList
				items={ [ ITEM, SECOND ] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 2, totalPages: 1 } }
				render={ Plain }
				rowProps={ { tone: 'Quiet' } }
			/>
		);
		expect(
			await screen.findByText(
				'Quiet: Acme Quillsheet wins regional prize'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Quiet: Prairie Signal opens a second bureau' )
		).toBeInTheDocument();
	} );

	it( 'draws ItemRow when the caller names no row', async () => {
		render(
			<ItemList
				items={ [ ITEM ] }
				view={ view( { type: 'activity' } ) }
				layout="activity"
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				rowProps={ { onOpenEntity: () => {} } }
			/>
		);
		expect(
			await screen.findByRole( 'link', {
				name: /Acme Quillsheet wins regional prize/,
			} )
		).toHaveAttribute( 'href', ITEM.url );
	} );

	it( 'an empty list shows its own words and no pager', async () => {
		render(
			<ItemList
				items={ [] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 0, totalPages: 0 } }
				render={ Plain }
			/>
		);
		expect(
			await screen.findByText( 'Nothing filed here.' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'No results' ) ).toBeNull();
		expect( screen.queryByText( /Page/ ) ).toBeNull();
	} );

	it( 'shows no pager at one page', async () => {
		render(
			<ItemList
				items={ [ ITEM ] }
				view={ view( { page: 1 } ) }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Plain }
			/>
		);
		await screen.findByText( /Acme Quillsheet wins regional prize/ );
		expect( screen.queryByText( /Page/ ) ).toBeNull();
	} );

	it( 'pages through the view, reading its page of the total', async () => {
		const onChangeView = jest.fn();
		render(
			<ItemList
				items={ [ ITEM, SECOND ] }
				view={ view() }
				onChangeView={ onChangeView }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 33, totalPages: 5 } }
				render={ Plain }
			/>
		);
		expect( await screen.findByText( 'Page 3 of 5' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onChangeView ).toHaveBeenLastCalledWith(
			expect.objectContaining( { page: 4, perPage: 7 } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( onChangeView ).toHaveBeenLastCalledWith(
			expect.objectContaining( { page: 2 } )
		);
	} );

	it( 'offers Previous only past the first page and Next only before the last', async () => {
		render(
			<ItemList
				items={ [ ITEM ] }
				view={ view( { page: 1 } ) }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 9, totalPages: 2 } }
				render={ Plain }
			/>
		);
		expect( await screen.findByText( 'Page 1 of 2' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Previous' } )
		).toBeNull();
		expect( screen.getByRole( 'button', { name: 'Next' } ) ).toBeTruthy();
	} );

	it( 'heads its row column by the label the caller names, else "Item"', async () => {
		const table = ( label ) => (
			<ItemList
				items={ [ ITEM ] }
				view={ view( { type: 'table', page: 1 } ) }
				layout="table"
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Plain }
				label={ label }
			/>
		);
		const heads = () =>
			screen
				.getAllByRole( 'columnheader' )
				.map( ( one ) => one.textContent );
		const { rerender } = render( table( 'Desk' ) );
		await screen.findByText( /Acme Quillsheet wins regional prize/ );
		expect( heads() ).toContain( 'Desk' );
		expect( heads() ).not.toContain( 'Item' );

		rerender( table( undefined ) );
		expect( heads() ).toContain( 'Item' );
	} );

	it( 'passes its actions through to each row', async () => {
		const callback = jest.fn();
		render(
			<ItemList
				items={ [ ITEM ] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Plain }
				actions={ [
					{
						id: 'confirm',
						label: 'Yes, keep it',
						isPrimary: true,
						callback,
					},
				] }
			/>
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Yes, keep it' } )
		);
		expect( callback ).toHaveBeenCalledWith( [ ITEM ], expect.anything() );
	} );

	it( 'keeps each row, and the focus inside it, across a render with new row props', async () => {
		/**
		 * A row holding a button, so a test can focus inside it.
		 *
		 * @param {Object} props
		 * @param {Object} props.item The item.
		 * @param {string} props.tone A row prop the caller passed through.
		 * @return {import('react').ReactElement} The row.
		 */
		const Focusable = ( { item, tone } ) => (
			<button type="button">{ `${ tone }: ${ item.title }` }</button>
		);
		const list = ( tone ) => (
			<ItemList
				items={ [ ITEM ] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Focusable }
				rowProps={ { tone } }
			/>
		);
		const { rerender } = render( list( 'Quiet' ) );
		const button = await screen.findByText(
			'Quiet: Acme Quillsheet wins regional prize'
		);
		button.focus();
		rerender( list( 'Loud' ) );
		expect( button ).toHaveTextContent(
			'Loud: Acme Quillsheet wins regional prize'
		);
		expect( document.activeElement ).toBe( button );
	} );
} );

describe( 'ItemList — a checked run', () => {
	// jsdom lays nothing out, so it has no scrollIntoView for a checked row.
	const { scrollIntoView } = window.Element.prototype;

	beforeEach( () => {
		window.NewspackNodesData = pageData();
		window.Element.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		window.Element.prototype.scrollIntoView = scrollIntoView;
	} );

	const THIRD = {
		...ITEM,
		item_key: 'linear:LW-31',
		title: 'Coastline Weekly asks for a second desk',
	};

	/**
	 * A list whose rows are checked by `selectLabel`, its run held here.
	 *
	 * @param {Object}   props
	 * @param {Object[]} props.actions The actions.
	 * @return {import('react').ReactElement} The list.
	 */
	function Checked( { actions } ) {
		const [ selection, setSelection ] = useState( [] );
		return (
			<ItemList
				items={ [ ITEM, SECOND, THIRD ] }
				view={ view( { page: 1 } ) }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 3, totalPages: 1 } }
				render={ Plain }
				rowProps={ { tone: 'Quiet' } }
				actions={ actions }
				selection={ selection }
				onChangeSelection={ setSelection }
				selectLabel={ ( item ) => `Pick ${ item.title }` }
			/>
		);
	}

	const bulk = ( callback ) => [
		{
			id: 'reject',
			label: 'Not this one',
			supportsBulk: true,
			callback,
		},
	];

	it( 'checks rows one by one into a run, the bulk action acting on the run alone', async () => {
		const callback = jest.fn();
		render( <Checked actions={ bulk( callback ) } /> );
		fireEvent.click(
			await screen.findByRole( 'checkbox', {
				name: `Pick ${ ITEM.title }`,
			} )
		);
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: `Pick ${ THIRD.title }` } )
		);
		expect(
			screen.getByRole( 'checkbox', { name: `Pick ${ SECOND.title }` } )
		).not.toBeChecked();
		expect( screen.getByText( '2 Items selected' ) ).toBeInTheDocument();
		await act( async () =>
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Not this one' } )
			)
		);
		expect( callback ).toHaveBeenCalledWith(
			[ ITEM, THIRD ],
			expect.anything()
		);
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: `Pick ${ ITEM.title }` } )
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
	} );

	it( 'marks its list as acting on a checked run alone', async () => {
		const { container } = render(
			<Checked actions={ bulk( jest.fn() ) } />
		);
		await screen.findByRole( 'checkbox', {
			name: `Pick ${ ITEM.title }`,
		} );
		expect( container.querySelector( '.dataviews-view-list' ) ).toHaveClass(
			'newspack-intelligence__run-only'
		);
	} );

	it( 'draws no checkbox and no bulk toolbar without a select label', async () => {
		render(
			<ItemList
				items={ [ ITEM ] }
				view={ view( { page: 1 } ) }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Plain }
				rowProps={ { tone: 'Quiet' } }
				actions={ bulk( jest.fn() ) }
			/>
		);
		await screen.findByText( /Acme Quillsheet wins regional prize/ );
		expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
		expect( screen.queryByText( /Item/ ) ).toBeNull();
	} );
} );
