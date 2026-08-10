/**
 * useInsightsGraph tests — the Publisher Insights dashboard as a GENUINE node
 * graph built from the substrate's batched-poll toolkit (useBatchedPoll +
 * addSliceFetcher), not a god object:
 *
 *   insights:timer (Timer) ─> insights:tee (Tee) ─> fetch-counts (Fetcher) ─┐
 *                                                 ├> fetch-top    (Fetcher) ─┤  target = _shell/_http/insights
 *                                                 └> fetch-acc    (Fetcher) ─┘
 *   countsIn (Tee) ─> source-counts:view
 *   topIn    (Tee) ─> top-table:view
 *   accIn    (Tee) ─> accumulated:view
 *
 * The Timer hitchhikes the router tick; the router brackets each tick with
 * `_http` lock/flush, so all three fetcher commands batch into ONE HttpOut POST.
 * Beyond the poll, the hook exposes the awaited `generate`/`collect` action verbs
 * the dashboard buttons call; their reply pivots straight back to accumulated:view,
 */

import { renderHook, act } from '@testing-library/react';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import {
	ID,
	TO,
	FROM,
	VALUE,
	Core,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { useInsightsGraph } from '../useInsightsGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const SHELL = '_shell';
const ACC_VIEW = 'accumulated:view';

function setVisibility( state ) {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => state,
	} );
	document.dispatchEvent( new Event( 'visibilitychange' ) );
}

// Fake transport: postBatch records each batch and echoes a reply via FROM.
const emptyPayloads = {
	counts: JSON.stringify( { sources: {} } ),
	top: JSON.stringify( { top: {} } ),
	accumulated: JSON.stringify( {
		accumulated: 0,
		done: 0,
		total: 0,
		digest: '',
	} ),
};

// The seam is the WIRE: the graph packs, POSTs and unpacks for real, so
// HttpOut, the router and the interpreter all run. `wire.batches` is what was
// posted; a verb in `errorVerbs` answers TM_ERROR carrying its payload.
function installWire( payloadByVerb = {}, errorVerbs = [] ) {
	return installFakeCommandWire( ( m ) => {
		const verb = m[ VALUE ]?.name;
		const payload = payloadByVerb[ verb ] ?? null;
		return errorVerbs.includes( verb )
			? new Error( payload ?? verb )
			: payload;
	} );
}

beforeEach( () => {
	Core.reset();
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => 'visible',
	} );
} );

