/* eslint-env jest */
/**
 * Entity is a profile: a fixed header — the back link, the name, the type
 * line, Watch, Edit and ⋯, and the churn notice with the client's
 * churn-risk note — over the page's profile
 * layout, whose blocks share the header's one `entity` read. A curator ends
 * a client through its dialog, says a report is not about it with Undo, and
 * keeps its churn-risk note.
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
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, pageData, VOCABULARY } from './fixtures';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { useIntelRead } from '../../hooks/useIntelCommand';
import Entity from '../Entity';

/** Each address Edit layout would load, in place of the navigation jsdom refuses. */
const mockLoads = [];
jest.mock( '../../layout/LayoutMenu', () => {
	const { editSearch } = jest.requireActual( '../../layout/editMode' );
	const { editLayoutControl } = jest.requireActual(
		'../../layout/LayoutMenu'
	);
	return {
		editLayoutControl: ( target ) => ( {
			...editLayoutControl( target ),
			onClick: () =>
				mockLoads.push(
					editSearch( globalThis.location.search, target )
				),
		} ),
	};
} );

/**
 * A read whose rendering says whether its answer is owed, remembered or
 * read, so a test sees what the cache holds for it.
 *
 * @param {Object}   props
 * @param {string}   props.scope   The read's scope.
 * @param {string}   props.command The read verb.
 * @param {string[]} props.args    Its tokens.
 * @return {import('react').ReactElement} Its state.
 */
function Probe( { scope, command, args } ) {
	const { loading, stale } = useIntelRead( scope, command, args );
	if ( loading ) {
		return <p>{ `${ command } owed` }</p>;
	}
	return <p>{ `${ command } ${ stale ? 'remembered' : 'read' }` }</p>;
}

/** A reply that never comes, so a read stays owed. */
const never = () => new Promise( () => {} );

const KEY = 'organization:harbor-ledger';

/** 10 Nov 2026, UTC. */
const TODAY = Date.UTC( 2026, 10, 10 ) / 1000;

/**
 * A UTC instant of a day.
 *
 * @param {number} year  The year.
 * @param {number} month The month, 1 to 12.
 * @param {number} day   The day of the month.
 * @return {number} Seconds since the epoch.
 */
const at = ( year, month, day ) => Date.UTC( year, month - 1, day ) / 1000;

const RECORD = {
	entity_key: KEY,
	type: 'organization',
	name: 'Harbor Ledger',
	state: 'proposed',
	merged_from: [ 'organization:harbor-ledger-co' ],
	names: [ { kind: 'alias', value: 'The Ledger' } ],
	identifiers: [ { kind: 'domain', value: 'harborledger.example' } ],
	roles: [ { role: 'client', from_ts: at( 2020, 5, 12 ), to_ts: 0 } ],
	topics: [],
	counts: { items: 31, proposed_links: 6 },
	published: { count: 0 },
	status: {
		client_since: at( 2020, 5, 12 ),
		kinds: {
			churned: 1,
			churn_signal: 2,
			feature_request: 3,
			complaint: 1,
		},
		last_item_ts: at( 2026, 11, 6 ),
		churn_open: false,
		churn_items: [],
		churn_note: null,
	},
	available: true,
};

const REPORT = {
	item_key: 'linear:harbor-offboard',
	title: 'Harbor Ledger offboarding checklist',
	url: 'https://tracker.example/harbor-offboard',
	source: 'linear',
	day: '2026-11-06',
};

const CHURNING = {
	...RECORD,
	status: { ...RECORD.status, churn_open: true, churn_items: [ REPORT ] },
};

const NOTE = {
	text: 'Budget cut at the paper',
	owner: 'Dana Reyes',
	next_step: 'renewal call',
	day: '2026-11-20',
	by: 'user:7',
};

