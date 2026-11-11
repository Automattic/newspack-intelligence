/* eslint-env jest */
/* global PopStateEvent */
/**
 * IntelligencePage opens on the Briefing, switches among its seven views in
 * place, opens an entity from any chip, saves an item through one dialog only for a curator, and
 * carries the substrate debug overlay under its own storage key.
 */

import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { useEffect } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';

jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: ( props ) => {
		global.__debugOverlayProps = props;
		return <div data-testid="debug-overlay" />;
	},
} ) );

import { intelWire } from './intelWire';
import { HEALTHY } from './healthFixture';
import { registerCompositionEditor } from '@newspack-nodes/shared/composition/editorRegistry';
import {
	COMPOSITIONS,
	ENTITY,
	GROUPS,
	ITEM,
	LENSES,
	pageData,
	VOCABULARY,
} from '../views/__tests__/fixtures';
import IntelligencePage from '../IntelligencePage';

let wire;

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.sessionStorage.clear();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.search = ( args ) =>
		args.includes( '--group=theme' )
			? GROUPS
			: { items: [ ITEM ], total: 1, available: true };
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [], total: 0, available: true };
	wire.replies.related = { entities: [], available: true };
	wire.replies.claims = { claims: [], available: true };
	wire.replies.review = ( [ what ] ) => ( {
		[ what ]: [],
		total: 0,
		available: true,
	} );
	wire.replies.collections = { collections: [], available: true };
	wire.replies.lenses = LENSES;
	wire.replies.trends = { rows: [], available: true };
	wire.replies.signals = {
		signals: [],
		trend: [ { bucket: '2026-10-12', count: 3, in_window: true } ],
		partial: { bucket: '2026-10-19', count: 1 },
		judged: true,
		bucket: 'week',
		baseline_buckets: 8,
		available: true,
	};
	wire.replies.watches = { watches: [], available: true };
	wire.replies.health = ( [ what ] ) => HEALTHY[ what ];
	wire.replies.briefing = ( [ what ] ) => WEEK[ what ];
} );

afterEach( () => {
	window.history.replaceState( {}, '', '/' );
} );

/**
 * A week whose one change is not under any client, so its chip is the only one.
 */
const WEEK = {
	changes: {
		order: 'importance',
		items: [ { ...ITEM, categories: [], otherwise: true } ],
		groups: [
			{
				role: 'otherwise',
				key: null,
				name: null,
				items: [ ITEM.item_key ],
			},
		],
		categories: [],
		otherwise: 1,
		total: 1,
		summary: {
			since: 1792000000,
			items: 6,
			clients: 4,
			kinds: [ { kind: 'churned', items: 5, clients: 3 } ],
		},
		available: true,
	},
	signals: { clients: [], lenses: [], judged: true, available: true },
};

const LINKED = {
	watches: [
		{
			watch_key: '9f3a51c07be2d468',
			name: 'Quillsheet group',
			target: { kind: 'entity', key: 'organization:acme-quillsheet' },
			digest: true,
			instant: false,
			inbox: true,
			unseen: 5,
		},
	],
	available: true,
};

function renderPage(
	canTune = false,
	canManage = false,
	compositions = COMPOSITIONS
) {
	return render(
		<IntelligencePage
			canTune={ canTune }
			canManage={ canManage }
			userId="6143"
			operator={ {
				name: 'Quillmere Cooperative',
				heading: 'Quillmere Cooperative',
			} }
			vocabulary={ VOCABULARY }
			compositions={ compositions }
		/>
	);
}

/** Wait for the Briefing's week, its one item standing open. */
const openWeek = () => screen.findByRole( 'link', { name: ITEM.title } );

/** What an empty Review queue says. */
const EMPTY_REVIEW =
	'Nothing to review. New suggestions arrive as items are read.';

/**
 * The tab named `name`, with or without the count it carries.
 *
 * @param {string} name The tab's name.
 * @return {HTMLElement} The tab.
 */
const nav = ( name ) =>
	within( screen.getByRole( 'tablist', { name: 'Views' } ) ).getByRole(
		'tab',
		{ name: new RegExp( `^${ name }( \\d+)?$` ) }
	);

/**
 * The Briefing's reads with the client Needs attention lists, whose row is a
 * chip that stays put while a profile stands over it.
 *
 * @param {string[]} args The read's tokens.
 * @return {Object} The answer.
 */
const attentionOn = ( [ what ] ) =>
	'signals' === what
		? {
				clients: [
					{
						entity_key: 'organization:acme-quillsheet',
						name: 'Acme Quillsheet',
						churn_note: null,
						signals: [
							{
								axis: 'about',
								signal: 'spike',
								bucket: '2026-11-02',
								by: 'kind',
								value: 'complaint',
								count: 6,
								usual: { mean: 1.5, low: 0, high: 3.9 },
								trend: [
									{
										bucket: '2026-11-02',
										count: 6,
										in_window: true,
									},
								],
								items: [],
							},
						],
					},
				],
				lenses: [],
				judged: true,
				available: true,
		  }
		: WEEK[ what ];

