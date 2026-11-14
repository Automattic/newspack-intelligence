/* eslint-env jest */
/**
 * Search stands under the shared header, its Ask box a plain part of the
 * page above "Find items", one DataViews list over the `search` command:
 * the words in DataViews' search, Team view and Kind always shown and
 * every other filter behind "Add filter", the client or
 * company picked by name into an `is` filter, the server's total as the
 * count and the pager, Importance or Newest as the order, and when narrow
 * every filter in a sheet. Each view it holds is asked as `viewToArgs`
 * reads it, and the page holds the view, so it outlives a visit elsewhere.
 */

import {
	act,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { ASK_DEFAULTS, ITEM, LENSES, pageData, VOCABULARY } from './fixtures';
import Search from '../Search';
import { namedView, newView } from '../searchView';
import { daysSince } from '../../components/lensDraft';

let wire;
let viewport;

/** The Briefing's layout context, whose stored layout Search's box never reads. */
const BRIEFING = 'newspack-intelligence/briefing';

/** A page of 128 items, most important first, distinct from every default. */
const PAGE = {
	items: [ ITEM ],
	total: 128,
	sort: 'importance',
	available: true,
};

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.NewspackNodesData = { ...pageData(), userId: '7' };
	wire = intelWire();
	viewport = installViewport( false );
} );

afterEach( () => {
	viewport.restore();
} );