/** The default profile layout, as the page data answers it. */
const DEFAULT_LAYOUT = {
	context: 'newspack-intelligence/entity',
	source: 'default',
	blocks: [
		{
			name: 'newspack-intelligence/pulse',
			attributes: { lens: '' },
			inner: [],
		},
		{
			name: 'newspack-intelligence/entity-facts',
			attributes: { show: [ 'counts' ], limit: 10 },
			inner: [],
		},
		{
			name: 'newspack-intelligence/timeline',
			attributes: {
				heading: 'Activity',
				published: 'disclosure',
				layout: 'activity',
				limit: 20,
			},
			inner: [],
		},
		{
			name: 'newspack-intelligence/aside',
			attributes: {},
			inner: [
				{
					name: 'newspack-intelligence/related',
					attributes: { heading: 'Related', limit: 5 },
					inner: [],
				},
				{
					name: 'newspack-intelligence/entity-facts',
					attributes: { show: [ 'claims', 'details' ], limit: 10 },
					inner: [],
				},
			],
		},
	],
	saved_ts: null,
	actor: null,
};

const EMPTY_LAYOUT = { ...DEFAULT_LAYOUT, source: 'saved', blocks: [] };

let wire;
let viewport;
let props;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData( {
		roleWords: {
			...VOCABULARY.roleWords,
			client: 'Client',
			former_client: 'Former client',
		},
		sourceWords: { ...VOCABULARY.sourceWords, linear: 'Linear' },
	} );
	jest.spyOn( Core, 'now' ).mockReturnValue( TODAY );
	viewport = installViewport( false );
	wire = intelWire();
	wire.replies.entity = RECORD;
	wire.replies.timeline = { items: [ ITEM ], total: 1, available: true };
	wire.replies.related = {
		entities: [
			{
				entity_key: 'organization:prairie-signal',
				name: 'Prairie Signal',
				type: 'organization',
				shared_items: 4,
				items: [],
			},
		],
		available: true,
	};
	wire.replies.signals = {
		signals: [],
		trend: [ { bucket: '2026-11-02', count: 4, in_window: true } ],
		partial: { bucket: '2026-11-09', count: 2 },
		judged: true,
		bucket: 'week',
		baseline_buckets: 8,
		available: true,
	};
	wire.replies.claims = { claims: [], available: true };
	props = {
		onBack: jest.fn(),
		onWatch: jest.fn(),
		onOpenEntity: jest.fn(),
	};
} );

afterEach( () => {
	viewport.restore();
	jest.restoreAllMocks();
} );

function renderEntity( over = {} ) {
	const canTune = over.canTune ?? false;
	return renderOnPage(
		<Entity
			entityKey={ KEY }
			canTune={ canTune }
			vocabulary={ VOCABULARY }
			operator={ { name: '', heading: '' } }
			layout={ DEFAULT_LAYOUT }
			back={ { label: 'Briefing', onBack: props.onBack } }
			onWatch={ props.onWatch }
			actions={ {
				onOpenEntity: props.onOpenEntity,
				canTune,
			} }
			{ ...over }
		/>
	);
}

/** The profile's header, once the record answers. */
const header = async () =>
	( await screen.findByRole( 'heading', { name: 'Harbor Ledger' } ) ).closest(
		'header'
	);

