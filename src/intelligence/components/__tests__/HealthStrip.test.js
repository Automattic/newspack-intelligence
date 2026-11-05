/* eslint-env jest */
/**
 * HealthStrip reads the four `health` slices once, shows one line while
 * nothing is wrong, opens on a fault with the fault rows first, offers each
 * fix to a reader holding its role and the text step to anyone else, and
 * re-reads every slice once a fix lands or Refresh is pressed. A state it
 * does not recognise reads as unknown, and the line then claims no health.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { HEALTHY, LAST_OK, okSource } from '../../__tests__/healthFixture';
import { formatLocalDateTime } from '@newspack-nodes/shared/utils/formatUtils';
import HealthStrip from '../HealthStrip';

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

let wire;
let health;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	health = JSON.parse( JSON.stringify( HEALTHY ) );
	wire.replies.health = ( [ what ] ) => health[ what ];
} );

const reads = ( what ) =>
	wire.sent( 'health' ).filter( ( [ slice ] ) => what === slice ).length;

const strip = () => screen.getByRole( 'region', { name: 'Pipeline health' } );

const toggle = () =>
	within( strip() ).getByRole( 'button', { name: /details/ } );

/** The strip's one line: its first status, ahead of every fix's. */
const line = () => within( strip() ).getAllByRole( 'status' )[ 0 ];

const rows = () => within( strip() ).getByRole( 'list' );

/**
 * Wait for the line to read `text`.
 *
 * @param {string} text The line.
 */
const lineReads = ( text ) =>
	waitFor( () => expect( line().textContent ).toBe( text ) );

/**
 * Wait for the line, and one row, to read `text`.
 *
 * @param {string} text The worst fault's text.
 */
async function worstFault( text ) {
	await lineReads( text );
	expect( within( rows() ).getByText( text ) ).toBeInTheDocument();
}

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

function renderStrip( { canTune = false, canManage = false } = {} ) {
	return render(
		<HealthStrip canTune={ canTune } canManage={ canManage } />
	);
}