describe( 'useInsightsGraph — graph wiring', () => {
	test( 'mounts the backbone, `_http`, `_shell` tap, the timer/tee/fetchers, and three view nodes, each sinking into the interpreter', async () => {
		installWire( emptyPayloads );
		renderHook( () => useInsightsGraph() );
		await act( async () => {} );

		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();

		const names = [
			HTTP,
			SHELL,
			'insights:timer',
			'insights:tee',
			'fetch-counts',
			'fetch-top',
			'fetch-acc',
			'countsIn',
			'topIn',
			'accIn',
			'source-counts:view',
			'top-table:view',
			ACC_VIEW,
		];
		for ( const name of names ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( 'each Fetcher is configured with its receiver + verb and targets `_shell/_http/insights`', async () => {
		installWire( emptyPayloads );
		renderHook( () => useInsightsGraph() );
		await act( async () => {} );
		const path = `${ SHELL }/${ HTTP }/insights`;
		expect( Core.node( 'fetch-counts' ).receiver ).toBe( 'countsIn' );
		expect( Core.node( 'fetch-counts' ).verb ).toBe( 'counts' );
		expect( Core.node( 'fetch-counts' ).target ).toBe( path );
		expect( Core.node( 'fetch-top' ).verb ).toBe( 'top' );
		expect( Core.node( 'fetch-acc' ).verb ).toBe( 'accumulated' );
	} );
} );

describe( 'useInsightsGraph — batched poll', () => {
	test( 'one router TIMER tick emits exactly three TM_COMMANDs (counts/top/accumulated, FROM=their receivers) batched into ONE HttpOut POST', async () => {
		const wire = installWire( emptyPayloads );
		renderHook( () => useInsightsGraph() );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect( wire.batches.length ).toBe( 1 );
		const batch = wire.batches[ 0 ];
		expect( batch.length ).toBe( 3 );

		const byVerb = Object.fromEntries(
			batch.map( ( m ) => [ m[ VALUE ].name, m ] )
		);
		expect( Object.keys( byVerb ).sort() ).toEqual( [
			'accumulated',
			'counts',
			'top',
		] );
		expect( byVerb.counts[ TO ] ).toBe( 'insights' );
		expect( byVerb.counts[ FROM ] ).toBe( 'countsIn' );
		expect( byVerb.top[ FROM ] ).toBe( 'topIn' );
		expect( byVerb.accumulated[ FROM ] ).toBe( 'accIn' );
	} );

	test( 'while the tab is HIDDEN no router tick posts; becoming visible resumes polling', async () => {
		const wire = installWire( emptyPayloads );
		renderHook( () => useInsightsGraph() );
		await act( async () => {} );
		wire.batches.length = 0;

		await act( async () => {
			setVisibility( 'hidden' );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		expect( wire.batches.length ).toBe( 0 );

		await act( async () => {
			setVisibility( 'visible' );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		expect( wire.batches.length ).toBe( 1 );
		expect( wire.batches[ 0 ].length ).toBe( 3 );
	} );

	test( 'each slice reply routes back to its own view node and lands in its slice', async () => {
		installWire( {
			counts: JSON.stringify( { sources: { github: 2 } } ),
			top: JSON.stringify( {
				top: { github: [ { title: 'X', score: 5 } ] },
			} ),
			accumulated: JSON.stringify( {
				accumulated: 7,
				done: 2,
				total: 3,
				digest: '# D',
			} ),
		} );
		renderHook( () => useInsightsGraph() );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect( Core.node( 'source-counts:view' ).setStateCache.view ).toEqual(
			{
				sources: { github: 2 },
			}
		);
		expect( Core.node( 'top-table:view' ).setStateCache.view ).toEqual( {
			top: { github: [ { title: 'X', score: 5 } ] },
		} );
		expect( Core.node( ACC_VIEW ).setStateCache.view ).toEqual( {
			accumulated: 7,
			done: 2,
			total: 3,
			digest: '# D',
		} );
	} );
} );

describe( 'useInsightsGraph — awaited action verbs', () => {
	test( 'generate() mints from its own node and resolves to its ack payload', async () => {
		const wire = installWire( {
			...emptyPayloads,
			generate: JSON.stringify( { regenerating: true, workers: 1 } ),
		} );
		const { result } = renderHook( () => useInsightsGraph() );
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await result.current.generate();
		} );
		expect( resolved ).toBe(
			JSON.stringify( { regenerating: true, workers: 1 } )
		);

		const genMsgs = wire.batches
			.flat()
			.filter( ( m ) => 'generate' === m[ VALUE ]?.name );
		expect( genMsgs.length ).toBe( 1 );
		expect( genMsgs[ 0 ][ FROM ] ).toBe( 'insights:generate' );
		// Addressed, not correlated.
		expect( genMsgs[ 0 ][ ID ] ).toBe( '' );
		// Token-array command contract: arguments is an argv list, never the
		// retired joined-string form.
		expect( genMsgs[ 0 ][ VALUE ] ).toMatchObject( {
			name: 'generate',
			arguments: [],
		} );
	} );

	/**
	 * An action fired before /auth resolves would mint UNSIGNED and be refused.
	 * The dashboard mounts and the user can click immediately.
	 */
	test( 'signs generate() even when the session lands late', async () => {
		forgetSession();
		let landAuth;
		const inFlight = new Promise( ( resolve ) => {
			landAuth = resolve;
		} );
		__setAuthFetch( () =>
			inFlight.then( () => ( {
				handle: 'ffff6666ffff6666ffff6666ffff6666',
				key: 'key-insights-late-auth',
				expires_in: 3600,
				now: 1771000000,
			} ) )
		);
		const wire = installWire( {
			...emptyPayloads,
			generate: JSON.stringify( { regenerating: true, workers: 1 } ),
		} );
		const { result } = renderHook( () => useInsightsGraph() );
		await act( async () => {} );

		// Click while /auth is still in flight — the real race.
		await act( async () => {
			const pending = result.current.generate();
			landAuth();
			await pending;
		} );

		const genMsgs = wire.batches
			.flat()
			.filter( ( m ) => 'generate' === m[ VALUE ]?.name );
		expect( genMsgs.length ).toBe( 1 );
		expect( genMsgs[ 0 ][ VALUE ].auth ).toBeDefined();
	} );

	test( 'generate() rejects when a TM_ERROR reply pivots back', async () => {
		installWire( { ...emptyPayloads, generate: 'compose failed' }, [
			'generate',
		] );
		const { result } = renderHook( () => useInsightsGraph() );
		await act( async () => {} );

		await act( async () => {
			await expect( result.current.generate() ).rejects.toThrow(
				/compose failed/i
			);
		} );
	} );

	test( 'collect() mints from its own node and resolves to its payload', async () => {
		const wire = installWire( {
			...emptyPayloads,
			collect: JSON.stringify( { collecting: 3, workers: 1 } ),
		} );
		const { result } = renderHook( () => useInsightsGraph() );
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await result.current.collect();
		} );
		expect( resolved ).toBe(
			JSON.stringify( { collecting: 3, workers: 1 } )
		);

		const collectMsgs = wire.batches
			.flat()
			.filter( ( m ) => 'collect' === m[ VALUE ]?.name );
		expect( collectMsgs.length ).toBe( 1 );
		expect( collectMsgs[ 0 ][ FROM ] ).toBe( 'insights:collect' );
		// Addressed, not correlated.
		expect( collectMsgs[ 0 ][ ID ] ).toBe( '' );
	} );
} );
