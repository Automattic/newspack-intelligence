/* eslint-env jest */
/**
 * ItemList is the substrate's DataViewsList with an item's defaults: each
 * item draws as ItemRow unless the caller names its own row, and rows are
 * keyed by `item_key` unless the caller names another key.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import ItemList from '../ItemList';
import { ITEM, pageData } from '../../views/__tests__/fixtures';

const SECOND = {
	...ITEM,
	item_key: 'feed:prairie-signal-4',
	title: 'Prairie Signal opens a second bureau',
};

const view = ( over = {} ) => ( {
	type: 'list',
	page: 1,
	perPage: 7,
	...over,
} );

/**
 * A row naming its item, so a test reads which row component drew it.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The row.
 */
const Plain = ( { item } ) => <span>{ item.title }</span>;

describe( 'ItemList', () => {
	// jsdom lays nothing out, so it has no scrollIntoView for a checked row.
	const { scrollIntoView } = window.Element.prototype;

	beforeEach( () => {
		window.NewspackNodesData = pageData();
		window.Element.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		window.Element.prototype.scrollIntoView = scrollIntoView;
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

	it( 'is the substrate list', async () => {
		const { container } = render(
			<ItemList
				items={ [ ITEM ] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				render={ Plain }
			/>
		);
		await screen.findByText( ITEM.title );
		expect(
			container.querySelector( '.newspack-nodes-dataviews' )
		).not.toBeNull();
	} );

	it( 'checks a row into the run by its item_key', async () => {
		const onChangeSelection = jest.fn();
		render(
			<ItemList
				items={ [ ITEM, SECOND ] }
				view={ view() }
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 2, totalPages: 1 } }
				render={ Plain }
				selection={ [ ITEM.item_key ] }
				onChangeSelection={ onChangeSelection }
				selectLabel={ ( item ) => `Pick ${ item.title }` }
			/>
		);
		fireEvent.click(
			await screen.findByRole( 'checkbox', {
				name: `Pick ${ SECOND.title }`,
			} )
		);
		expect( onChangeSelection ).toHaveBeenLastCalledWith( [
			ITEM.item_key,
			SECOND.item_key,
		] );
	} );
} );
