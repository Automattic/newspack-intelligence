import DebugOverlay from '@newspack-nodes/debug-overlay';
import PublisherInsights from './PublisherInsights';

/**
 * Publisher Insights dashboard page. M2 wires the data layer: the poll-only
 * `insights:view` node graph (no SSE), rendered by PublisherInsights. The
 * substrate debug overlay rides along (debug-gated, its own storage key) so the
 * `insights:view` browser graph is inspectable like every other dashboard.
 */
export default function PublisherInsightsPage() {
	return (
		<>
			<PublisherInsights refreshMs={ 4000 } />
			<DebugOverlay storageKey="newspack-nodes:debug:publisher-insights" />
		</>
	);
}
