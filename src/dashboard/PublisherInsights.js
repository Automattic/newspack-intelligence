import { __ } from '@wordpress/i18n';
import { useInsightsGraph } from './hooks/useInsightsGraph';
import { SourceCounts } from './widgets/SourceCounts';
import { TopTable } from './widgets/TopTable';
import { AccumulatedPanel } from './widgets/AccumulatedPanel';
import './styles/insights.scss';

/**
 * Publisher Insights — the dashboard orchestrator. It mounts the GENUINE node graph
 * (useInsightsGraph: Timer → Tee → three slice Fetchers, ONE batched POST per tick)
 * and renders the three thin per-slice widgets, each reading ITS OWN view node via
 * useNodeState. No god view node, no god `insights` command — each card owns one
 * slice: counts → SourceCounts, top → TopTable, accumulated (with the digest +
 * collection progress + actions) → AccumulatedPanel. The hook's awaited
 * `generate`/`collect` verbs (the only cross-widget wiring) flow into the panel.
 * Styling follows the Newspack in-product design system (docs/DESIGN.product.md):
 * light surfaces, a Cobalt accent, Inter, laid out in flow within wp-admin.
 *
 * @param {Object}   props
 * @param {Object}   [props.commandClient]     CommandClient seam forwarded to the hook (tests).
 * @param {number}   [props.intervalMs]        Poll cadence in ms forwarded to the hook.
 * @param {Function} [props.createDraft]       REST-call seam forwarded to AccumulatedPanel (tests).
 * @param {Function} [props.markdownToContent] Markdown→block-markup seam forwarded to AccumulatedPanel (tests).
 */
export default function PublisherInsights( {
	commandClient,
	intervalMs,
	createDraft,
	markdownToContent,
} = {} ) {
	const { generate, collect } = useInsightsGraph( {
		commandClient,
		intervalMs,
	} );

	return (
		<div className="eai-insights">
			<header className="eai-insights__header">
				<h1>
					{ __( 'Publisher Insights', 'newspack-ai-newsletter' ) }
				</h1>
				<p className="eai-insights__sub">
					{ __(
						'Each card is its own node graph slice — counts, top items, and the accumulated digest.',
						'newspack-ai-newsletter'
					) }
				</p>
			</header>
			<div className="eai-insights__grid">
				<AccumulatedPanel
					generate={ generate }
					collect={ collect }
					createDraft={ createDraft }
					markdownToContent={ markdownToContent }
				/>
				<SourceCounts />
				<TopTable />
			</div>
		</div>
	);
}
