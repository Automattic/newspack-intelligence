/**
 * useInsightsGraph — the Publisher Insights dashboard as a GENUINE node graph,
 * built from the substrate's batched-poll toolkit (mirrors the de-godded teaching
 * example):
 *
 *   insights:timer (Timer) ─> insights:tee (Tee) ─> fetch-counts (Fetcher) ─┐
 *                                                 ├> fetch-top    (Fetcher) ─┤  target = _shell/_http/insights
 *                                                 └> fetch-acc    (Fetcher) ─┘
 *   countsIn (Tee) ─> source-counts:view ─> <SourceCounts/>
 *   topIn    (Tee) ─> top-table:view     ─> <TopTable/>
 *   accIn    (Tee) ─> accumulated:view   ─> <AccumulatedPanel/>
 *
 * `useBatchedPoll` owns ALL the poll boilerplate (the `_shell`-Tap + `_http`
 * HttpOut, the fan-out Tee + router-hitchhike Timer, the lock/flush batch bracket,
 * and the page-visibility gate); `addSliceFetcher` wires each Fetcher → its
 * receiver Tee → its slice view. One batched POST per tick fans out three slice
 * commands; each reply pivots back to its OWN view and lands in its OWN slice.
 *
 * Beyond the poll the hook exposes the awaited `generate`/`collect` action verbs
 * the dashboard buttons call: each fires a TM_COMMAND (FROM=accumulated:view) and
 * stashes a `{ resolve, reject }` under its message[ID] in that view's
 * PendingReplies; the reply pivots straight back to accumulated:view, whose base
 * SliceViewNode.fill() settles the matching Promise before the slice path. Both
 * resolve to the verb's raw ack payload ({collecting,workers} / {regenerating,workers}
 * / {error}); the new digest from a regenerate arrives via the poll, not the reply.
 */

import { useCallback } from '@wordpress/element';
import {
	newMessage,
	TYPE,
	FROM,
	TO,
	ID,
	VALUE,
	TM_COMMAND,
	Core,
} from '@newspack-nodes/runtime';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { makeOpId } from '@newspack-nodes/shared/hooks/useDashboardGraph';
import '../nodes/register';

// The server-side CI mount this plugin owns. The Fetchers (poll) and the action
// verbs both target it through the substrate's `_shell/_http`.
const SERVER = 'insights';
const TARGET = `_shell/_http/${ SERVER }`;
const ACC_VIEW = 'accumulated:view';

// Per-slice fetcher config: the receiver Tee a reply pivots back to, the verb,
// and the view node (+ its registered class) the reply lands on.
const SLICES = [
	{
		fetcher: 'fetch-counts',
		receiver: 'countsIn',
		command: 'counts',
		view: 'source-counts:view',
		viewClass: 'SourceCountsView',
	},
	{
		fetcher: 'fetch-top',
		receiver: 'topIn',
		command: 'top',
		view: 'top-table:view',
		viewClass: 'TopTableView',
	},
	{
		fetcher: 'fetch-acc',
		receiver: 'accIn',
		command: 'accumulated',
		view: ACC_VIEW,
		viewClass: 'AccumulatedView',
	},
];

/**
 * Build a TM_COMMAND for an action verb: TO=`_shell/_http/insights` so the router
 * peels `_shell`/`_http` and HttpOut POSTs the bare command to the `insights`
 * server node; FROM=`accumulated:view` is the reply pivot (the CI replies TO=FROM),
 * landing the ack on the accumulated view that holds the PendingReplies registry.
 *
 * @param {string} verb The CI action verb (`generate` / `collect`).
 * @param {string} id   Correlator stamped into message[ID].
 * @return {Array} A 7-field positional Message.
 */
function buildAction( verb, id ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND;
	m[ FROM ] = ACC_VIEW;
	m[ TO ] = TARGET;
	m[ ID ] = id;
	m[ VALUE ] = { name: verb, arguments: '' };
	return m;
}

/**
 * @param {Object} [opts]               Options (test seams).
 * @param {Object} [opts.commandClient] CommandClient seam assigned to `_http.client`.
 * @param {number} [opts.intervalMs]    Poll cadence in ms (default: every router tick).
 * @return {{ generate: () => Promise<*>, collect: () => Promise<*> }} On-demand action verbs.
 */
export function useInsightsGraph( opts = {} ) {
	const { interpreterRef } = useBatchedPoll( {
		build: ( { interpreter, tee } ) =>
			SLICES.forEach( ( slice ) =>
				addSliceFetcher( interpreter, {
					...slice,
					tee,
					target: TARGET,
				} )
			),
		timerName: 'insights:timer',
		teeName: 'insights:tee',
		commandClient: opts.commandClient,
		intervalMs: opts.intervalMs,
	} );

	// Awaited verb: stash a pending Promise under the command ID on the accumulated
	// view's registry, fire the verb, and resolve with the reply's payload when it
	// pivots back to that view.
	const awaitVerb = useCallback(
		( verb, prefix ) => {
			const interpreter = interpreterRef.current;
			const view = Core.node( ACC_VIEW );
			if ( ! interpreter || ! view || ! view.replies ) {
				return Promise.reject(
					new Error( 'insights graph not ready' )
				);
			}
			const id = makeOpId( prefix );
			return new Promise( ( resolve, reject ) => {
				view.replies.add( id, resolve, reject );
				interpreter.fill( buildAction( verb, id ) );
			} );
		},
		[ interpreterRef ]
	);

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
