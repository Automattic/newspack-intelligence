import { __, sprintf } from '@wordpress/i18n';
import Cite from './Cite';

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
 * Trend rows, or signals: each bucket and value with its count, citing the
 * newest items the row counts.
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
					<tr
						key={ [
							row.axis,
							row.signal,
							row.bucket,
							row.value,
						].join( ' ' ) }
					>
						<td>{ row.bucket }</td>
						<td>{ value( row ) }</td>
						<td>{ count( row ) }</td>
						<td>
							<ul className="newspack-intelligence__facts">
								{ row.items.map( ( item ) => (
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
