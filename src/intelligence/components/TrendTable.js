import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { VisuallyHidden } from '@wordpress/components';
import * as d3 from 'd3';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import Sparkline from '@newspack-nodes/shared/components/Sparkline';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import { integerTicks } from '@newspack-nodes/shared/utils/axis-ticks';
import Cite from './Cite';

/** The newest buckets a trend chart draws, as many as a signal's history. */
const CHART_BUCKETS = 8;

/** The most values a trend chart draws a series for, the largest first. */
const CHART_VALUES = 5;

/** The trend chart's height in pixels, axis margins included. */
const CHART_HEIGHT = 180;

/** A signals card's sparkline box in pixels. */
const SPARK_WIDTH = 96;
const SPARK_HEIGHT = 24;

/** Stories, whole ones, ticked at whole steps. */
const formatStories = d3.format( 'd' );
formatStories.tickValues = integerTicks;

/**
 * The axis formatter whatever the peak: every count is whole stories.
 *
 * @return {import('@newspack-nodes/shared/utils/axis-ticks').AxisFormatter} The formatter.
 */
const storiesFor = () => formatStories;

/**
 * The colour a value takes from its rank in the chart.
 *
 * @param {string} _value The value; the rank alone decides.
 * @param {number} index  Its rank.
 * @return {string} A CSS colour value.
 */
const rankColor = ( _value, index ) => chartColor( index );

/**
 * A signal's count against the baseline it is read against, to one decimal.
 *
 * @param {Object} signal A signal as `signals` answers it.
 * @return {string} The count.
 */
export const againstBaseline = ( signal ) =>
	sprintf(
		/* translators: 1: the bucket's count, 2: the mean of the buckets before. */
		__( '%1$d against a baseline of %2$s', 'newspack-intelligence' ),
		signal.count,
		String( Math.round( signal.baseline * 10 ) / 10 )
	);

/**
 * The words a signal's axis and kind are shown in, each by the token the
 * server answers.
 *
 * @return {Object<string, string>} Each token's words.
 */
const signalWords = () => ( {
	about: __( 'about', 'newspack-intelligence' ),
	publisher: __( 'publisher', 'newspack-intelligence' ),
	spike: __( 'spike', 'newspack-intelligence' ),
	drop: __( 'drop', 'newspack-intelligence' ),
	silent: __( 'silent', 'newspack-intelligence' ),
	burst: __( 'burst', 'newspack-intelligence' ),
} );

/**
 * A signal as its axis and its kind, each a token it does not know as
 * spelled, then the value it counts, when it counts one.
 *
 * @param {Object} signal A signal as `signals` answers it.
 * @return {string} The value.
 */
export const signalValue = ( signal ) => {
	const words = signalWords();
	const axis = words[ signal.axis ] ?? signal.axis;
	const kind = words[ signal.signal ] ?? signal.signal;
	return null === signal.value
		? sprintf(
				/* translators: 1: the signal's axis, 2: its kind. */
				__( '%1$s %2$s', 'newspack-intelligence' ),
				axis,
				kind
		  )
		: sprintf(
				/* translators: 1: the signal's axis, 2: its kind, 3: the value it counts. */
				__( '%1$s %2$s: %3$s', 'newspack-intelligence' ),
				axis,
				kind,
				signal.value
		  );
};

/**
 * Trend rows, or signals: each bucket, a date that does not wrap, and value
 * with its count, citing the newest items the row counts. A row answering no
 * `items` shows none.
 *
 * @param {Object}                           props
 * @param {Array<Object>}                    props.rows    The rows `trends` or `signals` answers.
 * @param {(row: Object) => string}          [props.value] A row's value as shown.
 * @param {(row: Object) => (string|number)} [props.count] A row's count as shown.
 * @return {import('react').ReactElement} The table.
 */
