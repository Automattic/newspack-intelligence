import { SliceViewNode } from '@newspack-nodes/shared/nodes/slice-view-node';

/**
 * `top-table:view` — owns the per-source score-ranked top-items slice
 * ({ top:{ source:[{title,score}] } }). React reads it via
 * useNodeState('top-table:view','view') in <TopTable/>.
 */
export class TopTableViewNode extends SliceViewNode {
	emptySlice() {
		return { top: {} };
	}
}
