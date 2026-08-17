/**
 * The dashboard's slice views, declared rather than subclassed.
 *
 * Imported for its side effect by the dashboard hook and the bundle entry:
 * `registerSliceViews` merges these into `CommandInterpreterNode.includeNodes`,
 * the `make_node` type→class table the browser runtime resolves against, so
 * registration runs before the graph is built. Timer/Tee/Fetcher/Tap/HttpOut
 * are runtime nodes and register themselves.
 *
 * Each verb answers a JSON body that IS the slice, so none declares a `parse`.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = registerSliceViews( {
	/** `source-counts:view` — per-source counts, read by `<SourceCounts/>`. */
	SourceCountsView: {
		description: 'Owns the per-source counts slice for its React widget.',
		empty: { sources: {} },
		json: true,
	},

	/** `top-table:view` — score-ranked top items per source, for `<TopTable/>`. */
	TopTableView: {
		description:
			'Owns the per-source top-items slice for its React widget.',
		empty: { top: {} },
		json: true,
	},

	/**
	 * `accumulated:view` — the digest progress slice for `<AccumulatedPanel/>`.
	 *
	 * The awaited `generate` / `collect` verbs are minted from their OWN
	 * Request nodes and their acks are addressed there, so what lands here is
	 * only the slice.
	 */
	AccumulatedView: {
		description: 'Owns the accumulated-digest slice for its React widget.',
		empty: { accumulated: 0, done: 0, total: 0, digest: '' },
		json: true,
	},
} );
