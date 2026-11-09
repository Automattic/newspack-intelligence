/* eslint-env jest */
/**
 * Entity reads one entity through four commands — its record, its timeline, the
 * entities sharing its items and the claims naming it — and offers a curator
 * the four edits a record takes, each re-reading the record once it lands.
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
import { intelWire } from '../../__tests__/intelWire';
import { ENTITY, ITEM, VOCABULARY } from './fixtures';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { useIntelRead } from '../../hooks/useIntelCommand';
import Entity from '../Entity';

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

/**
 * The line bars standing in for a card's prose while its read is owed.
 *
 * @param {HTMLElement} card The card.
 * @return {number} How many.
 */
const owedLines = ( card ) =>
	card.querySelectorAll(
		'[role=status] .newspack-intelligence__skeleton-bar.is-line'
	).length;

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

const KEY = 'organization:acme-quillsheet';

const TREND = [
	{ bucket: '2026-10-12', count: 5 },
	{ bucket: '2026-10-05', count: 7 },
	{ bucket: '2026-09-28', count: 2 },
	{ bucket: '2026-09-21', count: 0 },
	{ bucket: '2026-09-14', count: 4 },
	{ bucket: '2026-09-07', count: 1 },
	{ bucket: '2026-08-31', count: 3 },
	{ bucket: '2026-08-24', count: 6 },
];

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [ ITEM ], total: 1, available: true };
	wire.replies.related = {
		entities: [
			{
				entity_key: 'person:jo-reporter',
				name: 'Jo Reporter',
				type: 'person',
				shared_items: 4,
				items: [
					{
						item_key: 'feed:inkfold-review-17',
						url: 'https://inkfold-review.example/acme',
						title: 'Acme Quillsheet wins regional prize',
					},
				],
			},
		],
		available: true,
	};
	wire.replies.signals = { signals: [], trend: TREND, available: true };
	wire.replies.claims = {
		claims: [
			{
				subject: KEY,
				subject_name: 'Acme Quillsheet',
				predicate: 'won',
				object: 'Regional Press Award',
				object_name: null,
				confidence: 0.9,
				item_key: 'feed:inkfold-review-17',
				url: 'https://inkfold-review.example/acme',
				title: 'Acme Quillsheet wins regional prize',
			},
			{
				subject: 'person:jo-reporter',
				subject_name: 'Jo Reporter',
				predicate: 'joined',
				object: KEY,
				object_name: 'Acme Quillsheet',
				confidence: 0.8,
				item_key: 'feed:inkfold-review-21',
				url: 'https://inkfold-review.example/jo',
				title: 'Jo Reporter joins the Quillsheet',
			},
		],
		available: true,
	};
} );

function renderEntity( props = {} ) {
	return render(
		<Entity
			entityKey={ KEY }
			canTune={ false }
			vocabulary={ VOCABULARY }
			operator={ { name: 'Cobalt Desk', heading: 'Cobalt Desk' } }
			onOpenEntity={ jest.fn() }
			onNamed={ jest.fn() }
			{ ...props }
		/>
	);
}

const recordCard = () =>
	screen
		.getByRole( 'heading', { name: 'Acme Quillsheet' } )
		.closest( 'section' );

const details = () => screen.getByRole( 'region', { name: 'Details' } );

/**
 * Open the Edit drawer once the record answers.
 *
 * @return {Promise<HTMLElement>} The drawer.
 */
async function openDrawer() {
	await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
	fireEvent.click( screen.getByRole( 'button', { name: 'Edit' } ) );
	return screen.getByRole( 'region', { name: 'Edit Acme Quillsheet' } );
}

