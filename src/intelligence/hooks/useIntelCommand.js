/**
 * The Intelligence page's one-shot commands, each a `useCommandOnce` riding
 * the batched router tick under the `intelligence` group, so `connect
 * intelligence:shell` watches every send. Each goes to the `intelligence` CI
 * but a write naming another, as the health strip's fixes to the substrate's
 * `workers` and `topologies` CIs do.
 *
 * A read is asked again whenever its arguments change and shows only the
 * answer to the question it asks now: the Fetcher's gate drops the answer to
 * a superseded ask, and the arguments a reply echoes say which question it
 * answers (ADR-7). A write is sent once per `run()` and refreshes what it
 * changed once it lands.
 *
 * A send never names a subject: an item key spells a whole URL, past the length
 * a reply address carries, so a subject would be dropped for that one item. A
 * write's refusal names its send by the arguments it echoes instead.
 *
 * Every answer is remembered in memory, keyed by verb and arguments, so a read
 * mounting again paints its last answer at once, marked stale, while it asks
 * afresh, and reads of one question under two scopes share it: a read whose
 * question another mounted read's Fetcher is already asking sends nothing and
 * shows that answer when it lands. A refusal forgets the answer it would have
 * replaced, so an item a reader may no longer see never paints after the
 * server has said so. The memory lives in the page alone and is never written
 * to browser storage, because an answer carries items under the reader's
 * tiers; a page load starts empty.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Core, formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';

/** The surface every command here is sent under. */
export const GROUP = 'intelligence';

/** The server CI mount a command here lives on unless a write names another. */
const CI = 'intelligence';

/** Every send here is about nothing narrower than its verb. */
const NO_SUBJECT = () => null;

/** The most answers remembered; the least recently used goes first. */
const CACHE_MAX = 64;

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

/** Every read a theme's merge regroups. */
export const THEME_READS = [
	'theme_suggestions:',
	'search:',
	'trends:',
	'signals:',
	'briefing:',
];

/**
 * Each page process's remembered answers, by the node registry `Core` boots
 * with, so a page load, or a `Core.reset()`, starts with none.
 *
 * @type {WeakMap<Object, Map<string, {answer: Object, at: number, landing: number}>>}
 */
const memories = new WeakMap();

/** How many answers have landed, which orders an answer against an ask. */
let landings = 0;

/**
 * This page's remembered answers, `{ answer, at, landing }` by cache key,
 * oldest use first.
 *
 * @return {Map<string, {answer: Object, at: number, landing: number}>} The answers.
 */
function answers() {
	if ( ! memories.has( Core.registry ) ) {
		memories.set( Core.registry, new Map() );
	}
	return memories.get( Core.registry );
}

/**
 * One mounted read: the cache key of what it asks, its Fetcher, the re-ask a
 * write's dirtying runs, and the wake that has it look again for its answer.
 *
 * @typedef {Object} Reader
 * @property {string}                                      entry      Its cache key.
 * @property {string}                                      fetcher    Its Fetcher's node name.
 * @property {(dirtied: (key: string) => boolean) => void} revalidate Asks again when a write dirtied it.
 * @property {() => void}                                  wake       Has it look again.
 */

/** @type {Set<Reader>} */
const readers = new Set();

/**
 * Wake every mounted read of `entry` but `self`.
 *
 * @param {string}  entry The cache key.
 * @param {?Reader} self  The read waking them.
 */
function wakeReaders( entry, self ) {
	readers.forEach(
		( reader ) => reader !== self && entry === reader.entry && reader.wake()
	);
}

/**
 * The key an answer is remembered under, its verb first, so a write dirties
 * every read of a verb by the prefix `<verb>:`.
 *
 * @param {string}   command The read verb.
 * @param {string[]} args    Its argument tokens.
 * @return {string} The key.
 */
const cacheKey = ( command, args ) =>
	`${ command }:${ JSON.stringify( args ) }`;

/**
 * Mark a remembered answer as used now, so it is the last to be evicted.
 *
 * @param {string} key The cache key.
 */
function touch( key ) {
	const memory = answers();
	const entry = memory.get( key );
	if ( entry ) {
		memory.delete( key );
		memory.set( key, entry );
	}
}

/**
 * Remember an answer as the newest, evicting the least recently used past
 * `CACHE_MAX`.
 *
 * @param {string} key    The cache key.
 * @param {Object} answer The answer.
 */
function remember( key, answer ) {
	const memory = answers();
	memory.delete( key );
	landings += 1;
	memory.set( key, { answer, at: Core.now(), landing: landings } );
	if ( CACHE_MAX < memory.size ) {
		memory.delete( memory.keys().next().value );
	}
}

