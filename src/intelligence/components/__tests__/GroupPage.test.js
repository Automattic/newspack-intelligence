/* eslint-env jest */
/**
 * GroupPage renders a lens's ranked theme groups in the answer's order, each
 * headed by its theme with its count, kind, newest day and client chips, its
 * items behind a disclosure button; a group of one is that item's row; and
 * it pages by group through the shared DataViews list's pager.
 */

import { act, render, screen, fireEvent, within } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import GroupPage from '../GroupPage';
import { GROUPS, pageData } from '../../views/__tests__/fixtures';

beforeEach( () => {
	window.NewspackNodesData = pageData();
	jest.spyOn( Core, 'now' ).mockReturnValue(
		Date.UTC( 2027, 1, 3, 12 ) / 1000
	);
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Render the page, settled past the list's first layout.
 *
 * @param {Object} props Props over the defaults.
 * @return {Promise<void>} Settles once the list has laid out.
 */
const page = async ( props = {} ) => {
	render(
		<GroupPage
			answer={ GROUPS }
			view={ { type: 'list', page: 1, perPage: 4 } }
			onChangeView={ jest.fn() }
			paginationInfo={ { totalItems: 41, totalPages: 11 } }
			empty="No stories."
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
	await act( async () => {} );
};

const groupOf = ( theme ) =>
	screen.getByRole( 'heading', { level: 4, name: theme } ).closest( 'li' );

/**
 * A group's meta line, cell by cell.
 *
 * @param {HTMLElement} group The group's row.
 * @return {string[]} Each cell's text.
 */
const cellsOf = ( group ) =>
	[ ...group.querySelector( '.newspack-intelligence__meta' ).children ].map(
		( cell ) => cell.textContent
	);

describe( 'GroupPage', () => {
	it( 'heads each group with its theme, in the order the answer gives', async () => {
		await page();
		expect(
			screen
				.getAllByRole( 'heading', { level: 4 } )
				.map( ( heading ) => heading.textContent )
		).toEqual( [ 'site blocked from sending email', 'bot registrations' ] );
	} );

	it( 'shows a group’s count, kind and newest day as cells, never dotted', async () => {
		await page();
		expect(
			cellsOf( groupOf( 'site blocked from sending email' ) )
		).toEqual( [ '12 items', 'Grumble', '23 Sep 2026' ] );
		expect( cellsOf( groupOf( 'bot registrations' ) ) ).toEqual( [
			'3 items',
			'Outage',
			'24 Sep 2026',
		] );
		expect(
			groupOf( 'bot registrations' ).querySelector( 'time' )
		).toHaveAttribute( 'dateTime', '2026-09-24' );
		expect( document.body ).not.toHaveTextContent( '·' );
	} );

	it( 'opens each client of a group from its chip', async () => {
		const onOpenEntity = jest.fn();
		await page( { onOpenEntity } );
		const group = groupOf( 'site blocked from sending email' );
		fireEvent.click(
			within( group ).getByRole( 'button', { name: 'Harbor Ledger' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:harbor-ledger'
		);
		expect(
			within( group ).getByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
	} );

	it( 'expands a group onto its item rows and collapses it again', async () => {
		const onSave = jest.fn();
		await page( { onSave } );
		const toggle = screen.getByRole( 'button', {
			name: 'Show items: site blocked from sending email',
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toBeNull();
		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( toggle ).toHaveAccessibleName(
			'Hide items: site blocked from sending email'
		);
		const group = groupOf( 'site blocked from sending email' );
		expect(
			within( group ).getByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-120' );
		expect(
			within( group ).getByRole( 'link', {
				name: 'Harbor Ledger newsletters held',
			} )
		).toBeInTheDocument();
		fireEvent.click(
			within( group ).getAllByRole( 'button', {
				name: 'Save to folder',
			} )[ 1 ]
		);
		expect( onSave ).toHaveBeenCalledWith( GROUPS.groups[ 0 ].items[ 1 ] );
		expect(
			screen.queryByRole( 'link', {
				name: 'Harbor Ledger floods with fake readers',
			} )
		).toBeNull();
		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toBeNull();
	} );

	it( 'renders a group of one as that item’s row, themed or not', async () => {
		await page();
		expect(
			screen.queryByRole( 'heading', { name: 'storage extension' } )
		).toBeNull();
		expect(
			screen.getByRole( 'link', {
				name: 'Acme asks for more media storage',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-77' );
		expect(
			screen.getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect(
			screen.getAllByRole( 'button', { name: /^Show items/ } )
		).toHaveLength( 2 );
	} );

	it( 'heads a group that answers no items and no clients, disclosing none', async () => {
		await page( {
			answer: {
				groups: [
					{
						theme: 'registration bots',
						kind: 'incident',
						count: 1,
						newest_ts: 1790294400,
					},
				],
				total: 1,
			},
		} );
		const group = groupOf( 'registration bots' );
		expect( cellsOf( group ) ).toEqual( [
			'1 item',
			'Outage',
			'25 Sep 2026',
		] );
		fireEvent.click(
			within( group ).getByRole( 'button', {
				name: 'Show items: registration bots',
			} )
		);
		expect( within( group ).queryByRole( 'link' ) ).toBeNull();
		expect(
			within( group )
				.getAllByRole( 'button' )
				.map( ( b ) => b.textContent )
		).toEqual( [ 'Hide items' ] );
	} );

	it( 'renders two one-story groups sharing a story as two keyed rows', async () => {
		const story = GROUPS.groups[ 2 ].items[ 0 ];
		await page( {
			answer: {
				groups: [
					{ ...GROUPS.groups[ 2 ], items: [ story ] },
					{
						...GROUPS.groups[ 2 ],
						theme: 'media storage limits',
						items: [ story ],
					},
					{ ...GROUPS.groups[ 1 ], theme: story.item_key },
				],
				total: 3,
			},
		} );
		expect(
			screen.getAllByRole( 'link', {
				name: 'Acme asks for more media storage',
			} )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'heading', { level: 4, name: story.item_key } )
		).toBeInTheDocument();
	} );

	it( 'says the fixed order the groups are ranked in, and offers no switch', async () => {
		await page();
		expect(
			screen.getByText(
				'Ranked by severity, importance, clients affected, then recency'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Show newest first' } )
		).toBeNull();
	} );

	it( 'pages by group through the shared list’s pager', async () => {
		const onChangeView = jest.fn();
		await page( {
			view: { type: 'list', page: 3, perPage: 4 },
			onChangeView,
		} );
		expect( screen.getByText( 'Page 3 of 11' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onChangeView ).toHaveBeenLastCalledWith(
			expect.objectContaining( { page: 4, perPage: 4 } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( onChangeView ).toHaveBeenLastCalledWith(
			expect.objectContaining( { page: 2 } )
		);
	} );
} );
