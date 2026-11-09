/* eslint-env jest */
/**
 * IntelligencePage opens on the Briefing, switches among its seven views in
 * place, opens an entity from any chip, saves an item through one dialog only for a curator, and
 * carries the substrate debug overlay under its own storage key.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
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
import {
	ENTITY,
	GROUPS,
	ITEM,
	LENSES,
	VOCABULARY,
} from '../views/__tests__/fixtures';
import IntelligencePage from '../IntelligencePage';

let wire;

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.sessionStorage.clear();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.search = ( args ) =>
		args.includes( '--group=theme' )
			? GROUPS
			: { items: [ ITEM ], total: 1, available: true };
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [], total: 0, available: true };
	wire.replies.related = { entities: [], available: true };
	wire.replies.claims = { claims: [], available: true };
	wire.replies.review = ( [ what ] ) => ( { [ what ]: [], available: true } );
	wire.replies.collections = { collections: [], available: true };
	wire.replies.lenses = LENSES;
	wire.replies.trends = { rows: [], available: true };
	wire.replies.signals = {
		signals: [],
		trend: [ { bucket: '2026-10-12', count: 3 } ],
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
		groups: [ { entity_key: null, name: null, items: [ ITEM ] } ],
		total: 1,
		summary: {
			since: 1792000000,
			items: 6,
			clients: 4,
			kinds: [ { kind: 'churned', items: 5, clients: 3 } ],
		},
		available: true,
	},
	signals: { clients: [], lenses: [], available: true },
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

function renderPage( canTune = false, canManage = false ) {
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
		/>
	);
}

/** Open the Briefing's one group of the week, collapsed to its count. */
const openWeek = async () =>
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Industry 1 item' } )
	);

const nav = ( name ) =>
	within( screen.getByRole( 'tablist', { name: 'Views' } ) ).getByRole(
		'tab',
		{ name }
	);

