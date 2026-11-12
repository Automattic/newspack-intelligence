/**
 * Review's paged reads of its match and name queues, each read after the
 * cursor the page before it answered.
 */

import { useCallback, useState } from '@wordpress/element';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';

/** The rows one page of a review queue holds. */
export const REVIEW_PAGE = 50;

/**
 * @typedef {ReturnType<typeof useIntelRead>} Read
 * @typedef {Read & {paging: boolean, page: number, at: ?string, reached: ?Set<string>, turn: (to: number) => boolean}} QueuePage
 */

/** What a queue has seen before its first answer. */
const UNSEEN = { held: null, pages: new Map(), reached: null };

/**
 * One page of a review queue, read after the cursor the page before it
 * answered as `next`; the cursors the reader turned through are a stack, so
 * a page turned back to is read after the cursor it came from. One answer
 * stands in for another, and says so: while the page it turns to is owed,
 * it holds the last page's answer, `next`, `stale` and `updatedAt`
 * included, with `paging` true, and `page` and `at` name that page by its
 * number and the cursor it was read after, so a pager, its focus and the
 * rows a curator judges stay on one page until the next lands, while its
 * `refresh` and `error` stay the owed page's. `turn( to )` goes on to the
 * page after this one, while it is answered and names a `next`, or back to
 * any before it, and answers whether it moved. `reached` holds the keys
 * `keysOf` reads off every page the queue still reaches: its first and,
 * from each page it has read, the one after the `next` that page last
 * answered; null until a page answers.
 *
 * @param {string}                       scope  Names the read's nodes.
 * @param {string}                       what   The queue: links or entities.
 * @param {(answer: Object) => string[]} keysOf The keys a page's answer lists.
 * @return {QueuePage} The page's read.
 */
export function useQueuePage( scope, what, keysOf ) {
	const [ cursors, setCursors ] = useState( [ null ] );
	const at = cursors.at( -1 );
	const read = useIntelRead( scope, 'review', [
		what,
		...commandArgs( { limit: REVIEW_PAGE, after: at } ),
	] );
	const [ seen, setSeen ] = useState( UNSEEN );
	if ( read.answer && read.answer !== seen.held?.answer ) {
		setSeen( see( seen.pages, read, cursors.length, at, keysOf ) );
	}
	const paging = read.loading && null !== seen.held;
	const next = read.answer?.next;
	const turn = useCallback(
		( to ) => {
			if ( 1 <= to && to < cursors.length ) {
				setCursors( cursors.slice( 0, to ) );
				return true;
			}
			if ( to === cursors.length + 1 && next ) {
				setCursors( [ ...cursors, next ] );
				return true;
			}
			return false;
		},
		[ cursors, next ]
	);
	const { reached } = seen;
	return paging
		? { ...read, ...seen.held, loading: false, paging, reached, turn }
		: { ...read, paging, page: cursors.length, at, reached, turn };
}

/**
 * What a queue has seen once a page answers: that page held as it read,
 * and the pages its cursors still reach from the first, with their keys.
 *
 * @param {Map<?string, {listed: string[], next: ?string}>} pages  Each page read, by cursor.
 * @param {Read}                                            read   The page's read.
 * @param {number}                                          page   Its number.
 * @param {?string}                                         at     Its cursor.
 * @param {(answer: Object) => string[]}                    keysOf The keys an answer lists.
 * @return {{held: Object, pages: Map<?string, {listed: string[], next: ?string}>, reached: Set<string>}} The seen.
 */
function see( pages, read, page, at, keysOf ) {
	const { answer, stale, updatedAt } = read;
	const every = new Map( pages ).set( at, {
		listed: keysOf( answer ),
		next: answer.next ?? null,
	} );
	const reach = new Map();
	for (
		let cursor = null;
		every.has( cursor ) && ! reach.has( cursor );
		cursor = reach.get( cursor ).next
	) {
		reach.set( cursor, every.get( cursor ) );
	}
	return {
		held: { answer, stale, updatedAt, page, at },
		pages: reach,
		reached: new Set(
			[ ...reach.values() ].flatMap( ( one ) => one.listed )
		),
	};
}
