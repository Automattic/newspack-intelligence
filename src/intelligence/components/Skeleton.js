import { __ } from '@wordpress/i18n';

/**
 * One placeholder bar on the shared sheet's loading state, sized by
 * `intelligence.scss` for the part of a row it stands for.
 *
 * @param {Object}                                props
 * @param {'title' | 'meta' | 'summary' | 'line'} props.part The part.
 * @return {import('react').ReactElement} The bar.
 */
function Bar( { part } ) {
	return (
		<span
			className={ `newspack-nodes-skeleton newspack-intelligence__skeleton-bar is-${ part }` }
		/>
	);
}

/**
 * Placeholders for rows still owed, each laid out in the markup of the row
 * it stands for, so the answer replaces it without the page moving. The
 * whole is announced as loading and carries no text of its own.
 *
 * @param {Object}         props
 * @param {'row' | 'line'} [props.shape] An item row, or a line of prose.
 * @param {number}         [props.count] How many.
 * @return {import('react').ReactElement} The placeholders.
 */
export default function Skeleton( { shape = 'row', count = 3 } ) {
	const each = Array.from( { length: count }, ( _, n ) => n );
	return (
		<div
			role="status"
			aria-label={ __( 'Loading', 'newspack-intelligence' ) }
		>
			{ 'line' === shape ? (
				each.map( ( n ) => (
					<p key={ n } aria-hidden="true">
						<Bar part="line" />
					</p>
				) )
			) : (
				<ul className="newspack-intelligence__items" aria-hidden="true">
					{ each.map( ( n ) => (
						<li key={ n } className="newspack-intelligence__item">
							<div className="newspack-intelligence__item-head">
								<Bar part="title" />
							</div>
							<p className="newspack-nodes-status is-muted">
								<Bar part="meta" />
							</p>
							<p>
								<Bar part="summary" />
							</p>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
