/* eslint-env jest */
/**
 * The Briefing's blocks, each read on its own page: the Ask box under the
 * block's own words; the lede joining what arrived since the last look into
 * one sentence of links; Needs attention listing signals up to its max; the
 * reader's watches; and the week, its top items open and the rest folded by
 * category, by `otherwise` and by client.
 */

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, pageData } from '../../views/__tests__/fixtures';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import ask from '../ask';
import since from '../since';
import needsAttention from '../needs-attention';
import watches from '../watches';
import week from '../week';

/** A last look the page holds. */
const LAST_LOOK = 1791999000;

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

/**
 * An item the week lists.
 *
 * @param {number} n    Its place.
 * @param {Object} over Fields it carries besides.
 * @return {Object} The item.
 */
const item = ( n, over = {} ) => ( {
	...ITEM,
	item_key: `feed:harbor-ledger-${ n }`,
	title: `Harbor Ledger story ${ n }`,
	url: `https://harborledger.example/${ n }`,
	categories: [],
	otherwise: true,
	...over,
} );

/**
 * A `changes` answer.
 *
 * @param {Object} over Fields it carries besides.
 * @return {Object} The answer.
 */
const changes = ( over = {} ) => ( {
	order: 'importance',
	items: [],
	groups: [],
	categories: [],
	otherwise: 0,
	total: 0,
	summary: { since: LAST_LOOK, items: 0, clients: 0, kinds: [] },
	available: true,
	...over,
} );

/**
 * A signal on the publisher or the about axis.
 *
 * @param {string} bucket The bucket's first day.
 * @param {number} count  Its count.
 * @return {Object} The signal.
 */
const signal = ( bucket, count ) => ( {
	axis: 'about',
	signal: 'spike',
	bucket,
	by: 'kind',
	value: 'complaint',
	count,
	usual: { mean: 1.5, low: 0, high: 3.9 },
	trend: [
		{ bucket, count, in_window: true },
		{ bucket: '2026-09-28', count: 1, in_window: true },
	],
	items: [],
} );

let wire;
let actions;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	document.documentElement.lang = 'en-GB';
	wire = intelWire();
	wire.replies.lenses = { lenses: [], available: true };
	actions = {
		onOpenEntity: jest.fn(),
		onOpenLens: jest.fn(),
		onOpenWatch: jest.fn(),
		onNewWatch: jest.fn(),
		onSearchFor: jest.fn(),
		onSearch: jest.fn(),
		canTune: false,
		asked: null,
		onAsked: jest.fn(),
	};
} );

afterEach( () => {
	document.documentElement.lang = '';
	jest.restoreAllMocks();
} );

/**
 * Render one block's View on the Briefing.
 *
 * @param {Object}  block      The block's `{ View }`.
 * @param {Object}  attributes Its attributes.
 * @param {?number} [lastLook] The reader's last look.
 * @return {Object} What `render` returned.
 */
const renderBlock = ( block, attributes, lastLook = LAST_LOOK ) =>
	renderOnPage(
		<ComposedPage.Provider
			value={ {
				page: 'briefing',
				lens_key: null,
				entity_key: null,
				since: lastLook,
				kept: true,
				actions,
			} }
		>
			<block.View attributes={ attributes } />
		</ComposedPage.Provider>
	);

describe( 'ask', () => {
	it( 'shows its placeholder and examples', async () => {
		renderBlock( ask, {
			label: 'Ask the desk',
			placeholder: 'Ask the desk…',
			examples: [ 'Who launched?' ],
		} );
		expect(
			await screen.findByPlaceholderText( 'Ask the desk…' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Ask the desk' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Try: Who launched?' } )
		).toBeInTheDocument();
	} );
} );

