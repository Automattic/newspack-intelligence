import { __, _n, sprintf } from '@wordpress/i18n';
import Sparkline from '@newspack-nodes/shared/components/Sparkline';
import { usualPhrase } from './format';

/** A pulse line's sparkline box in pixels. */
const SPARK_WIDTH = 96;
const SPARK_HEIGHT = 24;

/**
 * The words a pulse line says for one span, `day`, `week` or `month`: a
 * change, no unusual change, too little history against `baseline` buckets,
 * and the bucket still running.
 *
 * @param {string} bucket   The span counted.
 * @param {number} baseline How many buckets before a change is measured against.
 * @return {{changed: (subject: string, count: number, usual: string) => string, quiet: string, tooNew: string, soFar: (count: number) => string}} The words.
 */
export function spanWords( bucket, baseline ) {
	const spans = {
		day: () => ( {
			changed: ( subject, count, usual ) =>
				sprintf(
					/* translators: 1: what changed, 2: its count yesterday, 3: what is usual. */
					__(
						'%1$s: %2$d yesterday, %3$s.',
						'newspack-intelligence'
					),
					subject,
					count,
					usual
				),
			quiet: __(
				'No unusual change yesterday.',
				'newspack-intelligence'
			),
			tooNew: sprintf(
				/* translators: %d: how many days before a change is measured against. */
				_n(
					'Too little history to judge. A change is measured against the %d day before.',
					'Too little history to judge. A change is measured against the %d days before.',
					baseline,
					'newspack-intelligence'
				),
				baseline
			),
			soFar: ( count ) =>
				sprintf(
					/* translators: %d: how many so far today. */
					__( '%d so far today', 'newspack-intelligence' ),
					count
				),
		} ),
		week: () => ( {
			changed: ( subject, count, usual ) =>
				sprintf(
					/* translators: 1: what changed, 2: its count last week, 3: what is usual. */
					__(
						'%1$s: %2$d last week, %3$s.',
						'newspack-intelligence'
					),
					subject,
					count,
					usual
				),
			quiet: __(
				'No unusual change last week.',
				'newspack-intelligence'
			),
			tooNew: sprintf(
				/* translators: %d: how many weeks before a change is measured against. */
				_n(
					'Too little history to judge. A change is measured against the %d week before.',
					'Too little history to judge. A change is measured against the %d weeks before.',
					baseline,
					'newspack-intelligence'
				),
				baseline
			),
			soFar: ( count ) =>
				sprintf(
					/* translators: %d: how many so far this week. */
					__( '%d so far this week', 'newspack-intelligence' ),
					count
				),
		} ),
		month: () => ( {
			changed: ( subject, count, usual ) =>
				sprintf(
					/* translators: 1: what changed, 2: its count last month, 3: what is usual. */
					__(
						'%1$s: %2$d last month, %3$s.',
						'newspack-intelligence'
					),
					subject,
					count,
					usual
				),
			quiet: __(
				'No unusual change last month.',
				'newspack-intelligence'
			),
			tooNew: sprintf(
				/* translators: %d: how many months before a change is measured against. */
				_n(
					'Too little history to judge. A change is measured against the %d month before.',
					'Too little history to judge. A change is measured against the %d months before.',
					baseline,
					'newspack-intelligence'
				),
				baseline
			),
			soFar: ( count ) =>
				sprintf(
					/* translators: %d: how many so far this month. */
					__( '%d so far this month', 'newspack-intelligence' ),
					count
				),
		} ),
	};
	return spans[ bucket ]();
}

/**
 * What changed, in words, then drawn: a signal as its subject, its count over
 * the last complete bucket and what is usual (`usualPhrase()`), else no
 * unusual change when judged, else too little history; beside it a 96 × 24
 * sparkline of the series, a bucket outside the read's window a gap, the
 * signal's own usual range its band, so a quiet line carries none; then the
 * bucket still running, apart, never a point
 * on the line. When several signal, the line's accessible name says which
 * one's series it draws.
 *
 * @param {Object}                                                             props
 * @param {?{count: number, usual: {mean: number, low: number, high: number}}} props.signal  The signal shown, or null for a quiet answer.
 * @param {string}                                                             props.subject What changed, as the sentence leads with it.
 * @param {Array<{bucket: string, count: number, in_window: boolean}>}         props.trend   The series, the latest bucket first.
 * @param {?{bucket: string, count: number}}                                   props.partial The bucket holding now, or null.
 * @param {boolean}                                                            props.judged  Whether the history sufficed to judge.
 * @param {boolean}                                                            props.several Whether other signals stand beside the one shown.
 * @param {ReturnType<typeof spanWords>}                                       props.words   The span's words.
 * @return {import('react').ReactElement} The pulse line.
 */
export default function PulseLine( {
	signal,
	subject,
	trend,
	partial,
	judged,
	several,
	words,
} ) {
	let said = words.tooNew;
	if ( signal ) {
		said = words.changed(
			subject,
			signal.count,
			usualPhrase( signal.usual.mean )
		);
	} else if ( judged ) {
		said = words.quiet;
	}
	return (
		<p className="newspack-intelligence__pulse">
			<span>{ said }</span>
			<Sparkline
				values={ trend
					.map( ( point ) =>
						point.in_window ? point.count : null
					)
					.reverse() }
				band={
					signal ? [ signal.usual.low, signal.usual.high ] : undefined
				}
				width={ SPARK_WIDTH }
				height={ SPARK_HEIGHT }
				label={
					several
						? sprintf(
								/* translators: 1: what the line draws, 2: the line in words. */
								__(
									'The line draws %1$s. %2$s',
									'newspack-intelligence'
								),
								subject,
								trendWords( trend )
						  )
						: trendWords( trend )
				}
			/>
			{ partial && (
				<span className="newspack-nodes-status is-muted">
					{ words.soFar( partial.count ) }
				</span>
			) }
		</p>
	);
}

/**
 * A series in words for a screen reader: every bucket's count, oldest
 * first, a bucket outside the read's window as not read, then the latest
 * bucket's first day and count, or that it was not read, never a 0 it never
 * counted; one bucket alone in the singular, read only when it draws, so
 * in the window, and an empty series as an empty string.
 *
 * @param {Array<{bucket: string, count: number, in_window: boolean}>} trend The series, the latest bucket first.
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
				'Items in the last period, from %1$s: %2$d',
				'newspack-intelligence'
			),
			latest.bucket,
			latest.count
		);
	}
	const counts = trend
		.map( ( point ) =>
			point.in_window
				? String( point.count )
				: __( 'not read', 'newspack-intelligence' )
		)
		.reverse()
		.join( ', ' );
	return latest.in_window
		? sprintf(
				/* translators: 1: how many buckets, 2: each bucket's count, oldest first, 3: the first day of the latest bucket, 4: its count. */
				__(
					'Items in each of the last %1$d periods, oldest first: %2$s; the latest, from %3$s, counts %4$d',
					'newspack-intelligence'
				),
				trend.length,
				counts,
				latest.bucket,
				latest.count
		  )
		: sprintf(
				/* translators: 1: how many buckets, 2: each bucket's count, oldest first, 3: the first day of the latest bucket. */
				__(
					'Items in each of the last %1$d periods, oldest first: %2$s; the latest, from %3$s, was not read',
					'newspack-intelligence'
				),
				trend.length,
				counts,
				latest.bucket
		  );
}
