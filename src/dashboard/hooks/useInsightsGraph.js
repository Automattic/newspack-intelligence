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
 * SliceViewNode.fill() settles the matching Promise before the slice path. Both
 * resolve to the verb's raw ack payload ({collecting,workers} / {regenerating,workers}
 * / {error}); the new digest from a regenerate arrives via the poll, not the reply.
 */

import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { useAwaitableCommand } from '@newspack-nodes/shared/hooks/useAwaitableCommand';
import '../nodes/register';

// Server-side CI mount; Fetchers and action verbs target it via _shell/_http.
const SERVER = 'insights';
// Digest slices change slowly; the cadence is explicit, never inferred.
const DEFAULT_INTERVAL_MS = 30000;

const TARGET = `_shell/_http/${ SERVER }`;
const ACC_VIEW = 'accumulated:view';

// Per-slice fetcher config: receiver Tee, verb, view node, and its class.
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
 * @param {Object} [opts]            Options (test seams).
 * @param {number} [opts.intervalMs] Poll cadence in ms; defaults to DEFAULT_INTERVAL_MS. Never falls through to the router tick — that polled at 1Hz.
 * @return {{ generate: ( args?: string[] ) => Promise<*>, collect: ( args?: string[] ) => Promise<*> }}
 *   On-demand action verbs; each sends on the next tick and resolves with the
 *   reply that lands on the node that asked.
 */
export function useInsightsGraph( opts = {} ) {
	useBatchedPoll( {
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
		intervalMs: opts.intervalMs ?? DEFAULT_INTERVAL_MS,
	} );

	// One node per awaited action, each riding the same tick as the polls.
	const generate = useAwaitableCommand( {
		scope: `${ SERVER }:generate`,
		target: TARGET,
		command: 'generate',
	} );
	const collect = useAwaitableCommand( {
		scope: `${ SERVER }:collect`,
		target: TARGET,
		command: 'collect',
	} );

	return { generate, collect };
}
