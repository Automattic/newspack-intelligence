/**
 * useInsightsGraph tests — the Publisher Insights dashboard graph clipped onto
 * the substrate's exospine + `_http` I/O boundary plus the `insights:view`
 * view-model node. Mirrors useWorkerStatusGraph: the hook owns a setInterval
 * that fires a TM_COMMAND (FROM=`insights:view`) through the interpreter;
 * `_http.client` is injected so the hook never touches the network. NO SSE —
 * the repeated poll IS the live data, with a synchronous CI reply in the body.
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

// global (not a module-scoped var) so jest.mock's hoisted factory may read it.
global.__pageVisible = true;
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => global.__pageVisible,
} ) );

import { useInsightsGraph } from '../useInsightsGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const VIEW = 'insights:view';

beforeEach( () => {
	global.__pageVisible = true;
	Core.reset();
} );

// A fake CommandClient matching HttpOut's seam: postBatch echoes a reply
// addressed back along FROM, payload keyed by verb (the server reply pivot).
function makeFakeClient( payloadByVerb = {} ) {
	const client = {
		batches: [],
		buildMessage( { to, verb, args = '' } ) {
			const m = newMessage();
			m[ TYPE ] = TM_COMMAND;
			m[ TO ] = to;
			m[ VALUE ] = { name: verb, arguments: args };
			return m;
		},
		postBatch( messages ) {
			client.batches.push( messages );
			const replies = messages.map( ( m ) => {
				const reply = newMessage();
				reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
				reply[ TO ] = m[ FROM ];
				reply[ ID ] = m[ ID ];
				reply[ VALUE ] = {
					name: m[ VALUE ]?.name,
					payload: payloadByVerb[ m[ VALUE ]?.name ] ?? null,
				};
				return reply;
			} );
			return Promise.resolve( replies );
		},
	};
	return client;
}

const verbsOf = ( client ) =>
	client.batches.flat().map( ( m ) => m[ VALUE ]?.name );

describe( 'useInsightsGraph — graph wiring', () => {
	test( 'mounts the backbone + `_http` + `insights:view`, each sinking into the interpreter', async () => {
		const client = makeFakeClient();
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );

		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of [ HTTP, VIEW ] ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( '`_http` has the injected CommandClient as its client', async () => {
		const client = makeFakeClient();
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );
		expect( Core.node( HTTP ).client ).toBe( client );
	} );
} );

describe( 'useInsightsGraph — poll', () => {
	test( 'fires one immediate, well-formed `insights` command on mount', async () => {
		const client = makeFakeClient( {
			insights: JSON.stringify( {
				sources: {},
				top: [],
				accumulated: 0,
			} ),
		} );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );

		expect( client.batches.length ).toBeGreaterThanOrEqual( 1 );
		const msg = client.batches[ 0 ][ 0 ];
		// HttpOut strips `_http/`, so it's the bare `insights` server-node
		// target at postBatch time (the verb name stays `insights`).
		expect( msg[ TO ] ).toBe( 'insights' );
		expect( msg[ FROM ] ).toBe( VIEW );
		expect( msg[ VALUE ].name ).toBe( 'insights' );
		expect( msg[ VALUE ].arguments ).toBe( '' );
		expect( msg[ ID ] ).toBeTruthy();
	} );

	test( 'the poll reply routes back to the view and lands in the model', async () => {
		const model = {
			sources: { releases: 1 },
			top: [ { source: 'releases', title: 'X', score: 5 } ],
			accumulated: 1,
		};
		const client = makeFakeClient( { insights: JSON.stringify( model ) } );
		renderHook( () => useInsightsGraph( { commandClient: client } ) );
		await act( async () => {} );
		expect( Core.node( VIEW ).setStateCache.view ).toEqual( model );
	} );

	test( 'polls again on each interval tick while page-visible', async () => {
		jest.useFakeTimers();
		try {
			const client = makeFakeClient( {
				insights: JSON.stringify( {
					sources: {},
					top: [],
					accumulated: 0,
				} ),
			} );
			renderHook( () =>
				useInsightsGraph( { commandClient: client, refreshMs: 4000 } )
			);
			await act( async () => {} );
			const afterMount = verbsOf( client ).filter(
				( v ) => 'insights' === v
			).length;
			expect( afterMount ).toBeGreaterThanOrEqual( 1 );
			await act( async () => {
				jest.advanceTimersByTime( 4000 );
			} );
			const afterTick = verbsOf( client ).filter(
				( v ) => 'insights' === v
			).length;
			expect( afterTick ).toBe( afterMount + 1 );
		} finally {
			jest.useRealTimers();
		}
	} );

	test( 'does not poll on interval while the page is hidden', async () => {
		global.__pageVisible = false;
		jest.useFakeTimers();
		try {
			const client = makeFakeClient( {
				insights: JSON.stringify( {
					sources: {},
					top: [],
					accumulated: 0,
				} ),
			} );
			renderHook( () =>
				useInsightsGraph( { commandClient: client, refreshMs: 4000 } )
			);
			await act( async () => {} );
			const baseline = verbsOf( client ).length;
			await act( async () => {
				jest.advanceTimersByTime( 12000 );
			} );
			// Hidden: the interval effect bails, so no further polls.
			expect( verbsOf( client ).length ).toBe( baseline );
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'useInsightsGraph — generate (awaited verb)', () => {
	test( 'generate() fires a `generate` command (FROM=view) and resolves to the digest markdown', async () => {
		const client = makeFakeClient( {
			insights: JSON.stringify( {
				sources: {},
				top: [],
				accumulated: 0,
				digest: '',
			} ),
			generate: JSON.stringify( { digest: '## Fresh digest' } ),
		} );
		const { result } = renderHook( () =>
			useInsightsGraph( { commandClient: client } )
		);
		await act( async () => {} );

		let resolved;
		await act( async () => {
			resolved = await result.current.generate();
		} );
		expect( resolved ).toBe( '## Fresh digest' );

		const genMsgs = client.batches
			.flat()
			.filter( ( m ) => 'generate' === m[ VALUE ]?.name );
		expect( genMsgs.length ).toBe( 1 );
		expect( genMsgs[ 0 ][ FROM ] ).toBe( VIEW );
		expect( genMsgs[ 0 ][ ID ] ).toBeTruthy();
	} );

	test( 'generate() rejects when a TM_ERROR reply pivots back', async () => {
		const failing = {
			batches: [],
			postBatch( messages ) {
				failing.batches.push( messages );
				return Promise.resolve(
					messages.map( ( m ) => {
						const reply = newMessage();
						const isGen = 'generate' === m[ VALUE ]?.name;
						reply[ TYPE ] = isGen
							? TM_COMMAND | TM_RESPONSE | TM_ERROR
							: TM_COMMAND | TM_RESPONSE;
						reply[ TO ] = m[ FROM ];
						reply[ ID ] = m[ ID ];
						reply[ VALUE ] = {
							name: m[ VALUE ]?.name,
							payload: isGen
								? 'compose failed'
								: JSON.stringify( {
										sources: {},
										top: [],
										accumulated: 0,
										digest: '',
								  } ),
						};
						return reply;
					} )
				);
			},
		};
		const { result } = renderHook( () =>
			useInsightsGraph( { commandClient: failing } )
		);
		await act( async () => {} );

		await act( async () => {
			await expect( result.current.generate() ).rejects.toThrow(
				/compose failed/i
			);
		} );
	} );

	test( 'collect() fires a `collect` command (FROM=view) and resolves to its payload', async () => {
		const client = makeFakeClient( {
			insights: JSON.stringify( {
				sources: {},
				top: [],
				accumulated: 0,
				digest: '',
				done: 0,
				total: 0,
			} ),
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
		expect( collectMsgs[ 0 ][ FROM ] ).toBe( VIEW );
	} );
} );
