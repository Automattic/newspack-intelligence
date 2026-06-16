import { Node, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';
import {
	errorMessage,
	PendingReplies,
} from '@newspack-nodes/shared/pendingReplies';

// The shaped-but-empty model so a render before the first reply is still valid.
// Exported as the canonical empty shape — the view's fallback reuses it.
export const emptyModel = () => ( {
	sources: {},
	top: [],
	accumulated: 0,
	digest: '',
} );

/**
 * `insights:view` — owns the Publisher Insights view model, the single surface
 * React reads via useNodeState('insights:view','view').
 *
 * The `insights` Service_CI verb returns the FULLY-SHAPED model (sources, top,
 * accumulated, and the rendered `digest`) as a JSON STRING in the reply's
 * VALUE.payload (no transform node needed). The poll fires with FROM=`insights:view`
 * and no pending entry, so its reply parses the payload and publishes the model.
 * The awaited `generate` verb stashes a `{ resolve, reject }` in `replies` keyed
 * by message[ID]; the matching reply settles that Promise and returns early
 * (reject on TM_ERROR). On teardown those pending entries are rejected so a graph
 * reinit can't strand a caller awaiting a reply that will never land.
 *
 * The pending-Map + errorMessage shape mirrors workerStatusView via the shared
 * PendingReplies registry.
 */
export class InsightsViewNode extends Node {
	constructor() {
		super();
		this.registrations.view = {};
		this.model = emptyModel();
		// Hook-stamped ID → { resolve, reject }; settled when the matching reply lands.
		this.replies = new PendingReplies();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || 'object' !== typeof value ) {
			return;
		}
		const isError = 0 !== ( ( message[ TYPE ] || 0 ) & TM_ERROR );

		// Pending-Map gating: settle any Promise the hook stashed under this ID.
		if ( this.replies.settle( message ) ) {
			return;
		}

		// Un-correlated error (broadcast / a failed poll) surfaces in the model.
		if ( isError ) {
			this.model = {
				...emptyModel(),
				error: errorMessage( value.payload ),
			};
			this._publish();
			return;
		}

		// The poll reply: VALUE.payload is the JSON-string model from the CI.
		const model = this._parse( value.payload );
		if ( null !== model ) {
			this.model = model;
			this._publish();
		}
	}

	_parse( payload ) {
		if ( 'string' !== typeof payload ) {
			return null;
		}
		try {
			const model = JSON.parse( payload );
			return model && 'object' === typeof model ? model : null;
		} catch ( e ) {
			return null;
		}
	}

	_publish() {
		this.setState( 'view', this.model );
	}

	// Reject in-flight awaited verbs on teardown so no caller is stranded awaiting a reply.
	removeNode() {
		this.replies.rejectAll( 'insights graph torn down' );
		super.removeNode();
	}
}
