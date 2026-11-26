/* eslint-env jest */
/**
 * The `/command` double the Intelligence suites answer through: the page's
 * graph packs, POSTs, unpacks and routes for real, and each command answers what
 * its suite set, as a payload, an `Error` (a refusal) or a function of the
 * argument tokens it was sent.
 */

import { TO, VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';

/**
 * Install the double.
 *
 * @return {{replies: Object, sent: (verb: string) => string[][], sentTo: (verb: string) => string[], verbs: () => string[]}}
 *   The answers by verb, which a test fills; the argument tokens of every
 *   command of a verb that went on the wire; the CI each was addressed to;
 *   and the verb of every command that went on the wire, in order.
 */
export function intelWire() {
	const replies = {};
	const wire = installFakeCommandWire( ( m ) => {
		const answer = replies[ m[ VALUE ]?.name ];
		return 'function' === typeof answer
			? answer( m[ VALUE ].arguments ?? [] )
			: answer;
	} );
	const ofVerb = ( verb ) =>
		wire.batches.flat().filter( ( m ) => verb === m[ VALUE ]?.name );
	return {
		replies,
		sent: ( verb ) =>
			ofVerb( verb ).map( ( m ) => m[ VALUE ].arguments ?? [] ),
		sentTo: ( verb ) => ofVerb( verb ).map( ( m ) => m[ TO ] ),
		verbs: () => wire.batches.flat().map( ( m ) => m[ VALUE ]?.name ),
	};
}