describe( 'since', () => {
	const ALL = [
		'churned',
		'churn_signal',
		'incident',
		'bug',
		'launch',
		'funding',
		'award',
		'feature_request',
		'complaint',
	];
	const SUMMARY = {
		since: LAST_LOOK,
		items: 214,
		clients: 9,
		kinds: [
			{ kind: 'churned', items: 8, clients: 6 },
			{ kind: 'award', items: 2, clients: 2 },
			{ kind: 'incident', items: 1, clients: 0 },
		],
	};

	it( 'joins what arrived into one sentence, each phrase opening Search on it', async () => {
		wire.replies.briefing = changes( { summary: SUMMARY } );
		renderBlock( since, { kinds: ALL } );
		const lede = await screen.findByText( /among 214 new items/ );
		expect( lede ).toHaveTextContent(
			'6 clients started offboarding, 2 awards were announced and 1 incident was reported, among 214 new items.'
		);
		fireEvent.click(
			within( lede ).getByRole( 'button', {
				name: '2 awards were announced',
			} )
		);
		expect( actions.onSearchFor ).toHaveBeenCalledWith( {
			kind: 'award',
			since: '2026-10-14',
		} );
	} );

	it( 'words every kind it counts, a churn kind by its clients', async () => {
		wire.replies.briefing = changes( {
			summary: {
				since: LAST_LOOK,
				items: 47,
				clients: 9,
				kinds: ALL.map( ( kind, n ) => ( {
					kind,
					items: n + 2,
					clients: n + 1,
				} ) ),
			},
		} );
		renderBlock( since, { kinds: ALL } );
		expect(
			await screen.findByText( /among 47 new items/ )
		).toHaveTextContent(
			'1 client started offboarding, 2 clients showed churn signals, 4 incidents were reported, 5 bugs were filed, 6 launches went live, 7 funding rounds were announced, 8 awards were announced, 9 feature requests came in and 10 complaints came in, among 47 new items.'
		);
	} );

	it( 'counts the items alone when none is of a kind it names', async () => {
		wire.replies.briefing = changes( { summary: SUMMARY } );
		renderBlock( since, { kinds: [ 'bug' ] } );
		expect(
			await screen.findByText( '214 new items.' )
		).toBeInTheDocument();
	} );

	it( 'names only the kinds the block counts', async () => {
		wire.replies.briefing = changes( { summary: SUMMARY } );
		renderBlock( since, { kinds: [ 'award' ] } );
		expect(
			await screen.findByText( /among 214 new items/ )
		).toHaveTextContent( '2 awards were announced, among 214 new items.' );
	} );

	it( 'says so when nothing arrived since the last look', async () => {
		wire.replies.briefing = changes();
		renderBlock( since, { kinds: ALL } );
		expect(
			await screen.findByText( /^Nothing new since / )
		).toBeInTheDocument();
	} );

	it( 'heads a first visit "This week"', async () => {
		wire.replies.briefing = changes( { summary: SUMMARY } );
		renderBlock( since, { kinds: ALL }, null );
		expect(
			await screen.findByRole( 'heading', { name: 'This week' } )
		).toBeInTheDocument();
	} );

	it( 'stands two lines in while it is owed', async () => {
		wire.replies.briefing = owed;
		const { container } = renderBlock( since, { kinds: ALL } );
		await screen.findByRole( 'status', { name: 'Loading' } );
		expect(
			container.querySelectorAll(
				'[role=status] .newspack-nodes-skeleton'
			)
		).toHaveLength( 2 );
	} );
} );

