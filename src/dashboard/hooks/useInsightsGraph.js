/**
 * useInsightsGraph — mounts the Publisher Insights dashboard graph clipped onto
 * the canonical rule-#2 backbone (`_command_interpreter → _router`) via the
 * substrate's `_http` I/O boundary, plus the application's view-model node:
 *
 *   _http          (HttpOut — POST /command boundary; .client = CommandClient)
 *   insights:view  (the view-model node React reads + pending-Promise registry)
 *
 * EVERY node sinks into the interpreter; the router routes by TO. The `insights`
 * Service_CI verb returns the FULLY-SHAPED model (sources, top, accumulated, and
 * the rendered `digest`) synchronously in the POST body, so there is NO transform
 * node and NO SSE — the page-visibility-gated poll IS the live data. The shared
 * `useDashboardGraph` owns the exospine mount, the `_http` boundary, the immediate
 * + interval poll, and the page-visibility gate; this hook supplies its view node
 * + poll command.
 *
 * The command boundary is injectable: tests pass `opts.commandClient` assigned
 * to `_http.client` so the hook never touches the network. Production lazily
 * defaults to the shared CommandClient over window.NewspackNodesData.
 *
 * Beyond the poll, the hook exposes `generate()` — an AWAITED `generate` verb the
 * "Regenerate digest" button calls. It no longer composes here: the verb asks the
 * worker to recompose (TM_REQUEST REGENERATE) and resolves to the worker ack
 * (`{regenerating,workers}` / `{error}`); the new digest arrives via the poll.
 */

import { useCallback, useRef } from '@wordpress/element';
import {
	newMessage,
	TYPE,
	FROM,
	TO,
	ID,
	VALUE,
	TM_COMMAND,
} from '@newspack-nodes/runtime';
import {
	useDashboardGraph,
	makeOpId,
} from '@newspack-nodes/shared/hooks/useDashboardGraph';
import '../nodes/register';

const HTTP = '_http';
const VIEW = 'insights:view';

/**
 * Build a TM_COMMAND for one of the view's verbs: TO=`_http/insights` so the
 * router peels `_http` and HttpOut POSTs the bare command to the `insights`
 * server node (this plugin's CI mount); FROM=`insights:view` is the reply pivot
 * (the CI replies TO=FROM, landing at the view).
 *
 * @param {string} verb The CI verb (`insights` poll, or `generate`).
 * @param {string} id   Correlator stamped into message[ID].
 * @return {Array} A 7-field positional Message.
 */
function buildCommand( verb, id ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = VIEW;
	m[ TO ] = `${ HTTP }/insights`;
	m[ ID ] = id;
	m[ VALUE ] = { name: verb, arguments: '' };
	return m;
}

/**
 * @param {Object} [opts]               Options (test seams).
 * @param {Object} [opts.commandClient] CommandClient seam assigned to `_http.client`.
 * @param {number} [opts.refreshMs]     Poll interval in ms (default 4000).
 * @return {{ generate: () => Promise<*>, collect: () => Promise<*> }} On-demand verbs.
 */
export function useInsightsGraph( opts = {} ) {
	const { commandClient, refreshMs = 4000 } = opts;
	const viewRef = useRef( null );

	const { interpreterRef } = useDashboardGraph( {
		mountNodes: ( interpreter ) => {
			viewRef.current = interpreter.makeNode( 'InsightsView', VIEW );
		},
		poll: ( interpreter ) =>
			interpreter.fill(
				buildCommand( 'insights', makeOpId( 'insights-op' ) )
			),
		refreshMs,
		commandClient,
	} );

	// Awaited verb: stash a pending Promise under the command ID, fire the verb,
	// and resolve with the reply's payload when it pivots back to the view.
	const awaitVerb = useCallback(
		( verb, prefix ) => {
			const interpreter = interpreterRef.current;
			const view = viewRef.current;
			if ( ! interpreter || ! view ) {
				return Promise.reject(
					new Error( 'insights graph not ready' )
				);
			}
			const id = makeOpId( prefix );
			return new Promise( ( resolve, reject ) => {
				view.replies.add( id, resolve, reject );
				interpreter.fill( buildCommand( verb, id ) );
			} );
		},
		[ interpreterRef ]
	);

	// Both resolve to the verb's raw ack payload: `generate` → `{regenerating,workers}`
	// (or `{error}`), `collect` → `{collecting,workers}` (or `{error}`). The new digest
	// from a regenerate arrives via the poll, not this reply.
	const generate = useCallback(
		() => awaitVerb( 'generate', 'insights-gen' ),
		[ awaitVerb ]
	);
	const collect = useCallback(
		() => awaitVerb( 'collect', 'insights-collect' ),
		[ awaitVerb ]
	);

	return { generate, collect };
}
