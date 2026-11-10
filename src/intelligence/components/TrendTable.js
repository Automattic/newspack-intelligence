import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { VisuallyHidden } from '@wordpress/components';
import * as d3 from 'd3';
import { Core } from '@newspack-nodes/runtime';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import { integerTicks } from '@newspack-nodes/shared/utils/axis-ticks';
import Cite from './Cite';
import { SaveButton } from './ItemRow';
import PulseLine, { spanWords } from './PulseLine';
import { dayLabel, usualPhrase, utcDay } from './format';
import { word, words } from './words';

/** The newest buckets a trend chart draws, as many as a signal's history. */
const CHART_BUCKETS = 8;

/** The most values a trend chart draws a series for, the largest first. */
const CHART_VALUES = 5;

/** The trend chart's height in pixels, axis margins included. */
const CHART_HEIGHT = 180;

/** Stories, whole ones, ticked at whole steps. */
const formatStories = d3.format( 'd' );
formatStories.tickValues = integerTicks;

/**
 * The axis formatter whatever the peak: every count is whole stories.
 *
 * @return {import('@newspack-nodes/shared/utils/axis-ticks').AxisFormatter} The formatter.
 */
const storiesFor = () => formatStories;

/** The most series that skip the third palette slot, which is the accent. */
const FEW_SERIES = 3;

/** Seconds in a UTC day. */
const DAY_SECONDS = 86400;

/**
 * The colour each value takes from its rank in a chart of `count` series:
 * three or fewer take palette slots 1, 2 and 4, so no two are the accent.
 *
 * @param {number} count How many series the chart draws.
 * @return {(value: string, index: number) => string} A rank's CSS colour value.
 */
export const rankColor = ( count ) => ( _value, index ) =>
	chartColor( count <= FEW_SERIES && 2 === index ? 3 : index );

/**
 * A signal's count beside what is usual (`usualPhrase()`).
 *
 * @param {Object} signal A signal as `signals` answers it.
 * @return {string} The count.
 */
export const againstBaseline = ( signal ) =>
	sprintf(
		/* translators: 1: the bucket's count, 2: what is usual, as "usually under 1". */
		__( '%1$d, %2$s', 'newspack-intelligence' ),
		signal.count,
		usualPhrase( signal.usual.mean )
	);

/**
 * A trend or signal value as shown: a kind by its word, any other value, a
 * topic or a theme, as answered, since a theme never names a kind.
 *
 * @param {string} value The value.
 * @return {string} The value shown.
 */
const valueText = ( value ) => words( 'kindWords' )[ value ] ?? value;

/**
 * A trend value as a chart names it: a theme in sentence case, any other as
 * `valueText()` shows it.
 *
 * @param {string} value The value.
 * @param {string} by    The axis it falls on.
 * @return {string} The value shown.
 */
const seriesText = ( value, by ) =>
	'theme' === by
		? value.charAt( 0 ).toUpperCase() + value.slice( 1 )
		: valueText( value );

/**
 * A signal in words (`signalWords`): a spike names the value that jumped,
 * and any other signal is its word, then the value it counts when it counts
 * one.
 *
 * @param {Object} signal A signal as `signals` answers it.
 * @return {string} The value.
 */
export const signalValue = ( signal ) => {
	const said = word( 'signalWords', signal.signal );
	if ( 'spike' === signal.signal ) {
		// The word is a translated template naming one value, `%s` or `%1$s`.
		const value = valueText( String( signal.value ) );
		return said.replace( /%(?:1\$)?s/, () => value );
	}
	return null === signal.value
		? said
		: sprintf(
				/* translators: 1: the signal, 2: the value it counts. */
				__( '%1$s: %2$s', 'newspack-intelligence' ),
				said,
				valueText( signal.value )
		  );
};

/**
 * A table's column headings: the span its rows count, the axis its values
 * fall on, the count and the examples; a span or an axis it is not given
 * reads generically.
 *
 * @param {?string} span The span, `day`, `week` or `month`.
 * @param {?string} axis The axis, `kind`, `topic` or `theme`.
 * @return {string[]} The headings.
 */
const headings = ( span, axis ) => [
	span ? word( 'spanWords', span ) : __( 'When', 'newspack-intelligence' ),
	axis ? word( 'axisWords', axis ) : __( 'What', 'newspack-intelligence' ),
	__( 'Items', 'newspack-intelligence' ),
	__( 'Examples', 'newspack-intelligence' ),
];

/**
 * Trend rows, or signals: each bucket, a date that does not wrap, and value
 * with its count, citing the newest items the row counts, each with the
 * control saving it to a folder for a curator. A row answering no `items`
 * shows none. The headings name the span and the axis when given.
 *
 * @param {Object}                           props
 * @param {Array<Object>}                    props.rows     The rows `trends` or `signals` answers.
 * @param {?string}                          [props.span]   The span the rows count.
 * @param {?string}                          [props.axis]   The axis the values fall on.
 * @param {(row: Object) => string}          [props.value]  A row's value as shown.
 * @param {(row: Object) => (string|number)} [props.count]  A row's count as shown.
 * @param {(item: Object) => void}           [props.onSave] Saves a cited item; absent for a reader.
 * @return {import('react').ReactElement} The table.
 */
