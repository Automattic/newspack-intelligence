import { SliceViewNode } from '@newspack-nodes/shared/nodes/slice-view-node';
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';

/**
 * `accumulated:view` — owns the accumulated slice ({ accumulated, done, total,
 * digest }) for <AccumulatedPanel/>. Unlike its sibling slice views it also owns a
 * PendingReplies registry: the awaited `generate`/`collect` action verbs stash a
 * `{ resolve, reject }` under their outbound message[ID], and the base
 * SliceViewNode.fill() settles a matching reply FIRST (reject on TM_ERROR) before
 * the slice path. removeNode rejects any in-flight awaited reply so a graph reinit
 * can't strand a caller awaiting a reply that will never land.
 */
export class AccumulatedViewNode extends SliceViewNode {
	constructor() {
		super();
		// Hook-stamped ID → { resolve, reject }; settled by the base fill()'s replies.settle().
		this.replies = new PendingReplies();
	}

	emptySlice() {
		return { accumulated: 0, done: 0, total: 0, digest: '' };
	}

	removeNode() {
		this.replies.rejectAll( 'insights graph torn down' );
		super.removeNode();
	}
}
