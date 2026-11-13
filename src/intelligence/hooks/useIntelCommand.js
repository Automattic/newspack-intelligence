/**
 * The Intelligence page's one-shot commands: the substrate's
 * `useCommandRead` and `useCommandWrite`, bound to the `intelligence` group,
 * so `connect intelligence:shell` watches every send. Each goes to the
 * `intelligence` CI but a write naming another, as the health strip's fixes to
 * the substrate's `workers` and `topologies` CIs do.
 *
 * Reads that repeat on a page share one read through a provider rather than
 * through the memory: `usePageRead()` for the page's own, and `ChangesRead`
 * and `EntityRecord` for a view's. The prefix lists below name what each
 * write dirties.
 */

import {
	useCommandRead,
	useCommandWrite,
} from '@newspack-nodes/shared/hooks/useCommandRead';

export {
	commandArgs,
	refusalOfSend,
} from '@newspack-nodes/shared/hooks/useCommandRead';

/** The surface every command here is sent under. */
export const GROUP = 'intelligence';

/** The server CI mount a command here lives on unless a write names another. */
const CI = 'intelligence';

/** The folders, one folder's items, and the folder pickers. */
export const FOLDER_READS = [ 'collections:' ];

/** The watches, with their unseen counts, and each watch's matches. */
export const WATCH_READS = [ 'watches:', 'watch_matches:' ];

/** The health strip's slices. */
export const HEALTH_READS = [ 'health:' ];

/** Every read listing or counting items, so resting on which items show. */
export const ITEM_READS = [
	'search:',
	'briefing:',
	'timeline:',
	'signals:',
	'trends:',
	'related:',
	'claims:',
	'review:',
	'entity:',
	'theme_suggestions:',
	'ask_result:',
	'excluded:',
	'copies:',
	'trace:',
	...FOLDER_READS,
	...WATCH_READS,
];

/** Every read naming an entity, its links, names, identifiers or roles. */
export const ENTITY_READS = [ ...ITEM_READS, 'names:' ];

/** Those, and the health strip's dead clients, named by role and domain. */
export const CLIENT_READS = [ ...ENTITY_READS, ...HEALTH_READS ];

/** Those, and the lens list, whose entity and topics show only while seen. */
export const JUDGED_READS = [ ...CLIENT_READS, 'lenses:' ];

/** The lens list, and every read a lens's filters shape. */
export const LENS_READS = [
	'lenses:',
	'search:',
	'trends:',
	'signals:',
	'briefing:',
	...WATCH_READS,
];

/** Every read a theme's merge regroups, and the trace naming a survivor. */
export const THEME_READS = [
	'theme_suggestions:',
	'search:',
	'trends:',
	'signals:',
	'briefing:',
	'trace:',
];

/**
 * Ask one read command of the `intelligence` CI, again whenever `args`
 * changes; see `useCommandRead`.
 *
 * @param {string}    scope   Names this read's nodes, a subject naming what
 *                            the slice shows, so two reads on a page never
 *                            share a Fetcher.
 * @param {string}    command The read verb.
 * @param {?string[]} args    Its argument tokens, or null to ask nothing.
 * @return {ReturnType<typeof useCommandRead>} The read.
 */
export const useIntelRead = ( scope, command, args ) =>
	useCommandRead( { group: GROUP, ci: CI, scope, command, args } );

/**
 * One curation command, sent once per `run( args )`, to the `intelligence`
 * CI unless `ci` names another; see `useCommandWrite`.
 *
 * @param {string}                                               command The write verb.
 * @param {Omit<Parameters<typeof useCommandWrite>[1], 'group'>} [opts]  Its options, but the group.
 * @return {ReturnType<typeof useCommandWrite>} The write.
 */
export const useIntelWrite = ( command, { ci = CI, ...opts } = {} ) =>
	useCommandWrite( command, { ci, ...opts, group: GROUP } );
