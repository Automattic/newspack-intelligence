/**
 * The Intelligence page's one-shot commands, each a `useCommandOnce` riding
 * the batched router tick under the `intelligence` group, so `connect
 * intelligence:shell` watches every send. Each goes to the `intelligence` CI
 * but a write naming another, as the health strip's fixes to the substrate's
 * `workers` and `topologies` CIs do.
 *
 * A read is asked again whenever its arguments change and shows only the
 * answer to the question it asks now: the Fetcher's gate drops the answer to
 * a superseded ask, and the arguments a reply echoes say which question it
 * answers (ADR-7). A write is sent once per `run()` and refreshes what it
 * changed once it lands.
 *
 * A send never names a subject: an item key spells a whole URL, past the length
 * a reply address carries, so a subject would be dropped for that one item. A
 * write's refusal names its send by the arguments it echoes instead.
 */

import { useCallback, useEffect } from '@wordpress/element';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useCommandOnce } from '@newspack-nodes/shared/hooks/useCommandOnce';

/** The surface every command here is sent under. */
const GROUP = 'intelligence';

/** The server CI mount a verb here lives on unless a write names another. */
const CI = 'intelligence';

/** Every send here is about nothing narrower than its verb. */
const NO_SUBJECT = () => null;

/**
 * Ask one read verb, again whenever `args` changes. Null `args` asks
 * nothing and withdraws the ask outstanding, so its late answer never shows.
 *
 * @param {string}    scope   Names this read's nodes, a subject naming what
 *                            the slice shows, so two reads of one verb on a
 *                            page never share a Fetcher.
 * @param {string}    command The read verb.
 * @param {?string[]} args    Its argument tokens, or null to ask nothing.
 * @return {{answer: ?Object, error: ?string, loading: boolean, refresh: () => void}}
 *   The answer to `args`, or the refusal; `loading` while it is owed; and
 *   `refresh()`, which asks the same question again.
 */
export function useIntelRead( scope, command, args ) {
	const key = JSON.stringify( args );
	const { run, abandon, result, error, answeredArgs } = useCommandOnce( {
		group: GROUP,
		ci: CI,
		command,
		scope,
		retry: true,
		subjectOf: NO_SUBJECT,
	} );

	useEffect( () => {
		const asked = JSON.parse( key );
		if ( null === asked ) {
			abandon();
		} else {
			run( asked );
		}
	}, [ key, run, abandon ] );

	const refresh = useCallback( () => {
		const asked = JSON.parse( key );
		if ( null !== asked ) {
			run( asked );
		}
	}, [ key, run ] );

	// A bare refusal, such as a Router's NOT_AVAILABLE, answers this ask.
	const current =
		null !== answeredArgs &&
		( JSON.stringify( answeredArgs ) === key ||
			( null !== error && 0 === answeredArgs.length ) );
	return {
		answer: current ? result : null,
		error: current ? error : null,
		loading: null !== args && ! current,
		refresh,
	};
}

/**
 * The tokens naming every value given (`--key=value`), a list once per
 * member, as a verb's repeatable option binds them. A blank value and an
 * empty list are left out, so the verb reads its own default for them.
 *
 * @param {Object<string, string|number|Array<string>>} options By name.
 * @return {string[]} The tokens.
 */
export function commandArgs( options ) {
	return Object.entries( options ).flatMap( ( [ name, value ] ) =>
		[]
			.concat( value )
			.filter( ( one ) => '' !== one )
			.flatMap( ( one ) => formatCommandArgs( [], { [ name ]: one } ) )
	);
}

/**
 * One curation verb, sent once per `run( args )` with every arg named
 * through `commandArgs()`, so a curator's value that begins `--name=` binds
 * as the value it is rather than as an option. `run()` answers the tokens it sent;
 * a landed send hands `refresh` its answer, and a refused one hands `onRefused` the
 * tokens its reply echoes, which say which send it was. The last reply's
 * refusal, the arguments it answered and whether a send is still owed its
 * reply are `useCommandOnce`'s own.
 *
 * @param {string}                     command          The write verb.
 * @param {Object}                     [opts]           Options.
 * @param {string}                     [opts.ci]        The CI the verb lives on.
 * @param {string}                     [opts.scope]     Names this write's nodes, a
 *                                                      subject naming what it is
 *                                                      for, so two writes of one verb
 *                                                      on a page never share a Fetcher;
 *                                                      `<ci>:<command>` when omitted.
 * @param {(answer: Object) => void}   [opts.refresh]   Runs once a send lands, given its answer.
 * @param {(tokens: string[]) => void} [opts.onRefused] Runs once a send is refused.
 * @return {{run: (args: Object<string, string|Array<string>>) => string[], refusal: ?string, answeredArgs: ?string[], pending: boolean}}
 *   `run()`, the last refusal, the arguments the last reply answered, and
 *   whether a send is still owed its reply.
 */
export function useIntelWrite(
	command,
	{ ci = CI, scope = `${ ci }:${ command }`, refresh, onRefused } = {}
) {
	const {
		run: send,
		error,
		answeredArgs,
		pending,
	} = useCommandOnce( {
		group: GROUP,
		ci,
		command,
		scope,
		subjectOf: NO_SUBJECT,
		onDone: ( { error: refused, args, result } ) =>
			refused ? onRefused?.( args ) : refresh?.( result ),
	} );
	const run = useCallback(
		( args ) => {
			const tokens = commandArgs( args );
			send( tokens );
			return tokens;
		},
		[ send ]
	);
	return { run, refusal: error, answeredArgs, pending };
}
