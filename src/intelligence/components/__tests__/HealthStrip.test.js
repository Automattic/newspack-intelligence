/* eslint-env jest */
/**
 * HealthStrip reads the four `health` slices once, shows one line while
 * nothing is wrong, opens on a fault with the fault rows first, offers each
 * fix to a reader holding its role and the text step to anyone else, and
 * re-reads every slice once a fix lands or Refresh is pressed. A state it
 * does not recognise reads as unknown, and the line then claims no health.
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
import { intelWire } from '../../__tests__/intelWire';
import { HEALTHY, LAST_OK, okSource } from '../../__tests__/healthFixture';
import { formatLocalDateTime } from '@newspack-nodes/shared/utils/formatUtils';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import HealthStrip, { FAILING_SHARE, HealthBlock } from '../HealthStrip';
import { pageData } from '../../views/__tests__/fixtures';

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

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

/** 2026-10-30 14:05 UTC, the next day in Auckland. */
const SINCE = Date.UTC( 2026, 9, 30, 14, 5 ) / 1000;

/** SINCE as the strip renders it in Auckland, the zone jest.config.js pins. */
const SINCE_LOCAL = '2026-10-31 03:05:00 GMT+13';

/** LAST_OK as the strip renders it in Auckland. */
const LAST_OK_LOCAL = '2026-10-31 22:41:00 GMT+13';

/** The Vault tab of the substrate's Nodes station. */
const VAULT = 'admin.php?page=newspack-nodes-station&tab=vault';

const HEALTHY_LINE = 'Collecting from 3 sources. Nothing waiting.';

/**
 * A second `seconds` before now, as a slice stamps it.
 *
 * @param {number} seconds How long ago.
 * @return {number} The second.
 */
const ago = ( seconds ) => Math.floor( Date.now() / 1000 ) - seconds;

const failingSource = ( name, error ) => ( {
	...okSource( name ),
	state: 'failing',
	last_error: error,
	failing_since: SINCE,
} );

const STOPPED = {
	reason: 'no_live_entity',
	held: 47,
	since: SINCE,
	dropped: null,
};

const STOPPED_LINE = `Resolving is stopped: the ontology is empty. 47 items are waiting, since ${ SINCE_LOCAL }.`;

let wire;
let health;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	health = JSON.parse( JSON.stringify( HEALTHY ) );
	wire.replies.health = ( [ what ] ) => health[ what ];
} );

const reads = ( what ) =>
	wire.sent( 'health' ).filter( ( [ slice ] ) => what === slice ).length;

const strip = () => screen.getByRole( 'region', { name: 'Collection' } );

/** The strip's one line: its first status, ahead of every fix's. */
const line = () => within( strip() ).getAllByRole( 'status' )[ 0 ];

const rows = () => within( strip() ).getByRole( 'list' );

/**
 * The row whose label is `label`.
 *
 * @param {string} label The row's label.
 * @return {HTMLElement} The row.
 */
const row = ( label ) =>
	within( rows() )
		.getAllByRole( 'listitem' )
		.find(
			( item ) => label === item.querySelector( 'strong' ).textContent
		);

/**
 * The details panel every source listed, as a `health` block draws it.
 *
 * @param {boolean} [operator] Whether the reader may run the fixes.
 */
function renderStrip( operator = false ) {
	return renderOnPage(
		<HealthBlock operator={ operator } detail="sources" />
	);
}

