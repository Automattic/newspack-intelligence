/**
 * A block's capped list: at most `limit` rows until the reader asks for the
 * rest the list holds with "Show %d more", each row the `<li>` its
 * `children` draws.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * @template T
 * @param {Object}                                               props
 * @param {T[]}                                                  props.rows        Every row the list holds.
 * @param {number}                                               props.limit       The rows shown before the rest are asked for.
 * @param {string}                                               [props.className] The list's class, else a profile's facts list's.
 * @param {(row: T, index: number) => import('react').ReactNode} props.children    Draws one row as its `<li>`.
 * @return {import('react').ReactElement} The list.
 */
export default function CappedList( {
	rows,
	limit,
	className = 'newspack-intelligence__facts',
	children,
} ) {
	const [ all, setAll ] = useState( false );
	const shown = all ? rows : rows.slice( 0, limit );
	return (
		<>
			<ul className={ className }>
				{ shown.map( ( row, index ) => children( row, index ) ) }
			</ul>
			{ shown.length < rows.length && (
				<button
					type="button"
					className="button-link"
					onClick={ () => setAll( true ) }
				>
					{ sprintf(
						/* translators: %d: rows the list holds but does not show yet. */
						__( 'Show %d more', 'newspack-intelligence' ),
						rows.length - shown.length
					) }
				</button>
			) }
		</>
	);
}
