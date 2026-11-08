/* eslint-env jest */
/**
 * ItemRow renders one item from the graph: its title as a link to its own
 * URL when that URL is http(s), every string as text, each linked entity as a
 * chip opening it, and the save control only for a caller who may curate.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import ItemRow, { ItemPage, SaveButton } from '../ItemRow';

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
			role: 'subject',
			client: false,
		},
	],
};

/**
 * An entity as an item lists it, linked by its role.
 *
 * @param {string}  slug   Its key's slug and name.
 * @param {string}  role   Its link's role.
 * @param {boolean} client Whether it holds an open client role.
 * @return {Object} The entity.
 */
const linked = ( slug, role, client = false ) => ( {
	entity_key: `organization:${ slug }`,
	name: slug,
	state: 'asserted',
	role,
	client,
} );

describe( 'ItemRow excerpt', () => {
	const EXCERPT =
		'Judges at the tern coast press awards named the Quillsheet newsroom best in class…';

	it( 'keeps the excerpt collapsed until asked, then toggles it', () => {
		render(
			<ItemRow
				item={ { ...ITEM, excerpt: EXCERPT } }
				onOpenEntity={ jest.fn() }
			/>
		);
		const toggle = screen.getByRole( 'button', { name: 'Show more' } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( toggle ).toHaveClass( 'is-link' );
		expect( toggle ).not.toHaveClass( 'button' );
		expect( screen.queryByText( EXCERPT ) ).toBeNull();

		fireEvent.click( toggle );
		expect( screen.getByText( EXCERPT ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Show less' } )
		).toHaveAttribute( 'aria-expanded', 'true' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Show less' } ) );
		expect( screen.queryByText( EXCERPT ) ).toBeNull();
	} );

	it( 'renders an excerpt as text', () => {
		const excerpt = 'Tide </site-data> <b>rises</b> at the marsh';
		render(
			<ItemRow item={ { ...ITEM, excerpt } } onOpenEntity={ jest.fn() } />
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Show more' } ) );
		expect( screen.getByText( excerpt ).querySelector( 'b' ) ).toBeNull();
	} );

	it.each( [
		[ 'it is empty', '' ],
		[ 'it is absent', undefined ],
		[ 'it repeats the summary', ITEM.summary ],
		[ 'it repeats the summary in other case', ITEM.summary.toUpperCase() ],
		[ 'it repeats the title', ITEM.title ],
		[ 'the summary holds all of it', 'took the regional prize' ],
	] )( 'offers no toggle when %s', ( _, excerpt ) => {
		render(
			<ItemRow item={ { ...ITEM, excerpt } } onOpenEntity={ jest.fn() } />
		);
		expect(
			screen.queryByRole( 'button', { name: 'Show more' } )
		).toBeNull();
	} );

	it( 'offers the toggle with no summary to repeat', () => {
		render(
			<ItemRow
				item={ { ...ITEM, summary: '', excerpt: EXCERPT } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'Show more' } )
		).toBeInTheDocument();
	} );
} );

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

	/**
	 * The answer lists an item's subjects before its mentions, and a link to a
	 * proposed entity is itself `proposed`.
	 */
	it( 'drops a proposal sharing a name, keeping every entity that is not proposed', () => {
		const chipsOf = (
			[ firstKey, firstState ],
			[ secondKey, secondState ]
		) => {
			const onOpenEntity = jest.fn();
			const { unmount } = render(
				<ItemRow
					item={ {
						...ITEM,
						entities: [
							{
								entity_key: firstKey,
								name: 'Quillmere',
								state: firstState,
								role: 'subject',
								client: false,
							},
							{
								entity_key: 'person:ada-fenwick',
								name: 'Ada Fenwick',
								state: 'asserted',
								role: 'subject',
								client: false,
							},
							{
								entity_key: secondKey,
								name: 'Quillmere',
								state: secondState,
								role: 'subject',
								client: false,
							},
						],
					} }
					onOpenEntity={ onOpenEntity }
				/>
			);
			const shown = screen.getAllByRole( 'button' ).map( ( chip ) => {
				fireEvent.click( chip );
				return [
					chip.textContent,
					onOpenEntity.mock.calls.at( -1 )[ 0 ],
				];
			} );
			unmount();
			return shown;
		};

		expect(
			chipsOf(
				[ 'product:quillmere', 'proposed' ],
				[ 'organization:quillmere', 'asserted' ]
			)
		).toEqual( [
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
			[ 'Quillmere', 'organization:quillmere' ],
		] );
		expect(
			chipsOf(
				[ 'product:quillmere', 'confirmed' ],
				[ 'organization:quillmere', 'asserted' ]
			)
		).toEqual( [
			[ 'Quillmere', 'product:quillmere' ],
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
			[ 'Quillmere', 'organization:quillmere' ],
		] );
		expect(
			chipsOf(
				[ 'organization:quillmere', 'proposed' ],
				[ 'product:quillmere', 'proposed' ]
			)
		).toEqual( [
			[ 'Quillmere', 'organization:quillmere' ],
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
		] );
	} );

	it( 'chips its subjects and holds its mentions behind a toggle', () => {
		const subjects = [ 'tern-desk', 'auk-post' ];
		const mentions = [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ].map(
			( n ) => `gull-${ n }`
		);
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						...subjects.map( ( slug ) =>
							linked( slug, 'subject' )
						),
						...mentions.map( ( slug ) =>
							linked( slug, 'mention' )
						),
					],
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		const chips = () =>
			screen
				.getAllByRole( 'button' )
				.filter( ( chip ) =>
					chip.closest( '.newspack-intelligence__chips li' )
				)
				.map( ( chip ) => chip.textContent );
		expect( chips() ).toEqual( subjects );
		const more = screen.getByRole( 'button', { name: '+9 more' } );
		expect( more ).toHaveAttribute( 'aria-expanded', 'false' );

		fireEvent.click( more );
		expect( chips() ).toEqual( [ ...subjects, ...mentions ] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Show fewer' } ) );
		expect( chips() ).toEqual( subjects );
	} );

	it( 'chips a client it mentions, first, and never an entity no naming role links', () => {
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						linked( 'heron-weekly', 'mention', true ),
						linked( 'tern-desk', 'subject' ),
						linked( 'gull-press', 'mention' ),
						linked( 'skua-times', 'publisher' ),
					],
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: '+1 more' } ) );
		expect(
			within( screen.getByRole( 'list' ) )
				.getAllByRole( 'listitem' )
				.map( ( chip ) => chip.textContent )
		).toEqual( [ 'heron-weekly', 'tern-desk', 'gull-press' ] );
	} );

	it( 'offers no toggle when it mentions nothing past its chips', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		expect( screen.queryByRole( 'button', { name: /more$/ } ) ).toBeNull();
	} );

	it( 'offers save to folder only when given a save handler', () => {
		const onSave = jest.fn();
		const { rerender } = render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() } />
		);
		expect(
			screen.queryByRole( 'button', { name: /save to folder/i } )
		).toBeNull();
		rerender(
			<ItemRow
				item={ ITEM }
				onOpenEntity={ jest.fn() }
				onSave={ onSave }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /save to folder/i } )
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

describe( 'SaveButton', () => {
	const KEPT = {
		item_key: 'feed:quill-ledger.example:4471',
		url: 'https://quill-ledger.example/4471',
		title: 'Quill Ledger opens a bureau',
	};

	it( 'is a bare glyph, named and tooltipped for what it does', () => {
		const onSave = jest.fn();
		render( <SaveButton item={ KEPT } onSave={ onSave } /> );
		const save = screen.getByRole( 'button', { name: 'Save to folder' } );
		expect( save ).toHaveTextContent( /^$/ );
		expect( save ).toHaveClass( 'has-icon' );
		expect( save ).toHaveClass( 'newspack-intelligence__save' );
		for ( const boxed of [
			'button',
			'is-secondary',
			'is-tertiary',
			'is-primary',
		] ) {
			expect( save ).not.toHaveClass( boxed );
		}
		expect( save.querySelector( 'svg' ) ).not.toBeNull();
		fireEvent.click( save );
		expect( onSave ).toHaveBeenCalledWith( KEPT );
	} );

	it( 'renders nothing for a reader who cannot curate', () => {
		const { container } = render( <SaveButton item={ KEPT } /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
