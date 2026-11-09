/**
 * Formatting for strings and times read off the graph, which other people
 * wrote: an item's URL becomes an href only when it is http or https.
 */

import { __, sprintf } from '@wordpress/i18n';
import { word } from './words';

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
export function pageFormat( Format, options ) {
	try {
		return new Format(
			document.documentElement.lang || undefined,
			options
		);
	} catch {
		return new Format( undefined, options );
	}
}

/** Each date format by its options, built on first use, once per module. */
const dateFormats = new Map();

/**
 * A date format in the page's own language (`pageFormat()`), built once for
 * its options.
 *
 * @param {Intl.DateTimeFormatOptions} options Its options.
 * @return {Intl.DateTimeFormat} The format.
 */
function dateFormat( options ) {
	const key = JSON.stringify( options );
	if ( ! dateFormats.has( key ) ) {
		dateFormats.set( key, pageFormat( Intl.DateTimeFormat, options ) );
	}
	return dateFormats.get( key );
}

/**
 * An instant by its day and short month, "6 Nov", in `timeZone`, else the
 * reader's.
 *
 * @param {Date}    at         The instant.
 * @param {?string} [timeZone] The IANA zone to read it in.
 * @return {string} The day and month.
 */
function dayMonth( at, timeZone ) {
	return sprintf(
		/* translators: 1: day of the month, 2: short month name. */
		__( '%1$s %2$s', 'newspack-intelligence' ),
		dateFormat( { day: 'numeric', timeZone } ).format( at ),
		dateFormat( { month: 'short', timeZone } ).format( at )
	);
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
	if ( WEEK_DAYS <= days ) {
		return dayMonth( then );
	}
	const time = dateFormat( {
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
		dateFormat( { weekday: 'long' } ).format( then ),
		time
	);
}

/**
 * A UTC day, Y-m-d, by its day and short month, "6 Nov", with its year only
 * when it is not `today`'s: "6 Nov 2025". Both are UTC days, so no zone moves
 * the day named.
 *
 * @param {string} day   The day, Y-m-d.
 * @param {string} today Today's UTC day, Y-m-d.
 * @return {string} The name.
 */
export function dayLabel( day, today ) {
	const named = dayMonth( new Date( `${ day }T00:00:00Z` ), 'UTC' );
	const year = day.slice( 0, 4 );
	return year === today.slice( 0, 4 )
		? named
		: sprintf(
				/* translators: 1: day and short month, 2: year. */
				__( '%1$s %2$s', 'newspack-intelligence' ),
				named,
				year
		  );
}

/**
 * A usual count in words: "usually under 1" for a mean below 1, else
 * "usually about %d", the mean rounded.
 *
 * @param {number} mean The usual mean.
 * @return {string} The phrase.
 */
export function usualPhrase( mean ) {
	return 1 > mean
		? __( 'usually under 1', 'newspack-intelligence' )
		: sprintf(
				/* translators: %d: the usual count, rounded. */
				__( 'usually about %d', 'newspack-intelligence' ),
				Math.round( mean )
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
 * One role row by its word: open from its first day, or held between two.
 *
 * @param {Object} role The role, `to_ts` 0 while open.
 * @return {string} The row's text.
 */
export function roleText( role ) {
	const shown = word( 'roleWords', role.role );
	return 0 === role.to_ts
		? sprintf(
				/* translators: 1: role, 2: first UTC day. */
				__( '%1$s since %2$s', 'newspack-intelligence' ),
				shown,
				utcDay( role.from_ts )
		  )
		: sprintf(
				/* translators: 1: role, 2: first UTC day, 3: last UTC day. */
				__( '%1$s %2$s – %3$s', 'newspack-intelligence' ),
				shown,
				utcDay( role.from_ts ),
				utcDay( role.to_ts )
		  );
}