describe( 'HealthStrip', () => {
	it( 'stands a line in for each slice still owed among its rows', async () => {
		renderStrip();
		expect( owedLines( rows() ) ).toBe( 4 );
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		expect( owedLines( rows() ) ).toBe( 0 );
	} );

	it( 'reads each slice once', async () => {
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		for ( const what of Object.keys( HEALTHY ) ) {
			expect( reads( what ) ).toBe( 1 );
		}
	} );

	it( 'counts one source in the singular', async () => {
		health.sources.sources = [ okSource( 'github' ) ];
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 1 source. Nothing waiting.'
			)
		);
	} );

	it( 'shows every row, each state as a pill, each source’s last answer as a relative time', async () => {
		health.sources.sources[ 1 ].failed = 37;
		health.sources.sources[ 1 ].requests = 451;
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		expect( row( 'Web feed' ) ).toHaveTextContent(
			`ago (${ LAST_OK_LOCAL }), 37 of 451 failed`
		);
		expect(
			within( row( 'Web feed' ) ).getByTitle( LAST_OK_LOCAL )
		).toHaveTextContent( / d ago$/ );
		expect(
			within( row( 'Digests' ) ).getByText(
				`By the model; last extraction ${ LAST_OK_LOCAL }.`
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Code host' ) ).getByText( 'Running' )
		).toHaveClass( 'newspack-nodes-status-badge', 'is-pill', 'is-success' );
	} );

	it( 'counts one failed request in the singular', async () => {
		health.sources.sources[ 0 ].failed = 1;
		health.sources.sources[ 0 ].requests = 1;
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		expect(
			within( row( 'Code host' ) ).getByText( '1 of 1 failed' )
		).toBeInTheDocument();
	} );

	it( 'opens on empty ontology with count and since', async () => {
		health.backlog = STOPPED;
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`Resolving is stopped: the ontology is empty. 47 items are waiting, since ${ SINCE_LOCAL }.`
			)
		);
		expect(
			within( rows() ).getByText(
				`Resolving is stopped: the ontology is empty. 47 items are waiting, since ${ SINCE_LOCAL }.`
			)
		).toBeInTheDocument();
	} );

	it( 'counts one held item in the singular', async () => {
		health.backlog = { ...STOPPED, held: 1 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`Resolving is stopped: the ontology is empty. 1 item is waiting, since ${ SINCE_LOCAL }.`
			)
		);
		expect(
			within( rows() ).getByText(
				`Resolving is stopped: the ontology is empty. 1 item is waiting, since ${ SINCE_LOCAL }.`
			)
		).toBeInTheDocument();
	} );

	it( 'counts held items with no start', async () => {
		health.backlog = { ...STOPPED, held: 6, since: null };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Resolving is stopped: the ontology is empty. 6 items are waiting.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Resolving is stopped: the ontology is empty. 6 items are waiting.'
			)
		).toBeInTheDocument();
	} );

	it( 'seed button sends seed_ontology and re-reads backlog', async () => {
		health.backlog = STOPPED;
		wire.replies.seed_ontology = () => {
			health.backlog = HEALTHY.backlog;
			return { ok: true, events: 81, created: 79, present: 2 };
		};
		renderStrip( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		expect( wire.sent( 'seed_ontology' ) ).toEqual( [ [] ] );
		expect( reads( 'backlog' ) ).toBe( 2 );
		expect( reads( 'sources' ) ).toBe( 2 );
	} );

	it( 'keeps a fixed row a fault, its button replaced, while the re-read still answers it', async () => {
		health.backlog = STOPPED;
		wire.replies.seed_ontology = {
			ok: true,
			events: 81,
			created: 79,
			present: 2,
		};
		renderStrip( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		await waitFor( () => expect( reads( 'backlog' ) ).toBe( 2 ) );
		const waiting = row( 'Pairing items' );
		expect(
			await within( waiting ).findByText(
				'Seeded. Fix sent; Refresh to check.'
			)
		).toBeInTheDocument();
		await waitFor( () =>
			expect( line().textContent ).toBe( STOPPED_LINE )
		);
		expect(
			within( rows() ).getByText( STOPPED_LINE )
		).toBeInTheDocument();
		expect( within( waiting ).getByText( 'Held' ) ).toHaveClass(
			'is-warning'
		);
		expect(
			screen.queryByRole( 'button', { name: 'Seed the ontology' } )
		).toBeNull();
		expect( wire.sent( 'seed_ontology' ) ).toEqual( [ [] ] );
	} );

	it( 'gives a held fix its button back on Refresh, where a second seed is refused', async () => {
		health.backlog = STOPPED;
		let seeds = 0;
		wire.replies.seed_ontology = () =>
			1 < ++seeds
				? new Error(
						'Recent ontology changes are still being processed; try again in a minute.'
				  )
				: { ok: true, events: 81, created: 79, present: 2 };
		renderStrip( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		expect(
			await screen.findByText( 'Seeded. Fix sent; Refresh to check.' )
		).toBeInTheDocument();
		fireEvent.click(
			within( strip() ).getByRole( 'button', { name: 'Refresh' } )
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		expect(
			screen.queryByText( 'Seeded. Fix sent; Refresh to check.' )
		).toBeNull();
		expect(
			await screen.findByText(
				'Recent ontology changes are still being processed; try again in a minute.'
			)
		).toHaveClass( 'newspack-nodes-status', 'is-error' );
		expect( wire.sent( 'seed_ontology' ) ).toEqual( [ [], [] ] );
	} );

	it( 'lets a hold go once its row reads another state, so the fault can return', async () => {
		const dropped = { first_day: '2026-10-27', last_day: '2026-10-28' };
		health.backlog = { ...STOPPED, dropped };
		wire.replies.seed_ontology = {
			ok: true,
			events: 81,
			created: 79,
			present: 2,
		};
		renderStrip( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		expect(
			await screen.findByText( 'Seeded. Fix sent; Refresh to check.' )
		).toBeInTheDocument();

		health.backlog = { ...HEALTHY.backlog, dropped };
		health.collection = { state: 'stale', topology: 'intel-staging' };
		wire.replies.reresolve = { ok: true, workers: 1 };
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-resolve' } ) );
		await waitFor( () =>
			expect(
				within( row( 'Pairing items' ) ).getByText( 'Up to date' )
			).toBeInTheDocument()
		);

		health.backlog = STOPPED;
		wire.replies.restart = { restarted: 1 };
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Restart' } )
		);
		await waitFor( () => expect( reads( 'backlog' ) ).toBe( 4 ) );
		const waiting = row( 'Pairing items' );
		await waitFor( () =>
			expect( within( waiting ).getByText( STOPPED_LINE ) ).toBeVisible()
		);
		expect(
			within( waiting ).getByRole( 'button', {
				name: 'Seed the ontology',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Seeded. Fix sent; Refresh to check.' )
		).toBeNull();
	} );

	it( 'gives a reader the seed step as text', async () => {
		health.backlog = STOPPED;
		renderStrip();
		expect(
			await screen.findByText( 'An administrator can seed the ontology.' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Seed the ontology' } )
		).toBeNull();
	} );

	it( 'unknown held count reads as unknown, not zero', async () => {
		health.backlog = { ...STOPPED, held: null, since: null };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Resolving is stopped: the ontology is empty. An unknown number of items are waiting.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Resolving is stopped: the ontology is empty. An unknown number of items are waiting.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( /\b0 items/ ) ).toBeNull();
	} );

	it( 'reads a projector catching up for under five minutes as neutral', async () => {
		health.backlog = {
			...STOPPED,
			reason: 'behind_ontology',
			since: ago( 240 ),
		};
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. Catching up on the ontology.'
			)
		);
		expect(
			within( row( 'Pairing items' ) ).getByText( 'Catching up' )
		).toHaveClass( 'is-neutral' );
		expect(
			screen.queryByRole( 'button', { name: 'Seed the ontology' } )
		).toBeNull();
	} );

	it( 'reads a projector catching up with no known start as neutral', async () => {
		health.backlog = {
			...STOPPED,
			reason: 'behind_ontology',
			held: null,
			since: null,
		};
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. Catching up on the ontology.'
			)
		);
	} );

	it( 'turns a projector behind for over five minutes into a fault, offering nothing', async () => {
		const since = ago( 360 );
		health.backlog = { ...STOPPED, reason: 'behind_ontology', since };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`Resolving is held: the projector has been behind the ontology since ${ formatLocalDateTime(
					since
				) }.`
			)
		);
		expect(
			within( rows() ).getByText(
				`Resolving is held: the projector has been behind the ontology since ${ formatLocalDateTime(
					since
				) }.`
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Seed the ontology' } )
		).toBeNull();
	} );

	it( 'offers Re-resolve over the dropped days to a manager', async () => {
		health.backlog = {
			...HEALTHY.backlog,
			dropped: { first_day: '2026-10-27', last_day: '2026-10-28' },
		};
		wire.replies.reresolve = { ok: true, workers: 1 };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Items parked from 2026-10-27 to 2026-10-28 were dropped before they resolved.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Items parked from 2026-10-27 to 2026-10-28 were dropped before they resolved.'
			)
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-resolve' } ) );
		expect(
			await screen.findByText(
				'Re-resolving. Fix sent; Refresh to check.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'reresolve' ) ).toEqual( [
			[ '--since=2026-10-27', '--until=2026-10-28' ],
		] );
	} );

	it( 'gives a reader the re-resolve step as text', async () => {
		health.backlog = {
			...HEALTHY.backlog,
			dropped: { first_day: '2026-10-27', last_day: '2026-10-28' },
		};
		renderStrip();
		expect(
			await screen.findByText(
				'An administrator can re-resolve those days.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Re-resolve' } )
		).toBeNull();
	} );

	it( 'reads inactive as neutral, offering each composition to activate', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging', 'intel-workbench' ],
		};
		wire.replies.activate = { name: 'intel-workbench', active: true };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe( 'Collecting is off.' )
		);
		expect( within( row( 'Gathering' ) ).getByText( 'Off' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( row( 'Gathering' ) )
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'intel-staging', 'intel-workbench' ] );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'intel-workbench' } )
		);
		expect(
			await screen.findByText( 'Activated. Fix sent; Refresh to check.' )
		).toBeInTheDocument();
		expect( wire.sent( 'activate' ) ).toEqual( [
			[ '--name=intel-workbench' ],
		] );
		expect( wire.sentTo( 'activate' ) ).toEqual( [ 'topologies' ] );
		await waitFor( () => expect( reads( 'collection' ) ).toBe( 2 ) );
	} );

	it( 'gives a reader the activate step as text, once', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging', 'intel-workbench' ],
		};
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( 'Collecting is off.' )
		);
		expect(
			screen.getAllByText( 'An administrator can turn collecting on.' )
		).toHaveLength( 1 );
		expect(
			screen.queryByRole( 'button', { name: 'intel-staging' } )
		).toBeNull();
	} );

	it( 'offers no Activate while the slice names no composition', async () => {
		health.collection = { state: 'inactive', topologies: [] };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe( 'Collecting is off.' )
		);
		const command = within( row( 'Gathering' ) ).getByText(
			'wp nodes activate'
		);
		expect( command.tagName ).toBe( 'CODE' );
		expect( command.parentElement ).toHaveTextContent(
			'Collecting is off; wp nodes activate turns on the topology that composes Intelligence.'
		);
		expect(
			within( row( 'Gathering' ) ).queryAllByRole( 'button' )
		).toEqual( [] );
	} );

	it( 'names where the lists file belongs, and that empty will do', async () => {
		health.collection = {
			state: 'unconfigured',
			file: '/srv/kestrel-nodes/topologies/newspack-intelligence-lists.tsl',
		};
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'No lists file: create /srv/kestrel-nodes/topologies/newspack-intelligence-lists.tsl, empty if this site has no settings of its own.'
			)
		);
		expect(
			within( rows() ).getByText(
				'No lists file: create /srv/kestrel-nodes/topologies/newspack-intelligence-lists.tsl, empty if this site has no settings of its own.'
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Gathering' ) ).getByText( 'No lists file' )
		).toHaveClass( 'is-error' );
	} );

	it( 'tells a reader with no path that an administrator creates the lists file', async () => {
		health.collection = { state: 'unconfigured' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'An administrator can create the lists file; an empty one will do.'
			)
		);
		expect(
			within( rows() ).getByText(
				'An administrator can create the lists file; an empty one will do.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( /\/topologies\// ) ).toBeNull();
	} );

	it( 'restart offered only for stale', async () => {
		health.collection.state = 'stale';
		wire.replies.restart = { restarted: 1 };
		const { unmount } = renderStrip( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Restart' } )
		);
		expect(
			await screen.findByText( 'Restarted. Fix sent; Refresh to check.' )
		).toBeInTheDocument();
		expect( wire.sent( 'restart' ) ).toEqual( [
			[ '--types=intel-staging' ],
		] );
		expect( wire.sentTo( 'restart' ) ).toEqual( [ 'workers' ] );
		unmount();

		Core.reset();
		health.collection.state = 'down';
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The fleet revives a down worker within a minute; if it stays down, run `wp nodes doctor`.'
			)
		);
		expect(
			within( rows() ).getByText(
				'The fleet revives a down worker within a minute; if it stays down, run `wp nodes doctor`.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Restart' } )
		).toBeNull();
	} );

	it( 'names a deploy hold and how to lift it', async () => {
		health.collection.state = 'held';
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'A deploy hold is in place; `wp nodes start` lifts it.'
			)
		);
		expect(
			within( rows() ).getByText(
				'A deploy hold is in place; `wp nodes start` lifts it.'
			)
		).toBeInTheDocument();
		expect( within( row( 'Gathering' ) ).getByText( 'Held' ) ).toHaveClass(
			'is-warning'
		);
	} );

	it( 'gives a reader the restart step as text', async () => {
		health.collection.state = 'stale';
		renderStrip();
		expect(
			await screen.findByText(
				'An administrator can restart the worker.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Restart' } )
		).toBeNull();
	} );

	it( 'offers Add credential on an idle source, linking the Vault tab', async () => {
		health.sources.sources.push( {
			name: 'unthread',
			class: 'Unthread_Source',
			tier: 'restricted',
			state: 'idle',
			credential: {
				url: 'https://api.unthread.io',
				group: 'unthread-desk',
			},
		} );
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'No Vault entry for https://api.unthread.io in group unthread-desk.'
			)
		);
		expect(
			within( rows() ).getByText(
				'No Vault entry for https://api.unthread.io in group unthread-desk.'
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Help desk' ) ).getByRole( 'link', {
				name: 'Add credential',
			} )
		).toHaveAttribute( 'href', VAULT );
	} );

	it( 'shows a redacted idle source its state and the text step', async () => {
		health.sources.sources.push( {
			name: 'linear',
			class: 'Linear_Source',
			tier: 'internal',
			state: 'idle',
		} );
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'This source has no credential to fetch with.'
			)
		);
		expect(
			within( rows() ).getByText(
				'This source has no credential to fetch with.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'An administrator can add its credential.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'shows a reader without MANAGE the reason a source is idle', async () => {
		health.sources.sources.push( {
			name: 'linear',
			class: 'Context_A8C_Linear',
			tier: 'internal',
			state: 'idle',
			reason: 'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host',
		} );
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host'
			)
		);
		expect(
			within( rows() ).getByText(
				'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'An administrator can add its credential.' )
		).toBeNull();
	} );

	it( 'shows an idle source the reason it names, offering no Vault link', async () => {
		health.sources.sources.push( {
			name: 'slack',
			class: 'Context_A8C_Slack',
			tier: 'internal',
			state: 'idle',
			reason: 'not signed in to ContextA8C; sign in to ContextA8C on the host',
		} );
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'not signed in to ContextA8C; sign in to ContextA8C on the host'
			)
		);
		expect(
			within( rows() ).getByText(
				'not signed in to ContextA8C; sign in to ContextA8C on the host'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'offers one Fetch all sources now, on the first failing source, naming its error and since', async () => {
		health.sources.sources[ 0 ] = failingSource(
			'github',
			'GitHub answered an error for https://api.github.com: rate limited'
		);
		health.sources.sources[ 2 ] = failingSource(
			'news',
			'no RSS or Atom feed at https://search-7713.example/rss'
		);
		wire.replies.fetch_now = { ok: true, workers: 1 };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`GitHub answered an error for https://api.github.com: rate limited, failing since ${ SINCE_LOCAL }`
			)
		);
		expect(
			within( rows() ).getByText(
				`GitHub answered an error for https://api.github.com: rate limited, failing since ${ SINCE_LOCAL }`
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: 'Fetch all sources now' } )
		).toHaveLength( 1 );
		fireEvent.click(
			within( row( 'Code host' ) ).getByRole( 'button', {
				name: 'Fetch all sources now',
			} )
		);
		expect(
			await screen.findByText( 'Fetching. Fix sent; Refresh to check.' )
		).toBeInTheDocument();
		expect( wire.sent( 'fetch_now' ) ).toEqual( [ [] ] );
	} );

	it( 'mounts the header notice’s fix once, speaking the fault as text', async () => {
		health.sources.sources[ 2 ] = failingSource(
			'news',
			'no RSS or Atom feed at https://search-4417.example/rss'
		);
		wire.replies.fetch_now = { ok: true, workers: 3 };
		renderOnPage( <HealthStrip canManage={ true } slot={ undefined } /> );
		const notice = await waitFor( () => {
			const found = document.querySelector(
				'.newspack-intelligence__fault'
			);
			expect( found ).not.toBeNull();
			return found;
		} );
		fireEvent.click(
			within( notice ).getByRole( 'button', {
				name: 'Fetch all sources now',
			} )
		);
		expect(
			await within( notice ).findByText(
				'Fetching. Fix sent; Refresh to check.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'fetch_now' ) ).toEqual( [ [] ] );
	} );

	it( 'mounts the notice’s fix and the Details panel’s apart, each sending its own', async () => {
		health.sources.sources[ 2 ] = failingSource(
			'news',
			'no RSS or Atom feed at https://search-6620.example/rss'
		);
		wire.replies.fetch_now = { ok: true, workers: 4 };
		renderOnPage( <HealthStrip canManage={ true } slot={ undefined } /> );
		const notice = await waitFor( () => {
			const found = document.querySelector(
				'.newspack-intelligence__fault'
			);
			expect( found ).not.toBeNull();
			return found;
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Details' } ) );
		const fetch = { name: 'Fetch all sources now' };
		fireEvent.click( within( rows() ).getByRole( 'button', fetch ) );
		fireEvent.click( within( notice ).getByRole( 'button', fetch ) );
		await waitFor( () =>
			expect( wire.sent( 'fetch_now' ) ).toEqual( [ [], [] ] )
		);
	} );

	it( 'mounts the header strip and a health block on one page, each sending its own fetch', async () => {
		health.sources.sources[ 1 ] = failingSource(
			'linear',
			'Linear answered an error for https://api.linear.app: 503'
		);
		wire.replies.fetch_now = { ok: true, workers: 2 };
		renderOnPage(
			<>
				<HealthStrip canManage={ true } slot={ undefined } />
				<HealthBlock operator={ true } detail="sources" />
			</>
		);
		const fetch = { name: 'Fetch all sources now' };
		const block = await within( rows() ).findByRole( 'button', fetch );
		const notice = document.querySelector(
			'.newspack-intelligence__fault'
		);
		fireEvent.click( block );
		fireEvent.click( within( notice ).getByRole( 'button', fetch ) );
		await waitFor( () =>
			expect( wire.sent( 'fetch_now' ) ).toEqual( [ [], [] ] )
		);
	} );

	it( 'gives a reader without MANAGE a redacted failing source and the fetch step', async () => {
		health.sources.sources[ 0 ] = {
			...okSource( 'github' ),
			state: 'failing',
			failing_since: SINCE,
		};
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`Failing since ${ SINCE_LOCAL }`
			)
		);
		expect(
			within( rows() ).getByText( `Failing since ${ SINCE_LOCAL }` )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'An administrator can fetch all sources now.' )
		).toBeInTheDocument();
	} );

	it( 'names a source that has not reported yet, and stays neutral', async () => {
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 2 of 3 sources; news has not reported yet. Nothing waiting.'
			)
		);
		expect(
			within( row( 'news' ) ).getByText( 'No pass has reported yet.' )
		).toBeInTheDocument();
	} );

	it( 'names every source that has not reported yet, as a list', async () => {
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		health.sources.sources.push( okSource( 'slack' ) );
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 2 of 4 sources; github and news have not reported yet. Nothing waiting.'
			)
		);
	} );

	it( 'counts a total of one source in the singular', async () => {
		health.sources.sources = [ { name: 'github', state: 'unknown' } ];
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 0 of 1 source; github has not reported yet. Nothing waiting.'
			)
		);
	} );

	it( 'claims no health for a source in a state it does not recognise', async () => {
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'paused' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
	} );

	it( 'joins the unreported names in the page’s own language', async () => {
		document.documentElement.lang = 'de-DE';
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		try {
			renderStrip();
			await waitFor( () =>
				expect( line().textContent ).toBe(
					'Collecting from 1 of 3 sources; github und news have not reported yet. Nothing waiting.'
				)
			);
		} finally {
			document.documentElement.lang = '';
		}
	} );

	it( 'joins the unreported names in the default language when the page’s is malformed', async () => {
		document.documentElement.lang = 'en_US!';
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		try {
			renderStrip();
			await waitFor( () =>
				expect( line().textContent ).toBe(
					'Collecting from 1 of 3 sources; github and news have not reported yet. Nothing waiting.'
				)
			);
		} finally {
			document.documentElement.lang = '';
		}
	} );

	it( 'claims no health while another row is unknown beside an unreported source', async () => {
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		health.extraction = { mode: 'unknown' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
	} );

	it( 'counts the items waiting to resolve or retry while nothing holds', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 9 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 9 items are waiting to resolve or retry.'
			)
		);
	} );

	it( 'counts one item waiting to resolve or retry in the singular', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 1 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 1 item is waiting to resolve or retry.'
			)
		);
	} );

	it( 'claims nothing waiting only while the queue’s count is known', async () => {
		health.backlog = { ...HEALTHY.backlog, held: null };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. Resolving is running; how many items wait to retry is not known.'
			)
		);
	} );

	it( 'counts the model calls on a row of their own and on the line, never under Extraction', async () => {
		health.extraction.queue = { new: 3, backlog: 5741, in_flight: 4 };
		const calls = '5,744 calls waiting (5,741 backlog, 3 new), 4 in flight';
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`Collecting from 3 sources. ${ calls }`
			)
		);
		expect(
			within( row( 'Queued asks' ) ).getByText( calls )
		).toBeInTheDocument();
		expect(
			within( row( 'Queued asks' ) ).getByText( 'Busy' )
		).toHaveClass( 'is-neutral' );
		expect(
			within( row( 'Digests' ) ).getByText(
				`By the model; last extraction ${ LAST_OK_LOCAL }.`
			)
		).toBeInTheDocument();
	} );

	it( 'counts one model call waiting in the singular, leaving out a class it holds none of', async () => {
		health.extraction.queue = { new: 1, backlog: 0, in_flight: 1 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 1 call waiting (1 new), 1 in flight'
			)
		);
	} );

	it( 'names only the backlog when no new call waits', async () => {
		health.extraction.queue = { new: 0, backlog: 27, in_flight: 0 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 27 calls waiting (27 backlog)'
			)
		);
	} );

	it( 'counts calls in flight with none waiting', async () => {
		health.extraction.queue = { new: 0, backlog: 0, in_flight: 2 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 2 in flight'
			)
		);
	} );

	it( 'shows no model-call row while the queue holds no call, or its count is not known', async () => {
		for ( const queue of [ null, { new: 0, backlog: 0, in_flight: 0 } ] ) {
			health.extraction.queue = queue;
			const { unmount } = renderStrip();
			await waitFor( () =>
				expect( line().textContent ).toBe( HEALTHY_LINE )
			);
			expect( row( 'Digests' ) ).toBeDefined();
			expect( row( 'Queued asks' ) ).toBeUndefined();
			unmount();
		}
	} );

	it( 'counts the model calls beside the items waiting to resolve', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 9 };
		health.extraction.queue = { new: 0, backlog: 12, in_flight: 2 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. 9 items are waiting to resolve or retry. 12 calls waiting (12 backlog), 2 in flight'
			)
		);
	} );

	it( 'keeps the backlog row’s cause beside the calls while it is not idle', async () => {
		health.backlog = { ...HEALTHY.backlog, held: null };
		health.extraction.queue = { new: 5, backlog: 0, in_flight: 0 };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Collecting from 3 sources. Resolving is running; how many items wait to retry is not known. 5 calls waiting (5 new)'
			)
		);
	} );

	it( 'counts the model calls in the page’s own language', async () => {
		document.documentElement.lang = 'de-DE';
		health.extraction.queue = { new: 2, backlog: 1309, in_flight: 3 };
		try {
			renderStrip();
			await waitFor( () =>
				expect( line().textContent ).toBe(
					'Collecting from 3 sources. 1.311 calls waiting (1.309 backlog, 2 new), 3 in flight'
				)
			);
		} finally {
			document.documentElement.lang = '';
		}
	} );

	it( 'renders every time in the browser’s zone, a day ahead of UTC here', () => {
		expect( formatLocalDateTime( SINCE ) ).toBe( SINCE_LOCAL );
		expect( new Date( SINCE * 1000 ).toISOString().slice( 0, 10 ) ).toBe(
			'2026-10-30'
		);
	} );

	it( 'keeps every row neutral while collection is off', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging' ],
		};
		health.backlog = STOPPED;
		health.sources.sources.push( {
			name: 'linear',
			class: 'Linear_Source',
			tier: 'internal',
			state: 'idle',
			credential: {
				url: 'https://api.linear.app/graphql',
				group: 'linear',
			},
		} );
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe( 'Collecting is off.' )
		);
		expect( within( row( 'Tracker' ) ).getByText( 'Stopped' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( row( 'Pairing items' ) ).getByText( 'Held' )
		).toHaveClass( 'is-neutral' );
	} );

	it( 'says collection is off even while a source reads unknown', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging' ],
		};
		health.sources.sources[ 1 ] = { name: 'feed', state: 'unknown' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( 'Collecting is off.' )
		);
	} );

	it( 'reads a state it does not recognise as unknown, not blank', async () => {
		health.collection.state = 'paused';
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
		const collection = row( 'Gathering' );
		expect( within( collection ).getByText( 'Unknown' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( collection ).getByText( 'State not recognised: paused.' )
		).toBeInTheDocument();
	} );

	it( 'reads an inherited key as a state it does not recognise', async () => {
		health.backlog = { ...HEALTHY.backlog, reason: 'toString' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
		expect(
			within( row( 'Pairing items' ) ).getByText(
				'State not recognised: toString.'
			)
		).toBeInTheDocument();
	} );

	it( 'names the Vault group of a heuristic extractor', async () => {
		health.extraction = {
			mode: 'heuristic',
			vault_group: 'ai-proxy',
			last_ok_at: null,
			last_error: null,
			failing_since: null,
		};
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Extracting with the heuristic only: Vault group ai-proxy holds no entry for the model call.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Extracting with the heuristic only: Vault group ai-proxy holds no entry for the model call.'
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Digests' ) ).getByRole( 'link', {
				name: 'Open the Vault',
			} )
		).toHaveAttribute( 'href', VAULT );
	} );

	it( 'says the model call is not configured, naming each setter left unset', async () => {
		health.extraction = {
			mode: 'heuristic',
			vault_group: 'ai-relay-7731',
			unset: [ 'set_protocol', 'set_api_url' ],
			last_ok_at: null,
			last_error: null,
			failing_since: null,
		};
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Extracting with the heuristic only: the model call is not configured; the lists file sets no set_protocol, set_api_url.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Extracting with the heuristic only: the model call is not configured; the lists file sets no set_protocol, set_api_url.'
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Digests' ) ).queryByRole( 'link', {
				name: 'Open the Vault',
			} )
		).toBeNull();
		expect(
			within( row( 'Digests' ) ).getByText(
				'An administrator can set them on intel:llm in the site’s lists file.'
			)
		).toBeInTheDocument();
	} );

	it( 'gives a redacted heuristic extractor plain copy and the text step', async () => {
		health.extraction = { mode: 'heuristic' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'Extracting with the heuristic only: the model call has no Vault entry.'
			)
		);
		expect(
			within( rows() ).getByText(
				'Extracting with the heuristic only: the model call has no Vault entry.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( 'An administrator can add its Vault entry.' )
		).toBeInTheDocument();
	} );

	it( 'reads a failing extractor as a fault with its error', async () => {
		health.extraction = {
			mode: 'call',
			vault_group: 'ai-proxy',
			last_ok_at: LAST_OK,
			last_error: 'HTTP 502 https://models.example/v1/messages',
			failing_since: SINCE,
		};
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				`HTTP 502 https://models.example/v1/messages, failing since ${ SINCE_LOCAL }`
			)
		);
		expect(
			within( rows() ).getByText(
				`HTTP 502 https://models.example/v1/messages, failing since ${ SINCE_LOCAL }`
			)
		).toBeInTheDocument();
		expect( within( row( 'Digests' ) ).getByText( 'Failing' ) ).toHaveClass(
			'is-error'
		);
	} );

	it( 'reads a call extractor that has not answered yet', async () => {
		health.extraction = { mode: 'call', last_ok_at: null };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		expect(
			within( row( 'Digests' ) ).getByText( 'By the model.' )
		).toBeInTheDocument();
		expect( within( row( 'Digests' ) ).getByText( 'Running' ) ).toHaveClass(
			'is-success'
		);
	} );

	it( 'reads an extractor that has not reported as unknown', async () => {
		health.extraction = { mode: 'unknown' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
		expect(
			within( row( 'Digests' ) ).getByText(
				'No extraction has reported yet.'
			)
		).toBeInTheDocument();
	} );

	it( 'reads an unknown extractor whose newest extraction used the heuristic as unknown', async () => {
		health.extraction = { mode: 'unknown', last_mode: 'heuristic' };
		renderStrip( true );
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
		const extraction = row( 'Digests' );
		expect( within( extraction ).getByText( 'Unknown' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( extraction ).getByText(
				'No extraction has reported since the cache was cleared; the newest used the heuristic.'
			)
		).toBeInTheDocument();
		expect( within( extraction ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'reads an unknown extractor whose newest extraction used the model as unknown', async () => {
		health.extraction = { mode: 'unknown', last_mode: 'call' };
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health is not fully known.'
			)
		);
		expect(
			within( row( 'Digests' ) ).getByText(
				'No extraction has reported since the cache was cleared; the newest used the model.'
			)
		).toBeInTheDocument();
	} );

	it( 'lists the faults first, the worst naming the line', async () => {
		health.sources.sources[ 0 ] = {
			...okSource( 'github' ),
			state: 'failing',
			failing_since: SINCE,
		};
		health.collection.state = 'held';
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'A deploy hold is in place; `wp nodes start` lifts it.'
			)
		);
		const names = within( rows() )
			.getAllByRole( 'listitem' )
			.map( ( item ) => item.querySelector( 'strong' ).textContent );
		expect( names.slice( 0, 2 ) ).toEqual( [ 'Gathering', 'Code host' ] );
	} );

	describe( 'a dead client feed', () => {
		const KEY = 'organization:shoal-crier';
		const DOMAIN = 'shoalcrier.example';
		const DEAD = `Shoal Crier (${ DOMAIN }) has not resolved since ${ SINCE_LOCAL }.`;
		const OTHER = 'organization:kittiwake-mail';
		const deadRows = () =>
			within( rows() )
				.getAllByRole( 'listitem' )
				.filter(
					( item ) =>
						'Client feed' ===
						item.querySelector( 'strong' ).textContent
				);
		const markIn = ( item ) =>
			within( item ).getByRole( 'button', {
				name: 'Mark former client',
			} );
		const mark = () => markIn( row( 'Client feed' ) );
		const asked = () =>
			screen.getByRole( 'dialog', {
				name: 'End Shoal Crier as a client?',
			} );
		// Mark a client, then confirm the end in the dialog it opens.
		const markAndEnd = ( button ) => {
			fireEvent.click( button );
			fireEvent.click(
				within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
					name: 'End client',
				} )
			);
		};
		const dead = ( entityKey, name, domain ) => ( {
			entity_key: entityKey,
			name,
			domain,
			since: SINCE,
		} );

		beforeEach( () => {
			health.sources.dead_clients = [
				dead( KEY, 'Shoal Crier', DOMAIN ),
			];
		} );

		it( 'is a fault naming its client, its host and since when', async () => {
			renderStrip();
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			expect(
				within( row( 'Client feed' ) ).getByText( 'Not answering' )
			).toHaveClass( 'is-error' );
		} );

		it( 'asks before it marks a former client, naming the dead feed, and sends nothing until End client', async () => {
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			fireEvent.click( mark() );
			expect( asked() ).toHaveTextContent(
				`“${ DOMAIN }”, Client feed, ${ SINCE_LOCAL }`
			);
			expect(
				within( asked() ).queryByRole( 'button', {
					name: 'Not about this client',
				} )
			).toBeNull();
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
			);
			expect( wire.sent( 'end_role' ) ).toEqual( [] );
			expect( deadRows() ).toHaveLength( 1 );
		} );

		it( 'closes its dialog with nothing sent and the client still listed', async () => {
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			fireEvent.click( mark() );
			fireEvent.click(
				within( asked() ).getByRole( 'button', { name: 'Close' } )
			);
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
			);
			expect( wire.sent( 'end_role' ) ).toEqual( [] );
			expect( mark() ).toBeInTheDocument();
		} );

		it( 'marks a former client: end_role, then assert_role former_client, then reads again', async () => {
			wire.replies.end_role = { ok: true, event_id: 'ev-end-5520' };
			wire.replies.assert_role = { ok: true, event_id: 'ev-former-5520' };
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			await waitFor( () => {
				expect( wire.sent( 'end_role' ) ).toEqual( [
					[ `--key=${ KEY }`, '--role=client' ],
				] );
				expect( wire.sent( 'assert_role' ) ).toEqual( [
					[ `--key=${ KEY }`, '--role=former_client' ],
				] );
				expect( reads( 'sources' ) ).toBe( 2 );
			} );
			expect(
				document.querySelector( '.components-snackbar' )
			).toHaveTextContent( 'Shoal Crier is now a former client.' );
		} );

		it( 'hides the client from the click while a read still lists it', async () => {
			wire.replies.end_role = { ok: true, event_id: 'ev-end-5521' };
			wire.replies.assert_role = { ok: true, event_id: 'ev-former-5521' };
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			expect( within( strip() ).queryByText( /Shoal Crier/ ) ).toBeNull();
			await waitFor( () => expect( reads( 'sources' ) ).toBe( 2 ) );
			expect( within( strip() ).queryByText( /Shoal Crier/ ) ).toBeNull();
			expect( wire.sent( 'end_role' ) ).toHaveLength( 1 );
		} );

		it( 'hides every dead domain of the client it ends', async () => {
			health.sources.dead_clients.push(
				dead( KEY, 'Shoal Crier', 'shoal-archive-3310.example' ),
				dead( OTHER, 'Kittiwake Mail', 'kittiwakemail.example' )
			);
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			expect( deadRows() ).toHaveLength( 3 );
			markAndEnd( mark() );
			expect( deadRows() ).toHaveLength( 1 );
			expect( deadRows()[ 0 ] ).toHaveTextContent( 'Kittiwake Mail' );
		} );

		it( 'shows the client again once a read stops listing it and lists it anew', async () => {
			wire.replies.end_role = { ok: true, event_id: 'ev-end-5522' };
			wire.replies.assert_role = { ok: true, event_id: 'ev-former-5522' };
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			await waitFor( () => expect( reads( 'sources' ) ).toBe( 2 ) );
			health.sources.dead_clients = [];
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Refresh' } )
			);
			await waitFor( () => expect( reads( 'sources' ) ).toBe( 3 ) );
			health.sources.dead_clients = [
				dead( KEY, 'Shoal Crier', DOMAIN ),
			];
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Refresh' } )
			);
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
		} );

		it( 'asserts former_client only once the end lands', async () => {
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			await waitFor( () =>
				expect( wire.sent( 'end_role' ) ).toHaveLength( 1 )
			);
			expect( wire.sent( 'assert_role' ) ).toEqual( [] );
		} );

		it( 'holds every other client\u2019s button while a pair is owed', async () => {
			health.sources.dead_clients.push(
				dead( OTHER, 'Kittiwake Mail', 'kittiwakemail.example' )
			);
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( markIn( deadRows()[ 0 ] ) );
			const other = markIn( deadRows()[ 0 ] );
			expect( other ).toHaveAttribute( 'aria-disabled', 'true' );
			fireEvent.click( other );
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			await waitFor( () =>
				expect( wire.sent( 'end_role' ) ).toHaveLength( 1 )
			);
			expect( deadRows() ).toHaveLength( 1 );
		} );

		it( 'shows a refused end on its row again and asserts nothing', async () => {
			wire.replies.end_role = () =>
				new Error( `${ KEY } holds no open client role` );
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			expect(
				await screen.findByText( `${ KEY } holds no open client role` )
			).toHaveClass( 'is-error' );
			expect(
				within( row( 'Client feed' ) ).getByText(
					`${ KEY } holds no open client role`
				)
			).toBeInTheDocument();
			expect( wire.sent( 'assert_role' ) ).toEqual( [] );
			expect( mark() ).toHaveAttribute( 'aria-disabled', 'false' );
			expect(
				document.querySelector( '.components-snackbar' )
			).toBeNull();
		} );

		it( 'keeps the client hidden and names it on the strip when its assert is refused', async () => {
			wire.replies.end_role = { ok: true, event_id: 'ev-end-5523' };
			wire.replies.assert_role = () => {
				health.sources.dead_clients = [];
				return new Error( `${ KEY } already holds former_client` );
			};
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( mark() );
			const alert = await screen.findByRole( 'alert' );
			await waitFor( () => expect( reads( 'sources' ) ).toBe( 2 ) );
			expect( screen.queryByText( /has not resolved/ ) ).toBeNull();
			expect( alert ).toHaveTextContent(
				`Shoal Crier (${ KEY }) is no longer a client, but former_client was not recorded: ${ KEY } already holds former_client`
			);
			expect( strip() ).toContainElement( alert );
		} );

		it( 'moves focus to the next row when the marked row leaves', async () => {
			health.sources.dead_clients.push(
				dead( OTHER, 'Kittiwake Mail', 'kittiwakemail.example' )
			);
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			const button = markIn( deadRows()[ 0 ] );
			button.focus();
			markAndEnd( button );
			expect( document.activeElement ).toBe( deadRows()[ 0 ] );
			expect( document.activeElement ).toHaveTextContent(
				'Kittiwake Mail'
			);
		} );

		it( 'leaves focus alone on a click while a pair is owed', async () => {
			wire.replies.end_role = { ok: true, event_id: 'ev-end-5524' };
			wire.replies.assert_role = { ok: true, event_id: 'ev-former-5524' };
			health.sources.dead_clients.push(
				dead( OTHER, 'Kittiwake Mail', 'kittiwakemail.example' ),
				dead(
					'organization:gannet-post',
					'Gannet Post',
					'gannetpost.example'
				)
			);
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( markIn( deadRows()[ 0 ] ) );
			const waiting = markIn( deadRows()[ 1 ] );
			waiting.focus();
			fireEvent.click( waiting );
			await waitFor( () => expect( reads( 'sources' ) ).toBe( 2 ) );
			expect( wire.sent( 'end_role' ) ).toHaveLength( 1 );
			expect( document.activeElement ).toBe( waiting );
		} );

		it( 'moves focus to the row now in its place when the marked row was the last fault', async () => {
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			const button = mark();
			button.focus();
			markAndEnd( button );
			expect( document.activeElement ).toBe( row( 'Gathering' ) );
		} );

		it( 'shows a refusal on the row of the client it refused alone', async () => {
			health.sources.dead_clients.push(
				dead( OTHER, 'Kittiwake Mail', 'kittiwakemail.example' )
			);
			wire.replies.end_role = () =>
				new Error( `${ KEY } holds no open client role` );
			renderStrip( true );
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			markAndEnd( markIn( deadRows()[ 0 ] ) );
			await screen.findByText( `${ KEY } holds no open client role` );
			const [ shoal, kittiwake ] = deadRows();
			expect( shoal ).toHaveTextContent(
				`${ KEY } holds no open client role`
			);
			expect( kittiwake ).not.toHaveTextContent( 'holds no open' );
		} );

		it( 'gives a reader the step as text, mounting no write', async () => {
			renderStrip();
			await waitFor( () => expect( line().textContent ).toBe( DEAD ) );
			expect( within( rows() ).getByText( DEAD ) ).toBeInTheDocument();
			expect(
				within( row( 'Client feed' ) ).getByText(
					'An administrator can mark it a former client.'
				)
			).toBeInTheDocument();
			expect(
				Core.node( 'health-block:former-client:end_role:fetch' )
			).toBeFalsy();
		} );
	} );

	it( 'mounts a write only for a fix the reader may send', async () => {
		const writes = [
			'health-block:seed_ontology',
			'health-block:reresolve',
			'health-block:fetch_now',
			'health-block:restart',
		].map( ( scope ) => `${ scope }:fetch` );
		health.collection.state = 'stale';
		health.backlog = {
			...STOPPED,
			dropped: { first_day: '2026-10-27', last_day: '2026-10-28' },
		};
		health.sources.sources[ 0 ] = failingSource( 'github', 'rate limited' );
		const { unmount } = renderStrip();
		await screen.findByText( 'An administrator can seed the ontology.' );
		expect( writes.filter( ( name ) => Core.node( name ) ) ).toEqual( [] );
		unmount();

		Core.reset();
		renderStrip( true );
		await screen.findByRole( 'button', { name: 'Seed the ontology' } );
		expect( writes.filter( ( name ) => Core.node( name ) ) ).toEqual(
			writes
		);
	} );

	it( 'reads every slice again on Refresh', async () => {
		renderStrip();
		await waitFor( () =>
			expect( line().textContent ).toBe( HEALTHY_LINE )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Refresh' } ) );
		await waitFor( () => {
			for ( const what of Object.keys( HEALTHY ) ) {
				expect( reads( what ) ).toBe( 2 );
			}
		} );
	} );

	it( 'says so while a slice is owed, and shows a refused read', async () => {
		wire.replies.health = ( [ what ] ) =>
			'sources' === what ? new Error( 'NOT_AVAILABLE' ) : health[ what ];
		renderStrip();
		expect( line().textContent ).toBe( 'Reading the pipeline’s health…' );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'NOT_AVAILABLE' ) ).toBeNull();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		await waitFor( () =>
			expect( line().textContent ).toBe(
				'The pipeline’s health could not be read in full.'
			)
		);
	} );
} );