function Harness( { start = newView(), ...props } ) {
	const [ view, setView ] = useState( start );
	const [ asked, setAsked ] = useState( null );
	return (
		<Search
			view={ view }
			onChangeView={ setView }
			ask={ ASK_DEFAULTS }
			asked={ asked }
			onAsked={ setAsked }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
}

function renderSearch( props = {} ) {
	return renderOnPage( <Harness { ...props } /> );
}

/**
 * Type into the entity box as a reader does: focused first, then the text.
 *
 * @param {string} text What is typed.
 * @return {HTMLElement} The box.
 */
const typeEntity = ( text ) => {
	const box = screen.getByRole( 'combobox', { name: 'Client or company' } );
	act( () => box.focus() );
	fireEvent.change( box, { target: { value: text } } );
	return box;
};

/**
 * The filter chip DataViews draws for a filter, by the text it leads with.
 *
 * @param {string} name The filter's name.
 * @return {HTMLElement} The chip.
 */
const chip = ( name ) =>
	screen
		.getAllByRole( 'button' )
		.find(
			( one ) =>
				one.classList.contains( 'dataviews-filters__summary-chip' ) &&
				one.textContent.startsWith( name )
		);

/** jsdom's `CSS`, which carries no `supports()` for DataViews' menus. */
const jsdomCss = window.CSS;

// jsdom lays nothing out, so it has no scrollIntoView for the entity picker.
const { scrollIntoView } = window.Element.prototype;

beforeAll( () => {
	window.CSS = { ...jsdomCss, supports: () => false };
	window.Element.prototype.scrollIntoView = jest.fn();
} );

afterAll( () => {
	window.CSS = jsdomCss;
	window.Element.prototype.scrollIntoView = scrollIntoView;
} );

describe( 'Search', () => {
	it( 'opens under the shared header, its Ask box a plain part of the page above "Find items", the first section heading', async () => {
		wire.replies.search = PAGE;
		const { container } = renderSearch();
		const box = await screen.findByPlaceholderText(
			ASK_DEFAULTS.placeholder
		);
		const header = screen
			.getByRole( 'heading', { level: 2, name: 'Search' } )
			.closest( '.newspack-nodes-composed-section-head' );
		expect( header ).toHaveTextContent(
			'Ask a question the graph answers, or find items by their words and filters.'
		);
		expect( screen.getByLabelText( ASK_DEFAULTS.label ) ).toBe( box );
		expect( box.closest( '.components-card' ) ).toBeNull();
		expect( container.querySelector( '.components-card' ) ).toBeNull();
		expect(
			screen.getByRole( 'button', {
				name: `Try: ${ ASK_DEFAULTS.examples[ 0 ] }`,
			} )
		).toBeInTheDocument();
		const [ title, first ] = screen.getAllByRole( 'heading', {
			level: 2,
		} );
		expect( title ).toHaveTextContent( 'Search' );
		expect( first ).toHaveTextContent( 'Find items' );
		expect(
			box.compareDocumentPosition( first ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( wire.sent( 'ask' ) ).toEqual( [] );
	} );

	it( 'shows a refused declaration where the Ask box stands, and still finds items', async () => {
		renderSearch( {
			ask: { error: 'composition declaration wren-7712: refused' },
		} );

		expect(
			await screen.findByRole( 'heading', { name: 'Find items' } )
		).toBeTruthy();
		expect(
			screen
				.getAllByRole( 'status' )
				.some( ( one ) =>
					one.textContent.includes(
						'This could not be read just now.'
					)
				)
		).toBe( true );
		expect( screen.queryByRole( 'textbox', { name: /ask/i } ) ).toBeNull();
	} );

	it( "asks under the page data's ask defaults whatever a stored Briefing layout holds, and asks only the question, never Search's filters", async () => {
		window.NewspackNodesData.compositions[ BRIEFING ] = {
			context: BRIEFING,
			source: 'saved',
			blocks: [
				{
					name: 'newspack-intelligence/ask',
					attributes: {
						label: 'Put it to the desk',
						placeholder: 'Ask the desk…',
						examples: [ 'Who launched?' ],
					},
					inner: [],
				},
			],
			saved_ts: 1792000000,
			actor: 'user:lakeside',
		};
		wire.replies.search = PAGE;
		wire.replies.lenses = LENSES;
		wire.replies.ask = { ask_key: '0b7e5c3a1d9f2468' };
		wire.replies.ask_result = {
			ask_key: '0b7e5c3a1d9f2468',
			state: 'asked',
			reason: '',
			found: 0,
			dropped: 0,
			hidden: 0,
			sentences: [],
			items: [],
			searches: [],
			wait_s: 900,
			available: true,
		};
		renderSearch( {
			start: namedView( { q: 'sponsor', kind: 'bug' } ),
			ask: {
				label: 'Put it to the harbor',
				placeholder: 'Ask the harbor…',
				examples: [ 'Who docked at dawn?' ],
			},
		} );

		const box = screen.getByLabelText( 'Put it to the harbor' );
		expect( box ).toHaveAttribute( 'placeholder', 'Ask the harbor…' );
		expect(
			screen.getByRole( 'button', { name: 'Try: Who docked at dawn?' } )
		).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Put it to the desk' ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Try: Who launched?' } )
		).toBeNull();
		fireEvent.change( box, {
			target: { value: 'Who asked for digests?' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );

		await waitFor( () =>
			expect( wire.sent( 'ask' ) ).toEqual( [
				[ '--question=Who asked for digests?' ],
			] )
		);
	} );

	it( 'opens its own results on the words an empty ask offers to search', async () => {
		wire.replies.search = PAGE;
		wire.replies.ask = { ask_key: '0b7e5c3a1d9f2468' };
		wire.replies.ask_result = {
			ask_key: '0b7e5c3a1d9f2468',
			state: 'answered',
			reason: '',
			found: 0,
			dropped: 0,
			hidden: 0,
			sentences: [],
			items: [],
			searches: [],
			wait_s: 900,
			available: true,
		};
		renderSearch();
		fireEvent.change( screen.getByLabelText( ASK_DEFAULTS.label ), {
			target: { value: 'lighthouse grants' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );

		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Search for these words',
			} )
		);

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--q=lighthouse grants',
				'--limit=20',
			] )
		);
		expect( screen.getByLabelText( 'Search words' ) ).toHaveValue(
			'lighthouse grants'
		);
	} );

	it( 'asks for the first page on mount and counts the total, most important first', async () => {
		wire.replies.search = PAGE;
		renderSearch();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( '128 items, most important first' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 7' ) ).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toEqual( [ [ '--limit=20' ] ] );
	} );

	it( 'counts newest first under that order, and switches it from the first page', async () => {
		wire.replies.search = ( args ) => ( {
			...PAGE,
			sort: args.includes( '--sort=importance' ) ? 'importance' : 'time',
		} );
		renderSearch( { start: { ...newView(), search: 'paywall', page: 4 } } );
		expect(
			await screen.findByText( '128 items, newest first' )
		).toBeInTheDocument();
		const sort = screen.getByLabelText( 'Sort' );
		expect( sort ).toHaveValue( 'time' );

		fireEvent.change( sort, { target: { value: 'importance' } } );

		expect(
			await screen.findByText( '128 items, most important first' )
		).toBeInTheDocument();
		expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
			'--q=paywall',
			'--sort=importance',
			'--limit=20',
		] );
	} );

	it( 'searches the words typed', async () => {
		wire.replies.search = PAGE;
		renderSearch();
		await screen.findByText( '128 items, most important first' );

		fireEvent.change( screen.getByLabelText( 'Search words' ), {
			target: { value: 'regional prize' },
		} );

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--q=regional prize',
				'--limit=20',
			] )
		);
	} );

	it( 'shows Team view and Kind of news, and every other filter behind Add filter', async () => {
		wire.replies.search = PAGE;
		wire.replies.lenses = LENSES;
		renderSearch();
		await screen.findByText( '128 items, most important first' );

		expect( chip( 'Team view' ) ).toBeDefined();
		expect( chip( 'Kind of news' ) ).toBeDefined();
		expect( chip( 'About' ) ).toBeUndefined();

		fireEvent.click( screen.getByRole( 'button', { name: 'Add filter' } ) );

		const menu = await screen.findByRole( 'menu' );
		expect(
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( one ) => one.textContent )
		).toEqual( [ 'About', 'Dates', 'Sources', 'Topics' ] );
	} );

	it( 'sets the filter chips on the search field\u2019s line, before the layout switch', async () => {
		wire.replies.search = PAGE;
		wire.replies.lenses = LENSES;
		renderSearch();
		await screen.findByText( '128 items, most important first' );

		const bar = document.querySelector(
			'.newspack-intelligence__find-bar'
		);
		expect(
			within( bar ).getByRole( 'searchbox', { name: 'Search words' } )
		).toBeDefined();
		expect(
			within( bar ).getByRole( 'button', { name: 'Add filter' } )
		).toBeDefined();
		expect( bar.textContent ).toContain( 'Kind of news' );
	} );

	it( 'sends the kinds picked as repeated --kind args, each named by its word', async () => {
		wire.replies.search = PAGE;
		renderSearch();
		await screen.findByText( '128 items, most important first' );

		fireEvent.click( chip( 'Kind of news' ) );
		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Money raised' } )
		);
		fireEvent.click( screen.getByRole( 'option', { name: 'Prize' } ) );

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--kind=funding',
				'--kind=award',
				'--limit=20',
			] )
		);
	} );

	it( 'lists the entities whose names the entity typed leads, and searches the one picked at once', async () => {
		wire.replies.search = PAGE;
		wire.replies.names = ( args ) => ( {
			entities:
				'--prefix=Valley' === args[ 0 ]
					? [
							{
								entity_key: 'organization:valley-ledger',
								name: 'Valley Ledger',
							},
							{
								entity_key: 'organization:valley-times',
								name: 'Valley Times',
							},
					  ]
					: [],
			available: true,
		} );
		renderSearch();
		await screen.findByText( '128 items, most important first' );
		const entity = typeEntity( 'Valley' );
		expect(
			await screen.findByRole( 'option', { name: 'Valley Times' } )
		).toBeVisible();
		const searched = wire.sent( 'search' ).length;

		fireEvent.click(
			screen.getByRole( 'option', { name: 'Valley Ledger' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--entity=organization:valley-ledger',
				'--limit=20',
			] )
		);
		expect( wire.sent( 'search' ) ).toHaveLength( searched + 1 );
		expect( entity ).toHaveValue( 'Valley Ledger' );
	} );

	it( 'searches nothing for typed text, nor on Enter when no entity answers it', async () => {
		wire.replies.search = PAGE;
		wire.replies.names = { entities: [], available: true };
		renderSearch();
		await screen.findByText( '128 items, most important first' );
		const searched = wire.sent( 'search' ).length;

		const entity = typeEntity( 'organization:coastline-weekly' );
		await waitFor( () =>
			expect( wire.sent( 'names' ) ).toContainEqual( [
				'--prefix=organization:coastline-weekly',
			] )
		);
		await act( async () => {} );
		fireEvent.keyDown( entity, { key: 'Enter', code: 'Enter' } );
		await act( async () => {} );

		expect( wire.sent( 'search' ) ).toHaveLength( searched );
	} );

	it( 'drops the entity when the box is reset, and empties the box when the filter goes', async () => {
		wire.replies.search = PAGE;
		renderSearch( {
			start: {
				...newView(),
				filters: [
					{
						field: 'entity',
						operator: 'is',
						value: 'organization:coastline-weekly',
					},
				],
			},
		} );
		await screen.findByText( '128 items, most important first' );
		const entity = screen.getByRole( 'combobox', {
			name: 'Client or company',
		} );
		expect( entity ).toHaveValue( 'organization:coastline-weekly' );

		fireEvent.click(
			within(
				entity.closest( '.components-combobox-control' )
			).getByRole( 'button', { name: 'Reset' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [ '--limit=20' ] )
		);
		act( () => entity.blur() );
		expect( entity ).toHaveValue( '' );
	} );

	it( 'pages forward, the page it leaves gone while the next is owed', async () => {
		let land;
		wire.replies.search = ( args ) => {
			const page = {
				...PAGE,
				items: [
					{
						...ITEM,
						item_key: `feed:${ args.join( '' ) }`,
						title: `found by ${ args.join( ' ' ) }`,
					},
				],
			};
			return 1 === args.length
				? page
				: new Promise( ( resolve ) => {
						land = () => resolve( page );
				  } );
		};
		renderSearch();
		await screen.findByText( 'found by --limit=20' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );

		await waitFor( () =>
			expect( screen.queryByText( 'found by --limit=20' ) ).toBeNull()
		);
		await waitFor( () => expect( land ).toBeDefined() );
		await act( async () => land() );
		expect(
			await screen.findByText( 'found by --limit=20 --offset=20' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 2 of 7' ) ).toBeInTheDocument();
	} );

	it( 'never repaints the items a read showed before it was refused', async () => {
		wire.replies.search = ( args ) => {
			if ( 1 === args.length ) {
				return PAGE;
			}
			return args.includes( '--offset=20' )
				? new Error( 'search could not be read for --offset=20' )
				: new Promise( () => {} );
		};
		renderSearch();
		await screen.findByText( ITEM.title );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText(
				'This could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'harbor' },
		} );
		await waitFor( () =>
			expect(
				wire
					.sent( 'search' )
					.some( ( args ) => args.includes( '--q=harbor' ) )
			).toBe( true )
		);
		expect( screen.queryByText( ITEM.title ) ).toBeNull();
	} );

	it( 'says nothing matches, and removes every filter and the words', async () => {
		wire.replies.search = ( args ) =>
			1 === args.length
				? PAGE
				: { items: [], total: 0, sort: 'time', available: true };
		renderSearch( {
			start: namedView( { q: 'lighthouse', kind: 'award' } ),
		} );
		expect(
			await screen.findByText( 'Nothing matches these filters.' )
		).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove filters' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [ '--limit=20' ] )
		);
		expect(
			await screen.findByText( '128 items, most important first' )
		).toBeInTheDocument();
	} );

	it( 'says the graph is unavailable rather than that nothing matched', async () => {
		wire.replies.search = { items: [], total: 0, available: false };
		renderSearch();
		expect(
			await screen.findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Nothing matches these filters.' )
		).toBeNull();
	} );

	it( 'shows a team view picked read-only, and copies it into its own filters to edit', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = PAGE;
		renderSearch( {
			start: namedView( { q: 'paywall', lens: 'support-desk' } ),
		} );
		const runs = await screen.findByRole( 'group', {
			name: 'Support desk searches with',
		} );
		for ( const shown of [
			'Grumble, Kudos',
			'Paying member',
			'organization:acme-quillsheet',
			'21 days',
			'Staff',
		] ) {
			expect( within( runs ).getByText( shown ) ).toBeInTheDocument();
		}
		expect(
			screen.getByText(
				'A search reads every tier you may read, so editing drops this team view’s tiers.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--q=paywall', '--lens=support-desk', '--limit=20' ],
		] );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit as my own search' } )
		);

		const since = daysSince( 21, Date.now() );
		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--q=paywall',
				'--kind=complaint',
				'--kind=praise',
				'--role=client',
				'--entity=organization:acme-quillsheet',
				`--since=${ since }`,
				'--limit=20',
			] )
		);
		expect(
			screen.queryByRole( 'group', { name: /searches with/ } )
		).toBeNull();
	} );

	it( 'says a refused team view list was not read, its words kept from a reader', async () => {
		wire.replies.lenses = () => new Error( 'unknown command: lenses' );
		wire.replies.search = PAGE;
		renderSearch();
		expect(
			await screen.findByText(
				'This could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'unknown command: lenses' ) ).toBeNull();
	} );

	it( 'opens an entity from a result chip', async () => {
		wire.replies.search = PAGE;
		const onOpenEntity = jest.fn();
		renderSearch( { onOpenEntity } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-quillsheet'
		);
	} );

	it( 'offers save to folder to a curator alone', async () => {
		wire.replies.search = PAGE;
		const onSave = jest.fn();
		const { unmount } = renderSearch( { onSave } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: /save to folder/i } )
		);
		expect( onSave ).toHaveBeenCalledWith( ITEM );
		unmount();

		renderSearch();
		await screen.findByText( '128 items, most important first' );
		expect(
			screen.queryByRole( 'button', { name: /save to folder/i } )
		).toBeNull();
	} );

	describe( 'as a table', () => {
		/** A second item, each column value apart from the first item's value. */
		const HERON = {
			...ITEM,
			item_key: 'linear:SUP-9041',
			title: 'Heron Ledger newsletter sends stall for a week',
			url: 'https://linear.example/SUP-9041',
			source: 'linear',
			kind: 'launch',
			importance: 9,
			item_ts: 1790380800,
			entities: [
				{
					entity_key: 'organization:heron-ledger',
					name: 'Heron Ledger',
					state: 'asserted',
					role: 'mention',
					client: true,
				},
				{
					entity_key: 'organization:otter-press',
					name: 'Otter Press',
					state: 'asserted',
					role: 'subject',
					client: false,
				},
			],
		};
		const TABLE = { ...PAGE, items: [ ITEM, HERON ] };
		const tableView = { ...newView(), type: 'table' };
		const rowOf = ( title ) =>
			screen.getByRole( 'link', { name: title } ).closest( 'tr' );
		const cells = ( title ) =>
			Array.from( rowOf( title ).querySelectorAll( 'td' ) );

		it( 'draws one row per item under six columns', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			const heads = screen
				.getAllByRole( 'columnheader' )
				.map( ( one ) => one.textContent.trim() )
				.filter( Boolean );
			expect( heads ).toEqual( [
				'Title',
				'Kind',
				'Source',
				'Date',
				'Clients',
				'Importance',
			] );
			expect( screen.getAllByRole( 'row' ) ).toHaveLength( 3 );
			const [ title, kind, source, date, clients, importance ] = cells(
				HERON.title
			);
			expect( within( title ).getByRole( 'link' ) ).toHaveAttribute(
				'href',
				HERON.url
			);
			expect( kind ).toHaveTextContent( 'Going live' );
			expect( source ).toHaveTextContent( 'Tracker' );
			expect( date.querySelector( 'time' ) ).toHaveAttribute(
				'dateTime',
				'2026-09-26'
			);
			expect( date ).toHaveTextContent( /^26 Sep/ );
			expect(
				within( clients ).getByRole( 'button', {
					name: 'Heron Ledger, mentioned',
				} )
			).toBeInTheDocument();
			expect( clients ).not.toHaveTextContent( 'Otter Press' );
			expect( importance ).toHaveTextContent( 'High importance' );
			const first = cells( ITEM.title );
			expect( first[ 1 ] ).toHaveTextContent( 'Prize' );
			expect( first[ 2 ] ).toHaveTextContent( 'Web feed' );
			expect( first[ 5 ] ).toHaveTextContent( 'Medium importance' );
		} );

		it( 'keeps the summary, the excerpt and why it ranks to the list', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			expect( screen.queryAllByText( ITEM.summary ) ).toEqual( [] );
			expect(
				screen.queryByRole( 'button', { name: 'Why this ranks' } )
			).toBeNull();
		} );

		it( 'offers no sort in its headers, the Sort select the one order', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			for ( const header of screen.getAllByRole( 'columnheader' ) ) {
				expect( within( header ).queryAllByRole( 'button' ) ).toEqual(
					[]
				);
			}
			expect(
				screen.queryByRole( 'menuitemradio', {
					name: 'Sort ascending',
				} )
			).toBeNull();
			fireEvent.change( screen.getByLabelText( 'Sort' ), {
				target: { value: 'time' },
			} );
			await waitFor( () =>
				expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
					'--sort=time',
					'--limit=20',
				] )
			);
		} );

		/**
		 * The named row's own "More actions" menu, opened.
		 *
		 * @param {string} title The row's title.
		 * @return {Promise<HTMLElement>} The menu.
		 */
		const openRowMenu = async ( title ) => {
			fireEvent.click(
				within( rowOf( title ) ).getByRole( 'button', {
					name: 'More actions',
				} )
			);
			return screen.findByRole( 'menu' );
		};
		const entries = ( menu ) =>
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( one ) => one.textContent );

		it( 'offers every action the card offers in each row’s menu', async () => {
			wire.replies.search = TABLE;
			renderSearch( {
				start: tableView,
				onSave: jest.fn(),
				canTune: true,
			} );
			await screen.findByRole( 'link', { name: HERON.title } );
			expect( entries( await openRowMenu( HERON.title ) ) ).toEqual( [
				'Open profile',
				'Copy link',
				'Save to folder',
				'Exclude from every view',
				'Is this about…',
				'How this was read',
			] );
		} );

		it( 'offers a reader the menu without the curator’s actions', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			expect( entries( await openRowMenu( HERON.title ) ) ).toEqual( [
				'Open profile',
				'Copy link',
				'How this was read',
			] );
		} );

		it( 'saves an item to a folder from its row menu', async () => {
			wire.replies.search = TABLE;
			const onSave = jest.fn();
			renderSearch( { start: tableView, onSave } );
			await screen.findByRole( 'link', { name: HERON.title } );
			fireEvent.click(
				within( await openRowMenu( HERON.title ) ).getByRole(
					'menuitem',
					{ name: 'Save to folder' }
				)
			);
			expect( onSave ).toHaveBeenCalledWith( HERON );
		} );

		it( 'excludes an item from its row menu, for a reader holding tune', async () => {
			wire.replies.search = TABLE;
			wire.replies.exclude_item = { ok: true, event_id: 'ev-t' };
			renderSearch( { start: tableView, canTune: true } );
			await screen.findByRole( 'link', { name: HERON.title } );
			fireEvent.click(
				within( await openRowMenu( HERON.title ) ).getByRole(
					'menuitem',
					{ name: 'Exclude from every view' }
				)
			);
			expect(
				await screen.findByText( 'Excluded.' )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'link', { name: HERON.title } )
			).toBeNull();
			expect(
				screen.getByRole( 'link', { name: ITEM.title } )
			).toBeInTheDocument();
		} );

		it( 'opens how an item was read, and asks what it is about, from its row menu', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView, canTune: true } );
			await screen.findByRole( 'link', { name: HERON.title } );
			fireEvent.click(
				within( await openRowMenu( HERON.title ) ).getByRole(
					'menuitem',
					{ name: 'How this was read' }
				)
			);
			expect(
				within( rowOf( HERON.title ) ).getByRole( 'region', {
					name: 'How this was read',
				} )
			).toBeInTheDocument();
			fireEvent.click(
				within( await openRowMenu( HERON.title ) ).getByRole(
					'menuitem',
					{ name: 'Is this about…' }
				)
			);
			expect(
				within( await screen.findByRole( 'dialog' ) ).getByRole(
					'combobox',
					{ name: 'Client or company' }
				)
			).toBeInTheDocument();
		} );

		it( 'takes a client off an item from its Clients cell, for a reader holding tune', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView, canTune: true } );
			await screen.findByRole( 'link', { name: HERON.title } );
			const [ , , , , clients ] = cells( HERON.title );
			fireEvent.click(
				within( clients ).getByRole( 'button', {
					name: 'Remove Heron Ledger from this item',
				} )
			);
			expect( clients ).not.toHaveTextContent( 'Heron Ledger' );
			expect( document.activeElement ).toBe(
				within( rowOf( HERON.title ) ).getByRole( 'button', {
					name: 'More actions',
				} )
			);
		} );

		it( 'offers a reader no remove on a client', async () => {
			wire.replies.search = TABLE;
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			expect(
				within( cells( HERON.title )[ 4 ] ).queryByRole( 'button', {
					name: /^Remove /,
				} )
			).toBeNull();
		} );

		it( 'counts an item’s other copies under its title, and opens them', async () => {
			wire.replies.search = {
				...TABLE,
				items: [ { ...HERON, copies: 3 }, ITEM ],
			};
			renderSearch( { start: tableView } );
			await screen.findByRole( 'link', { name: HERON.title } );
			const [ title ] = cells( HERON.title );
			const toggle = within( title ).getByRole( 'button', {
				name: 'and 3 other copies',
			} );
			fireEvent.click( toggle );
			expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
			await waitFor( () =>
				expect( wire.sent( 'copies' ) ).toEqual( [
					[ HERON.item_key ],
				] )
			);
		} );

		it( 'draws the cards again in the list layout, under no columns', async () => {
			wire.replies.search = TABLE;
			renderSearch();
			await screen.findByRole( 'link', { name: HERON.title } );
			expect( screen.getAllByText( ITEM.summary ) ).toHaveLength( 2 );
			expect( screen.queryAllByRole( 'columnheader' ) ).toEqual( [] );
		} );
	} );

	describe( 'narrow', () => {
		beforeEach( () => {
			viewport.restore();
			viewport = installViewport( true );
		} );

		it( 'holds every filter in a sheet that shows the items counted, and hands focus back on Escape', async () => {
			wire.replies.search = PAGE;
			wire.replies.lenses = LENSES;
			const named = namedView( {
				kind: 'bug',
				source: 'slack',
				q: 'digest',
			} );
			renderSearch( {
				start: {
					...named,
					filters: [
						...named.filters,
						{ field: 'day', operator: 'between', value: undefined },
					],
				},
			} );
			await screen.findByText( '128 items, most important first' );
			expect( chip( 'Kind of news' ) ).toBeUndefined();
			const opener = screen.getByRole( 'button', {
				name: 'Filters (2)',
			} );

			opener.focus();
			fireEvent.click( opener );

			const sheet = screen.getByRole( 'dialog', { name: 'Filters' } );
			expect(
				within( sheet ).getByText( /^Kind of news/ )
			).toBeDefined();
			expect(
				within( sheet ).getByLabelText( 'Client or company' )
			).toBeInTheDocument();
			expect(
				within( sheet ).getByRole( 'button', {
					name: 'Show 128 items',
				} )
			).toHaveClass( 'is-primary' );

			fireEvent.keyDown( document, { key: 'Escape' } );

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( opener ).toHaveFocus();
		} );

		it( 'closes the sheet on its primary button', async () => {
			wire.replies.search = PAGE;
			renderSearch();
			await screen.findByText( '128 items, most important first' );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Filters (0)' } )
			);

			fireEvent.click(
				screen.getByRole( 'button', { name: 'Show 128 items' } )
			);

			await waitFor( () =>
				expect( screen.queryByRole( 'dialog' ) ).toBeNull()
			);
			expect(
				await screen.findByText( '128 items, most important first' )
			).toBeTruthy();
		} );
	} );

	describe.each( [
		[ '390 px', true ],
		[ '1,440 px', false ],
	] )( 'at %s', ( _, narrow ) => {
		beforeEach( () => {
			viewport.restore();
			viewport = installViewport( narrow );
		} );

		it( 'stands skeleton rows in for the first page while it is owed', async () => {
			wire.replies.search = PAGE;
			renderSearch();
			expect(
				screen.getByRole( 'status', { name: 'Loading' } )
			).toBeInTheDocument();
			await screen.findByText( '128 items, most important first' );
			expect(
				screen.queryByRole( 'status', { name: 'Loading' } )
			).toBeNull();
		} );

		it( 'says nothing matches, with the one way out', async () => {
			wire.replies.search = {
				items: [],
				total: 0,
				sort: 'importance',
				available: true,
			};
			renderSearch();
			expect(
				await screen.findByText( 'Nothing matches these filters.' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Remove filters' } )
			).toBeInTheDocument();
		} );

		it( 'says a failed search was not read, above the list, and claims no empty result', async () => {
			wire.replies.search = () =>
				new Error( 'search could not be read for --limit=20' );
			renderSearch();
			expect(
				await screen.findByText(
					'This could not be read just now. Try again.'
				)
			).toBeInTheDocument();
			expect(
				screen.queryByText( 'Nothing matches these filters.' )
			).toBeNull();
			expect(
				screen.queryByText( 'search could not be read for --limit=20' )
			).toBeNull();
		} );
	} );
} );
