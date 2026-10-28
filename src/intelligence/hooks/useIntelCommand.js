/**
 * The Intelligence page's one-shot commands to the `intelligence` CI, each a
 * `useCommandOnce` riding the batched router tick under the `intelligence`
 * group, so `connect intelligence:shell` watches every send.
 *
 * A read is asked again whenever its arguments change and shows only the
 * answer to the question it asks now: the Fetcher's gate drops the answer to
 * a superseded ask, and the arguments a reply echoes say which question it
 * answers (ADR-7). A write is sent once per `run()` and refreshes what it
 * changed once it lands.
 *
 * No send names a subject: an item key spells a whole URL, past the length a
 * reply address carries, so a subject would be dropped for that one item and
 * its pending guard with it. Pending is per verb instead.
 */

import { useCallback, useEffect } from '@wordpress/element';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';

/** The surface every command here is sent under. */
const GROUP = 'intelligence';

/** The server CI mount every verb here lives on. */
const CI = 'intelligence';

/** Every send here is about nothing narrower than its verb. */
const NO_SUBJECT = () => null;

/**
 * Ask one read verb, again whenever `args` changes.
 *
 * @param {string}   scope   Names this read's nodes, a subject naming what
 *                           the slice shows, so two reads of one verb on a
 *                           page never share a Fetcher.
 * @param {string}   command The read verb.
 * @param {string[]} args    Its argument tokens.
 * @return {{answer: ?Object, error: ?string, loading: boolean, refresh: () => void}}
 *   The answer to `args`, or the refusal; `loading` while it is owed; and
 *   `refresh()`, which asks the same question again.
 */
export function useIntelRead( scope, command, args ) {
	const key = JSON.stringify( args );
	const { run, result, error, answeredArgs } = useCommandOnce( {
		group: GROUP,
		ci: CI,
		command,
		scope,
		retry: true,
		subjectOf: NO_SUBJECT,
	} );

	useEffect( () => run( JSON.parse( key ) ), [ key, run ] );

	const refresh = useCallback( () => run( JSON.parse( key ) ), [ key, run ] );

	// A bare refusal, such as a Router's NOT_AVAILABLE, answers this ask.
	const current =
		null !== answeredArgs &&
		( JSON.stringify( answeredArgs ) === key ||
			( null !== error && 0 === answeredArgs.length ) );
	return {
		answer: current ? result : null,
		error: current ? error : null,
		loading: ! current,
		refresh,
	};
}

/**
 * One curation verb, sent once per `run( args )` with every arg named
 * (`--key=value`), so a curator's value that begins `--name=` binds as the
 * value it is rather than as an option. `run()` answers the tokens it sent;
 * a landed send runs `refresh`, and a refused one hands `onRefused` the
 * tokens its reply echoes, which say which send it was. Everything else is
 * `useCommandOnce`'s own: whether a send still waits, the last reply's
 * refusal, and the arguments it answered.
 *
 * @param {string}                     command          The write verb.
 * @param {Object}                     [opts]           Options.
 * @param {() => void}                 [opts.refresh]   Runs once a send lands.
 * @param {(tokens: string[]) => void} [opts.onRefused] Runs once a send is refused.
 * @return {{run: (args: Object<string, string>) => string[], pending: boolean, refusal: ?string, answeredArgs: ?string[]}}
 *   `run()`, whether a send of this verb still waits, the last refusal, and
 *   the arguments the last reply answered.
 */
export function useIntelWrite( command, { refresh, onRefused } = {} ) {
	const {
		run: send,
		pending,
		error,
		answeredArgs,
	} = useCommandOnce( {
		group: GROUP,
		ci: CI,
		command,
		subjectOf: NO_SUBJECT,
		onDone: ( { error: refused, args } ) =>
			refused ? onRefused?.( args ) : refresh?.(),
	} );
	const run = useCallback(
		( args ) => {
			const tokens = formatCommandArgs( [], args );
			send( tokens );
			return tokens;
		},
		[ send ]
	);
	return { run, pending, refusal: error, answeredArgs };
}