/**
 * Forget every answer whose key starts with one of `prefixes`, and have each
 * mounted read so keyed ask again.
 *
 * @param {string[]} prefixes Cache-key prefixes, such as `collections:`.
 */
function dirty( prefixes ) {
	const dirtied = ( key ) =>
		prefixes.some( ( prefix ) => key.startsWith( prefix ) );
	const memory = answers();
	for ( const key of memory.keys() ) {
		if ( dirtied( key ) ) {
			memory.delete( key );
		}
	}
	readers.forEach( ( reader ) => reader.revalidate( dirtied ) );
}

/**
 * Ask one read command, again whenever `args` changes. Null `args` asks
 * nothing and withdraws the ask outstanding, so its late answer never shows.
 * Until the answer to `args` lands, a remembered one paints, marked stale. A
 * read whose question another mounted read is already asking shares that
 * ask, and asks for itself once the other no longer does.
 *
 * @param {string}    scope   Names this read's nodes, a subject naming what
 *                            the slice shows, so two reads on a page never
 *                            share a Fetcher.
 * @param {string}    command The read verb.
 * @param {?string[]} args    Its argument tokens, or null to ask nothing.
 * @return {{answer: ?Object, error: ?string, loading: boolean, stale: boolean, updatedAt: ?number, refresh: () => void}}
 *   The answer to `args`, or the refusal alone; `loading` while it is owed
 *   with nothing remembered; `stale` while a remembered answer stands in,
 *   received at `updatedAt` (epoch seconds); and `refresh()`, which asks the
 *   same question again.
 */
export function useIntelRead( scope, command, args ) {
	const key = JSON.stringify( args );
	const keyRef = useRef( key );
	keyRef.current = key;
	// The landings counted when this read last asked; a later one answers it.
	const asked = useRef( { key: null, landing: 0 } );
	if ( key !== asked.current.key ) {
		asked.current = { key, landing: landings };
	}
	const [ woken, wake ] = useState( 0 );
	const reader = useRef( /** @type {?Reader} */ ( null ) );
	const { run, abandon, result, error, answeredArgs } = useCommandOnce( {
		group: GROUP,
		ci: CI,
		command,
		scope,
		retry: true,
		subjectOf: NO_SUBJECT,
		onDone: ( { result: landed, error: refused, args: answered } ) => {
			// A bare refusal answers whatever is being asked now.
			const question =
				refused && 0 === answered.length
					? JSON.parse( keyRef.current )
					: answered;
			if ( null === question ) {
				return;
			}
			const entry = cacheKey( command, question );
			if ( refused ) {
				answers().delete( entry );
			} else {
				remember( entry, landed );
			}
			wakeReaders( entry, reader.current );
		},
	} );

	/**
	 * Ask `question`, unless another mounted read of it is asking it, or,
	 * short of `again`, holds its answer already.
	 *
	 * @param {string[]} question The argument tokens.
	 * @param {boolean}  [again]  Ask even where a mounted read holds the answer.
	 */
	const ask = useCallback(
		( question, again = false ) => {
			const entry = cacheKey( command, question );
			const others = [ ...readers ].filter(
				( one ) => one !== reader.current && entry === one.entry
			);
			asked.current = {
				key: JSON.stringify( question ),
				landing: landings,
			};
			if (
				others.some( ( one ) =>
					Core.node( one.fetcher )?.asks( null, question )
				)
			) {
				return;
			}
			const landed = answers().get( entry );
			if ( ! again && landed && 0 < others.length ) {
				// Asked just before the answer the mounted read holds landed.
				asked.current.landing = landed.landing - 1;
				wake( ( n ) => n + 1 );
				return;
			}
			run( question );
		},
		[ command, run ]
	);

	useEffect( () => {
		const question = JSON.parse( key );
		if ( null === question ) {
			abandon();
			return undefined;
		}
		const entry = cacheKey( command, question );
		const self = {
			entry,
			fetcher: `${ scope }:fetch`,
			revalidate: ( dirtied ) =>
				dirtied( entry ) && ask( question, true ),
			wake: () => wake( ( n ) => n + 1 ),
		};
		reader.current = self;
		readers.add( self );
		touch( entry );
		ask( question );
		return () => {
			readers.delete( self );
			reader.current = null;
			wakeReaders( entry, self );
		};
	}, [ key, command, scope, ask, abandon ] );

	// Woken and still owed its answer, a read asks unless another shares.
	useEffect( () => {
		const question = JSON.parse( keyRef.current );
		if ( 0 === woken || null === question ) {
			return;
		}
		const landed = answers().get( cacheKey( command, question ) );
		if ( ! landed || landed.landing <= asked.current.landing ) {
			ask( question );
		}
	}, [ woken, command, ask ] );

	const refresh = useCallback( () => {
		const question = JSON.parse( key );
		if ( null !== question ) {
			ask( question, true );
		}
	}, [ key, ask ] );

	const remembered =
		null === args ? undefined : answers().get( cacheKey( command, args ) );
	// An answer landed after this read asked, by its own Fetcher or another's.
	const fresh = Boolean(
		remembered && remembered.landing > asked.current.landing
	);
	// A bare refusal, such as a Router's NOT_AVAILABLE, answers this ask.
	const own =
		null !== answeredArgs &&
		( JSON.stringify( answeredArgs ) === key ||
			( null !== error && 0 === answeredArgs.length ) );
	const current = fresh || own;
	let answer = remembered?.answer ?? null;
	if ( ! fresh && own ) {
		answer = result;
	}
	return {
		answer,
		error: ! fresh && own ? error : null,
		loading: null !== args && ! current && ! remembered,
		stale: ! current && Boolean( remembered ),
		updatedAt: current ? null : remembered?.at ?? null,
		refresh,
	};
}

