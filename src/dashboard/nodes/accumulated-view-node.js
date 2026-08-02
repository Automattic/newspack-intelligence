import { SliceViewNode } from '@newspack-nodes/shared/nodes/slice-view-node';

/**
 * `accumulated:view` — owns the accumulated slice ({ accumulated, done, total,
 * digest }) for <AccumulatedPanel/>.
 *
 * The awaited `generate` / `collect` verbs are minted from their OWN Request
 * nodes and their acks are addressed there, so what lands here is only the
 * slice — and a failure the caller is already catching never reaches it.
 */
export class AccumulatedViewNode extends SliceViewNode {
	emptySlice() {
		return { accumulated: 0, done: 0, total: 0, digest: '' };
	}
}
