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
 * The Collect and Regenerate buttons are NOT here: each is its own one-shot,
 * held by `AccumulatedPanel` beside the lock and note its reply sets. This hook
 * owns the poll; the panel owns its buttons.
 */

import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import { views } from '../nodes/register';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';

// Server-side CI mount; Fetchers and action verbs target it via _shell/_http.
export const SERVER = 'insights';
// Digest slices change slowly; the cadence is explicit, never inferred.
const DEFAULT_INTERVAL_MS = 30000;

const TARGET = egressPath( SERVER );
const ACC_VIEW = 'accumulated:view';

// Per-slice fetcher config: receiver Tee, verb, view node, and its class.
const SLICES = [
	{
		fetcher: 'fetch-counts',
		receiver: 'countsIn',
		command: 'counts',
		view: 'source-counts:view',
		viewClass: views.SourceCountsView,
	},
	{
		fetcher: 'fetch-top',
		receiver: 'topIn',
		command: 'top',
		view: 'top-table:view',
		viewClass: views.TopTableView,
	},
	{
		fetcher: 'fetch-acc',
		receiver: 'accIn',
		command: 'accumulated',
		view: ACC_VIEW,
		viewClass: views.AccumulatedView,
	},
];

/**
 * @param {Object} [opts]            Options (test seams).
 * @param {number} [opts.intervalMs] Poll cadence in ms; defaults to DEFAULT_INTERVAL_MS. Never falls through to the router tick — that polled at 1Hz.
 * @return {void} Nothing: every widget reads its own slice via `useNodeState`.
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
}