export default function TrendTable( {
	rows,
	span = null,
	axis = null,
	value = ( row ) => valueText( row.value ),
	count = ( row ) => row.count,
	onSave,
} ) {
	return (
		<table className="newspack-nodes-table">
			<thead>
				<tr>
					{ headings( span, axis ).map( ( heading ) => (
						<th key={ heading } scope="col">
							{ heading }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ rowKey( row ) }>
						<td className="newspack-intelligence__date">
							{ row.bucket }
						</td>
						<td className="newspack-intelligence__value">
							{ value( row ) }
						</td>
						<td>{ count( row ) }</td>
						<td>
							<ul className="newspack-intelligence__facts">
								{ ( row.items ?? [] ).map( ( item ) => (
									<li key={ item.item_key }>
										<Cite
											url={ item.url }
											title={ item.title }
										/>{ ' ' }
										<SaveButton
											item={ item }
											onSave={ onSave }
										/>
									</li>
								) ) }
							</ul>
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * A signals answer as its pulse line (`PulseLine`, its props read by
 * `pulseOf()`), then, when anything signals, the signals as a table, each
 * counted against its usual mean.
 *
 * @param {Object}                  props
 * @param {Object}                  props.answer   The answer `signals` gave.
 * @param {(row: Object) => string} [props.value]  A signal's value as its row shows it.
 * @param {(item: Object) => void}  [props.onSave] Saves a cited item; absent for a reader.
 * @return {import('react').ReactElement} The signals.
 */
export function Signals( { answer, value, onSave } ) {
	return (
		<>
			<PulseLine { ...pulseOf( answer ) } />
			{ 0 < answer.signals.length && (
				<TrendTable
					rows={ answer.signals }
					span={ answer.bucket }
					value={ value }
					count={ againstBaseline }
					onSave={ onSave }
				/>
			) }
		</>
	);
}

/**
 * A signals answer as a pulse line's props: its first signal, named by the
 * value it counts (`valueText()`), capitalized, else by the signal itself (`signalValue()`),
 * with that signal's own series and usual range; with none, its totals and
 * no range, since the rules judge only a signal's own series.
 *
 * @param {Object} answer The answer `signals` gave: `signals`, `trend`, `partial`, `judged`, `bucket` and `baseline_buckets`.
 * @return {Object} The props.
 */
export function pulseOf( answer ) {
	const [ shown = null ] = answer.signals;
	let subject = '';
	if ( shown ) {
		subject =
			null === shown.value
				? signalValue( shown )
				: valueText( String( shown.value ) );
	}
	const trend = shown ? shown.trend : answer.trend;
	return {
		signal: shown,
		subject: subject.charAt( 0 ).toUpperCase() + subject.slice( 1 ),
		trend: counted( trend, answer.partial ) ? trend : [],
		partial: answer.partial,
		judged: answer.judged,
		several: 1 < answer.signals.length,
		words: spanWords( answer.bucket, answer.baseline_buckets ),
	};
}

/**
 * Whether a series counted anything, in its window or in the bucket still
 * running, since a line of zeros draws nothing worth reading.
 *
 * @param {Array<{count: number, in_window: boolean}>} trend   The series.
 * @param {?{count: number}}                           partial The bucket holding now.
 * @return {boolean} Whether it counted.
 */
const counted = ( trend, partial ) =>
	0 < ( partial?.count ?? 0 ) ||
	trend.some( ( point ) => point.in_window && 0 < point.count );

/**
 * A row's key: its axis and kind when a signal, its bucket and its value.
 *
 * @param {Object} row A trend row or a signal.
 * @return {string} The key.
 */
const rowKey = ( row ) =>
	[ row.axis, row.signal, row.bucket, row.value ].join( ' ' );

/**
 * The first UTC day of the bucket holding `now`, Y-m-d, as the server's
 * BUCKETS spell it: the day, the Monday of the week, or the month's first.
 *
 * @param {string} span The span, `day`, `week` or `month`.
 * @param {number} now  UTC seconds.
 * @return {string} The day.
 */
function runningBucket( span, now ) {
	const day = new Date(
		Math.floor( now / DAY_SECONDS ) * DAY_SECONDS * 1000
	);
	if ( 'week' === span ) {
		day.setUTCDate( day.getUTCDate() - ( ( day.getUTCDay() + 6 ) % 7 ) );
	} else if ( 'month' === span ) {
		day.setUTCDate( 1 );
	}
	return day.toISOString().slice( 0, 10 );
}

/**
 * The words naming the bucket still running, for its bar.
 *
 * @param {string} span The span, `day`, `week` or `month`.
 * @return {string} The label.
 */
const runningLabel = ( span ) =>
	( {
		day: __( 'today', 'newspack-intelligence' ),
		week: __( 'this week', 'newspack-intelligence' ),
		month: __( 'this month', 'newspack-intelligence' ),
	} )[ span ];

/**
 * A trends answer as the series the shared time chart draws: the
 * CHART_BUCKETS newest complete buckets, oldest first, each a UTC day, and
 * the CHART_VALUES values counting the most over them, the largest first,
 * then by value, each a count per bucket, 0 where a bucket counts none; and
 * the bucket still running apart, its total, never a point on the line.
 *
 * @param {{rows: Array<Object>, by: string, bucket: string}} answer The answer `trends` gave, its rows newest bucket first.
 * @param {number}                                            now    UTC seconds.
 * @return {{series: Array<{label: string, values: Array<{date: Date, value: number}>}>, partial: ?{bucket: string, count: number, label: string}}} The series.
 */
function trendSeries( { rows, by, bucket: span }, now ) {
	const running = runningBucket( span, now );
	const buckets = [ ...new Set( rows.map( ( row ) => row.bucket ) ) ]
		.filter( ( bucket ) => bucket !== running )
		.slice( 0, CHART_BUCKETS )
		.reverse();
	const counts = new Map();
	let soFar = 0;
	for ( const row of rows ) {
		if ( row.bucket === running ) {
			soFar += row.count;
		} else if ( buckets.includes( row.bucket ) ) {
			const value = String( row.value );
			counts.set( value, {
				...counts.get( value ),
				[ row.bucket ]: row.count,
			} );
		}
	}
	const total = ( per ) =>
		Object.values( per ).reduce( ( sum, n ) => sum + n, 0 );
	const series = [ ...counts ]
		.sort(
			( [ a, x ], [ b, y ] ) =>
				total( y ) - total( x ) || a.localeCompare( b )
		)
		.slice( 0, CHART_VALUES )
		.map( ( [ label, per ] ) => ( {
			label: seriesText( label, by ),
			values: buckets.map( ( bucket ) => ( {
				date: new Date( `${ bucket }T00:00:00Z` ),
				value: per[ bucket ] ?? 0,
			} ) ),
		} ) );
	const partial = rows.some( ( row ) => row.bucket === running )
		? { bucket: running, count: soFar, label: runningLabel( span ) }
		: null;
	return { series, partial };
}

/**
 * A time tick by its day and short month, "5 Oct", the year only when it is
 * not this one's; d3 places a tick at a local midnight, so the local date.
 *
 * @param {Date} at The tick.
 * @return {string} Its words.
 */
const tickDay = ( at ) =>
	dayLabel(
		[
			at.getFullYear(),
			String( at.getMonth() + 1 ).padStart( 2, '0' ),
			String( at.getDate() ).padStart( 2, '0' ),
		].join( '-' ),
		utcDay( Math.floor( Core.now() ) )
	);

/**
 * A trend chart's title: its heading, its `%s` the axis it counts in lower
 * case, since a curator's heading is a template naming one value.
 *
 * @param {string} heading The heading.
 * @param {string} by      The axis.
 * @return {string} The title.
 */
export const chartTitle = ( heading, by ) => {
	const axis = word( 'axisWords', by ).toLowerCase();
	return heading.replace( /%(?:1\$)?s/, () => axis );
};

/**
 * A trends answer as one compact time chart, the shared `AreaTimeChart`: a
 * series per value over the newest complete buckets (`trendSeries()`), the
 * bucket still running a hatched bar beside them, titled by `heading` with
 * the axis it counted in its `%s`, stacked or drawn as lines as `style`
 * says until the reader flips it, its legend picking a value. A screen
 * reader is handed every row as a visually hidden table of its bucket,
 * value and count; no rows draw nothing.
 *
 * @param {Object}                                            props
 * @param {{rows: Array<Object>, by: string, bucket: string}} props.answer  The answer `trends` gave.
 * @param {string}                                            props.heading The title, `%s` the axis.
 * @param {'stack'|'lines'}                                   props.style   How the series draw at first.
 * @return {?import('react').ReactElement} The chart.
 */
export function TrendChart( { answer, heading, style } ) {
	const { series, partial } = useMemo(
		() => trendSeries( answer, Core.now() ),
		[ answer ]
	);
	if ( 0 === answer.rows.length ) {
		return null;
	}
	const title = chartTitle( heading, answer.by );
	return (
		<>
			<AreaTimeChart
				series={ series }
				yFormatFor={ storiesFor }
				colorAt={ rankColor( series.length ) }
				title={ title }
				height={ CHART_HEIGHT }
				storageKey="newspack-intelligence:lens-trends"
				yLabel={ __( 'Items', 'newspack-intelligence' ) }
				stacked={ 'stack' === style }
				formatX={ tickDay }
				partial={ partial ?? undefined }
			/>
			<VisuallyHidden>
				<table>
					<caption>
						{ __( 'Trend rows', 'newspack-intelligence' ) }
					</caption>
					<thead>
						<tr>
							{ headings( null, null )
								.slice( 0, 3 )
								.map( ( one ) => (
									<th key={ one } scope="col">
										{ one }
									</th>
								) ) }
						</tr>
					</thead>
					<tbody>
						{ answer.rows.map( ( row ) => (
							<tr key={ rowKey( row ) }>
								<td>{ row.bucket }</td>
								<td>
									{ seriesText(
										String( row.value ),
										answer.by
									) }
								</td>
								<td>{ row.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</VisuallyHidden>
		</>
	);
}
