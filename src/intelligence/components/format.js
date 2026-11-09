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

/** The days back past which a last look is named by its date. */
const WEEK_DAYS = 7;

/** One calendar day, in milliseconds. */
const DAY_MS = 86400000;

/**
 * An `Intl` format in the page's own language, `document.documentElement`'s
 * `lang`, or the default one when that is empty or not a language tag, the
 * one input the constructor's RangeError refuses here.
 *
 * @template T
 * @param {new ( locale?: string, options?: Object ) => T} Format    The format's constructor.
 * @param {Object}                                         [options] Its options.
 * @return {T} The format.
 */
function pageFormat( Format, options ) {
	try {
		return new Format(
			document.documentElement.lang || undefined,
			options
		);
	} catch {
		return new Format( undefined, options );
	}
}

/**
 * The reader's calendar day of an instant, as a count of days, so two
 * instants a DST change separates still differ by whole days.
 *
 * @param {Date} at The instant.
 * @return {number} Days since the epoch, by the local date.
 */
const calendarDay = ( at ) =>
	Date.UTC( at.getFullYear(), at.getMonth(), at.getDate() ) / DAY_MS;

/**
 * A last look named by the reader's calendar, in the browser's zone: "today
 * at 07:42", "yesterday at 09:05", "Tuesday at 14:31" within a week, else
 * its date, "28 Oct". A mark ahead of `now`, from a skewed clock, is today.
 *
 * @param {number} since The last look, UTC seconds.
 * @param {number} now   The instant it is named from, UTC seconds.
 * @return {string} The name.
 */
export function sinceLabel( since, now ) {
	const then = new Date( since * 1000 );
	const days = calendarDay( new Date( now * 1000 ) ) - calendarDay( then );
	const part = ( options ) => pageFormat( Intl.DateTimeFormat, options );
	if ( WEEK_DAYS <= days ) {
		return sprintf(
			/* translators: 1: day of the month, 2: short month name. */
			__( '%1$s %2$s', 'newspack-intelligence' ),
			part( { day: 'numeric' } ).format( then ),
			part( { month: 'short' } ).format( then )
		);
	}
	const time = part( {
		hour: '2-digit',
		minute: '2-digit',
		hourCycle: 'h23',
	} ).format( then );
	if ( 1 > days ) {
		/* translators: %s: a time today, 24-hour. */
		return sprintf( __( 'today at %s', 'newspack-intelligence' ), time );
	}
	if ( 1 === days ) {
		return sprintf(
			/* translators: %s: a time yesterday, 24-hour. */
			__( 'yesterday at %s', 'newspack-intelligence' ),
			time
		);
	}
	return sprintf(
		/* translators: 1: weekday name, 2: a time that day, 24-hour. */
		__( '%1$s at %2$s', 'newspack-intelligence' ),
		part( { weekday: 'long' } ).format( then ),
		time
	);
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