describe( 'the header phrase and the fault notice', () => {
	/** 2026-10-31 14:02 in Auckland, 01:02 UTC. */
	const STOPPED_AT = Date.UTC( 2026, 9, 31, 1, 2 ) / 1000;

	/** A GitHub source with no credential, as an operator reads it. */
	const uncredentialed = () => ( {
		...okSource( 'github' ),
		state: 'idle',
		last_ok_at: STOPPED_AT,
		credential: {
			url: 'https://api.github.example',
			group: 'tracker-keys-4410',
		},
	} );

	/**
	 * The strip, its phrase inline, as a page standing alone renders it.
	 *
	 * @param {boolean} canManage Whether the reader is an operator.
	 */
	const renderHeader = ( canManage ) =>
		renderOnPage(
			<HealthStrip canManage={ canManage } slot={ undefined } />
		);

	const phrase = () =>
		document.querySelector( '.newspack-intelligence__collecting' );

	it( 'reads healthy as collecting, updated the newest answer’s relative time, the absolute one in its title', async () => {
		const newest = ago( 150 );
		health.sources.sources[ 0 ].last_ok_at = ago( 4000 );
		health.sources.sources[ 1 ].last_ok_at = newest;
		renderHeader( false );
		const time = await screen.findByText( '2 min ago' );
		expect( time ).toHaveAttribute(
			'title',
			formatLocalDateTime( newest )
		);
		expect( phrase() ).toHaveTextContent(
			`Collecting, updated 2 min ago (${ formatLocalDateTime( newest ) })`
		);
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'reads only the time under 600 px', async () => {
		const viewport = installViewport( true );
		try {
			const newest = ago( 150 );
			health.sources.sources[ 1 ].last_ok_at = newest;
			renderHeader( false );
			await screen.findByText( '2 min ago' );
			expect( phrase() ).toHaveTextContent(
				`2 min ago (${ formatLocalDateTime( newest ) })`,
				{ normalizeWhitespace: true }
			);
			expect( phrase().textContent ).not.toMatch( /^Collecting/ );
		} finally {
			viewport.restore();
		}
	} );

	it( 'names the items waiting while nothing is wrong', async () => {
		health.backlog.held = 37;
		renderHeader( false );
		await waitFor( () =>
			expect( phrase() ).toHaveTextContent(
				'Collecting; 37 items waiting'
			)
		);
	} );

	it( 'says collection is off', async () => {
		health.collection = { state: 'inactive', topologies: [] };
		renderHeader( false );
		await waitFor( () =>
			expect( phrase() ).toHaveTextContent( 'Collection is off.' )
		);
	} );

	it( 'reads Checking collection… while owed, then names a failed read with Refresh for an operator', async () => {
		wire.replies.health = ( [ what ] ) =>
			'collection' === what
				? new Error( 'NOT_AVAILABLE' )
				: health[ what ];
		renderHeader( true );
		expect( phrase() ).toHaveTextContent( 'Checking collection…' );
		await waitFor( () =>
			expect( phrase() ).toHaveTextContent(
				'Collection status could not be read.'
			)
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Refresh' } ) );
		await waitFor( () => expect( reads( 'collection' ) ).toBe( 2 ) );
	} );

	it( 'gives a reader a failed read with no Refresh', async () => {
		wire.replies.health = ( [ what ] ) =>
			'collection' === what
				? new Error( 'NOT_AVAILABLE' )
				: health[ what ];
		renderHeader( false );
		await waitFor( () =>
			expect( phrase() ).toHaveTextContent(
				'Collection status could not be read.'
			)
		);
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'shows a reader the fault as one sentence with no controls', async () => {
		const { credential, ...redacted } = uncredentialed();
		health.sources.sources[ 0 ] = redacted;
		expect( credential.group ).toBe( 'tracker-keys-4410' );
		renderHeader( false );
		const notice = await screen.findByText(
			'Some sources stopped updating at 14:02, so recent items may be missing.',
			{ selector: 'p' }
		);
		expect( notice.closest( '.components-notice' ) ).toHaveClass(
			'is-warning'
		);
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( screen.queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'shows an operator the worst fault in a notice with its fix, and Details', async () => {
		health.sources.sources[ 0 ] = uncredentialed();
		renderHeader( true );
		const fix = await screen.findByRole( 'link', {
			name: 'Add credential',
		} );
		expect( fix ).toHaveAttribute( 'href', VAULT );
		expect( fix.closest( '.components-notice' ) ).toHaveTextContent(
			'No Vault entry for https://api.github.example in group tracker-keys-4410.'
		);
		expect(
			screen.getByRole( 'button', { name: 'Details' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Some sources stopped updating/ )
		).toBeNull();
	} );

	it( 'opens the details for an operator, each source with its state', async () => {
		health.sources.sources[ 1 ].failed = 8;
		health.sources.sources[ 1 ].requests = 411;
		health.sources.sources[ 2 ].failed = 50;
		health.sources.sources[ 2 ].requests = 411;
		renderHeader( true );
		await screen.findByText( /Collecting, updated/ );
		fireEvent.click( screen.getByRole( 'button', { name: 'Details' } ) );
		await screen.findByRole( 'region', { name: 'Collection' } );
		const feed = row( 'Web feed' );
		expect( within( feed ).getByText( 'Running' ) ).toHaveClass(
			'is-success'
		);
		expect( within( feed ).getByText( '8 of 411 failed' ) ).toHaveClass(
			'is-muted'
		);
		const news = row( 'news' );
		expect( within( news ).getByText( 'Failing' ) ).toHaveClass(
			'is-warning'
		);
		expect(
			within( news ).getByText( '50 of 411 failed' )
		).not.toHaveClass( 'is-muted' );
	} );

	it( 'reads a failed share at FAILING_SHARE as failing', async () => {
		expect( FAILING_SHARE ).toBe( 0.1 );
		health.sources.sources[ 1 ].failed = 41;
		health.sources.sources[ 1 ].requests = 410;
		renderHeader( true );
		await screen.findByText( /Collecting, updated/ );
		fireEvent.click( screen.getByRole( 'button', { name: 'Details' } ) );
		await screen.findByRole( 'region', { name: 'Collection' } );
		expect(
			within( row( 'Web feed' ) ).getByText( 'Failing' )
		).toHaveClass( 'is-warning' );
	} );
} );