describe( 'Entity — header', () => {
	it( 'names the profile in the shared header, Watch and the curator’s menu at its end', async () => {
		renderEntity( { canTune: true } );
		const title = await screen.findByRole( 'heading', {
			level: 2,
			name: 'Harbor Ledger',
		} );
		const row = title.closest(
			'.newspack-intelligence__section-head.newspack-nodes-section-heading'
		);
		expect( row ).not.toBeNull();
		expect(
			within( row ).getByRole( 'button', { name: 'Watch' } )
		).toHaveClass( 'button' );
		expect(
			within( row ).getByRole( 'button', { name: 'Profile actions' } )
		).toBeInTheDocument();
		expect( title ).toHaveAttribute( 'tabindex', '-1' );
	} );

	it( 'reads the client’s start year, and holds no key, state or proposed links', async () => {
		renderEntity( { canTune: true } );
		const head = await header();
		expect(
			within( head ).getByText( 'Client since 2020' )
		).toBeInTheDocument();
		expect(
			within( head ).getByText( 'Web address harborledger.example' )
		).toBeInTheDocument();
		expect( head ).not.toHaveTextContent( KEY );
		expect( head ).not.toHaveTextContent( /Suggested|proposed|6/i );
	} );

	it( 'reads a former client’s years', async () => {
		wire.replies.entity = {
			...RECORD,
			roles: [
				{
					role: 'client',
					from_ts: at( 2019, 3, 2 ),
					to_ts: at( 2022, 1, 9 ),
				},
				{
					role: 'client',
					from_ts: at( 2023, 2, 1 ),
					to_ts: at( 2024, 8, 30 ),
				},
				{ role: 'former_client', from_ts: at( 2024, 8, 30 ), to_ts: 0 },
			],
			status: null,
		};
		renderEntity();
		expect(
			within( await header() ).getByText( 'Former client, 2019 to 2024' )
		).toBeInTheDocument();
	} );

	it( 'reads another entity by its type’s word', async () => {
		wire.replies.entity = {
			...RECORD,
			type: 'person',
			roles: [],
			identifiers: [],
			status: null,
		};
		renderEntity();
		expect(
			within( await header() ).getByText( 'Human' )
		).toBeInTheDocument();
	} );

	it( 'returns to the view that opened it', async () => {
		renderEntity();
		await header();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Briefing' } )
		);
		expect( props.onBack ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'opens a watch on the entity', async () => {
		renderEntity();
		fireEvent.click(
			within( await header() ).getByRole( 'button', { name: 'Watch' } )
		);
		expect( props.onWatch ).toHaveBeenCalledWith(
			{ kind: 'entity', key: KEY },
			'Harbor Ledger'
		);
	} );

	it( 'offers a curator Edit layout in its ⋯ menu, and a reader no menu', async () => {
		const reader = renderEntity();
		await header();
		expect(
			screen.queryByRole( 'button', { name: 'Profile actions' } )
		).toBeNull();
		reader.unmount();

		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Profile actions',
			} )
		);
		expect(
			await screen.findByRole( 'menuitem', { name: 'Edit layout' } )
		).toBeInTheDocument();
	} );

	it( 'loads the layout editor on this profile, over the view it stands on, from its Edit layout', async () => {
		mockLoads.length = 0;
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&view=watches'
		);
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Profile actions',
			} )
		);
		fireEvent.click(
			await screen.findByRole( 'menuitem', { name: 'Edit layout' } )
		);
		expect( mockLoads ).toEqual( [
			`?page=newspack-intelligence&view=watches&edit=entity&entity=${ encodeURIComponent(
				KEY
			) }`,
		] );
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'says a refused profile is not available, with the way back', async () => {
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity();
		expect(
			await screen.findByText(
				'This profile is not available to you, or it no longer exists.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Back to Briefing' } )
		);
		expect( props.onBack ).toHaveBeenCalledTimes( 1 );
		expect(
			screen.queryByRole( 'heading', { name: 'Activity' } )
		).toBeNull();
	} );
} );