/**
 * The tokens naming every value given (`--key=value`), a list once per
 * member, as a command's repeatable option binds them. A blank value, a
 * value not given (null or undefined) and an empty list are left out, so the
 * command reads its own default for them.
 *
 * @param {Object<string, ?(string|number|Array<string>)>} options By name.
 * @return {string[]} The tokens.
 */
export function commandArgs( options ) {
	return Object.entries( options ).flatMap( ( [ name, value ] ) =>
		[]
			.concat( value )
			.filter(
				( one ) => '' !== one && null !== one && undefined !== one
			)
			.flatMap( ( one ) => formatCommandArgs( [], { [ name ]: one } ) )
	);
}

/**
 * One curation command, sent once per `run( args )` with every arg named
 * through `commandArgs()`, so a curator's value that begins `--name=` binds
 * as the value it is rather than as an option. `run()` answers the tokens it sent;
 * a landed send hands `refresh` its answer, and a refused one hands `onRefused` the
 * tokens its reply echoes, which say which send it was, and its own refusal,
 * which a later reply in the same batch cannot overwrite. A landed send first
 * forgets every remembered answer under the prefixes it `dirties`, and every
 * mounted read so keyed asks again. The last reply's
 * refusal, the arguments it answered and whether a send is still owed its
 * reply are `useCommandOnce`'s own. A send still queued when the hook unmounts
 * or the page is left goes all the same, because a write declares it.
 *
 * @param {string}                                    command          The write verb.
 * @param {Object}                                    [opts]           Options.
 * @param {string}                                    [opts.ci]        The CI the command lives on.
 * @param {string}                                    [opts.scope]     Names this write's nodes, a
 *                                                                     subject naming what it is
 *                                                                     for, so two writes of one verb
 *                                                                     on a page never share a Fetcher;
 *                                                                     `<ci>:<command>` when omitted.
 * @param {(answer: Object) => void}                  [opts.refresh]   Runs once a send lands, given its answer.
 * @param {string[]}                                  [opts.dirties]   Cache-key prefixes a landed send
 *                                                                     forgets and re-reads, such as
 *                                                                     `collections:`.
 * @param {(tokens: string[], error: string) => void} [opts.onRefused] Runs once a send is refused.
 * @return {{run: (args: Object<string, string|Array<string>>) => string[], refusal: ?string, answeredArgs: ?string[], pending: boolean}}
 *   `run()`, the last refusal, the arguments the last reply answered, and
 *   whether a send is still owed its reply.
 */
export function useIntelWrite(
	command,
	{
		ci = CI,
		scope = `${ ci }:${ command }`,
		refresh,
		onRefused,
		dirties = [],
	} = {}
) {
	const {
		run: send,
		error,
		answeredArgs,
		pending,
	} = useCommandOnce( {
		group: GROUP,
		ci,
		command,
		scope,
		subjectOf: NO_SUBJECT,
		sendsOnRemoval: true,
		onDone: ( { error: refused, args, result } ) => {
			if ( refused ) {
				onRefused?.( args, refused );
				return;
			}
			dirty( dirties );
			refresh?.( result );
		},
	} );
	const run = useCallback(
		( args ) => {
			const tokens = commandArgs( args );
			send( tokens );
			return tokens;
		},
		[ send ]
	);
	return { run, refusal: error, answeredArgs, pending };
}
