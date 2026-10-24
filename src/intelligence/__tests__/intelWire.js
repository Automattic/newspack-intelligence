/* eslint-env jest */
/**
 * The `/command` double the Intelligence suites answer through: the page's
 * graph packs, POSTs, unpacks and routes for real, and each verb answers what
 * its suite set, as a payload, an `Error` (a refusal) or a function of the
 * argument tokens it was sent.
 */

import { VALUE } from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';

/**
 * Install the double.
 *
 * @return {{replies: Object, sent: (verb: string) => string[][]}} The answers
 *   by verb, which a test fills, and the argument tokens of every command of a
 *   verb that went on the wire.
 */
export function intelWire() {
	const replies = {};
	const wire = installFakeCommandWire( ( m ) => {
		const answer = replies[ m[ VALUE ]?.name ];
		return 'function' === typeof answer
			? answer( m[ VALUE ].arguments ?? [] )
			: answer;
	} );
	return {
		replies,
		sent: ( verb ) =>
			wire.batches
				.flat()
				.filter( ( m ) => verb === m[ VALUE ]?.name )
				.map( ( m ) => m[ VALUE ].arguments ?? [] ),
	};
}