describe( 'Entity — layout', () => {
	it( 'lays out the pulse, the counts and the activity, then Related and Facts aside, sharing one entity read', async () => {
		renderEntity( { canTune: true } );
		await header();
		const pulse = await screen.findByText( 'No unusual change last week.' );
		const counts = await screen.findByText( '3 feature requests' );
		const activity = screen.getByRole( 'heading', { name: 'Activity' } );
		const related = screen.getByRole( 'heading', { name: 'Related' } );
		const facts = screen.getByRole( 'heading', { name: 'Facts' } );
		const following = ( a, b ) =>
			a.compareDocumentPosition( b ) &
			window.Node.DOCUMENT_POSITION_FOLLOWING;

		expect( following( pulse, counts ) ).toBeTruthy();
		expect( following( counts, activity ) ).toBeTruthy();
		expect( following( activity, related ) ).toBeTruthy();
		expect( following( related, facts ) ).toBeTruthy();
		const aside = screen.getByRole( 'complementary' );
		expect( aside ).toContainElement( related );
		expect( aside ).toContainElement( facts );
		expect( aside ).not.toContainElement( activity );
		expect( wire.sent( 'entity' ) ).toEqual( [ [ KEY ] ] );
	} );

	it( 'offers no jump links to its headings', async () => {
		renderEntity();
		await header();
		await screen.findByRole( 'heading', { name: 'Facts' } );
		expect( screen.queryByRole( 'navigation' ) ).toBeNull();
	} );

	it( 'keeps the churn notice when its layout holds no block', async () => {
		wire.replies.entity = CHURNING;
		renderEntity( { layout: EMPTY_LAYOUT } );
		expect(
			await screen.findByText( 'Possible churn' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'navigation', { name: 'On this profile' } )
		).toBeNull();
	} );
} );

