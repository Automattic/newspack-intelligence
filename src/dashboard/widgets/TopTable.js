import { __, sprintf } from '@wordpress/i18n';
import { useNodeState } from '@newspack-nodes/runtime';

/**
 * TopTable — the "Top items by source" card. Reads ONLY the `top-table:view` node's
 * slice ({ top:{ source:[{title,score}] } }) via useNodeState and renders one
 * score-ranked table per source with inline score bars (sized against the global
 * top score so they're comparable across sources), plus the Top score KPI. A slice
 * error surfaces as a notice; no items yet shows an empty state.
 */
export function TopTable() {
	const slice = useNodeState( 'top-table:view', 'view' ) || { top: {} };
	const top = slice.top ?? {};
	const topBySource = Object.entries( top );
	const allTopItems = topBySource.flatMap( ( [ , items ] ) => items );
	const topScore = allTopItems.reduce(
		( max, item ) => Math.max( max, item.score || 0 ),
		0
	);

	if ( slice.error ) {
		return (
			<section className="eai-insights__card eai-insights__top">
				<h2>
					{ __( 'Top items by source', 'newspack-ai-newsletter' ) }
				</h2>
				<div
					className="eai-insights__notice eai-insights__notice--error"
					role="alert"
				>
					{ slice.error }
				</div>
			</section>
		);
	}

	if ( 0 === topBySource.length ) {
		return (
			<section className="eai-insights__card eai-insights__top">
				<h2>
					{ __( 'Top items by source', 'newspack-ai-newsletter' ) }
				</h2>
				<div className="eai-insights__empty">
					<p>
						{ __(
							'No scored items yet.',
							'newspack-ai-newsletter'
						) }
					</p>
					<p className="eai-insights__empty-hint">
						{ __(
							'Hit Collect to run the sources (or tick them from the REPL); this updates on the next poll.',
							'newspack-ai-newsletter'
						) }
					</p>
				</div>
			</section>
		);
	}

	return (
		<section className="eai-insights__card eai-insights__top">
			<div className="eai-insights__stat">
				<span className="eai-insights__stat-num">{ topScore }</span>
				<span className="eai-insights__stat-label">
					{ __( 'Top score', 'newspack-ai-newsletter' ) }
				</span>
			</div>
			<h2>{ __( 'Top items by source', 'newspack-ai-newsletter' ) }</h2>
			<div className="eai-insights__source-grid">
				{ topBySource.map( ( [ source, items ] ) => (
					<div className="eai-insights__source-top" key={ source }>
						<h3>{ source }</h3>
						<table>
							<thead>
								<tr>
									<th className="eai-insights__rank-col">
										{ __( '#', 'newspack-ai-newsletter' ) }
									</th>
									<th>
										{ __(
											'Title',
											'newspack-ai-newsletter'
										) }
									</th>
									<th>
										{ __(
											'Score',
											'newspack-ai-newsletter'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item, i ) => (
									<tr key={ `${ source }-${ i }` }>
										<td className="eai-insights__rank">
											{ sprintf(
												/* translators: %d: the item's rank within its source. */
												__(
													'#%d',
													'newspack-ai-newsletter'
												),
												i + 1
											) }
										</td>
										<td
											className="eai-insights__item-title"
											title={ item.title }
										>
											{ item.title }
										</td>
										<td className="eai-insights__score-cell">
											<div
												className="eai-insights__score-bar-track"
												aria-hidden="true"
											>
												<div
													className="eai-insights__score-bar"
													style={ {
														width: `${
															topScore
																? ( ( item.score ||
																		0 ) /
																		topScore ) *
																  100
																: 0
														}%`,
													} }
												/>
											</div>
											<span className="eai-insights__score-num">
												{ item.score }
											</span>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) ) }
			</div>
		</section>
	);
}
