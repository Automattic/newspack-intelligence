/**
 * When a reader last looked at the Briefing, held for the whole browser
 * session.
 *
 * Two clocks are read. How long the Briefing has been seen is a duration in
 * this page, so it is `performance.now()`, which never steps. The last look
 * itself is compared on the server against items' stamps, so it is the wall
 * clock, `Date.now()`, and carries the browser's skew.
 */

import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * How long the Briefing must be seen in a page before a leave marks it.
 *
 * @testonly Exported so the suite counts against the same threshold.
 */
export const MIN_VISIBLE_MS = 10000;

/**
 * Where the browser keeps the reader's last look, across sessions.
 *
 * @param {string} reader The reader.
 * @return {string} The localStorage key.
 */
const lastLookKey = ( reader ) => `newspack-intelligence:last-look:${ reader }`;

/**
 * Where the session keeps the baseline it read on its first page.
 *
 * @param {string} reader The reader.
 * @return {string} The sessionStorage key.
 */
const baselineKey = ( reader ) => `newspack-intelligence:baseline:${ reader }`;

/**
 * Where the session counts how long its pages have shown the Briefing.
 *
 * @param {string} reader The reader.
 * @return {string} The sessionStorage key.
 */
const seenKey = ( reader ) => `newspack-intelligence:seen-ms:${ reader }`;

/**
 * One store by its window property, or null where touching it throws, as a
 * browser blocking site data makes it.
 *
 * @param {'localStorage'|'sessionStorage'} name The store.
 * @return {?Storage} The store.
 */
function store( name ) {
	try {
		return window[ name ];
	} catch {
		return null;
	}
}

/**
 * Read a key, answering `undefined` where the store refuses, so a refusal is
 * told from a key never set, which reads null.
 *
 * @param {?Storage} from The store.
 * @param {string}   key  The key.
 * @return {?string|undefined} The value, null, or undefined for a refusal.
 */
function read( from, key ) {
	try {
		return from ? from.getItem( key ) : undefined;
	} catch {
		return undefined;
	}
}

/**
 * Write a key, best-effort: a store that refuses keeps nothing.
 *
 * @param {?Storage} to    The store.
 * @param {string}   key   The key.
 * @param {string}   value The value.
 * @return {boolean} Whether the store took it.
 */
function write( to, key, value ) {
	try {
		if ( ! to ) {
			return false;
		}
		to.setItem( key, value );
		return true;
	} catch {
		return false;
	}
}

/**
 * A stored whole number, an instant or a count, or null for none or for a
 * value that is not one.
 *
 * @param {?string|undefined} stored The stored string.
 * @return {?number} The number.
 */
const wholeNumber = ( stored ) =>
	'string' === typeof stored && /^\d+$/.test( stored )
		? Number( stored )
		: null;

/**
 * The session's baseline: the one the session holds, '' holding "never
 * looked", else the last look, copied into the session so every page of it
 * reads the same. `kept` is false when neither store answered, or when the
 * session would not take the copy, since a reload would then read a newer
 * last look and the baseline would drift.
 *
 * @param {string} reader The reader.
 * @return {{since: ?number, kept: boolean}} The baseline.
 */
function baseline( reader ) {
	const session = store( 'sessionStorage' );
	const held = read( session, baselineKey( reader ) );
	if ( 'string' === typeof held ) {
		return { since: wholeNumber( held ), kept: true };
	}
	const last = read( store( 'localStorage' ), lastLookKey( reader ) );
	if ( undefined === last ) {
		return { since: null, kept: false };
	}
	const since = wholeNumber( last );
	const copied = write(
		session,
		baselineKey( reader ),
		null === since ? '' : String( since )
	);
	return copied ? { since, kept: true } : { since: null, kept: false };
}

/**
 * The reader's last look, fixed for the session as `since`, UTC seconds or
 * null for a reader who never looked; `kept` false where storage refuses.
 *
 * The page holds it, so a tab switch keeps it. The Briefing calls
 * `noteVisible( true )` when it mounts and `noteVisible( false )` when it
 * unmounts; only time it shows while the document is visible counts, summed
 * over the session's pages in sessionStorage, and
 * counting resumes on `pageshow`, a return from the back-forward cache. On
 * `pagehide`, or the document turning hidden, once that time reaches
 * MIN_VISIBLE_MS, the last look is written as the instant the Briefing was
 * last seen, for the next session to read.
 *
 * @param {string} reader The reader, whose keys hold their own last look.
 * @return {{since: ?number, kept: boolean, noteVisible: (visible: boolean) => void}}
 *   The baseline, whether it is known, and the Briefing's visibility seam.
 */
export function useLastLook( reader ) {
	const [ { since, kept } ] = useState( () => baseline( reader ) );
	const seen = useRef( {
		shown: false,
		from: null,
		ms: 0,
		lastSeenMs: null,
	} );

	const pause = useCallback( () => {
		const watch = seen.current;
		if ( null !== watch.from ) {
			const elapsed = performance.now() - watch.from;
			const session = store( 'sessionStorage' );
			const before = wholeNumber( read( session, seenKey( reader ) ) );
			watch.ms += elapsed;
			watch.from = null;
			watch.lastSeenMs = Date.now();
			write(
				session,
				seenKey( reader ),
				String( Math.round( ( before ?? 0 ) + elapsed ) )
			);
		}
	}, [ reader ] );

	const resume = useCallback( () => {
		const watch = seen.current;
		if (
			watch.shown &&
			null === watch.from &&
			'visible' === document.visibilityState
		) {
			watch.from = performance.now();
		}
	}, [] );

	const noteVisible = useCallback(
		( visible ) => {
			seen.current.shown = visible;
			if ( visible ) {
				resume();
			} else {
				pause();
			}
		},
		[ pause, resume ]
	);

	useEffect( () => {
		const leave = () => {
			pause();
			const watch = seen.current;
			const counted = wholeNumber(
				read( store( 'sessionStorage' ), seenKey( reader ) )
			);
			if (
				null !== watch.lastSeenMs &&
				MIN_VISIBLE_MS <= Math.max( counted ?? 0, watch.ms )
			) {
				write(
					store( 'localStorage' ),
					lastLookKey( reader ),
					String( Math.floor( watch.lastSeenMs / 1000 ) )
				);
			}
		};
		const turned = () =>
			'hidden' === document.visibilityState ? leave() : resume();
		document.addEventListener( 'visibilitychange', turned );
		window.addEventListener( 'pagehide', leave );
		window.addEventListener( 'pageshow', resume );
		return () => {
			document.removeEventListener( 'visibilitychange', turned );
			window.removeEventListener( 'pagehide', leave );
			window.removeEventListener( 'pageshow', resume );
		};
	}, [ reader, pause, resume ] );

	return { since, kept, noteVisible };
}