describe( 'Entity — churn', () => {
	const notice = async () =>
		( await screen.findByText( 'Possible churn' ) ).closest(
			'.components-notice'
		);
	const dialog = () =>
		screen.getByRole( 'dialog', {
			name: 'End Harbor Ledger as a client?',
		} );
	const openDialog = async () => {
		fireEvent.click(
			within( await notice() ).getByRole( 'button', {
				name: 'End client…',
			} )
		);
		return dialog();
	};
	const endFromDialog = async () =>
		fireEvent.click(
			within( await openDialog() ).getByRole( 'button', {
				name: 'End client',
			} )
		);
	const snackbar = () => document.querySelector( '.components-snackbar' );
	// Long enough for a write the click sent to reach the wire.
	const pause = () =>
		act( () => new Promise( ( resolve ) => setTimeout( resolve, 300 ) ) );

	it( 'names each churn report, leaving the operator out when there is none', async () => {
		wire.replies.entity = CHURNING;
		renderEntity( { canTune: true } );
		const shown = await notice();
		expect( shown ).toHaveClass( 'is-warning' );
		expect( shown ).toHaveTextContent(
			'“Harbor Ledger offboarding checklist” (Linear, 6 Nov) says this client is leaving.'
		);
		expect(
			within( shown ).getByRole( 'link', { name: 'Open item' } )
		).toHaveAttribute( 'href', REPORT.url );
		expect(
			within( shown ).getByRole( 'button', {
				name: 'Not about this client',
			} )
		).toBeInTheDocument();
	} );

	it( 'names the operator the client is leaving when one is set', async () => {
		wire.replies.entity = CHURNING;
		renderEntity( {
			canTune: true,
			operator: { name: 'Lakeside Works', heading: 'Lakeside Works' },
		} );
		expect( await notice() ).toHaveTextContent(
			'“Harbor Ledger offboarding checklist” (Linear, 6 Nov) says this client is leaving Lakeside Works.'
		);
	} );

	it( 'shows a reader the notice with Open item alone', async () => {
		wire.replies.entity = CHURNING;
		renderEntity();
		const shown = await notice();
		expect(
			within( shown ).getByRole( 'link', { name: 'Open item' } )
		).toBeInTheDocument();
		expect( within( shown ).queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'renders no notice without churn_open', async () => {
		wire.replies.entity = {
			...RECORD,
			status: { ...RECORD.status, churn_items: [ REPORT ] },
		};
		renderEntity( { canTune: true } );
		await header();
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
	} );

	it( 'sends nothing when End client… is pressed', async () => {
		wire.replies.entity = CHURNING;
		renderEntity( { canTune: true } );
		const asked = await openDialog();
		expect( asked ).toHaveTextContent(
			'“Harbor Ledger offboarding checklist”, Linear, 6 Nov'
		);
		await pause();
		expect( wire.sent( 'end_role' ) ).toEqual( [] );
		expect( wire.sent( 'assert_role' ) ).toEqual( [] );
	} );

	it( 'ends the client from its dialog: end_role, then assert_role former_client', async () => {
		wire.replies.entity = CHURNING;
		wire.replies.end_role = { ok: true, event_id: 'ev-end-client' };
		wire.replies.assert_role = { ok: true, event_id: 'ev-former' };
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		await pause();
		expect( wire.sent( 'end_role' ) ).toEqual( [
			[ `--key=${ KEY }`, '--role=client' ],
		] );
		await waitFor( () =>
			expect( wire.sent( 'assert_role' ) ).toEqual( [
				[ `--key=${ KEY }`, '--role=former_client' ],
			] )
		);
		await waitFor( () =>
			expect( snackbar() ).toHaveTextContent(
				'Harbor Ledger is now a former client.'
			)
		);
	} );

	it( 'drops a remembered Briefing and entity once ending the client lands', async () => {
		wire.replies.briefing = { groups: [], total: 0, available: true };
		const remembered = [
			[ 'probe:briefing', 'briefing', [ 'changes' ] ],
			[ 'probe:entity', 'entity', [ 'person:harbor-ledger-editor' ] ],
		];
		const probes = () => (
			<>
				{ remembered.map( ( [ scope, command, args ] ) => (
					<Probe
						key={ scope }
						scope={ scope }
						command={ command }
						args={ args }
					/>
				) ) }
			</>
		);
		const seeded = renderOnPage( probes() );
		await screen.findByText( 'briefing read' );
		await screen.findByText( 'entity read' );
		seeded.unmount();
		wire.replies.entity = CHURNING;
		wire.replies.end_role = { ok: true, event_id: 'ev-end-harbor' };
		wire.replies.assert_role = { ok: true, event_id: 'ev-former-harbor' };
		const view = renderEntity( { canTune: true } );
		await endFromDialog();
		await waitFor( () =>
			expect( wire.sent( 'assert_role' ) ).toHaveLength( 1 )
		);
		await pause();
		view.unmount();
		wire.replies.briefing = never;
		wire.replies.entity = never;
		renderOnPage( probes() );
		expect( screen.getByText( 'briefing owed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'entity owed' ) ).toBeInTheDocument();
	} );

	it( 'shows a refused end as the refusal and asserts nothing', async () => {
		wire.replies.entity = CHURNING;
		wire.replies.end_role = () =>
			new Error( `${ KEY } holds no open client role` );
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect(
			await screen.findByText( `${ KEY } holds no open client role` )
		).toBeInTheDocument();
		expect( wire.sent( 'assert_role' ) ).toEqual( [] );
		expect( await notice() ).toBeInTheDocument();
		expect( snackbar() ).toBeNull();
	} );

	it( 'hides the notice from the end while a read still lists the client', async () => {
		wire.replies.entity = CHURNING;
		wire.replies.end_role = { ok: true, event_id: 'ev-end-6630' };
		wire.replies.assert_role = { ok: true, event_id: 'ev-former-6630' };
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'entity' ).length ).toBeGreaterThan( 1 )
		);
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
	} );

	it( 'moves focus to the heading when the notice leaves', async () => {
		wire.replies.entity = CHURNING;
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect( document.activeElement ).toBe(
			screen.getByRole( 'heading', { name: 'Harbor Ledger' } )
		);
	} );

	describe( 'not about this client', () => {
		// Only the undo window's own timer is faked; the wire runs as it does.
		const fakeUndoClock = () =>
			jest.useFakeTimers( {
				doNotFake: [
					'Date',
					'hrtime',
					'nextTick',
					'performance',
					'queueMicrotask',
					'requestAnimationFrame',
					'cancelAnimationFrame',
					'requestIdleCallback',
					'cancelIdleCallback',
					'setImmediate',
					'clearImmediate',
					'setInterval',
					'clearInterval',
				],
			} );
		const elapse = ( ms ) =>
			act( async () => {
				jest.advanceTimersByTime( ms );
			} );
		const REJECTED = [
			[ `--item=${ REPORT.item_key }`, `--entity=${ KEY }` ],
		];

		afterEach( () => {
			jest.useRealTimers();
		} );

		it( 'sends reject_link for the item and the client after the undo window', async () => {
			wire.replies.entity = CHURNING;
			wire.replies.reject_link = { ok: true, event_id: 'ev-reject' };
			renderEntity( { canTune: true } );
			const asked = await openDialog();
			fakeUndoClock();
			fireEvent.click(
				within( asked ).getByRole( 'button', {
					name: 'Not about this client',
				} )
			);
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
			expect(
				snackbar().querySelector( '.components-snackbar__content' )
					.firstChild
			).toHaveTextContent( /^Removed the link to Harbor Ledger\.$/ );
			expect( wire.sent( 'reject_link' ) ).toEqual( [] );
			await elapse( UNDO_MS );
			await waitFor( () =>
				expect( wire.sent( 'reject_link' ) ).toEqual( REJECTED )
			);
			expect( wire.sent( 'end_role' ) ).toEqual( [] );
		} );

		it( 'puts the report back on Undo and sends nothing', async () => {
			wire.replies.entity = CHURNING;
			renderEntity( { canTune: true } );
			const shown = await notice();
			fakeUndoClock();
			fireEvent.click(
				within( shown ).getByRole( 'button', {
					name: 'Not about this client',
				} )
			);
			fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
			expect( screen.getByText( 'Possible churn' ) ).toBeInTheDocument();
			await elapse( UNDO_MS );
			expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		} );
	} );
} );

describe( 'Entity — churn-risk note', () => {
	const noted = { ...RECORD, status: { ...RECORD.status, churn_note: NOTE } };
	const SAID =
		'Churn risk: Budget cut at the paper. Owner: Dana Reyes. Next: renewal call, by 20 Nov.';

	it( 'shows the note with its owner and next step', async () => {
		wire.replies.entity = noted;
		renderEntity();
		expect(
			within( await header() ).getByText( SAID )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Edit note' } )
		).toBeNull();
	} );

	it( 'shows the note inside the churn notice when one stands', async () => {
		wire.replies.entity = {
			...CHURNING,
			status: { ...CHURNING.status, churn_note: NOTE },
		};
		renderEntity();
		const shown = ( await screen.findByText( 'Possible churn' ) ).closest(
			'.components-notice'
		);
		expect( within( shown ).getByText( SAID ) ).toBeInTheDocument();
	} );

	it( 'lets a curator edit the note, sending note_churn and reading the profile again', async () => {
		wire.replies.entity = noted;
		wire.replies.note_churn = { ok: true, event_id: 'ev-note' };
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Edit note',
			} )
		);
		const form = screen.getByRole( 'group', { name: 'Churn-risk note' } );
		expect( within( form ).getByLabelText( 'Owner' ) ).toHaveValue(
			'Dana Reyes'
		);
		fireEvent.change(
			within( form ).getByLabelText( 'What puts it at risk' ),
			{
				target: { value: 'Rival offered a lower price' },
			}
		);
		fireEvent.change( within( form ).getByLabelText( 'Next step' ), {
			target: { value: 'pricing review' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'By' ), {
			target: { value: '2026-12-02' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save note' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'note_churn' ) ).toEqual( [
				[
					`--entity=${ KEY }`,
					'--text=Rival offered a lower price',
					'--owner=Dana Reyes',
					'--next_step=pricing review',
					'--day=2026-12-02',
				],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'entity' ).length ).toBeGreaterThan( 1 )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'group', { name: 'Churn-risk note' } )
			).toBeNull()
		);
	} );

	it( 'lets a curator add a note to a client from its ⋯ menu', async () => {
		wire.replies.note_churn = { ok: true, event_id: 'ev-note-new' };
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Profile actions',
			} )
		);
		fireEvent.click(
			await screen.findByRole( 'menuitem', {
				name: 'Add a churn-risk note',
			} )
		);
		const form = screen.getByRole( 'group', { name: 'Churn-risk note' } );
		fireEvent.change(
			within( form ).getByLabelText( 'What puts it at risk' ),
			{
				target: { value: 'Went quiet after the outage' },
			}
		);
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save note' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'note_churn' ) ).toEqual( [
				[ `--entity=${ KEY }`, '--text=Went quiet after the outage' ],
			] )
		);
	} );

	it( 'offers no note on an entity that is not a client', async () => {
		wire.replies.entity = { ...RECORD, roles: [], status: null };
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Profile actions',
			} )
		);
		await screen.findByRole( 'menuitem', { name: 'Edit layout' } );
		expect(
			screen.queryByRole( 'menuitem', { name: 'Add a churn-risk note' } )
		).toBeNull();
	} );

	it( 'shows a refused note as the refusal', async () => {
		wire.replies.entity = noted;
		wire.replies.note_churn = () =>
			new Error( 'next_step is past its 1000 bytes' );
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', {
				name: 'Edit note',
			} )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Save note' } ) );
		expect(
			await screen.findByText( 'next_step is past its 1000 bytes' )
		).toBeInTheDocument();
	} );
} );

