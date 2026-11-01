/* eslint-env jest */
/**
 * A healthy answer to each of the four `health` slices, as the strip and the
 * page both read it: a live worker on a staging topology, three sources that
 * answered, nothing held and the AI proxy extracting.
 */

/** 2026-10-31 09:41 UTC. */
export const LAST_OK = Date.UTC( 2026, 9, 31, 9, 41 ) / 1000;

/**
 * A source whose last pass answered.
 *
 * @param {string} name The source's name.
 * @return {Object} Its row.
 */
export const okSource = ( name ) => ( {
	name,
	class: 'Feed_Source',
	tier: 'public',
	state: 'ok',
	last_ok_at: LAST_OK,
	failed: 0,
	requests: 12,
} );

export const HEALTHY = {
	collection: { state: 'live', topology: 'intel-staging' },
	sources: {
		sources: [
			okSource( 'github' ),
			okSource( 'feed' ),
			okSource( 'news' ),
		],
	},
	backlog: { reason: '', held: 0, since: null, dropped: null },
	extraction: {
		mode: 'call',
		vault_id: 'AI-proxy',
		last_ok_at: LAST_OK,
		last_error: null,
		failing_since: null,
	},
};
