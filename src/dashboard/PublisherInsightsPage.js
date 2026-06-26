import DebugOverlay from '@newspack-nodes/debug-overlay';
import PublisherInsights from './PublisherInsights';

/**
 * Publisher Insights dashboard page. PublisherInsights mounts the de-godded node
 * graph (three slice Fetchers, one batched POST per tick) and renders the three
 * per-slice widgets. The substrate debug overlay rides along (debug-gated, its own
 * storage key) so the browser node graph is inspectable like every other dashboard.
 */
export default function PublisherInsightsPage() {
	return (
		<>
			<PublisherInsights />
			<DebugOverlay storageKey="newspack-nodes:debug:publisher-insights" />
		</>
	);
}