describe( 'needs-attention', () => {
	const FIVE = {
		clients: [
			{
				entity_key: 'organization:prairie-signal',
				name: 'Prairie Signal',
				signals: [
					signal( '2026-10-05', 5 ),
					signal( '2026-09-28', 4 ),
				],
				churn_note: null,
			},
		],
		lenses: [
			{
				lens_key: 'desk-awards',
				name: 'Desk awards',
				signals: [
					signal( '2026-10-12', 7 ),
					signal( '2026-09-21', 6 ),
					signal( '2026-10-05', 8 ),
				],
			},
		],
		judged: true,
		available: true,
	};

	it( 'lists a client carrying a churn-risk note first, with the note, whatever it signals', async () => {
		jest.spyOn( Core, 'now' ).mockReturnValue(
			Date.UTC( 2026, 10, 10 ) / 1000
		);
		wire.replies.briefing = {
			...FIVE,
			clients: [
				...FIVE.clients,
				{
					entity_key: 'organization:harbor-ledger',
					name: 'Harbor Ledger',
					signals: [],
					churn_note: {
						text: 'Budget cut at the paper',
						owner: 'Dana Reyes',
						next_step: 'renewal call',
						day: '2026-11-20',
						by: 'user:7',
					},
				},
			],
		};
		renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 3,
			clients: true,
			lenses: [],
		} );
		const block = await screen.findByRole( 'region', {
			name: 'Desk alerts',
		} );
		const [ first ] = await within( block ).findAllByRole( 'listitem' );
		expect( first ).toHaveTextContent(
			'Churn risk: Budget cut at the paper. Owner: Dana Reyes. Next: renewal call, by 20 Nov.'
		);
		fireEvent.click(
			within( first ).getByRole( 'button', { name: 'Harbor Ledger' } )
		);
		expect( actions.onOpenEntity ).toHaveBeenCalledWith(
			'organization:harbor-ledger'
		);
	} );

	it( 'lists at most its max, the most recent first, then offers the rest', async () => {
		wire.replies.briefing = FIVE;
		renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 3,
			clients: true,
			lenses: [],
		} );
		const block = await screen.findByRole( 'region', {
			name: 'Desk alerts',
		} );
		const rows = await within( block ).findAllByRole( 'listitem' );
		expect( rows ).toHaveLength( 3 );
		expect( rows[ 0 ] ).toHaveTextContent( 'Desk awards' );
		fireEvent.click(
			within( block ).getByRole( 'button', { name: 'Show 2 more' } )
		);
		expect( within( block ).getAllByRole( 'listitem' ) ).toHaveLength( 5 );
	} );

	it( 'opens the team view or the profile a row is about', async () => {
		wire.replies.briefing = FIVE;
		renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 20,
			clients: true,
			lenses: [],
		} );
		fireEvent.click(
			(
				await screen.findAllByRole( 'button', { name: /Desk awards/ } )
			)[ 0 ]
		);
		expect( actions.onOpenLens ).toHaveBeenCalledWith( 'desk-awards' );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: /Prairie Signal/ } )[ 0 ]
		);
		expect( actions.onOpenEntity ).toHaveBeenCalledWith(
			'organization:prairie-signal'
		);
	} );

	it( 'words a team view’s signal in the span the view counts by, and a client’s by the week', async () => {
		wire.replies.briefing = FIVE;
		wire.replies.lenses = {
			lenses: [
				{
					lens_key: 'desk-awards',
					name: 'Desk awards',
					description: '',
					filters: {
						kinds: [],
						topics: [],
						roles: [],
						sources: [],
						entity: null,
						days: null,
						tiers: [],
						by: null,
						bucket: 'day',
					},
					week_items: 3,
				},
			],
			available: true,
		};
		renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 20,
			clients: true,
			lenses: [],
		} );
		const block = await screen.findByRole( 'region', {
			name: 'Desk alerts',
		} );
		await waitFor( () =>
			expect(
				within( block )
					.getAllByRole( 'listitem' )
					.filter( ( row ) => /Desk awards/.test( row.textContent ) )
					.every( ( row ) => /yesterday/.test( row.textContent ) )
			).toBe( true )
		);
		within( block )
			.getAllByRole( 'listitem' )
			.filter( ( row ) => /Prairie Signal/.test( row.textContent ) )
			.forEach( ( row ) =>
				expect( row ).toHaveTextContent( /last week/ )
			);
	} );

	it( 'leaves out the clients, and every lens it does not name', async () => {
		wire.replies.briefing = {
			...FIVE,
			lenses: [
				...FIVE.lenses,
				{
					lens_key: 'partner-desk',
					name: 'Partner desk',
					signals: [ signal( '2026-10-12', 9 ) ],
				},
			],
		};
		renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 20,
			clients: false,
			lenses: [ 'partner-desk' ],
		} );
		const block = await screen.findByRole( 'region', {
			name: 'Desk alerts',
		} );
		const rows = await within( block ).findAllByRole( 'listitem' );
		expect( rows ).toHaveLength( 1 );
		expect( rows[ 0 ] ).toHaveTextContent( 'Partner desk' );
	} );

	it( 'says nothing moved when it was judged, and that it is too new when not', async () => {
		wire.replies.briefing = {
			clients: [],
			lenses: [],
			judged: true,
			available: true,
		};
		const { unmount } = renderBlock( needsAttention, {
			heading: 'Needs attention',
			max: 8,
			clients: true,
			lenses: [],
		} );
		expect(
			await screen.findByText(
				'No client or team view moved off its usual range last week.'
			)
		).toBeInTheDocument();
		unmount();
		Core.reset();
		wire.replies.briefing = {
			clients: [],
			lenses: [],
			judged: false,
			available: true,
		};
		renderBlock( needsAttention, {
			heading: 'Needs attention',
			max: 8,
			clients: true,
			lenses: [],
		} );
		expect(
			await screen.findByText(
				'Too little history to judge changes yet. Signals start once a view has nine weeks.'
			)
		).toBeInTheDocument();
	} );

	it( 'stands three pulse rows in while it is owed', async () => {
		wire.replies.briefing = owed;
		const { container } = renderBlock( needsAttention, {
			heading: 'Needs attention',
			max: 8,
			clients: true,
			lenses: [],
		} );
		await screen.findByRole( 'status', { name: 'Loading' } );
		expect(
			container.querySelectorAll(
				'[role=status] .newspack-nodes-skeleton'
			)
		).toHaveLength( 3 );
	} );

	it( "fades each line's latest point in on the session's first paint, but not under reduced motion", async () => {
		wire.replies.briefing = FIVE;
		const { container, unmount } = renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 3,
			clients: true,
			lenses: [],
		} );
		await screen.findAllByRole( 'listitem' );
		const latest = container.querySelectorAll(
			'.newspack-nodes-sparkline__latest'
		);
		expect( latest ).toHaveLength( 3 );
		latest.forEach( ( point ) =>
			expect( point.closest( '.is-fading-in' ) ).not.toBeNull()
		);
		unmount();
		Core.reset();
		jest.spyOn( window, 'matchMedia' ).mockImplementation( ( query ) => ( {
			matches: query.includes( 'prefers-reduced-motion: reduce' ),
			media: query,
			addEventListener: () => {},
			removeEventListener: () => {},
		} ) );
		const reduced = renderBlock( needsAttention, {
			heading: 'Desk alerts',
			max: 3,
			clients: true,
			lenses: [],
		} );
		await screen.findAllByRole( 'listitem' );
		expect(
			reduced.container.querySelectorAll( '.is-fading-in' )
		).toHaveLength( 0 );
	} );
} );