describe( 'HealthStrip', () => {
	it( 'renders collapsed healthy line', async () => {
		renderStrip();
		await lineReads( HEALTHY_LINE );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
		const controlled = document.getElementById(
			toggle().getAttribute( 'aria-controls' )
		);
		expect( strip() ).toContainElement( controlled );
		expect( controlled ).not.toBeVisible();
		expect( screen.getByText( 'github' ) ).not.toBeVisible();
		for ( const what of Object.keys( HEALTHY ) ) {
			expect( reads( what ) ).toBe( 1 );
		}
	} );

	it( 'counts one source in the singular', async () => {
		health.sources.sources = [ okSource( 'github' ) ];
		renderStrip();
		await lineReads( 'Collecting from 1 source. Nothing waiting.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'shows every row when opened, each state as a pill', async () => {
		health.sources.sources[ 1 ].failed = 37;
		health.sources.sources[ 1 ].requests = 451;
		renderStrip();
		await lineReads( HEALTHY_LINE );
		fireEvent.click( toggle() );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( rows() ).toBeVisible();
		expect(
			within( row( 'feed' ) ).getByText(
				`Last answered ${ LAST_OK_LOCAL } · 37 of 451 requests failed`
			)
		).toBeInTheDocument();
		expect(
			within( row( 'Extraction' ) ).getByText(
				`By the AI proxy; last extraction ${ LAST_OK_LOCAL }.`
			)
		).toBeInTheDocument();
		expect( within( row( 'github' ) ).getByText( 'ok' ) ).toHaveClass(
			'newspack-nodes-status-badge',
			'is-pill',
			'is-success'
		);
		fireEvent.click( toggle() );
		expect( screen.getByText( 'feed' ) ).not.toBeVisible();
	} );

	it( 'counts one failed request in the singular', async () => {
		health.sources.sources[ 0 ].failed = 1;
		health.sources.sources[ 0 ].requests = 1;
		renderStrip();
		await lineReads( HEALTHY_LINE );
		fireEvent.click( toggle() );
		expect(
			within( row( 'github' ) ).getByText(
				`Last answered ${ LAST_OK_LOCAL } · 1 of 1 request failed`
			)
		).toBeInTheDocument();
	} );

	it( 'opens on empty ontology with count and since', async () => {
		health.backlog = STOPPED;
		renderStrip();
		await worstFault(
			`Resolving is stopped: the ontology is empty. 47 items are waiting, since ${ SINCE_LOCAL }.`
		);
		expect( strip() ).toHaveClass( 'newspack-nodes-banner', 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'counts one held item in the singular', async () => {
		health.backlog = { ...STOPPED, held: 1 };
		renderStrip();
		await worstFault(
			`Resolving is stopped: the ontology is empty. 1 item is waiting, since ${ SINCE_LOCAL }.`
		);
		expect( strip() ).toHaveClass( 'is-warning' );
	} );

	it( 'counts held items with no start', async () => {
		health.backlog = { ...STOPPED, held: 6, since: null };
		renderStrip();
		await worstFault(
			'Resolving is stopped: the ontology is empty. 6 items are waiting.'
		);
		expect( strip() ).toHaveClass( 'is-warning' );
	} );

	it( 'seed button sends seed_ontology and re-reads backlog', async () => {
		health.backlog = STOPPED;
		wire.replies.seed_ontology = () => {
			health.backlog = HEALTHY.backlog;
			return { ok: true, events: 81, created: 79, present: 2 };
		};
		renderStrip( { canTune: true } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		);
		await lineReads( HEALTHY_LINE );
		expect( wire.sent( 'seed_ontology' ) ).toEqual( [ [] ] );
		expect( reads( 'backlog' ) ).toBe( 2 );
		expect( reads( 'sources' ) ).toBe( 2 );
	} );

	it( 'shows the refusal of a second seed while the first is processing', async () => {
		health.backlog = STOPPED;
		let seeds = 0;
		wire.replies.seed_ontology = () =>
			1 < ++seeds
				? new Error(
						'Recent ontology changes are still being processed; try again in a minute.'
				  )
				: { ok: true, events: 81, created: 79, present: 2 };
		renderStrip( { canTune: true } );
		const seed = await screen.findByRole( 'button', {
			name: 'Seed the ontology',
		} );
		fireEvent.click( seed );
		expect( await screen.findByText( 'Seeded.' ) ).toBeInTheDocument();
		fireEvent.click( seed );
		expect(
			await screen.findByText(
				'Recent ontology changes are still being processed; try again in a minute.'
			)
		).toHaveClass( 'newspack-nodes-status', 'is-error' );
		expect( wire.sent( 'seed_ontology' ) ).toEqual( [ [], [] ] );
	} );

	it( 'reader without TUNE sees seed as text step', async () => {
		health.backlog = STOPPED;
		renderStrip( { canManage: true } );
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
		await worstFault(
			'Resolving is stopped: the ontology is empty. An unknown number of items are waiting.'
		);
		expect( screen.queryByText( /\b0 items/ ) ).toBeNull();
	} );

	it( 'reads a projector catching up for under five minutes as neutral', async () => {
		health.backlog = {
			...STOPPED,
			reason: 'behind_ontology',
			since: ago( 240 ),
		};
		renderStrip( { canTune: true, canManage: true } );
		await lineReads(
			'Collecting from 3 sources. Catching up on the ontology.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Waiting to resolve' ) ).getByText( 'catching up' )
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
		await lineReads(
			'Collecting from 3 sources. Catching up on the ontology.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'turns a projector behind for over five minutes into a fault, offering nothing', async () => {
		const since = ago( 360 );
		health.backlog = { ...STOPPED, reason: 'behind_ontology', since };
		renderStrip( { canTune: true, canManage: true } );
		await worstFault(
			`Resolving is held: the projector has been behind the ontology since ${ formatLocalDateTime(
				since
			) }.`
		);
		expect( strip() ).toHaveClass( 'is-warning' );
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
		renderStrip( { canManage: true } );
		await worstFault(
			'Items parked from 2026-10-27 to 2026-10-28 were dropped before they resolved.'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Re-resolve' } ) );
		expect(
			await screen.findByText( 'Re-resolving.' )
		).toBeInTheDocument();
		expect( wire.sent( 'reresolve' ) ).toEqual( [
			[ '--since=2026-10-27', '--until=2026-10-28' ],
		] );
	} );

	it( 'gives a reader without MANAGE the re-resolve step as text', async () => {
		health.backlog = {
			...HEALTHY.backlog,
			dropped: { first_day: '2026-10-27', last_day: '2026-10-28' },
		};
		renderStrip( { canTune: true } );
		expect(
			await screen.findByText(
				'An administrator can re-resolve those days.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Re-resolve' } )
		).toBeNull();
	} );

	it( 'inactive is neutral and does not open', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging', 'intel-workbench' ],
		};
		wire.replies.activate = { name: 'intel-workbench', active: true };
		renderStrip( { canManage: true } );
		await lineReads( 'Collection is off.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'button', { name: 'intel-workbench' } )
		).toBeNull();
		fireEvent.click( toggle() );
		expect(
			within( row( 'Collection' ) ).getByText( 'inactive' )
		).toHaveClass( 'is-neutral' );
		expect(
			within( row( 'Collection' ) )
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'intel-staging', 'intel-workbench' ] );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'intel-workbench' } )
		);
		expect( await screen.findByText( 'Activated.' ) ).toBeInTheDocument();
		expect( wire.sent( 'activate' ) ).toEqual( [
			[ '--name=intel-workbench' ],
		] );
		expect( wire.sentTo( 'activate' ) ).toEqual( [ 'topologies' ] );
		await waitFor( () => expect( reads( 'collection' ) ).toBe( 2 ) );
	} );

	it( 'gives a reader without MANAGE the activate step as text, once', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging', 'intel-workbench' ],
		};
		renderStrip( { canTune: true } );
		await lineReads( 'Collection is off.' );
		fireEvent.click( toggle() );
		expect(
			screen.getAllByText( 'An administrator can activate collection.' )
		).toHaveLength( 1 );
		expect(
			screen.queryByRole( 'button', { name: 'intel-staging' } )
		).toBeNull();
	} );

	it( 'offers no Activate while the slice names no composition', async () => {
		health.collection = { state: 'inactive', topologies: [] };
		renderStrip( { canManage: true } );
		await lineReads( 'Collection is off.' );
		fireEvent.click( toggle() );
		const command = within( row( 'Collection' ) ).getByText(
			'wp nodes activate'
		);
		expect( command.tagName ).toBe( 'CODE' );
		expect( command.parentElement ).toHaveTextContent(
			'Collection is off; wp nodes activate turns on the topology that composes Intelligence.'
		);
		expect(
			within( row( 'Collection' ) ).queryAllByRole( 'button' )
		).toEqual( [] );
	} );

	it( 'names where the lists file belongs, and that empty will do', async () => {
		health.collection = {
			state: 'unconfigured',
			file: '/srv/kestrel-nodes/topologies/newspack-intelligence-lists.tsl',
		};
		renderStrip( { canManage: true } );
		await worstFault(
			'No lists file: create /srv/kestrel-nodes/topologies/newspack-intelligence-lists.tsl, empty if this site has no settings of its own.'
		);
		expect( strip() ).toHaveClass( 'is-warning' );
		expect(
			within( row( 'Collection' ) ).getByText( 'no lists file' )
		).toHaveClass( 'is-error' );
	} );

	it( 'tells a reader with no path that an administrator creates the lists file', async () => {
		health.collection = { state: 'unconfigured' };
		renderStrip( { canManage: false } );
		await worstFault(
			'An administrator can create the lists file; an empty one will do.'
		);
		expect( screen.queryByText( /\/topologies\// ) ).toBeNull();
	} );

	it( 'restart offered only for stale', async () => {
		health.collection.state = 'stale';
		wire.replies.restart = { restarted: 1 };
		const { unmount } = renderStrip( { canManage: true } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Restart' } )
		);
		expect( await screen.findByText( 'Restarted.' ) ).toBeInTheDocument();
		expect( wire.sent( 'restart' ) ).toEqual( [
			[ '--types=intel-staging' ],
		] );
		expect( wire.sentTo( 'restart' ) ).toEqual( [ 'workers' ] );
		unmount();

		Core.reset();
		health.collection.state = 'down';
		renderStrip( { canManage: true } );
		await worstFault(
			'The fleet revives a down worker within a minute; if it stays down, run `wp nodes doctor`.'
		);
		expect(
			screen.queryByRole( 'button', { name: 'Restart' } )
		).toBeNull();
	} );

	it( 'names a deploy hold and how to lift it', async () => {
		health.collection.state = 'held';
		renderStrip( { canManage: true } );
		await worstFault(
			'A deploy hold is in place; `wp nodes start` lifts it.'
		);
		expect( within( row( 'Collection' ) ).getByText( 'held' ) ).toHaveClass(
			'is-warning'
		);
	} );

	it( 'gives a reader without MANAGE the restart step as text', async () => {
		health.collection.state = 'stale';
		renderStrip( { canTune: true } );
		expect(
			await screen.findByText(
				'An administrator can restart the worker.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Restart' } )
		).toBeNull();
	} );

	it( 'idle source links to the Vault tab with group', async () => {
		health.sources.sources.push( {
			name: 'linear',
			class: 'Linear_Source',
			tier: 'internal',
			state: 'idle',
			credential: { url: 'https://api.linear.app', group: 'linear-crew' },
		} );
		renderStrip( { canManage: true } );
		await worstFault(
			'No Vault entry for https://api.linear.app in group linear-crew.'
		);
		expect(
			within( row( 'linear' ) ).getByRole( 'link', {
				name: 'Open the Vault',
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
		await worstFault( 'This source has no credential to fetch with.' );
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
		await worstFault(
			'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host'
		);
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
		renderStrip( { canManage: true } );
		await worstFault(
			'not signed in to ContextA8C; sign in to ContextA8C on the host'
		);
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
		renderStrip( { canManage: true } );
		await worstFault(
			`GitHub answered an error for https://api.github.com: rate limited · failing since ${ SINCE_LOCAL }`
		);
		expect(
			screen.getAllByRole( 'button', { name: 'Fetch all sources now' } )
		).toHaveLength( 1 );
		fireEvent.click(
			within( row( 'github' ) ).getByRole( 'button', {
				name: 'Fetch all sources now',
			} )
		);
		expect( await screen.findByText( 'Fetching.' ) ).toBeInTheDocument();
		expect( wire.sent( 'fetch_now' ) ).toEqual( [ [] ] );
	} );

	it( 'gives a reader without MANAGE a redacted failing source and the fetch step', async () => {
		health.sources.sources[ 0 ] = {
			...okSource( 'github' ),
			state: 'failing',
			failing_since: SINCE,
		};
		renderStrip();
		await worstFault( `Failing since ${ SINCE_LOCAL }` );
		expect(
			screen.getByText( 'An administrator can fetch all sources now.' )
		).toBeInTheDocument();
	} );

	it( 'names a source that has not reported yet, and stays neutral', async () => {
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		renderStrip();
		await lineReads(
			'Collecting from 2 of 3 sources; news has not reported yet. Nothing waiting.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'news' ) ).getByText( 'No pass has reported yet.' )
		).toBeInTheDocument();
	} );

	it( 'names every source that has not reported yet, as a list', async () => {
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		health.sources.sources.push( okSource( 'slack' ) );
		renderStrip();
		await lineReads(
			'Collecting from 2 of 4 sources; github and news have not reported yet. Nothing waiting.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts a total of one source in the singular', async () => {
		health.sources.sources = [ { name: 'github', state: 'unknown' } ];
		renderStrip();
		await lineReads(
			'Collecting from 0 of 1 source; github has not reported yet. Nothing waiting.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'claims no health for a source in a state it does not recognise', async () => {
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'paused' };
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'joins the unreported names in the page’s own language', async () => {
		document.documentElement.lang = 'de-DE';
		health.sources.sources[ 0 ] = { name: 'github', state: 'unknown' };
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		try {
			renderStrip();
			await lineReads(
				'Collecting from 1 of 3 sources; github und news have not reported yet. Nothing waiting.'
			);
			expect( strip() ).not.toHaveClass( 'is-warning' );
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
			await lineReads(
				'Collecting from 1 of 3 sources; github and news have not reported yet. Nothing waiting.'
			);
			expect( strip() ).not.toHaveClass( 'is-warning' );
		} finally {
			document.documentElement.lang = '';
		}
	} );

	it( 'claims no health while another row is unknown beside an unreported source', async () => {
		health.sources.sources[ 2 ] = { name: 'news', state: 'unknown' };
		health.extraction = { mode: 'unknown' };
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts the items waiting to resolve or retry while nothing holds', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 9 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. 9 items are waiting to resolve or retry.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts one item waiting to resolve or retry in the singular', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 1 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. 1 item is waiting to resolve or retry.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'claims nothing waiting only while the queue’s count is known', async () => {
		health.backlog = { ...HEALTHY.backlog, held: null };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. Resolving is running; how many items wait to retry is not known.'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts the model calls on a row of their own and on the line, never under Extraction', async () => {
		health.extraction.queue = { new: 3, backlog: 5741, in_flight: 4 };
		const calls =
			'5,744 calls waiting (5,741 backlog, 3 new) · 4 in flight';
		renderStrip();
		await lineReads( `Collecting from 3 sources. ${ calls }` );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Model calls' ) ).getByText( calls )
		).toBeInTheDocument();
		expect(
			within( row( 'Model calls' ) ).getByText( 'busy' )
		).toHaveClass( 'is-neutral' );
		expect(
			within( row( 'Extraction' ) ).getByText(
				`By the AI proxy; last extraction ${ LAST_OK_LOCAL }.`
			)
		).toBeInTheDocument();
	} );

	it( 'counts one model call waiting in the singular, leaving out a class it holds none of', async () => {
		health.extraction.queue = { new: 1, backlog: 0, in_flight: 1 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. 1 call waiting (1 new) · 1 in flight'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'names only the backlog when no new call waits', async () => {
		health.extraction.queue = { new: 0, backlog: 27, in_flight: 0 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. 27 calls waiting (27 backlog)'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts calls in flight with none waiting', async () => {
		health.extraction.queue = { new: 0, backlog: 0, in_flight: 2 };
		renderStrip();
		await lineReads( 'Collecting from 3 sources. 2 in flight' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'shows no model-call row while the queue holds no call, or its count is not known', async () => {
		for ( const queue of [ null, { new: 0, backlog: 0, in_flight: 0 } ] ) {
			health.extraction.queue = queue;
			const { unmount } = renderStrip();
			await lineReads( HEALTHY_LINE );
			fireEvent.click( toggle() );
			expect( row( 'Extraction' ) ).toBeDefined();
			expect( row( 'Model calls' ) ).toBeUndefined();
			unmount();
		}
	} );

	it( 'counts the model calls beside the items waiting to resolve', async () => {
		health.backlog = { ...HEALTHY.backlog, held: 9 };
		health.extraction.queue = { new: 0, backlog: 12, in_flight: 2 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. 9 items are waiting to resolve or retry. 12 calls waiting (12 backlog) · 2 in flight'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'keeps the backlog row’s cause beside the calls while it is not idle', async () => {
		health.backlog = { ...HEALTHY.backlog, held: null };
		health.extraction.queue = { new: 5, backlog: 0, in_flight: 0 };
		renderStrip();
		await lineReads(
			'Collecting from 3 sources. Resolving is running; how many items wait to retry is not known. 5 calls waiting (5 new)'
		);
		expect( strip() ).not.toHaveClass( 'is-warning' );
	} );

	it( 'counts the model calls in the page’s own language', async () => {
		document.documentElement.lang = 'de-DE';
		health.extraction.queue = { new: 2, backlog: 1309, in_flight: 3 };
		try {
			renderStrip();
			await lineReads(
				'Collecting from 3 sources. 1.311 calls waiting (1.309 backlog, 2 new) · 3 in flight'
			);
			expect( strip() ).not.toHaveClass( 'is-warning' );
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
		renderStrip( { canTune: true, canManage: true } );
		await lineReads( 'Collection is off.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
		fireEvent.click( toggle() );
		expect( within( row( 'linear' ) ).getByText( 'idle' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( row( 'Waiting to resolve' ) ).getByText( 'held' )
		).toHaveClass( 'is-neutral' );
	} );

	it( 'says collection is off even while a source reads unknown', async () => {
		health.collection = {
			state: 'inactive',
			topologies: [ 'intel-staging' ],
		};
		health.sources.sources[ 1 ] = { name: 'feed', state: 'unknown' };
		renderStrip();
		await lineReads( 'Collection is off.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'reads a state it does not recognise as unknown, not blank', async () => {
		health.collection.state = 'paused';
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		fireEvent.click( toggle() );
		const collection = row( 'Collection' );
		expect( within( collection ).getByText( 'unknown' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( collection ).getByText( 'State not recognised: paused.' )
		).toBeInTheDocument();
	} );

	it( 'reads an inherited key as a state it does not recognise', async () => {
		health.backlog = { ...HEALTHY.backlog, reason: 'toString' };
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Waiting to resolve' ) ).getByText(
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
		renderStrip( { canManage: true } );
		await worstFault(
			'Extracting with the heuristic only: Vault group ai-proxy holds no entry for the AI proxy.'
		);
		expect(
			within( row( 'Extraction' ) ).getByRole( 'link', {
				name: 'Open the Vault',
			} )
		).toHaveAttribute( 'href', VAULT );
	} );

	it( 'gives a redacted heuristic extractor plain copy and the text step', async () => {
		health.extraction = { mode: 'heuristic' };
		renderStrip();
		await worstFault(
			'Extracting with the heuristic only: the AI proxy has no Vault entry.'
		);
		expect(
			screen.getByText( 'An administrator can add its Vault entry.' )
		).toBeInTheDocument();
	} );

	it( 'reads a failing extractor as a fault with its error', async () => {
		health.extraction = {
			mode: 'call',
			vault_group: 'ai-proxy',
			last_ok_at: LAST_OK,
			last_error: 'AI proxy answered HTTP 502',
			failing_since: SINCE,
		};
		renderStrip( { canManage: true } );
		await worstFault(
			`AI proxy answered HTTP 502 · failing since ${ SINCE_LOCAL }`
		);
		expect( strip() ).toHaveClass( 'is-warning' );
		expect(
			within( row( 'Extraction' ) ).getByText( 'failing' )
		).toHaveClass( 'is-error' );
	} );

	it( 'reads a call extractor that has not answered yet', async () => {
		health.extraction = { mode: 'call', last_ok_at: null };
		renderStrip();
		await lineReads( HEALTHY_LINE );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Extraction' ) ).getByText( 'By the AI proxy.' )
		).toBeInTheDocument();
	} );

	it( 'reads an extractor that has not reported as unknown', async () => {
		health.extraction = { mode: 'unknown' };
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Extraction' ) ).getByText(
				'No extraction has reported yet.'
			)
		).toBeInTheDocument();
	} );

	it( 'reads an unknown extractor whose newest extraction used the heuristic as unknown', async () => {
		health.extraction = { mode: 'unknown', last_mode: 'heuristic' };
		renderStrip( { canManage: true } );
		await lineReads( 'The pipeline’s health is not fully known.' );
		expect( strip() ).not.toHaveClass( 'is-warning' );
		fireEvent.click( toggle() );
		const extraction = row( 'Extraction' );
		expect( within( extraction ).getByText( 'unknown' ) ).toHaveClass(
			'is-neutral'
		);
		expect(
			within( extraction ).getByText(
				'No extraction has reported since the cache was cleared; the newest used the heuristic.'
			)
		).toBeInTheDocument();
		expect( within( extraction ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'reads an unknown extractor whose newest extraction used the AI proxy as unknown', async () => {
		health.extraction = { mode: 'unknown', last_mode: 'call' };
		renderStrip();
		await lineReads( 'The pipeline’s health is not fully known.' );
		fireEvent.click( toggle() );
		expect(
			within( row( 'Extraction' ) ).getByText(
				'No extraction has reported since the cache was cleared; the newest used the AI proxy.'
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
		await lineReads(
			'A deploy hold is in place; `wp nodes start` lifts it.'
		);
		const names = within( rows() )
			.getAllByRole( 'listitem' )
			.map( ( item ) => item.querySelector( 'strong' ).textContent );
		expect( names.slice( 0, 2 ) ).toEqual( [ 'Collection', 'github' ] );
	} );

	it( 'keeps the reader’s choice while the faults stand, and reopens on a new one', async () => {
		health.backlog = STOPPED;
		renderStrip();
		await lineReads(
			`Resolving is stopped: the ontology is empty. 47 items are waiting, since ${ SINCE_LOCAL }.`
		);
		fireEvent.click( toggle() );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Refresh' } ) );
		await waitFor( () => expect( reads( 'backlog' ) ).toBe( 2 ) );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );

		health.collection.state = 'down';
		fireEvent.click( screen.getByRole( 'button', { name: 'Refresh' } ) );
		await waitFor( () =>
			expect( toggle() ).toHaveAttribute( 'aria-expanded', 'true' )
		);
	} );

	it( 'mounts a write only for a fix the reader may send', async () => {
		const writes = [
			'intelligence:seed_ontology',
			'intelligence:reresolve',
			'intelligence:fetch_now',
			'workers:restart',
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
		renderStrip( { canTune: true, canManage: true } );
		await screen.findByRole( 'button', { name: 'Seed the ontology' } );
		expect( writes.filter( ( name ) => Core.node( name ) ) ).toEqual(
			writes
		);
	} );

	it( 'reads every slice again on Refresh', async () => {
		renderStrip();
		await lineReads( HEALTHY_LINE );
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
		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'NOT_AVAILABLE'
		);
		await lineReads( 'The pipeline’s health could not be read in full.' );
	} );
} );
