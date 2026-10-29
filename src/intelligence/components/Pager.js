import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * How many of a page's matches it shows, and Previous and Next over the
 * stack of page starts the caller holds, so Previous returns to the page
 * before. Next starts past the rows shown, so no page size is assumed.
 *
 * @param {Object}                     props
 * @param {number[]}                   props.starts   Each page's first row, the current last.
 * @param {(starts: number[]) => void} props.onStarts Replaces the stack.
 * @param {number}                     props.shown    The rows this page shows.
 * @param {number}                     props.total    The rows matching.
 * @return {import('react').ReactElement} The pager.
 */
export default function Pager( { starts, onStarts, shown, total } ) {
	const offset = starts[ starts.length - 1 ];
	return (
		<div className="newspack-intelligence__pager">
			<span className="newspack-nodes-status is-muted">
				{ sprintf(
					/* translators: 1: items shown, 2: items matching. */
					_n(
						'%1$d of %2$d item',
						'%1$d of %2$d items',
						total,
						'newspack-intelligence'
					),
					shown,
					total
				) }
			</span>
			{ 1 < starts.length && (
				<button
					type="button"
					className="button"
					onClick={ () => onStarts( starts.slice( 0, -1 ) ) }
				>
					{ __( 'Previous', 'newspack-intelligence' ) }
				</button>
			) }
			{ 0 < shown && offset + shown < total && (
				<button
					type="button"
					className="button"
					onClick={ () => onStarts( [ ...starts, offset + shown ] ) }
				>
					{ __( 'Next', 'newspack-intelligence' ) }
				</button>
			) }
		</div>
	);
}