describe( 'watches', () => {
	const WATCHES = {
		watches: [ 134, 22, 9 ].map( ( unseen, n ) => ( {
			watch_key: `7f3a9c1e5b2d4${ n }08`,
			name: `Desk watch ${ n }`,
			target: { kind: 'lens', key: `desk-${ n }` },
			digest: false,
			instant: false,
			inbox: true,
			unseen,
		} ) ),
		available: true,
	};

	it( 'lists the watches up to its max, each opening its watch', async () => {
		wire.replies.watches = WATCHES;
		renderBlock( watches, { heading: 'Followed', max: 2 } );
		const block = await screen.findByRole( 'region', { name: 'Followed' } );
		const rows = await within( block ).findAllByRole( 'listitem' );
		expect( rows ).toHaveLength( 2 );
		expect( rows[ 0 ] ).toHaveTextContent( '134 new' );
		fireEvent.click(
			within( rows[ 1 ] ).getByRole( 'button', { name: /Desk watch 1/ } )
		);
		expect( actions.onOpenWatch ).toHaveBeenCalledWith(
			'7f3a9c1e5b2d4108'
		);
	} );

	it( 'invites a first watch when there is none', async () => {
		wire.replies.watches = { watches: [], available: true };
		renderBlock( watches, { heading: 'Followed', max: 5 } );
		expect(
			await screen.findByText(
				/Watch a client, a team view or a folder to see its new items here\./
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		expect( actions.onNewWatch ).toHaveBeenCalled();
	} );
} );

describe( 'week', () => {
	/**
	 * Fifty items: four open, five more under `otherwise`, and 41 about 34
	 * clients, the first holding eight.
	 *
	 * @return {Object} The answer.
	 */
	const fifty = () => {
		const items = Array.from( { length: 50 }, ( _, n ) => item( n ) );
		const clients = Array.from( { length: 34 }, ( _, n ) => ( {
			role: 'client',
			key: `organization:client-${ n }`,
			name: `Client ${ n }`,
			items: [],
		} ) );
		items
			.slice( 5, 46 )
			.forEach( ( listed, n ) =>
				clients[ Math.max( 0, n - 7 ) ].items.push( listed.item_key )
			);
		return changes( {
			items,
			groups: [
				...clients,
				{
					role: 'otherwise',
					key: null,
					name: null,
					items: [ ...items.slice( 0, 5 ), ...items.slice( 46 ) ].map(
						( listed ) => listed.item_key
					),
				},
			],
			otherwise: 9,
			total: 3353,
		} );
	};

	it( 'opens its top items, folds the rest, and counts the week', async () => {
		wire.replies.briefing = fifty();
		renderBlock( week, {
			heading: 'This week, most important first',
			top: 4,
			categories: [],
			otherwise: 'Everything else',
			by_client: true,
		} );
		const block = await screen.findByRole( 'region', {
			name: 'This week, most important first',
		} );
		expect(
			await within( block ).findAllByRole( 'link', {
				name: /Harbor Ledger story/,
			} )
		).toHaveLength( 4 );
		const tail = within( block ).getByRole( 'button', {
			name: '34 more clients, 41 items',
		} );
		expect( tail ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			within( block ).getByRole( 'button', {
				name: 'Everything else (5)',
			} )
		).toHaveAttribute( 'aria-expanded', 'false' );
		expect( block ).toHaveTextContent( '50 of 3,353 items this week.' );
		fireEvent.click(
			within( block ).getByRole( 'button', { name: 'Search them all' } )
		);
		expect( actions.onSearch ).toHaveBeenCalled();
		expect( within( block ).queryByLabelText( 'Show' ) ).toBeNull();
	} );

	it( 'opens a fold by its whole row, grouping the long tail by client', async () => {
		wire.replies.briefing = fifty();
		renderBlock( week, {
			heading: 'This week, most important first',
			top: 4,
			categories: [],
			otherwise: 'Everything else',
			by_client: true,
		} );
		const tail = await screen.findByRole( 'button', {
			name: '34 more clients, 41 items',
		} );
		fireEvent.click( tail );
		expect( tail ).toHaveAttribute( 'aria-expanded', 'true' );
		expect(
			await screen.findByRole( 'heading', { name: 'Client 0' } )
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'link', { name: /Harbor Ledger story/ } )
		).toHaveLength( 45 );
	} );

	it( "offers each category and `otherwise` in Show, with the server's counts", async () => {
		const prize = item( 1, {
			categories: [ 'desk-awards' ],
			otherwise: false,
		} );
		const wire1 = item( 2, {
			categories: [ 'partner-desk' ],
			otherwise: false,
		} );
		wire.replies.briefing = changes( {
			items: [ prize, wire1, item( 3 ) ],
			groups: [
				{
					role: 'category',
					key: 'desk-awards',
					name: 'Desk awards',
					items: [ prize.item_key ],
				},
				{
					role: 'category',
					key: 'partner-desk',
					name: 'Partner desk',
					items: [ wire1.item_key ],
				},
				{
					role: 'otherwise',
					key: null,
					name: null,
					items: [ item( 3 ).item_key ],
				},
			],
			categories: [
				{ lens_key: 'desk-awards', name: 'Desk awards', count: 2 },
				{ lens_key: 'partner-desk', name: 'Partner desk', count: 7 },
			],
			otherwise: 4,
			total: 3,
		} );
		renderBlock( week, {
			heading: 'This week, most important first',
			top: 0,
			categories: [ 'desk-awards', 'partner-desk' ],
			otherwise: 'Elsewhere',
			by_client: true,
		} );
		const show = await screen.findByLabelText( 'Show' );
		expect(
			within( show )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [
			'All',
			'Desk awards 2',
			'Partner desk 7',
			'Elsewhere 4',
		] );
		expect(
			screen.getByRole( 'button', { name: 'Elsewhere (1)' } )
		).toBeInTheDocument();
		fireEvent.change( show, { target: { value: 'partner-desk' } } );
		expect(
			await screen.findByRole( 'link', { name: 'Harbor Ledger story 2' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Harbor Ledger story 1' } )
		).toBeNull();
	} );

	it( 'asks the week through its categories', async () => {
		wire.replies.briefing = changes();
		renderBlock( week, {
			heading: 'This week, most important first',
			top: 10,
			categories: [ 'desk-awards', 'partner-desk' ],
			otherwise: 'Elsewhere',
			by_client: true,
		} );
		await screen.findByText( 'Nothing arrived this week.' );
		expect( wire.sent( 'briefing' ) ).toEqual( [
			[
				'changes',
				`--since=${ LAST_LOOK }`,
				'--lenses=desk-awards,partner-desk',
			],
		] );
	} );

	it( 'says when nothing arrived, offering Search', async () => {
		wire.replies.briefing = changes();
		renderBlock( week, {
			heading: 'This week, most important first',
			top: 10,
			categories: [],
			otherwise: 'Everything else',
			by_client: true,
		} );
		expect(
			await screen.findByText( 'Nothing arrived this week.' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Search everything' } )
		);
		expect( actions.onSearch ).toHaveBeenCalled();
	} );

	it( 'stands ten item rows in while it is owed', async () => {
		wire.replies.briefing = owed;
		const { container } = renderBlock( week, {
			heading: 'This week, most important first',
			top: 10,
			categories: [],
			otherwise: 'Everything else',
			by_client: true,
		} );
		await screen.findByRole( 'status', { name: 'Loading' } );
		expect(
			container.querySelectorAll(
				'.newspack-nodes-composed-skeleton-rows > li'
			)
		).toHaveLength( 10 );
	} );
} );

describe( 'Controls', () => {
	const LENSES = {
		lenses: [
			{ lens_key: 'desk-awards', name: 'Desk awards' },
			{ lens_key: 'partner-desk', name: 'Partner desk' },
		],
		available: true,
	};

	it( "write each block's words and bounds", async () => {
		wire.replies.lenses = LENSES;
		const set = jest.fn();
		render(
			<>
				<ask.Controls
					attributes={ {
						label: 'Ask the desk',
						placeholder: 'Ask the desk…',
						examples: [ 'Who launched?' ],
					} }
					setAttributes={ set }
				/>
				<watches.Controls
					attributes={ { heading: 'Followed', max: 2 } }
					setAttributes={ set }
				/>
			</>
		);
		fireEvent.change( screen.getByLabelText( 'Label' ), {
			target: { value: 'Ask the bureau' },
		} );
		expect( set ).toHaveBeenLastCalledWith( { label: 'Ask the bureau' } );
		fireEvent.change( screen.getByLabelText( 'Placeholder' ), {
			target: { value: 'Ask the bureau…' },
		} );
		expect( set ).toHaveBeenLastCalledWith( {
			placeholder: 'Ask the bureau…',
		} );
		fireEvent.change( screen.getByLabelText( 'Heading' ), {
			target: { value: 'Followed closely' },
		} );
		expect( set ).toHaveBeenLastCalledWith( {
			heading: 'Followed closely',
		} );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Watches shown' } ),
			{ target: { value: '7' } }
		);
		expect( set ).toHaveBeenLastCalledWith( { max: 7 } );
	} );

	it( 'adds an example question as a token', () => {
		const set = jest.fn();
		render(
			<ask.Controls
				attributes={ {
					label: 'Ask the desk',
					placeholder: 'Ask the desk…',
					examples: [ 'Who launched?' ],
				} }
				setAttributes={ set }
			/>
		);
		const field = screen.getByLabelText( 'Example questions' );
		fireEvent.change( field, { target: { value: 'Who won?' } } );
		fireEvent.keyDown( field, { key: 'Enter' } );
		expect( set ).toHaveBeenLastCalledWith( {
			examples: [ 'Who launched?', 'Who won?' ],
		} );
	} );

	it( 'counts a kind in the lede only while it is ticked', () => {
		const set = jest.fn();
		render(
			<since.Controls
				attributes={ { kinds: [ 'award', 'churned' ] } }
				setAttributes={ set }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Prize' ) );
		expect( set ).toHaveBeenLastCalledWith( { kinds: [ 'churned' ] } );
		fireEvent.click( screen.getByLabelText( 'Outage' ) );
		expect( set ).toHaveBeenLastCalledWith( {
			kinds: [ 'churned', 'incident', 'award' ],
		} );
	} );

	it( "write the week's and Needs attention's settings, team views by name", async () => {
		wire.replies.lenses = LENSES;
		const set = jest.fn();
		renderOnPage(
			<>
				<week.Controls
					attributes={ {
						heading: 'This week',
						top: 4,
						categories: [ 'desk-awards' ],
						otherwise: 'Elsewhere',
						by_client: true,
					} }
					setAttributes={ set }
				/>
				<needsAttention.Controls
					attributes={ {
						heading: 'Desk alerts',
						max: 3,
						clients: true,
						lenses: [],
					} }
					setAttributes={ set }
				/>
			</>
		);
		expect( await screen.findByText( 'Desk awards' ) ).toBeInTheDocument();
		fireEvent.change( screen.getByLabelText( 'Everything else' ), {
			target: { value: 'Industry' },
		} );
		expect( set ).toHaveBeenLastCalledWith( { otherwise: 'Industry' } );
		fireEvent.click( screen.getByLabelText( 'Group the rest by client' ) );
		expect( set ).toHaveBeenLastCalledWith( { by_client: false } );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Top items' } ),
			{ target: { value: '12' } }
		);
		expect( set ).toHaveBeenLastCalledWith( { top: 12 } );
		fireEvent.click( screen.getByLabelText( 'Include clients' ) );
		expect( set ).toHaveBeenLastCalledWith( { clients: false } );
		const views = screen.getByLabelText(
			'Team views (none lists every one)'
		);
		fireEvent.change( views, { target: { value: 'Partner desk' } } );
		fireEvent.keyDown( views, { key: 'Enter' } );
		expect( set ).toHaveBeenLastCalledWith( {
			lenses: [ 'partner-desk' ],
		} );
	} );
} );
