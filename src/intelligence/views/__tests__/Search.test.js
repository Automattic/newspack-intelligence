/* eslint-env jest */
/**
 * Search is the Ask box above "Find items", one DataViews list over the
 * `search` command: the words in DataViews' search, Team view and Kind
 * always shown and every other filter behind "Add filter", the client or
 * company picked by name into an `is` filter, the server's total as the
 * count and the pager, Importance or Newest as the order, and when narrow
 * every filter in a sheet. Each view it holds is asked as `viewToArgs`
 * reads it, and the page holds the view, so it outlives a visit elsewhere.
 */

import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, LENSES, pageData, VOCABULARY } from './fixtures';
import Search from '../Search';
import { namedView, newView } from '../searchView';
import { daysSince } from '../../components/lensDraft';

let wire;
let viewport;

/** The Briefing's layout context, whose first ask block Search's box takes. */
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
			asked={ asked }
			onAsked={ setAsked }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
}

function renderSearch( props = {} ) {
	return render( <Harness { ...props } /> );
}

/**
 * What an input's suggestion list offers, each its value and its label.
 *
 * @param {HTMLElement} input The input.
 * @return {string[][]} Each offer.
 */
const offered = ( input ) =>
	[
		...( document
			.getElementById( input.getAttribute( 'list' ) )
			?.querySelectorAll( 'option' ) ?? [] ),
	].map( ( one ) => [ one.value, one.label ] );

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

beforeAll( () => {
	window.CSS = { ...jsdomCss, supports: () => false };
} );

afterAll( () => {
	window.CSS = jsdomCss;
} );

describe( 'Search', () => {
	it( 'opens with the Ask box above the filters, at the defaults when the Briefing holds no ask block', async () => {
		wire.replies.search = PAGE;
		renderSearch();
		const box = await screen.findByPlaceholderText(
			'Ask about anything collected here…'
		);
		expect( document.querySelectorAll( 'section' )[ 0 ] ).toContainElement(
			box
		);
		expect( screen.getByLabelText( 'Ask a question' ) ).toBe( box );
		expect(
			screen.getByRole( 'button', {
				name: 'Try: What changed last week?',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Find items' } )
		).toBeInTheDocument();
		expect( wire.sent( 'ask' ) ).toEqual( [] );
	} );

	it( "takes the Briefing layout's first ask block, and asks only the question, never Search's filters", async () => {
		window.NewspackNodesData.compositions[ BRIEFING ] = {
			context: BRIEFING,
			source: 'saved',
			blocks: [
				{
					name: 'newspack-intelligence/since',
					attributes: { kinds: [ 'award' ] },
					inner: [],
				},
				{
					name: 'newspack-intelligence/aside',
					attributes: {},
					inner: [
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
				},
				{
					name: 'newspack-intelligence/ask',
					attributes: {
						label: 'A second box',
						placeholder: 'Never shown',
						examples: [],
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
		renderSearch( { start: namedView( { q: 'sponsor', kind: 'bug' } ) } );

		const box = screen.getByLabelText( 'Put it to the desk' );
		expect( box ).toHaveAttribute( 'placeholder', 'Ask the desk…' );
		expect(
			screen.getByRole( 'button', { name: 'Try: Who launched?' } )
		).toBeInTheDocument();
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

	it( 'keeps the defaults when the Briefing layout could not be read', async () => {
		window.NewspackNodesData.compositions[ BRIEFING ] = {
			context: BRIEFING,
			error: 'the stored layout no longer validates',
		};
		wire.replies.search = PAGE;
		renderSearch();

		expect( screen.getByLabelText( 'Ask a question' ) ).toHaveAttribute(
			'placeholder',
			'Ask about anything collected here…'
		);
		await screen.findByText( '128 items, most important first' );
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
		fireEvent.change( screen.getByLabelText( 'Ask a question' ), {
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

	it( 'suggests the entities whose names the entity typed leads, and searches the one picked', async () => {
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
		const entity = screen.getByLabelText( 'Client or company' );
		fireEvent.change( entity, { target: { value: 'Valley' } } );
		await waitFor( () =>
			expect( offered( entity ) ).toEqual( [
				[ 'Valley Ledger', 'organization:valley-ledger' ],
				[ 'Valley Times', 'organization:valley-times' ],
			] )
		);

		fireEvent.change( entity, { target: { value: 'Valley Ledger' } } );

		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--entity=organization:valley-ledger',
				'--limit=20',
			] )
		);
		expect( entity ).toHaveValue( 'organization:valley-ledger' );
	} );

	it( 'searches an entity key typed and entered, and drops the entity when emptied', async () => {
		wire.replies.search = PAGE;
		wire.replies.names = { entities: [], available: true };
		renderSearch();
		await screen.findByText( '128 items, most important first' );
		const entity = screen.getByLabelText( 'Client or company' );

		fireEvent.change( entity, {
			target: { value: 'organization:coastline-weekly' },
		} );
		fireEvent.submit( entity.closest( 'form' ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [
				'--entity=organization:coastline-weekly',
				'--limit=20',
			] )
		);

		fireEvent.change( entity, { target: { value: '' } } );
		fireEvent.submit( entity.closest( 'form' ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [ '--limit=20' ] )
		);
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
				'Intelligence could not be read just now. Try again.'
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
				'Intelligence could not be read just now. Try again.'
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
					'Intelligence could not be read just now. Try again.'
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