export default function TrendTable( {
	rows,
	value = ( row ) => row.value,
	count = ( row ) => row.count,
} ) {
	return (
		<table className="newspack-nodes-table">
			<thead>
				<tr>
					<th scope="col">
						{ __( 'Bucket', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Value', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Count', 'newspack-intelligence' ) }
					</th>
					<th scope="col">
						{ __( 'Items', 'newspack-intelligence' ) }
					</th>
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
 * A signals answer: its trend as a sparkline beside what the latest
 * bucket says — each signal called out by its axis, kind and value, or
 * "Steady" when nothing signals — then the signals as a table, each counted
 * against its baseline. A trend holding no bucket draws no line.
 *
 * @param {Object}                  props
 * @param {Object}                  props.answer  The answer `signals` gave: `signals` and `trend`.
 * @param {(row: Object) => string} [props.value] A signal's value as its row shows it.
 * @return {import('react').ReactElement} The signals.
 */
export function SignalTrend( { answer, value } ) {
	const { signals, trend } = answer;
	return (
		<>
			<p className="newspack-intelligence__pulse">
				<Sparkline
					values={ trend.map( ( point ) => point.count ).reverse() }
					width={ SPARK_WIDTH }
					height={ SPARK_HEIGHT }
					label={ trendWords( trend ) }
				/>
				<span>
					{ 0 === signals.length
						? __( 'Steady', 'newspack-intelligence' )
						: signals.map( signalValue ).join( ', ' ) }
				</span>
			</p>
			{ 0 < signals.length && (
				<TrendTable
					rows={ signals }
					value={ value }
					count={ againstBaseline }
				/>
			) }
		</>
	);
}

/**
 * A trend in words for a screen reader: every bucket's count, oldest first,
 * then the latest bucket's first day and count; one bucket alone in the
 * singular, and an empty trend as an empty string, because it draws nothing.
 *
 * @param {Array<{bucket: string, count: number}>} trend The trend `signals` answers, the latest bucket first.
 * @return {string} The words.
 */
function trendWords( trend ) {
	if ( 0 === trend.length ) {
		return '';
	}
	const [ latest ] = trend;
	if ( 1 === trend.length ) {
		return sprintf(
			/* translators: 1: the first day of the one bucket, 2: its count. */
			__(
				'Stories in the last bucket, from %1$s: %2$d',
				'newspack-intelligence'
			),
			latest.bucket,
			latest.count
		);
	}
	return sprintf(
		/* translators: 1: how many buckets, 2: each bucket's count, oldest first, 3: the first day of the latest bucket, 4: its count. */
		__(
			'Stories in each of the last %1$d buckets, oldest first: %2$s; the latest, from %3$s, counts %4$d',
			'newspack-intelligence'
		),
		trend.length,
		trend
			.map( ( point ) => point.count )
			.reverse()
			.join( ', ' ),
		latest.bucket,
		latest.count
	);
}

/**
 * A row's key: its axis and kind when a signal, its bucket and its value.
 *
 * @param {Object} row A trend row or a signal.
 * @return {string} The key.
 */
const rowKey = ( row ) =>
	[ row.axis, row.signal, row.bucket, row.value ].join( ' ' );

/**
 * Trend rows as the series the shared time chart draws: the CHART_BUCKETS
 * newest buckets, oldest first, each a UTC day, and the CHART_VALUES values
 * counting the most over them, the largest first, then by value, each a
 * count per bucket, 0 where a bucket counts none.
 *
 * @param {Array<Object>} rows The rows `trends` answers, newest bucket first.
 * @return {{buckets: string[], series: Array<{label: string, values: Array<{date: Date, value: number}>}>}} The series.
 */
function trendSeries( rows ) {
	const buckets = [ ...new Set( rows.map( ( row ) => row.bucket ) ) ]
		.slice( 0, CHART_BUCKETS )
		.reverse();
	const counts = new Map();
	for ( const row of rows ) {
		if ( buckets.includes( row.bucket ) ) {
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
			label,
			values: buckets.map( ( bucket ) => ( {
				date: new Date( `${ bucket }T00:00:00Z` ),
				value: per[ bucket ] ?? 0,
			} ) ),
		} ) );
	return { buckets, series };
}

/**
 * Trend rows as one compact time chart, the shared `AreaTimeChart`: a
 * series per value over the newest buckets (`trendSeries()`), titled by the
 * span it covers, its legend picking a value. A screen reader is handed
 * every row as a visually hidden table of its bucket, value and count; no
 * rows draw nothing.
 *
 * @param {Object}        props
 * @param {Array<Object>} props.rows The rows `trends` answers, newest bucket first.
 * @return {?import('react').ReactElement} The chart.
 */
export function TrendChart( { rows } ) {
	const { buckets, series } = useMemo( () => trendSeries( rows ), [ rows ] );
	if ( 0 === rows.length ) {
		return null;
	}
	return (
		<>
			<AreaTimeChart
				series={ series }
				yFormatFor={ storiesFor }
				colorAt={ rankColor }
				title={ sprintf(
					/* translators: 1: the oldest bucket drawn, 2: the newest. */
					__( 'From %1$s to %2$s', 'newspack-intelligence' ),
					buckets[ 0 ],
					buckets[ buckets.length - 1 ]
				) }
				height={ CHART_HEIGHT }
				storageKey="newspack-intelligence:lens-trends"
				yLabel={ __( 'Stories', 'newspack-intelligence' ) }
			/>
			<VisuallyHidden>
				<table>
					<caption>
						{ __( 'Trend rows', 'newspack-intelligence' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Bucket', 'newspack-intelligence' ) }
							</th>
							<th scope="col">
								{ __( 'Value', 'newspack-intelligence' ) }
							</th>
							<th scope="col">
								{ __( 'Count', 'newspack-intelligence' ) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ rowKey( row ) }>
								<td>{ row.bucket }</td>
								<td>{ row.value }</td>
								<td>{ row.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</VisuallyHidden>
		</>
	);
}