describe( 'Entity — reads', () => {
	it( 'asks each read command about the entity', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		await waitFor( () => {
			for ( const verb of [
				'entity',
				'timeline',
				'related',
				'claims',
			] ) {
				expect( wire.sent( verb ) ).toEqual( [ [ KEY ] ] );
			}
		} );
	} );

	it( 'heads with its counts and lists its names, identifiers, roles and topics after the timeline', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		expect(
			within( recordCard() ).getByText( /12 items, 3 proposed links/ )
		).toBeInTheDocument();
		expect(
			within( recordCard() ).queryByText( /alias: The Quillsheet/ )
		).toBeNull();
		const record = details();
		expect(
			screen
				.getByRole( 'region', { name: 'Timeline' } )
				.compareDocumentPosition( record ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect(
			within( record ).getByText( /alias: The Quillsheet/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /Web address: acmequillsheet\.example/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /Paying member since 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /Lead 2026-05-28 – 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /organization:acme-quillsheet-inc/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByRole( 'button', { name: 'Local news' } )
		).toBeInTheDocument();
	} );

	it( 'offers a curator the exclusion on its timeline, and a reader none', async () => {
		const { unmount } = renderEntity( {
			canTune: true,
			onSave: jest.fn(),
		} );
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		const row = (
			await within( timeline ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).closest( 'li' );
		expect(
			within( row ).getByRole( 'button', {
				name: 'Exclude from every view',
			} )
		).toBeInTheDocument();
		unmount();

		renderEntity( { onSave: jest.fn() } );
		const read = await screen.findByRole( 'region', { name: 'Timeline' } );
		await within( read ).findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect(
			within( read ).queryByRole( 'button', {
				name: 'Exclude from every view',
			} )
		).toBeNull();
	} );

	it( 'renders the timeline and the related entities', async () => {
		const onOpenEntity = jest.fn();
		renderEntity( { onOpenEntity } );
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		expect(
			await within( timeline ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
		const related = await within(
			await screen.findByRole( 'region', { name: 'Related' } )
		).findByRole( 'button', { name: 'Jo Reporter' } );
		expect( related.closest( 'li' ) ).toHaveClass(
			'newspack-intelligence__actions'
		);
		fireEvent.click( related );
		expect( onOpenEntity ).toHaveBeenCalledWith( 'person:jo-reporter' );
		expect( screen.getByText( /4 shared items/ ) ).toBeInTheDocument();
	} );

	it( 'reads each claim by name and cites its item by title', async () => {
		renderEntity();
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		const [ won, joined ] =
			await within( claims ).findAllByRole( 'listitem' );
		expect( won ).toHaveTextContent(
			'Acme Quillsheet won Regional Press Award Acme Quillsheet wins regional prize'
		);
		expect( joined ).toHaveTextContent(
			'Jo Reporter joined Acme Quillsheet Jo Reporter joins the Quillsheet'
		);
		expect(
			within( joined ).getByRole( 'link', {
				name: 'Jo Reporter joins the Quillsheet',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/jo' );
		expect( claims ).not.toHaveTextContent( /person:|organization:|feed:/ );
	} );

	it( "saves a claim's item to a folder when given a save handler", async () => {
		const onSave = jest.fn();
		renderEntity( { onSave } );
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		const saves = await within( claims ).findAllByRole( 'button', {
			name: 'Save to folder',
		} );
		fireEvent.click( saves[ 1 ] );
		expect( onSave ).toHaveBeenCalledWith(
			expect.objectContaining( {
				item_key: 'feed:inkfold-review-21',
				title: 'Jo Reporter joins the Quillsheet',
			} )
		);
	} );

	it( "offers a reader no save on a claim's item", async () => {
		renderEntity();
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		await within( claims ).findAllByRole( 'listitem' );
		expect(
			within( claims ).queryByRole( 'button', { name: 'Save to folder' } )
		).toBeNull();
	} );

	it( 'leaves out a role row that held no time', async () => {
		wire.replies.entity = {
			...ENTITY,
			roles: [
				...ENTITY.roles,
				{ role: 'client', from_ts: 1793232000, to_ts: 1793232000 },
				{ role: 'competitor', from_ts: 1785542400, to_ts: 1793232000 },
			],
		};
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		const record = details();
		expect(
			within( record ).getByText( /Rival 2026-08-01 – 2026-10-29/ )
		).toBeInTheDocument();
		expect(
			within( record ).queryByText( /2026-10-29 – 2026-10-29/ )
		).toBeNull();
		expect(
			within( record ).getAllByText( /^Paying member/ )
		).toHaveLength( 1 );
	} );

	it( 'keeps an open role a seed asserted from 0', async () => {
		wire.replies.entity = {
			...ENTITY,
			roles: [ { role: 'client', from_ts: 0, to_ts: 0 } ],
		};
		renderEntity( { canTune: true } );
		const end = within( await openDrawer() ).getByRole( 'button', {
			name: 'End Paying member…',
		} );
		expect( end.closest( 'li' ) ).toHaveTextContent(
			/^Paying member since/
		);
	} );

	it( 'hands the page the name its record answers', async () => {
		const onNamed = jest.fn();
		wire.replies.entity = { ...ENTITY, name: 'Quillsheet Media Group' };
		renderEntity( { onNamed } );
		await screen.findByRole( 'heading', {
			name: 'Quillsheet Media Group',
		} );
		await waitFor( () =>
			expect( onNamed ).toHaveBeenLastCalledWith( {
				key: KEY,
				name: 'Quillsheet Media Group',
			} )
		);
	} );

	it( 'hands the page the key its heading falls back to for a nameless record', async () => {
		const onNamed = jest.fn();
		wire.replies.entity = { ...ENTITY, name: null };
		renderEntity( { onNamed } );
		await screen.findByRole( 'heading', { name: KEY } );
		await waitFor( () =>
			expect( onNamed ).toHaveBeenLastCalledWith( {
				key: KEY,
				name: KEY,
			} )
		);
	} );

	it( 'clears the name it handed the page once the record is refused', async () => {
		const onNamed = jest.fn();
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity( { onNamed } );
		await screen.findByText(
			'Intelligence could not be read just now. Try again.'
		);
		await waitFor( () =>
			expect( onNamed ).toHaveBeenLastCalledWith( {
				key: KEY,
				name: null,
			} )
		);
		expect( onNamed ).not.toHaveBeenCalledWith(
			expect.objectContaining( { name: expect.any( String ) } )
		);
	} );

	it( 'opens a claim’s subject and its keyed object from their chips, the key standing in for a missing name', async () => {
		const onOpenEntity = jest.fn();
		wire.replies.claims = {
			claims: [
				{
					subject: 'person:kit-sorrel',
					subject_name: null,
					predicate: 'founded',
					object: KEY,
					object_name: 'Acme Quillsheet',
					confidence: 0.7,
					item_key: 'feed:inkfold-review-30',
					url: 'https://inkfold-review.example/kit',
					title: 'Kit Sorrel looks back',
				},
			],
			available: true,
		};
		renderEntity( { onOpenEntity } );
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		fireEvent.click(
			await within( claims ).findByRole( 'button', {
				name: 'person:kit-sorrel',
			} )
		);
		fireEvent.click(
			within( claims ).getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity.mock.calls ).toEqual( [
			[ 'person:kit-sorrel' ],
			[ KEY ],
		] );
	} );

	it( 'renders a claim’s free-text object as text, not a chip', async () => {
		renderEntity();
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		await within( claims ).findByText( 'Regional Press Award' );
		expect(
			within( claims ).queryByRole( 'button', {
				name: 'Regional Press Award',
			} )
		).toBeNull();
	} );

	it( 'counts one of a thing in the singular', async () => {
		wire.replies.entity = {
			...ENTITY,
			counts: { items: 1, proposed_links: 1 },
		};
		wire.replies.related = {
			entities: [
				{
					entity_key: 'person:jo-reporter',
					name: 'Jo Reporter',
					type: 'person',
					shared_items: 1,
					items: [
						{
							item_key: 'feed:inkfold-review-17',
							url: 'https://inkfold-review.example/acme',
							title: 'Acme Quillsheet wins regional prize',
						},
					],
				},
			],
			available: true,
		};
		renderEntity();
		expect(
			await screen.findByText( '1 item, 1 proposed link' )
		).toBeInTheDocument();
		expect(
			await screen.findByText( /person · 1 shared item$/ )
		).toBeInTheDocument();
	} );

	it( 'pages the timeline past the items shown, with its total', async () => {
		wire.replies.timeline = ( args ) => ( {
			items: [ 'a', 'b', 'c' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.slice( 1 ).join( ' ' ) || 'start' }`,
			} ) ),
			total: 7,
			available: true,
		} );
		renderEntity();
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		await within( timeline ).findByText( 'a at start' );
		expect(
			within( timeline ).getByText( '3 of 7 items' )
		).toBeInTheDocument();
		fireEvent.click(
			within( timeline ).getByRole( 'button', { name: 'Next' } )
		);
		expect(
			await within( timeline ).findByText( 'a at --offset=3' )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toContainEqual( [
			KEY,
			'--offset=3',
		] );
	} );

	it( 'stands lines in for its prose cards while they are owed', async () => {
		wire.replies.entity = owed;
		wire.replies.signals = owed;
		wire.replies.related = owed;
		wire.replies.claims = owed;
		const { container } = renderEntity();
		const card = ( name ) => screen.getByRole( 'region', { name } );
		await waitFor( () =>
			expect( owedLines( card( 'Signals' ) ) ).toBe( 1 )
		);
		expect( owedLines( container.querySelector( 'section' ) ) ).toBe( 2 );
		expect( owedLines( card( 'Related' ) ) ).toBe( 3 );
		expect( owedLines( card( 'Claims' ) ) ).toBe( 3 );
	} );

	it( 'says which kind of nothing each section holds', async () => {
		wire.replies.timeline = { items: [], total: 0, available: true };
		wire.replies.related = { entities: [], available: true };
		wire.replies.claims = { claims: [], available: false };
		renderEntity();
		expect(
			await screen.findByText( 'No readable item is about this entity.' )
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'No other entity shares an item with this one.'
			)
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
	} );

	it( 'says the record could not be read when the graph is unavailable', async () => {
		wire.replies.entity = { ...ENTITY, name: null, available: false };
		renderEntity();
		expect(
			await screen.findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows its signals under its header, each marked by its axis', async () => {
		wire.replies.signals = {
			trend: TREND,
			signals: [
				{
					axis: 'about',
					signal: 'spike',
					bucket: '2026-10-12',
					by: 'kind',
					value: 'complaint',
					count: 6,
					baseline: 1.125,
					items: [
						{
							item_key: 'linear:SUP-140',
							url: 'https://linear.example/SUP-140',
							title: 'Acme asks for a refund',
						},
					],
				},
				{
					axis: 'publisher',
					signal: 'drop',
					bucket: '2026-10-12',
					by: null,
					value: null,
					count: 2,
					baseline: 7.375,
					items: [
						{
							item_key: 'feed:acmequillsheet.example:city-hall',
							url: 'https://acmequillsheet.example/city-hall',
							title: 'City hall budget vote',
						},
					],
				},
			],
			available: true,
		};
		renderEntity();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const drop = await within( signals ).findByRole( 'row', {
			name: /publisher drop/,
		} );
		expect(
			within( drop ).getByText( '2 against a baseline of 7.4' )
		).toBeInTheDocument();
		expect(
			within( drop ).getByRole( 'link', {
				name: 'City hall budget vote',
			} )
		).toHaveAttribute( 'href', 'https://acmequillsheet.example/city-hall' );
		expect(
			within( signals ).getByRole( 'row', {
				name: /about spike: complaint/,
			} )
		).toBeInTheDocument();
		expect(
			recordCard().compareDocumentPosition( signals ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect(
			signals.compareDocumentPosition(
				screen.getByRole( 'region', { name: 'Timeline' } )
			) & window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( wire.sent( 'signals' ) ).toEqual( [ [ `--entity=${ KEY }` ] ] );
	} );

	it( 'reads an entity with no signal as steady beside its sparkline', async () => {
		renderEntity();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const line = ( await within( signals ).findByText( 'Steady' ) ).closest(
			'p'
		);
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			'Stories in each of the last 8 buckets, oldest first: 6, 3, 1, 4, 0, 2, 7, 5; the latest, from 2026-10-12, counts 5'
		);
		expect( within( signals ).queryByRole( 'table' ) ).toBeNull();
		expect( screen.queryByText( /No signal/ ) ).toBeNull();
	} );

	it( 'says a refused read was not read', async () => {
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity();
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Entity — status', () => {
	const heading = () =>
		screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );

	it( 'heads a client with its start, latest signal, churn signals, asks and last item', async () => {
		wire.replies.signals = {
			trend: TREND,
			signals: [
				{
					axis: 'publisher',
					signal: 'drop',
					bucket: '2026-10-12',
					by: null,
					value: null,
					count: 2,
					baseline: 4.5,
					items: [],
				},
			],
			available: true,
		};
		renderEntity();
		const status = ( await heading() ).nextElementSibling;
		await waitFor( () =>
			expect( status ).toHaveTextContent(
				'Client since 2026-09-09 · Latest signal: publisher drop, week of 2026-10-12 · 2 churn reports, 4 churn signals, 2 feature requests, 5 complaints · Last item 2026-09-22'
			)
		);
		expect( wire.sent( 'signals' ) ).toHaveLength( 1 );
	} );

	it( 'reads a client with no signal as steady, says it has no item yet, and counts one in the singular', async () => {
		wire.replies.entity = {
			...ENTITY,
			status: {
				client_since: 1791504000,
				kinds: {
					churned: 1,
					churn_signal: 1,
					feature_request: 1,
					complaint: 0,
				},
				last_item_ts: null,
			},
		};
		renderEntity();
		const status = ( await heading() ).nextElementSibling;
		await waitFor( () =>
			expect( status ).toHaveTextContent(
				'Client since 2026-10-09 · Steady · 1 churn report, 1 churn signal, 1 feature request, 0 complaints · No item about it yet'
			)
		);
	} );

	it( 'renders no status line for an entity holding no client role', async () => {
		wire.replies.entity = {
			...ENTITY,
			roles: [ ENTITY.roles[ 1 ] ],
			status: null,
		};
		renderEntity();
		await heading();
		expect( screen.queryByText( /Client since/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /Latest signal/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'Entity — churn', () => {
	const REPORT = {
		item_key: 'linear:acme-offboard',
		title: 'Acme Quillsheet offboarding checklist',
		url: 'https://tracker.example/acme-offboard',
		source: 'linear',
		day: '2026-10-06',
	};
	const churning = {
		...ENTITY,
		status: { ...ENTITY.status, churn_open: true, churn_items: [ REPORT ] },
	};
	const notice = async () =>
		( await screen.findByText( 'Possible churn' ) ).closest(
			'.components-notice'
		);
	const dialog = () =>
		screen.getByRole( 'dialog', {
			name: 'End Acme Quillsheet as a client?',
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

	it( 'names each churn report in a notice for a curator', async () => {
		wire.replies.entity = churning;
		renderEntity( { canTune: true } );
		const shown = await notice();
		expect( shown ).toHaveClass( 'is-warning' );
		expect( shown ).toHaveTextContent(
			'“Acme Quillsheet offboarding checklist” (linear, 2026-10-06) says this client is leaving Cobalt Desk.'
		);
		expect(
			within( shown ).getByRole( 'link', { name: 'Open item' } )
		).toHaveAttribute( 'href', REPORT.url );
	} );

	it( 'sends nothing when End client… is pressed', async () => {
		wire.replies.entity = churning;
		renderEntity( { canTune: true } );
		const asked = await openDialog();
		expect( asked ).toHaveTextContent(
			'“Acme Quillsheet offboarding checklist”, linear, 2026-10-06'
		);
		expect( asked ).toHaveTextContent( 'Main subject of the item.' );
		await pause();
		expect( wire.sent( 'end_role' ) ).toEqual( [] );
		expect( wire.sent( 'assert_role' ) ).toEqual( [] );
	} );

	it( 'ends the client from its dialog: end_role, then assert_role former_client', async () => {
		wire.replies.entity = churning;
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
				'Acme Quillsheet is now a former client.'
			)
		);
	} );

	it( 'drops a remembered Briefing and entity once ending the client lands', async () => {
		wire.replies.briefing = { groups: [], total: 0, available: true };
		const remembered = [
			[ 'probe:briefing', 'briefing', [ 'changes' ] ],
			[ 'probe:entity', 'entity', [ 'person:harbor-ledger-editor' ] ],
		];
		const seeded = render(
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
		await screen.findByText( 'briefing read' );
		await screen.findByText( 'entity read' );
		seeded.unmount();
		wire.replies.entity = churning;
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
		render(
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
		expect( screen.getByText( 'briefing owed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'entity owed' ) ).toBeInTheDocument();
	} );

	it( 'closes its dialog with nothing sent', async () => {
		wire.replies.entity = churning;
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await openDialog() ).getByRole( 'button', {
				name: 'Close',
			} )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		await pause();
		expect( wire.sent( 'end_role' ) ).toEqual( [] );
		expect( await notice() ).toBeInTheDocument();
	} );

	it( 'asserts the former client role only once the end lands', async () => {
		wire.replies.entity = churning;
		renderEntity( { canTune: true } );
		await endFromDialog();
		await waitFor( () =>
			expect( wire.sent( 'end_role' ) ).toHaveLength( 1 )
		);
		expect( wire.sent( 'assert_role' ) ).toEqual( [] );
	} );

	it( 'shows a refused end as the refusal and asserts nothing', async () => {
		wire.replies.entity = churning;
		wire.replies.end_role = () =>
			new Error( `${ KEY } holds no open client role` );
		wire.replies.assert_role = { ok: true, event_id: 'ev-former' };
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
		wire.replies.entity = churning;
		wire.replies.end_role = { ok: true, event_id: 'ev-end-6630' };
		wire.replies.assert_role = { ok: true, event_id: 'ev-former-6630' };
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'entity' ).length ).toBeGreaterThan( 1 )
		);
		expect( wire.sent( 'assert_role' ) ).toHaveLength( 1 );
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
	} );

	it( 'keeps a refused assert on screen after the status goes', async () => {
		wire.replies.entity = churning;
		wire.replies.end_role = { ok: true, event_id: 'ev-end-6631' };
		wire.replies.assert_role = () => {
			wire.replies.entity = { ...ENTITY, status: null };
			return new Error( `${ KEY } already holds former_client` );
		};
		renderEntity( { canTune: true } );
		await endFromDialog();
		const alert = await screen.findByText(
			`Acme Quillsheet (${ KEY }) is no longer a client, but former_client was not recorded: ${ KEY } already holds former_client`
		);
		await waitFor( () =>
			expect( wire.sent( 'entity' ).length ).toBeGreaterThan( 1 )
		);
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
		expect( alert ).toBeInTheDocument();
		expect( alert ).toHaveAttribute( 'role', 'alert' );
	} );

	it( 'moves focus to the heading when the notice leaves', async () => {
		wire.replies.entity = churning;
		renderEntity( { canTune: true } );
		await endFromDialog();
		expect( document.activeElement ).toBe(
			screen.getByRole( 'heading', { name: 'Acme Quillsheet' } )
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
			wire.replies.entity = churning;
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
			).toHaveTextContent( /^Removed the link to Acme Quillsheet\.$/ );
			expect(
				within( snackbar() ).getByRole( 'button', { name: 'Undo' } )
			).toBeInTheDocument();
			expect( wire.sent( 'reject_link' ) ).toEqual( [] );
			await elapse( UNDO_MS );
			await waitFor( () =>
				expect( wire.sent( 'reject_link' ) ).toEqual( REJECTED )
			);
			expect( wire.sent( 'end_role' ) ).toEqual( [] );
		} );

		it( 'sends it from the notice too', async () => {
			wire.replies.entity = churning;
			wire.replies.reject_link = { ok: true, event_id: 'ev-reject-2' };
			renderEntity( { canTune: true } );
			const shown = await notice();
			fakeUndoClock();
			fireEvent.click(
				within( shown ).getByRole( 'button', {
					name: 'Not about this client',
				} )
			);
			expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
			await elapse( UNDO_MS );
			await waitFor( () =>
				expect( wire.sent( 'reject_link' ) ).toEqual( REJECTED )
			);
		} );

		it( 'puts the report back on Undo and sends nothing', async () => {
			wire.replies.entity = churning;
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

	it( 'asks a reader nothing', async () => {
		wire.replies.entity = churning;
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'End client…' } )
		).toBeNull();
	} );

	it( 'asks nothing about a client with no churn report since it began', async () => {
		wire.replies.entity = {
			...ENTITY,
			status: { ...ENTITY.status, churn_items: [] },
		};
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		expect( screen.queryByText( 'Possible churn' ) ).toBeNull();
	} );
} );

describe( 'Entity — published', () => {
	const published = () =>
		screen.getByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );

	beforeEach( () => {
		wire.replies.entity = { ...ENTITY, published: { count: 4 } };
		wire.replies.timeline = ( args ) =>
			args.includes( '--published' )
				? {
						items: [ 'crater', 'ferry' ].map( ( n ) => ( {
							...ITEM,
							item_key: `feed:acmequillsheet.example:${ n }`,
							title: `${ n } at ${
								args.slice( 2 ).join( ' ' ) || 'start'
							}`,
							entities: [],
						} ) ),
						total: 4,
						available: true,
				  }
				: { items: [ ITEM ], total: 1, available: true };
	} );

	it( 'lists the items about it first, then a collapsed section counting what it published', async () => {
		renderEntity();
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		await screen.findByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );
		const section = published().closest( 'section' );
		expect(
			// eslint-disable-next-line no-bitwise
			timeline.compareDocumentPosition( section ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( published() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( within( section ).queryByRole( 'list' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'timeline' ) ).toEqual( [ [ KEY ] ] )
		);
	} );

	it( 'reads what it published once opened, a page at a time', async () => {
		renderEntity();
		await screen.findByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );
		fireEvent.click( published() );
		const section = published().closest( 'section' );
		expect( published() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect(
			await within( section ).findByText( 'crater at start' )
		).toBeInTheDocument();
		expect(
			within( section ).getByText( '2 of 4 items' )
		).toBeInTheDocument();
		fireEvent.click(
			within( section ).getByRole( 'button', { name: 'Next' } )
		);
		expect(
			await within( section ).findByText( 'crater at --offset=2' )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toEqual(
			expect.arrayContaining( [
				[ KEY, '--published' ],
				[ KEY, '--published', '--offset=2' ],
			] )
		);
	} );

	it( 'offers no section for an entity that published nothing', async () => {
		wire.replies.entity = { ...ENTITY, published: { count: 0 } };
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		expect(
			screen.queryByRole( 'button', { name: /^Published by/ } )
		).toBeNull();
	} );
} );

describe( 'Entity — curation', () => {
	it( 'offers a reader no curation control', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		for ( const name of [
			'Edit',
			'Add name',
			'Add identifier',
			'Assert role',
			'End Paying member…',
		] ) {
			expect( screen.queryByRole( 'button', { name } ) ).toBeNull();
		}
	} );

	it( 'offers each role and identifier kind by its label, sending its token', async () => {
		renderEntity( { canTune: true } );
		const drawer = await openDrawer();
		const option = ( group, label ) =>
			within(
				within( within( drawer ).getByRole( 'group', { name: group } ) )
					.getAllByRole( 'combobox' )
					.at( 0 )
			).getByRole( 'option', { name: label } );
		expect( option( 'Assert role', 'Rival' ) ).toHaveValue( 'competitor' );
		expect( option( 'Add identifier', 'Code org' ) ).toHaveValue(
			'github_org'
		);
	} );

	it( 'keeps every curation form in the Edit drawer, closed until opened', async () => {
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		const toggle = screen.getByRole( 'button', { name: 'Edit' } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		for ( const name of [ 'Add name', 'Add identifier', 'Assert role' ] ) {
			expect( screen.queryByRole( 'group', { name } ) ).toBeNull();
		}
		expect(
			screen.queryByRole( 'button', { name: 'End Paying member…' } )
		).toBeNull();

		const drawer = await openDrawer();
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( toggle ).toHaveAttribute( 'aria-controls', drawer.id );
		expect( drawer.id ).not.toBe( '' );
		expect( recordCard() ).toContainElement( drawer );
		for ( const name of [ 'Add name', 'Add identifier', 'Assert role' ] ) {
			expect(
				within( drawer ).getByRole( 'group', { name } )
			).toBeInTheDocument();
		}
		expect(
			within( drawer ).getByRole( 'button', {
				name: 'End Paying member…',
			} )
		).toBeInTheDocument();

		fireEvent.click( toggle );
		expect(
			screen.queryByRole( 'region', { name: 'Edit Acme Quillsheet' } )
		).toBeNull();
	} );

	it( 'offers no edit until the record answers', async () => {
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity( { canTune: true } );
		await screen.findByText(
			'Intelligence could not be read just now. Try again.'
		);
		expect(
			screen.queryByRole( 'group', { name: 'Add name' } )
		).toBeNull();
		expect(
			screen.queryByRole( 'group', { name: 'Assert role' } )
		).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Edit' } ) ).toBeNull();
	} );

	it( 'adds a name of the chosen kind and re-reads the record', async () => {
		wire.replies.add_name = { ok: true, event_id: 'ev-name' };
		renderEntity( { canTune: true } );
		const form = within( await openDrawer() ).getByRole( 'group', {
			name: 'Add name',
		} );
		fireEvent.change( within( form ).getByLabelText( 'Kind' ), {
			target: { value: 'acronym' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'AG' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add name' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_name' ) ).toEqual( [
				[ `--key=${ KEY }`, '--kind=acronym', '--value=AG' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toHaveLength( 2 )
		);
	} );

	it( 'sends a value that reads like an option as the value', async () => {
		wire.replies.add_name = { ok: true, event_id: 'ev-dash' };
		renderEntity( { canTune: true } );
		const form = within( await openDrawer() ).getByRole( 'group', {
			name: 'Add name',
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: '--kind=acronym' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add name' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_name' ) ).toEqual( [
				[
					`--key=${ KEY }`,
					'--kind=canonical',
					'--value=--kind=acronym',
				],
			] )
		);
	} );

	it( 'adds an identifier of the chosen kind', async () => {
		wire.replies.add_identifier = { ok: true, event_id: 'ev-id' };
		renderEntity( { canTune: true } );
		const form = within( await openDrawer() ).getByRole( 'group', {
			name: 'Add identifier',
		} );
		fireEvent.change( within( form ).getByLabelText( 'Kind' ), {
			target: { value: 'github_org' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'acme-quillsheet' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add identifier' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_identifier' ) ).toEqual( [
				[
					`--key=${ KEY }`,
					'--kind=github_org',
					'--value=acme-quillsheet',
				],
			] )
		);
	} );

	it( 'asserts the chosen role and offers an end only for a role held open', async () => {
		wire.replies.assert_role = { ok: true, event_id: 'ev-role' };
		renderEntity( { canTune: true } );
		const form = within( await openDrawer() ).getByRole( 'group', {
			name: 'Assert role',
		} );
		fireEvent.change( within( form ).getByLabelText( 'Role' ), {
			target: { value: 'competitor' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Assert role' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'assert_role' ) ).toEqual( [
				[ `--key=${ KEY }`, '--role=competitor' ],
			] )
		);
		expect(
			screen
				.getByRole( 'button', { name: 'End Paying member…' } )
				.closest( 'li' )
		).toHaveClass( 'newspack-intelligence__actions' );
		expect(
			screen.queryByRole( 'button', { name: 'End Lead' } )
		).toBeNull();
	} );

	it( 'ends the client role through the End client dialog, never on one click', async () => {
		wire.replies.end_role = { ok: true, event_id: 'ev-end' };
		wire.replies.assert_role = { ok: true, event_id: 'ev-former' };
		renderEntity( { canTune: true } );
		fireEvent.click(
			within( await openDrawer() ).getByRole( 'button', {
				name: 'End Paying member…',
			} )
		);
		const asked = screen.getByRole( 'dialog', {
			name: 'End Acme Quillsheet as a client?',
		} );
		expect(
			within( asked ).queryByRole( 'button', {
				name: 'Not about this client',
			} )
		).toBeNull();
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
		);
		expect( wire.sent( 'end_role' ) ).toEqual( [] );
		fireEvent.click(
			within( asked ).getByRole( 'button', { name: 'End client' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'assert_role' ) ).toEqual( [
				[ `--key=${ KEY }`, '--role=former_client' ],
			] )
		);
		expect( wire.sent( 'end_role' ) ).toEqual( [
			[ `--key=${ KEY }`, '--role=client' ],
		] );
	} );

	it( 'shows a refused edit as the refusal', async () => {
		wire.replies.assert_role = () =>
			new Error( `${ KEY } already holds client` );
		renderEntity( { canTune: true } );
		const form = within( await openDrawer() ).getByRole( 'group', {
			name: 'Assert role',
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Assert role' } )
		);
		expect(
			await screen.findByText( `${ KEY } already holds client` )
		).toBeInTheDocument();
	} );
} );
