import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { VisuallyHidden } from '@wordpress/components';
import * as d3 from 'd3';
import AreaTimeChart from '@newspack-nodes/shared/components/AreaTimeChart';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import { integerTicks } from '@newspack-nodes/shared/utils/axis-ticks';
import Cite from './Cite';
import { SaveButton } from './ItemRow';
import PulseLine, { spanWords } from './PulseLine';
import { usualPhrase } from './format';
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

/**
 * The colour a value takes from its rank in the chart.
 *
 * @param {string} _value The value; the rank alone decides.
 * @param {number} index  Its rank.
 * @return {string} A CSS colour value.
 */
const rankColor = ( _value, index ) => chartColor( index );

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
function pulseOf( answer ) {
	const [ shown = null ] = answer.signals;
	let subject = '';
	if ( shown ) {
		subject =
			null === shown.value
				? signalValue( shown )
				: valueText( String( shown.value ) );
	}
	return {
		signal: shown,
		subject: subject.charAt( 0 ).toUpperCase() + subject.slice( 1 ),
		trend: shown ? shown.trend : answer.trend,
		partial: answer.partial,
		judged: answer.judged,
		several: 1 < answer.signals.length,
		words: spanWords( answer.bucket, answer.baseline_buckets ),
	};
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
			label: valueText( label ),
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
				yLabel={ __( 'Items', 'newspack-intelligence' ) }
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
								.map( ( heading ) => (
									<th key={ heading } scope="col">
										{ heading }
									</th>
								) ) }
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row ) => (
							<tr key={ rowKey( row ) }>
								<td>{ row.bucket }</td>
								<td>{ valueText( String( row.value ) ) }</td>
								<td>{ row.count }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</VisuallyHidden>
		</>
	);
}