/**
 * The chip Needs attention opens the client's profile from.
 *
 * @return {HTMLElement} The chip.
 */
const attentionChip = () =>
	within(
		screen
			.getByRole( 'heading', { name: 'Needs attention' } )
			.closest( 'section' )
	).getByRole( 'button', { name: 'Acme Quillsheet' } );

describe( 'IntelligencePage', () => {
	it( 'sets its tab bar flush under the header, as the Station does, its views padded below', async () => {
		renderPage();
		const tabs = await screen.findByRole( 'tablist', { name: 'Views' } );
		expect( tabs.closest( '.newspack-intelligence__page' ) ).toBeNull();
		expect(
			document.querySelector(
				'.newspack-intelligence > .newspack-intelligence__page'
			)
		).not.toBeNull();
	} );

	it( 'opens on the Briefing, its first tab', async () => {
		renderPage();
		await openWeek();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
		const tabs = within(
			screen.getByRole( 'tablist', { name: 'Views' } )
		).getAllByRole( 'tab' );
		expect( tabs[ 0 ] ).toBe( nav( 'Briefing' ) );
		expect( nav( 'Briefing' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( wire.sent( 'search' ) ).toEqual( [] );
	} );

	it( 'opens Search on a line of what arrived since the last look', async () => {
		window.localStorage.setItem(
			'newspack-intelligence:last-look:6143',
			'1791999000'
		);
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: '3 clients started offboarding',
			} )
		);
		expect( nav( 'Search' ) ).toHaveAttribute( 'aria-selected', 'true' );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toEqual( [
				[ '--kind=churned', '--since=2026-10-14', '--limit=20' ],
			] )
		);
		expect(
			await screen.findByText( '1 item, most important first' )
		).toBeInTheDocument();
	} );

	it( 'opens a watch the Briefing lists, on its matches', async () => {
		wire.replies.watches = LINKED;
		wire.replies.watch_matches = {
			items: [ ITEM ],
			total: 1,
			available: true,
		};
		renderPage();
		await screen.findByText( '5 new' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Quillsheet group' } )
		);
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'region', { name: 'Quillsheet group' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'watch_matches' ) ).toEqual( [
				[ '--key=9f3a51c07be2d468', '--limit=50' ],
			] )
		);
	} );

	it( 'opens the watch form from the Briefing’s empty watches', async () => {
		renderPage();
		fireEvent.click(
			(
				await screen.findAllByRole( 'button', { name: 'New watch' } )
			)[ 0 ]
		);
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const form = screen.getByRole( 'dialog', { name: 'New watch' } );
		expect(
			within( form ).getByRole( 'radio', { name: 'A team view' } )
		).toBeChecked();
		await waitFor( () =>
			expect( wire.sent( 'lenses' ).length ).toBeGreaterThan( 0 )
		);
		await screen.findByText(
			'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.'
		);
	} );

	it( 'opens a lens a Briefing signal names, and Search from its invitation', async () => {
		wire.replies.briefing = ( args ) =>
			'signals' === args[ 0 ]
				? {
						...attentionOn( args ),
						lenses: [
							{
								lens_key: 'support-desk',
								name: 'Support desk',
								signals: [
									{
										axis: 'about',
										signal: 'spike',
										bucket: '2026-10-12',
										by: 'kind',
										value: 'complaint',
										count: 7,
										usual: { mean: 1.5, low: 0, high: 3.9 },
										trend: [],
										items: [],
									},
								],
							},
						],
				  }
				: {
						...WEEK,
						changes: {
							...WEEK.changes,
							items: [],
							groups: [],
							otherwise: 0,
							total: 0,
							summary: {
								since: 1792000000,
								items: 0,
								clients: 0,
								kinds: [],
							},
							available: true,
						},
				  }[ args[ 0 ] ];
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk' } )
		);
		expect( nav( 'Team views' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		expect(
			await screen.findByRole( 'heading', {
				level: 2,
				name: 'Support desk',
			} )
		).toBeInTheDocument();
		fireEvent.click( nav( 'Briefing' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Search everything' } )
		);
		expect( nav( 'Search' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows collection’s status in the header on every tab, its fault under the tab bar', async () => {
		wire.replies.health = ( [ what ] ) =>
			'sources' === what
				? {
						...HEALTHY.sources,
						sources: [
							{ ...HEALTHY.sources.sources[ 0 ], state: 'idle' },
						],
				  }
				: HEALTHY[ what ];
		renderPage();
		const header = () =>
			document.querySelector( '.topology-header__controls' );
		await waitFor( () =>
			expect( header() ).toHaveTextContent( /^Collecting, updated / )
		);
		const notice = await screen.findByText(
			/^Some sources stopped updating at /,
			{ selector: 'p' }
		);
		const tabs = screen.getByRole( 'tablist', { name: 'Views' } );
		expect(
			tabs.compareDocumentPosition( notice ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		const views = [
			[ 'Review', EMPTY_REVIEW ],
			[
				'Folders',
				'No folders yet. Create one, then use Save on any item to file it here.',
			],
			[
				'Watches',
				'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.',
			],
		];
		for ( const [ name, shown ] of views ) {
			fireEvent.click( nav( name ) );
			await screen.findByText( shown );
			expect( header() ).toHaveTextContent( /^Collecting, updated / );
			expect(
				screen.getByText( /^Some sources stopped updating at /, {
					selector: 'p',
				} )
			).toBeInTheDocument();
		}
		expect(
			wire.sent( 'health' ).filter( ( [ what ] ) => 'backlog' === what )
		).toHaveLength( 1 );
	} );

	it( 'keeps an answer across a switch to Search and back, asking once', async () => {
		wire.replies.ask = { ask_key: '4c2e6a8b0d1f3957' };
		wire.replies.ask_result = {
			ask_key: '4c2e6a8b0d1f3957',
			state: 'answered',
			reason: '',
			found: 1,
			dropped: 0,
			hidden: 0,
			sentences: [ { text: 'Quillsheet won a prize.', cites: [ 1 ] } ],
			items: [ ITEM ],
			searches: [ { words: 'prize', days: 9 } ],
			wait_s: 900,
			available: true,
		};
		renderPage();
		fireEvent.change(
			await screen.findByPlaceholderText(
				'Ask about anything collected here…'
			),
			{ target: { value: 'Who won a prize?' } }
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );
		expect(
			await screen.findByText( 'Quillsheet won a prize.' )
		).toBeInTheDocument();

		fireEvent.click( nav( 'Search' ) );

		expect(
			await screen.findByText( 'Quillsheet won a prize.' )
		).toBeInTheDocument();

		fireEvent.click( nav( 'Briefing' ) );

		expect(
			await screen.findByText( 'Quillsheet won a prize.' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Ask a question' ) ).toHaveValue(
			'Who won a prize?'
		);
		expect( wire.sent( 'ask' ) ).toEqual( [
			[ '--question=Who won a prize?' ],
		] );

		fireEvent.click( screen.getByRole( 'button', { name: 'Clear' } ) );
		fireEvent.click( nav( 'Search' ) );

		await screen.findByLabelText( 'Search words' );
		expect( screen.queryByText( 'Quillsheet won a prize.' ) ).toBeNull();
	} );

	it( 'switches to Review and Folders in place', async () => {
		renderPage();
		fireEvent.click( nav( 'Review' ) );
		expect( await screen.findByText( EMPTY_REVIEW ) ).toBeInTheDocument();
		expect( nav( 'Review' ) ).toHaveAttribute( 'aria-selected', 'true' );
		fireEvent.click( nav( 'Folders' ) );
		expect(
			await screen.findByText(
				'No folders yet. Create one, then use Save on any item to file it here.'
			)
		).toBeInTheDocument();
	} );

	it( 'counts the reader’s new matches on the Watches tab', async () => {
		const watch = ( key, unseen, inbox = true ) => ( {
			...LINKED.watches[ 0 ],
			watch_key: key,
			name: key,
			inbox,
			unseen,
		} );
		wire.replies.watches = {
			watches: [
				watch( 'developers', 134 ),
				watch( 'prairie-signal', 22 ),
				watch( 'newsletter-picks', 22 ),
				watch( 'email-only', 0, false ),
			],
			available: true,
		};
		renderPage();
		expect(
			await within(
				screen.getByRole( 'tablist', { name: 'Views' } )
			).findByRole( 'tab', { name: 'Watches 178' } )
		).toBeInTheDocument();
	} );

	it( 'counts the suggested matches on the Review tab for a curator alone', async () => {
		wire.replies.review = ( [ what ] ) => ( {
			[ what ]: [],
			total: 'links' === what ? 41 : 7,
			available: true,
		} );
		const curator = renderPage( true );
		expect(
			await within(
				screen.getByRole( 'tablist', { name: 'Views' } )
			).findByRole( 'tab', { name: 'Review 41' } )
		).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toEqual( [ [ 'links' ] ] );
		curator.unmount();
		Core.reset();
		wire = intelWire();
		wire.replies.health = ( [ what ] ) => HEALTHY[ what ];
		wire.replies.briefing = ( [ what ] ) => WEEK[ what ];
		wire.replies.watches = { watches: [], available: true };
		renderPage();
		await openWeek();
		expect( nav( 'Review' ) ).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toEqual( [] );
	} );

	it( 'labels its view panel by the selected tab, and moves with the keys', async () => {
		renderPage();
		const briefing = nav( 'Briefing' );
		const panel = screen.getByRole( 'tabpanel' );
		expect( briefing ).toHaveAttribute( 'aria-controls', panel.id );
		expect( panel ).toHaveAttribute( 'aria-labelledby', briefing.id );

		fireEvent.keyDown( briefing, { key: 'End' } );
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( nav( 'Watches' ) ).toHaveFocus();
		fireEvent.keyDown( nav( 'Watches' ), { key: 'ArrowRight' } );
		expect( nav( 'Briefing' ) ).toHaveAttribute( 'aria-selected', 'true' );
		fireEvent.keyDown( nav( 'Briefing' ), { key: 'ArrowRight' } );
		expect( nav( 'Search' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
	} );

	it( 'opens a profile in the panel of the view that opened it, and Back focuses that chip', async () => {
		wire.replies.briefing = ( [ what ] ) =>
			'signals' === what
				? {
						clients: [
							{
								entity_key: 'organization:acme-quillsheet',
								name: 'Acme Quillsheet',
								churn_note: null,
								signals: [
									{
										axis: 'about',
										signal: 'spike',
										bucket: '2026-11-02',
										by: 'kind',
										value: 'complaint',
										count: 6,
										usual: { mean: 1.5, low: 0, high: 3.9 },
										trend: [
											{
												bucket: '2026-11-02',
												count: 6,
												in_window: true,
											},
										],
										items: [],
									},
								],
							},
						],
						lenses: [],
						judged: true,
						available: true,
				  }
				: WEEK[ what ];
		renderPage();
		await openWeek();
		const tabs = screen
			.getAllByRole( 'tab' )
			.map( ( tab ) => tab.textContent );
		const chip = attentionChip();
		chip.focus();
		fireEvent.click( chip );
		expect(
			await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toEqual( [
				[ 'organization:acme-quillsheet' ],
			] )
		);
		expect(
			screen.getAllByRole( 'tab' ).map( ( tab ) => tab.textContent )
		).toEqual( tabs );
		expect( nav( 'Briefing' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.queryByRole( 'link', { name: ITEM.title } ) ).toBeNull();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Briefing' } )
		);
		expect(
			screen.queryByRole( 'heading', { name: 'Acme Quillsheet' } )
		).toBeNull();
		expect( chip ).toHaveFocus();
		expect(
			screen.getByRole( 'link', { name: ITEM.title } )
		).toBeVisible();
	} );

	it( 'closes a profile when a tab is picked', async () => {
		renderPage();
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		fireEvent.click( nav( 'Review' ) );
		await screen.findByText( EMPTY_REVIEW );
		expect(
			screen.queryByRole( 'heading', { name: 'Acme Quillsheet' } )
		).toBeNull();
	} );

	it( 'says a refused profile is not available, with the way back', async () => {
		wire.replies.entity = () =>
			new Error( 'no entity: organization:acme-quillsheet' );
		renderPage();
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect(
			await screen.findByText(
				'This profile is not available to you, or it no longer exists.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Briefing' } )
		);
		expect(
			screen.getByRole( 'link', { name: ITEM.title } )
		).toBeVisible();
	} );

	it( 'names the operator in an entity’s churn notice', async () => {
		const now = jest
			.spyOn( Core, 'now' )
			.mockReturnValue( Date.UTC( 2026, 10, 10 ) / 1000 );
		wire.replies.entity = {
			...ENTITY,
			status: {
				...ENTITY.status,
				churn_open: true,
				churn_items: [
					{
						item_key: 'linear:acme-offboard',
						title: 'Acme Quillsheet offboarding checklist',
						url: 'https://tracker.example/acme-offboard',
						source: 'linear',
						day: '2026-10-06',
					},
				],
				churn_note: null,
			},
		};
		renderPage( true );
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect(
			await screen.findByText(
				'“Acme Quillsheet offboarding checklist” (Tracker, 6 Oct) says this client is leaving Quillmere Cooperative.'
			)
		).toBeInTheDocument();
		now.mockRestore();
	} );

	it( 'opens one profile from another, and Back returns to the chip that opened the first', async () => {
		wire.replies.entity = ( [ key ] ) =>
			'person:jo-reporter' === key
				? {
						...ENTITY,
						entity_key: key,
						name: 'Jo Reporter',
						type: 'person',
						roles: [],
						status: null,
				  }
				: ENTITY;
		wire.replies.related = {
			entities: [
				{
					entity_key: 'person:jo-reporter',
					name: 'Jo Reporter',
					type: 'person',
					shared_items: 2,
					items: [],
				},
			],
			available: true,
		};
		wire.replies.briefing = attentionOn;
		renderPage( false, false, {
			...COMPOSITIONS,
			'newspack-intelligence/entity': {
				...COMPOSITIONS[ 'newspack-intelligence/entity' ],
				blocks: [
					{
						name: 'newspack-intelligence/related',
						attributes: { heading: 'Shares coverage', limit: 4 },
						inner: [],
					},
				],
			},
		} );
		await openWeek();
		const chip = attentionChip();
		chip.focus();
		fireEvent.click( chip );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Jo Reporter' } )
		);
		expect(
			await screen.findByRole( 'heading', { name: 'Jo Reporter' } )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Briefing' } )
		);
		expect( chip ).toHaveFocus();
	} );

	it( 'restores the search filters and page after visiting an entity', async () => {
		wire.replies.search = ( args ) => ( {
			items: [
				{
					...ITEM,
					title: `found by ${ args.join( ' ' ) || 'nothing' }`,
				},
			],
			total: 40,
			available: true,
		} );
		renderPage();
		fireEvent.click( nav( 'Search' ) );
		await screen.findByText( 'found by --limit=20' );
		fireEvent.change( screen.getByLabelText( 'Search words' ), {
			target: { value: 'regional prize' },
		} );
		await screen.findByText( 'found by --q=regional prize --limit=20' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await screen.findByText(
			'found by --q=regional prize --limit=20 --offset=20'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Search' } )
		);
		expect(
			await screen.findByText(
				'found by --q=regional prize --limit=20 --offset=20'
			)
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Search words' ) ).toHaveValue(
			'regional prize'
		);
	} );

	it( 'searches through a lens picked for its items, apart from the lens’s groups', async () => {
		renderPage();
		fireEvent.click( nav( 'Search' ) );
		await screen.findByText( '1 item, most important first' );
		fireEvent.click(
			screen
				.getAllByRole( 'button' )
				.find( ( one ) => one.textContent.startsWith( 'Team view' ) )
		);
		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Support desk' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--lens=support-desk',
				'--limit=20',
			] )
		);
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect( screen.queryByRole( 'heading', { level: 4 } ) ).toBeNull();
		fireEvent.click( nav( 'Team views' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk 29 new' } )
		);
		expect(
			await screen.findByRole( 'heading', {
				level: 4,
				name: 'site blocked from sending email',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toContainEqual( [
			'--lens=support-desk',
			'--group=theme',
			'--limit=10',
		] );
	} );

	it( 'reopens the team view it left', async () => {
		renderPage();
		fireEvent.click( nav( 'Team views' ) );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Developer shipping 4 new',
			} )
		);
		await screen.findByRole( 'heading', {
			level: 2,
			name: 'Developer shipping',
		} );
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Team views' ) );
		expect(
			await screen.findByRole( 'heading', {
				level: 2,
				name: 'Developer shipping',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows an operator the words of a refused read, muted', async () => {
		wire.replies.collections = () =>
			new Error( 'collections refused for Harbor Ledger' );
		renderPage( false, true );
		fireEvent.click( nav( 'Folders' ) );
		expect(
			await screen.findByText( 'collections refused for Harbor Ledger' )
		).toHaveClass( 'is-muted' );
		expect(
			screen
				.getByText(
					'Intelligence could not be read just now. Try again.'
				)
				.closest( '[role=status]' )
		).not.toBeNull();
	} );

	it( 'keeps the words of a refused read from a reader', async () => {
		wire.replies.collections = () =>
			new Error( 'collections refused for Harbor Ledger' );
		renderPage( false, false );
		fireEvent.click( nav( 'Folders' ) );
		expect(
			(
				await screen.findByText(
					'Intelligence could not be read just now. Try again.'
				)
			).closest( '[role=status]' )
		).not.toBeNull();
		expect(
			screen.queryByText( 'collections refused for Harbor Ledger' )
		).toBeNull();
	} );

	it( 'switches to Watches for every reader', async () => {
		renderPage( false );
		fireEvent.click( nav( 'Watches' ) );
		expect(
			await screen.findByText(
				'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.'
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: 'New watch' } ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'opens the watch form on a lens from its Watch this', async () => {
		renderPage( false );
		fireEvent.click( nav( 'Team views' ) );
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Developer shipping 4 new',
			} )
		);
		await screen.findByRole( 'heading', {
			level: 2,
			name: 'Developer shipping',
		} );
		await screen.findByText( 'No unusual change last week.' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Watch' } ) );
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const form = screen.getByRole( 'dialog', { name: 'New watch' } );
		expect( within( form ).getByLabelText( 'Name' ) ).toHaveValue(
			'Developer shipping'
		);
		expect(
			within( form ).getByRole( 'radio', { name: 'A team view' } )
		).toBeChecked();
		await waitFor( () =>
			expect(
				within( form ).getByRole( 'combobox', { name: 'Team view' } )
			).toHaveValue( 'Developer shipping' )
		);
		await screen.findByText(
			'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.'
		);
	} );

	it( 'opens the watch a link names, on the Watches view', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&watch=9f3a51c07be2d468'
		);
		wire.replies.watches = LINKED;
		wire.replies.watch_matches = {
			items: [ ITEM ],
			total: 1,
			available: true,
		};
		renderPage();
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const region = await screen.findByRole( 'region', {
			name: 'Quillsheet group',
		} );
		expect(
			await within( region ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [
			[ '--key=9f3a51c07be2d468', '--limit=50' ],
		] );
		expect( wire.sent( 'search' ) ).toEqual( [] );
	} );

	it( 'shows the list for a linked watch the read does not list', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&watch=0d1e2f3a4b5c6d7e'
		);
		wire.replies.watches = LINKED;
		renderPage();
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'button', { name: 'Quillsheet group' } )
		).not.toHaveClass( 'is-active' );
		expect(
			screen.queryByRole( 'region', { name: 'Quillsheet group' } )
		).toBeNull();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [] );
	} );

	it( 'offers a reader no save control and no curation', async () => {
		renderPage( false );
		await openWeek();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect(
			screen.queryByRole( 'button', { name: /save to folder/i } )
		).toBeNull();
	} );

	it( 'offers a curator the exclusion on the Briefing and Search rows, and a reader none', async () => {
		const excludes = () =>
			screen.queryAllByRole( 'button', {
				name: 'Exclude from every view',
			} );
		const { unmount } = renderPage( true );
		await openWeek();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect( excludes() ).toHaveLength( 1 );
		fireEvent.click( nav( 'Search' ) );
		await waitFor( () => expect( excludes() ).toHaveLength( 1 ) );
		unmount();

		window.history.replaceState( {}, '', '/' );
		renderPage( false );
		await openWeek();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect( excludes() ).toHaveLength( 0 );
		fireEvent.click( nav( 'Search' ) );
		await screen.findAllByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect( excludes() ).toHaveLength( 0 );
	} );

	it( 'saves a result through the dialog for a curator', async () => {
		renderPage( true );
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: /save to folder/i } )
		);
		expect(
			await screen.findByRole( 'dialog', { name: 'Save to folder' } )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'renders inside the shared dashboard shell, titled Intelligence', async () => {
		const { container } = renderPage();
		await openWeek();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect( container.firstChild ).toHaveClass(
			'newspack-nodes-theme',
			'newspack-nodes-skin-root',
			'newspack-nodes-ui'
		);
		expect(
			container.querySelector( '.newspack-nodes-page-surface' )
		).not.toBeNull();
		expect(
			container.querySelector( '.topology-subtitle' ).textContent
		).toContain( 'Intelligence' );
		expect(
			container.querySelector( '.topology-header' ).textContent
		).toContain( 'NEWSPACK::NODES' );
		expect(
			container.querySelector(
				'.newspack-nodes-page-content .newspack-intelligence'
			)
		).not.toBeNull();
	} );

	describe( 'the address bar', () => {
		const PAGE = '/wp-admin/admin.php?page=newspack-intelligence';
		const view = () =>
			new URLSearchParams( window.location.search ).get( 'view' );
		const popTo = ( url ) => {
			window.history.replaceState( {}, '', url );
			act( () => {
				window.dispatchEvent( new PopStateEvent( 'popstate' ) );
			} );
		};
		let pushSpy;
		let replaceSpy;

		beforeEach( () => {
			window.history.replaceState( {}, '', PAGE );
			pushSpy = jest.spyOn( window.history, 'pushState' );
			replaceSpy = jest.spyOn( window.history, 'replaceState' );
		} );

		afterEach( () => {
			pushSpy.mockRestore();
			replaceSpy.mockRestore();
		} );

		it( 'pushes one entry naming each tab switched to', async () => {
			renderPage();
			expect( pushSpy ).not.toHaveBeenCalled();
			expect( replaceSpy ).not.toHaveBeenCalled();

			fireEvent.click( nav( 'Review' ) );
			expect( pushSpy ).toHaveBeenCalledTimes( 1 );
			expect( view() ).toBe( 'review' );

			fireEvent.click( nav( 'Review' ) );
			fireEvent.click( nav( 'Folders' ) );
			expect( pushSpy ).toHaveBeenCalledTimes( 2 );
			expect( view() ).toBe( 'collections' );
			expect( replaceSpy ).not.toHaveBeenCalled();
			await screen.findByText(
				'No folders yet. Create one, then use Save on any item to file it here.'
			);
		} );

		it( 'restores the tab of the entry Back or Forward lands on', async () => {
			renderPage();
			fireEvent.click( nav( 'Review' ) );
			fireEvent.click( nav( 'Folders' ) );
			replaceSpy.mockClear();

			popTo( `${ PAGE }&view=review` );
			expect( nav( 'Review' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			await screen.findByText( EMPTY_REVIEW );

			popTo( PAGE );
			expect( nav( 'Briefing' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( pushSpy ).toHaveBeenCalledTimes( 2 );
			expect( replaceSpy ).toHaveBeenCalledTimes( 2 );
			await openWeek();
		} );

		it( 'closes a profile when Back lands on another tab, as picking one does', async () => {
			renderPage();
			await openWeek();
			fireEvent.click(
				await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
			);
			await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );

			popTo( `${ PAGE }&view=review` );

			expect( nav( 'Review' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect(
				screen.queryByRole( 'heading', { name: 'Acme Quillsheet' } )
			).toBeNull();
			expect( await screen.findByText( EMPTY_REVIEW ) ).toBeVisible();
		} );

		it( 'opens the tab a link names', async () => {
			window.history.replaceState( {}, '', `${ PAGE }&view=review` );
			renderPage();
			expect( nav( 'Review' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( view() ).toBe( 'review' );
			expect( pushSpy ).not.toHaveBeenCalled();
			await screen.findByText( EMPTY_REVIEW );
		} );

		it( 'opens the default tab for a view the page does not offer', async () => {
			window.history.replaceState(
				{},
				'',
				`${ PAGE }&view=entity&watch=9f3a51c07be2d468`
			);
			wire.replies.watches = LINKED;
			wire.replies.watch_matches = {
				items: [ ITEM ],
				total: 1,
				available: true,
			};
			renderPage();
			expect( nav( 'Watches' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( view() ).toBeNull();
			expect(
				new URLSearchParams( window.location.search ).get( 'watch' )
			).toBe( '9f3a51c07be2d468' );
			expect( pushSpy ).not.toHaveBeenCalled();
			await screen.findByRole( 'region', { name: 'Quillsheet group' } );
		} );
	} );

	it( 'mounts one debug overlay under its own storage key', async () => {
		renderPage();
		await openWeek();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect( screen.getByTestId( 'debug-overlay' ) ).toBeInTheDocument();
		expect( global.__debugOverlayProps.storageKey ).toBe(
			'newspack-nodes:debug:intelligence'
		);
	} );

	describe( 'layouts', () => {
		const LENS = 'newspack-intelligence/lens';
		const editor = { done: null, dirty: null };

		/**
		 * The editor a curator's bundle registers, standing in for it: clean
		 * as it mounts, as the shared editor tells its page.
		 *
		 * @param {Object}   props
		 * @param {Object}   props.target  The page and its subject.
		 * @param {Function} props.onDone  Hands the page its next layout.
		 * @param {Function} props.onDirty Tells the page whether a change stands.
		 * @return {import('react').ReactElement} The stand-in.
		 */
		function StandInEditor( { target, onDone, onDirty } ) {
			editor.done = onDone;
			editor.dirty = onDirty;
			useEffect( () => onDirty( false ), [ onDirty ] );
			return <p>{ `Editing the layout of ${ target.lens_key }` }</p>;
		}

		beforeEach( () => {
			window.__newspackNodesCompositionEditors = undefined;
			registerCompositionEditor( LENS, StandInEditor );
		} );

		/** Open the page on a team view's layout edit. */
		const editLens = () =>
			window.history.replaceState(
				{},
				'',
				'/wp-admin/admin.php?page=newspack-intelligence&view=lenses&edit=lens&lens=support-desk'
			);

		it( 'offers Edit layout on a composed page for a curator alone', async () => {
			const curator = renderPage( true );
			await openWeek();
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Briefing actions' } )
			);
			expect(
				await screen.findByRole( 'menuitem', { name: 'Edit layout' } )
			).toBeInTheDocument();
			fireEvent.click( nav( 'Review' ) );
			await screen.findByText( EMPTY_REVIEW );
			expect(
				screen.queryByRole( 'button', {
					name: /^(Briefing|Team view|Profile) actions$/,
				} )
			).toBeNull();
			expect(
				screen.queryByRole( 'menuitem', { name: 'Edit layout' } )
			).toBeNull();
			curator.unmount();
			window.history.replaceState( {}, '', '/' );

			renderPage();
			await openWeek();
			expect(
				screen.queryByRole( 'button', { name: 'Briefing actions' } )
			).toBeNull();
		} );

		it( 'puts the editor in the layout region and keeps the tab bar', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );

			expect(
				screen.getByText( 'Editing the layout of support-desk' )
			).toBeInTheDocument();
			expect( nav( 'Team views' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
			expect( screen.queryByText( 'Support desk' ) ).toBeNull();
		} );

		/** The editor's text, or null once the edit has ended. */
		const editorText = () =>
			screen.queryByText( 'Editing the layout of support-desk' );

		/**
		 * The question asked before an edit ends, answered.
		 *
		 * @param {string} answer The button it is answered with.
		 */
		const answerDiscard = ( answer ) =>
			fireEvent.click(
				within(
					screen.getByRole( 'dialog', {
						name: 'Discard your changes to this layout?',
					} )
				).getByRole( 'button', { name: answer } )
			);

		/** Change the layout in the stand-in editor. */
		const change = () => act( () => editor.dirty( true ) );

		it( 'asks before a tab change ends a changed edit, and keeps editing on Keep editing', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );
			change();

			fireEvent.click( nav( 'Briefing' ) );
			expect(
				screen.getByRole( 'button', { name: 'Keep editing' } )
			).toHaveFocus();
			answerDiscard( 'Keep editing' );
			expect( editorText() ).toBeInTheDocument();
			expect( nav( 'Team views' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);

			fireEvent.click( nav( 'Briefing' ) );
			answerDiscard( 'Discard changes' );
			expect( editorText() ).toBeNull();
			await openWeek();
			expect(
				new URLSearchParams( window.location.search ).get( 'edit' )
			).toBeNull();

			fireEvent.click( nav( 'Team views' ) );
			expect(
				await screen.findAllByText( 'Support desk' )
			).not.toHaveLength( 0 );
			expect( editorText() ).toBeNull();
		} );

		it( 'leaves an unchanged edit on a tab change without asking', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );

			fireEvent.click( nav( 'Review 0' ) );

			expect(
				screen.queryByRole( 'dialog', {
					name: 'Discard your changes to this layout?',
				} )
			).toBeNull();
			expect( editorText() ).toBeNull();
			expect( await screen.findByText( EMPTY_REVIEW ) ).toBeVisible();
			expect(
				new URLSearchParams( window.location.search ).get( 'edit' )
			).toBeNull();
		} );

		it( 'leaves an unchanged edit on Back without asking', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );

			window.history.replaceState(
				{},
				'',
				'/wp-admin/admin.php?page=newspack-intelligence&view=review'
			);
			act( () => {
				window.dispatchEvent( new PopStateEvent( 'popstate' ) );
			} );

			expect(
				screen.queryByRole( 'dialog', {
					name: 'Discard your changes to this layout?',
				} )
			).toBeNull();
			expect( editorText() ).toBeNull();
			expect( await screen.findByText( EMPTY_REVIEW ) ).toBeVisible();
			expect( nav( 'Review 0' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
		} );

		it( 'asks before Back ends a changed edit, the editor standing until it is answered', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );
			change();

			window.history.replaceState(
				{},
				'',
				'/wp-admin/admin.php?page=newspack-intelligence&view=review'
			);
			act( () => {
				window.dispatchEvent( new PopStateEvent( 'popstate' ) );
			} );
			expect( editorText() ).toBeInTheDocument();
			answerDiscard( 'Keep editing' );
			expect( editorText() ).toBeInTheDocument();
			expect( nav( 'Team views' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);

			window.history.replaceState(
				{},
				'',
				'/wp-admin/admin.php?page=newspack-intelligence&view=review'
			);
			act( () => {
				window.dispatchEvent( new PopStateEvent( 'popstate' ) );
			} );
			answerDiscard( 'Discard changes' );
			expect( editorText() ).toBeNull();
			expect( await screen.findByText( EMPTY_REVIEW ) ).toBeVisible();
			expect( nav( 'Review' ) ).toHaveAttribute(
				'aria-selected',
				'true'
			);
		} );

		it( 'restores a refused layout’s default for a curator, without a reload', async () => {
			wire.replies.restore =
				COMPOSITIONS[ 'newspack-intelligence/briefing' ];
			renderPage( true, false, {
				...COMPOSITIONS,
				'newspack-intelligence/briefing': {
					context: 'newspack-intelligence/briefing',
					error: 'composition newspack-intelligence/briefing: newspack-intelligence/retired is not a declared block',
				},
			} );
			fireEvent.click(
				await screen.findByRole( 'button', { name: 'Restore default' } )
			);
			fireEvent.click(
				within(
					screen.getByRole( 'dialog', {
						name: 'Restore the default layout?',
					} )
				).getByRole( 'button', { name: 'Restore default' } )
			);
			expect( await openWeek() ).toBeInTheDocument();
			expect(
				screen.queryByText( 'Intelligence could not be read just now.' )
			).toBeNull();
		} );

		it( 'ignores a reader’s edit', async () => {
			editLens();
			renderPage();

			expect(
				screen.queryByText( 'Editing the layout of support-desk' )
			).toBeNull();
			expect(
				await screen.findAllByText( 'Support desk' )
			).not.toHaveLength( 0 );
		} );

		it( 'renders the tree a save hands back without a reload', async () => {
			editLens();
			renderPage( true );
			await screen.findByRole( 'tab', { name: 'Review 0' } );
			expect( screen.queryByRole( 'complementary' ) ).toBeNull();

			act( () =>
				editor.done(
					{
						context: LENS,
						source: 'saved',
						blocks: [
							{
								name: 'newspack-intelligence/aside',
								attributes: {},
								inner: [],
							},
						],
						saved_ts: 1912345678.5,
						actor: 'lakeside-editor',
					},
					'save'
				)
			);

			expect(
				screen.queryByText( 'Editing the layout of support-desk' )
			).toBeNull();
			expect( await screen.findByRole( 'complementary' ) ).toHaveClass(
				'newspack-intelligence__aside'
			);
			expect(
				document.querySelector( '.components-snackbar' )
			).toHaveTextContent( 'Layout saved.' );
			const query = new URLSearchParams( window.location.search );
			expect( [ query.get( 'edit' ), query.get( 'lens' ) ] ).toEqual( [
				null,
				null,
			] );
			expect(
				await screen.findAllByText( 'Support desk' )
			).not.toHaveLength( 0 );
		} );

		it( 'shows a layout that no longer reads as the read error in its place', async () => {
			renderPage( false, true, {
				...COMPOSITIONS,
				'newspack-intelligence/briefing': {
					context: 'newspack-intelligence/briefing',
					error: 'composition newspack-intelligence/briefing: newspack-intelligence/retired is not a declared block',
				},
			} );
			expect(
				await screen.findByText(
					'composition newspack-intelligence/briefing: newspack-intelligence/retired is not a declared block'
				)
			).toBeInTheDocument();
			expect( wire.sent( 'briefing' ) ).toEqual( [] );
		} );
	} );
} );
