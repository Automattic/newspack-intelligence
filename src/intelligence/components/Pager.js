import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The tally's words for a page of items, in the form the count matching
 * takes.
 *
 * @param {number} total The items matching.
 * @return {string} The format, the rows shown first and the rows matching second.
 */
const itemForms = ( total ) =>
	/* translators: 1: items shown, 2: items matching. */
	_n(
		'%1$d of %2$d item',
		'%1$d of %2$d items',
		total,
		'newspack-intelligence'
	);

/**
 * How many of a page's matches it shows, in the plural forms the caller
 * names, and Previous and Next over the
 * stack of page starts the caller holds, so Previous returns to the page
 * before. Next starts past the rows shown, so it assumes no page size.
 *
 * @param {Object}                     props
 * @param {number[]}                   props.starts   Each page's first row, the current last.
 * @param {(starts: number[]) => void} props.onStarts Replaces the stack.
 * @param {number}                     props.shown    The rows this page shows.
 * @param {number}                     props.total    The rows matching.
 * @param {(total: number) => string}  [props.forms]  The tally's format, rows shown then rows matching, in the form `total` takes; items by default.
 * @return {import('react').ReactElement} The pager.
 */
export default function Pager( {
	starts,
	onStarts,
	shown,
	total,
	forms = itemForms,
} ) {
	const offset = starts[ starts.length - 1 ];
	return (
		<div className="newspack-intelligence__pager">
			<span className="newspack-nodes-status is-muted">
				{ sprintf(
					/** @type {'%1$d of %2$d'} */ ( forms( total ) ),
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
