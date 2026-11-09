/**
 * Formatting for strings and times read off the graph, which other people
 * wrote: an item's URL becomes an href only when it is http or https.
 */

import { __, sprintf } from '@wordpress/i18n';

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
 * @param {?number} ts Seconds since the epoch; 0 is not a time.
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

/**
 * One role row by its label: open from its first day, or held between two.
 *
 * @param {Object}                 role  The role, `to_ts` 0 while open.
 * @param {Object<string, string>} words Each role's label.
 * @return {string} The row's text.
 */
export function roleText( role, words ) {
	return 0 === role.to_ts
		? sprintf(
				/* translators: 1: role, 2: first UTC day. */
				__( '%1$s since %2$s', 'newspack-intelligence' ),
				words[ role.role ],
				utcDay( role.from_ts )
		  )
		: sprintf(
				/* translators: 1: role, 2: first UTC day, 3: last UTC day. */
				__( '%1$s %2$s – %3$s', 'newspack-intelligence' ),
				words[ role.role ],
				utcDay( role.from_ts ),
				utcDay( role.to_ts )
		  );
}