describe( 'Entity — curation', () => {
	it( 'offers a reader no Edit', async () => {
		renderEntity();
		await header();
		expect( screen.queryByRole( 'button', { name: 'Edit' } ) ).toBeNull();
	} );

	it( 'opens the Edit drawer from the header, focused on its first field, and Escape returns to Edit', async () => {
		renderEntity( { canTune: true } );
		const edit = within( await header() ).getByRole( 'button', {
			name: 'Edit',
		} );
		edit.focus();
		fireEvent.click( edit );
		expect(
			within(
				screen.getByRole( 'group', { name: 'Add a name' } )
			).getByRole( 'combobox', { name: 'Kind' } )
		).toHaveFocus();
		const names = screen.getByRole( 'region', { name: 'Names' } );
		const current = within( names ).getByText( 'The Ledger' );
		expect(
			current.compareDocumentPosition(
				within( names ).getByRole( 'group', { name: 'Add a name' } )
			) & window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();

		fireEvent.keyDown( document, { key: 'Escape' } );
		expect( edit ).toHaveFocus();
	} );

	it( 'adds a name and re-reads the record', async () => {
		wire.replies.add_name = { ok: true, event_id: 'ev-name' };
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', { name: 'Edit' } )
		);
		const form = screen.getByRole( 'group', { name: 'Add a name' } );
		fireEvent.change( within( form ).getByLabelText( /Kind/ ), {
			target: { value: 'acronym' },
		} );
		fireEvent.change( within( form ).getByLabelText( /Value/ ), {
			target: { value: '--kind=HL' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add a name' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_name' ) ).toEqual( [
				[ `--key=${ KEY }`, '--kind=acronym', '--value=--kind=HL' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'entity' ).length ).toBeGreaterThan( 1 )
		);
	} );

	it( 'ends the client role from the drawer through its dialog', async () => {
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', { name: 'Edit' } )
		);
		fireEvent.click(
			within( screen.getByRole( 'region', { name: 'Roles' } ) ).getByRole(
				'button',
				{ name: 'End Client…' }
			)
		);
		expect(
			screen.getByRole( 'dialog', {
				name: 'End Harbor Ledger as a client?',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'end_role' ) ).toEqual( [] );
	} );

	it( 'shows a refused edit as the refusal', async () => {
		wire.replies.assert_role = () =>
			new Error( `${ KEY } already holds competitor` );
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await header() ).getByRole( 'button', { name: 'Edit' } )
		);
		const form = screen.getByRole( 'group', { name: 'Add a role' } );
		fireEvent.change( within( form ).getByLabelText( /Role/ ), {
			target: { value: 'competitor' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add a role' } )
		);
		expect(
			await screen.findByText( `${ KEY } already holds competitor` )
		).toBeInTheDocument();
	} );
} );