describe( 'IntelligencePage', () => {
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
				[ '--kind=churned', '--since=2026-10-14' ],
			] )
		);
		expect( screen.getByLabelText( 'Since' ) ).toHaveValue( '2026-10-14' );
	} );

	it( 'opens a watch the Briefing lists, on its matches', async () => {
		wire.replies.watches = LINKED;
		wire.replies.watch_matches = {
			items: [ ITEM ],
			total: 1,
			available: true,
		};
		renderPage();
		await screen.findByText(
			'Entity · organization:acme-quillsheet · 5 unseen'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Quillsheet group' } )
		);
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'region', { name: 'Quillsheet group' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'watch_matches' ) ).toEqual( [
				[ '--key=9f3a51c07be2d468' ],
			] )
		);
	} );

	it( 'opens the watch form from the Briefing’s empty watches', async () => {
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'New watch' } )
		);
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const form = screen.getByRole( 'form', { name: 'Save watch' } );
		expect( within( form ).getByLabelText( 'Follows' ) ).toHaveValue(
			'lens'
		);
		await within( form ).findByRole( 'option', {
			name: 'Developer shipping',
		} );
		await screen.findByText( 'No watch yet.' );
	} );

	it( 'opens a lens a Briefing signal names, and Search from its invitation', async () => {
		wire.replies.briefing = ( [ what ] ) =>
			'signals' === what
				? {
						clients: [],
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
										baseline: 1.5,
										items: [],
									},
								],
							},
						],
						available: true,
				  }
				: {
						...WEEK,
						changes: {
							groups: [],
							total: 0,
							summary: {
								since: 1792000000,
								items: 0,
								clients: 0,
								kinds: [],
							},
							available: true,
						},
				  }[ what ];
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk' } )
		);
		expect( nav( 'Lenses' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'region', { name: 'Support desk' } )
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

	it( 'mounts the strip above the tab bar on every tab', async () => {
		renderPage();
		const healthy = `Collecting from ${ HEALTHY.sources.sources.length } sources. Nothing waiting.`;
		await screen.findByText( healthy );
		const strip = screen.getByRole( 'region', { name: 'Pipeline health' } );
		const tabs = screen.getByRole( 'tablist', { name: 'Views' } );
		expect(
			strip.compareDocumentPosition( tabs ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		const views = [
			[ 'Review', 'No proposed link awaits review.' ],
			[ 'Collections', 'No folder yet.' ],
			[ 'Watches', 'No watch yet.' ],
		];
		for ( const [ name, shown ] of views ) {
			fireEvent.click( nav( name ) );
			await screen.findByText( shown );
			expect(
				screen.getByRole( 'region', { name: 'Pipeline health' } )
			).toHaveTextContent( healthy );
		}
		expect(
			wire.sent( 'health' ).filter( ( [ what ] ) => 'backlog' === what )
		).toHaveLength( 1 );
	} );

	it( 'keeps an ask in flight across a switch from the Briefing to Search', async () => {
		wire.replies.ask = { ask_key: '4c2e6a8b0d1f3957' };
		wire.replies.ask_result = {
			ask_key: '4c2e6a8b0d1f3957',
			state: 'answered',
			reason: '',
			found: 1,
			dropped: 0,
			sentences: [ { text: 'Quillsheet won a prize.', cites: [ 1 ] } ],
			items: [ ITEM ],
			searches: [],
			wait_s: 900,
			available: true,
		};
		renderPage();
		fireEvent.change(
			await screen.findByPlaceholderText(
				'Ask about clients, the industry or Quillmere Cooperative…'
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
		expect( wire.sent( 'ask' ) ).toEqual( [
			[ '--question=Who won a prize?' ],
		] );
	} );

	it( 'switches to Review and Collections in place', async () => {
		renderPage();
		fireEvent.click( nav( 'Review' ) );
		expect(
			await screen.findByText( 'No proposed link awaits review.' )
		).toBeInTheDocument();
		expect( nav( 'Review' ) ).toHaveAttribute( 'aria-selected', 'true' );
		fireEvent.click( nav( 'Collections' ) );
		expect(
			await screen.findByText( 'No folder yet.' )
		).toBeInTheDocument();
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

	it( 'opens an entity from a result chip and returns to it from the nav', async () => {
		renderPage();
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect(
			await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toEqual( [
				[ 'organization:acme-quillsheet' ],
			] )
		);
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Entity: Acme Quillsheet' ) );
		expect(
			await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
	} );

	it( 'labels the Entity tab by the key until the record names it', async () => {
		wire.replies.entity = () =>
			new Error( 'no entity: organization:acme-quillsheet' );
		renderPage();
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await screen.findByText(
			'Intelligence could not be read just now. Try again.'
		);
		expect( nav( 'Entity: organization:acme-quillsheet' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
	} );

	it( 'labels the Entity tab by the key again once a curator’s edit leaves the record refused', async () => {
		let asked = 0;
		wire.replies.entity = () =>
			1 === ++asked
				? ENTITY
				: new Error( 'no entity: organization:acme-quillsheet' );
		wire.replies.add_name = { ok: true, event_id: 'ev-merged' };
		renderPage( true );
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await waitFor( () =>
			expect( nav( 'Entity: Acme Quillsheet' ) ).toBeInTheDocument()
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Edit' } ) );
		const form = screen.getByRole( 'group', { name: 'Add name' } );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'Quillsheet' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add name' } )
		);
		await screen.findByText(
			'Intelligence could not be read just now. Try again.'
		);
		expect( nav( 'Entity: organization:acme-quillsheet' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
	} );

	it( 'names the operator in an entity’s churn notice', async () => {
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
			},
		};
		renderPage( true );
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect(
			await screen.findByText(
				'“Acme Quillsheet offboarding checklist” (linear, 2026-10-06) says this client is leaving Quillmere Cooperative.'
			)
		).toBeInTheDocument();
	} );

	it( 'labels the Entity tab by the entity open now, not the one before', async () => {
		wire.replies.entity = ( [ key ] ) =>
			'person:jo-reporter' === key
				? {
						...ENTITY,
						entity_key: key,
						name: 'Jo Reporter',
						type: 'person',
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
		renderPage();
		await openWeek();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await waitFor( () =>
			expect( nav( 'Entity: Acme Quillsheet' ) ).toBeInTheDocument()
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Jo Reporter' } )
		);
		expect( nav( 'Entity: person:jo-reporter' ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		await screen.findByRole( 'heading', { name: 'Jo Reporter' } );
		await waitFor( () =>
			expect( nav( 'Entity: Jo Reporter' ) ).toBeInTheDocument()
		);
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
		await screen.findByText( 'found by nothing' );
		const form = screen.getByRole( 'search', { name: 'Filters' } );
		fireEvent.change( within( form ).getByLabelText( 'Words' ), {
			target: { value: 'regional prize' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Search' } )
		);
		await screen.findByText( 'found by --q=regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await screen.findByText( 'found by --q=regional prize --offset=1' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		fireEvent.click( nav( 'Search' ) );
		expect(
			await screen.findByText( 'found by --q=regional prize --offset=1' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Words' ) ).toHaveValue(
			'regional prize'
		);
	} );

	it( 'searches through a lens picked for its items, apart from the lens’s groups', async () => {
		renderPage();
		fireEvent.click( nav( 'Search' ) );
		const form = screen.getByRole( 'search', { name: 'Filters' } );
		await within( form ).findByRole( 'option', { name: 'Support desk' } );
		fireEvent.change( within( form ).getByLabelText( 'Lens' ), {
			target: { value: 'support-desk' },
		} );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--lens=support-desk',
			] )
		);
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect( screen.queryByRole( 'heading', { level: 4 } ) ).toBeNull();
		fireEvent.click( nav( 'Lenses' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk' } )
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
		] );
	} );

	it( 'reopens the lens it left, on the page it left', async () => {
		wire.replies.search = ( args ) => ( {
			groups: [
				{
					...GROUPS.groups[ 0 ],
					theme: `seen by ${ args.join( ' ' ) || 'nothing' }`,
				},
			],
			total: 30,
			available: true,
		} );
		renderPage();
		fireEvent.click( nav( 'Lenses' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk' } )
		);
		await screen.findByText( 'seen by --lens=support-desk --group=theme' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await screen.findByText(
			'seen by --lens=support-desk --group=theme --offset=1'
		);
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Lenses' ) );
		expect(
			await screen.findByText(
				'seen by --lens=support-desk --group=theme --offset=1'
			)
		).toBeInTheDocument();
	} );

	it( 'shows an operator the words of a refused read, muted', async () => {
		wire.replies.collections = () =>
			new Error( 'collections refused for Harbor Ledger' );
		renderPage( false, true );
		fireEvent.click( nav( 'Collections' ) );
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
		fireEvent.click( nav( 'Collections' ) );
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
			await screen.findByText( 'No watch yet.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'New watch' } )
		).toBeInTheDocument();
	} );

	it( 'opens the watch form on a lens from its Watch this', async () => {
		renderPage( false );
		fireEvent.click( nav( 'Lenses' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Developer shipping' } )
		);
		await screen.findByText( 'Steady' );
		await screen.findByText( 'No trend is seen through this lens.' );
		await screen.findByText( 'Acme Quillsheet wins regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Watch this' } ) );
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const form = screen.getByRole( 'form', { name: 'Save watch' } );
		expect( within( form ).getByLabelText( 'Name' ) ).toHaveValue(
			'Developer shipping'
		);
		expect( within( form ).getByLabelText( 'Follows' ) ).toHaveValue(
			'lens'
		);
		await within( form ).findByRole( 'option', {
			name: 'Developer shipping',
		} );
		expect( within( form ).getByLabelText( 'Lens' ) ).toHaveValue(
			'dev-shipping'
		);
		await screen.findByText( 'No watch yet.' );
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
			[ '--key=9f3a51c07be2d468' ],
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

	it( 'reopens the collection it left', async () => {
		wire.replies.collections = ( args ) =>
			0 === args.length
				? {
						collections: [
							{
								collection_key: 'q4-client-wins',
								name: 'Q4 client wins',
								created_ts: 1790035200,
								items: 1,
							},
						],
						available: true,
				  }
				: {
						collection_key: 'q4-client-wins',
						name: 'Q4 client wins',
						created_ts: 1790035200,
						items: [],
						available: true,
				  };
		renderPage();
		fireEvent.click( nav( 'Collections' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		await screen.findByRole( 'region', { name: 'Q4 client wins' } );
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Collections' ) );
		expect(
			await screen.findByRole( 'region', { name: 'Q4 client wins' } )
		).toBeInTheDocument();
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
		expect( screen.getByRole( 'heading', { level: 1 } ).textContent ).toBe(
			'Intelligence'
		);
		expect( container.querySelector( '.topology-subtitle' ) ).toBeNull();
		const header =
			container.querySelector( '.topology-header' ).textContent;
		expect( header ).not.toMatch( /\d+\.\d+\.\d+/ );
		expect( header ).not.toContain( window.location.hostname );
		expect(
			container.querySelector(
				'.newspack-nodes-page-content .newspack-intelligence'
			)
		).not.toBeNull();
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
} );
