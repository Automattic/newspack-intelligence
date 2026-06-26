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
 * whose PendingReplies settles the Promise.
 */

import { renderHook, act } from '@testing-library/react';
import {
	newMessage,
	ID,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	Core,
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

// A fake CommandClient matching HttpOut's seam: postBatch records each batch and
// echoes a reply addressed back along FROM, payload keyed by the posted verb.
function makeFakeClient( payloadByVerb = {}, replyTypeByVerb = {} ) {
	const client = {
		batches: [],
		postBatch( messages ) {
			client.batches.push( messages );
			const replies = messages.map( ( m ) => {
				const verb = m[ VALUE ]?.name;
				const reply = newMessage();
				reply[ TYPE ] =
					replyTypeByVerb[ verb ] ?? TM_COMMAND | TM_RESPONSE;
				reply[ TO ] = m[ FROM ];
				reply[ ID ] = m[ ID ];
				reply[ VALUE ] = {
					name: verb,
					payload: payloadByVerb[ verb ] ?? null,
				};
				return reply;
			} );
			return Promise.resolve( replies );
		},
	};
	return client;
}

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

beforeEach( () => {
	Core.reset();
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => 'visible',
	} );
} );

describe( 'useInsightsGraph — graph wiring', () => {
	test( 'mounts the backbone, `_http`, `_shell` tap, the timer/tee/fetchers, and three view nodes, each sinking into the interpreter', async () => {
		const client = makeFakeClient( emptyPayloads );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
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
		const client = makeFakeClient( emptyPayloads );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );
		const path = `${ SHELL }/${ HTTP }/insights`;
		expect( Core.node( 'fetch-counts' ).receiver ).toBe( 'countsIn' );
		expect( Core.node( 'fetch-counts' ).command ).toBe( 'counts' );
		expect( Core.node( 'fetch-counts' ).target ).toBe( path );
		expect( Core.node( 'fetch-top' ).command ).toBe( 'top' );
		expect( Core.node( 'fetch-acc' ).command ).toBe( 'accumulated' );
	} );
} );

describe( 'useInsightsGraph — batched poll', () => {
	test( 'one router TIMER tick emits exactly three TM_COMMANDs (counts/top/accumulated, FROM=their receivers) batched into ONE HttpOut POST', async () => {
		const client = makeFakeClient( emptyPayloads );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );
		client.batches.length = 0;

		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect( client.batches.length ).toBe( 1 );
		const batch = client.batches[ 0 ];
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
		const client = makeFakeClient( emptyPayloads );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );
		client.batches.length = 0;

		await act( async () => {
			setVisibility( 'hidden' );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		expect( client.batches.length ).toBe( 0 );

		await act( async () => {
			setVisibility( 'visible' );
		} );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );
		expect( client.batches.length ).toBe( 1 );
		expect( client.batches[ 0 ].length ).toBe( 3 );
	} );

	test( 'each slice reply routes back to its own view node and lands in its slice', async () => {
		const client = makeFakeClient( {
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
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {
			Core.node( ROUTER ).fireCb();
		} );

		expect( Core.node( 'source-counts:view' ).setStateCache.view ).toEqual(
			{ sources: { github: 2 } }
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
	test( 'generate() fires a `generate` command (FROM=accumulated:view) and resolves to its ack payload', async () => {
		const client = makeFakeClient( {
			...emptyPayloads,
			generate: JSON.stringify( { regenerating: true, workers: 1 } ),
		} );
		const { result } = renderHook( () =>
			useInsightsGraph( { commandClient: client } )
		);
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await result.current.generate();
		} );
		expect( resolved ).toBe(
			JSON.stringify( { regenerating: true, workers: 1 } )
		);

		const genMsgs = client.batches
			.flat()
			.filter( ( m ) => 'generate' === m[ VALUE ]?.name );
		expect( genMsgs.length ).toBe( 1 );
		expect( genMsgs[ 0 ][ FROM ] ).toBe( ACC_VIEW );
		expect( genMsgs[ 0 ][ ID ] ).toBeTruthy();
	} );

	test( 'generate() rejects when a TM_ERROR reply pivots back', async () => {
		const client = makeFakeClient(
			{ ...emptyPayloads, generate: 'compose failed' },
			{ generate: TM_COMMAND | TM_RESPONSE | TM_ERROR }
		);
		const { result } = renderHook( () =>
			useInsightsGraph( { commandClient: client } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect( result.current.generate() ).rejects.toThrow(
				/compose failed/i
			);
		} );
	} );

	test( 'collect() fires a `collect` command (FROM=accumulated:view) and resolves to its payload', async () => {
		const client = makeFakeClient( {
			...emptyPayloads,
			collect: JSON.stringify( { collecting: 3, workers: 1 } ),
		} );
		const { result } = renderHook( () =>
			useInsightsGraph( { commandClient: client } )
		);
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await result.current.collect();
		} );
		expect( resolved ).toBe(
			JSON.stringify( { collecting: 3, workers: 1 } )
		);

		const collectMsgs = client.batches
			.flat()
			.filter( ( m ) => 'collect' === m[ VALUE ]?.name );
		expect( collectMsgs.length ).toBe( 1 );
		expect( collectMsgs[ 0 ][ FROM ] ).toBe( ACC_VIEW );
	} );
} );
