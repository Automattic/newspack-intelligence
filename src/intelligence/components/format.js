/**
 * Formatting for strings and times read off the graph, which other people
 * wrote: an item's URL becomes an href only when it is http or https.
 */

/**
 * The URL as an href, or null for any other scheme.
 *
 * @param {?string} url A URL from the graph.
 * @return {?string} The URL, when it is safe to link.
 */
export function safeHref( url ) {
	return 'string' === typeof url && /^https?:\/\//i.test( url ) ? url : null;
}

/**
 * The UTC day of a Unix time, Y-m-d, or '' for none.
 *
 * @param {?number} ts Seconds since the epoch; 0 is no time.
 * @return {string} The day.
 */
export function utcDay( ts ) {
	return ts ? new Date( ts * 1000 ).toISOString().slice( 0, 10 ) : '';
}

/**
 * A row's meta line: its parts joined by " · ", leaving out only the absent
 * ones — null, undefined and '' — so a 0 still shows.
 *
 * @param {...*} parts The parts, in order.
 * @return {string} The line.
 */
export function metaLine( ...parts ) {
	return parts
		.filter(
			( part ) => null !== part && undefined !== part && '' !== part
		)
		.join( ' · ' );
}
