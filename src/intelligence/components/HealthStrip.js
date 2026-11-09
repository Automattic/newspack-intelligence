import {
	createInterpolateElement,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { answerStatus } from '@newspack-nodes/shared/utils/answerStatus';
import { formatLocalDateTime } from '@newspack-nodes/shared/utils/formatUtils';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { unrecordedText, useEndClient } from '../hooks/useEndClient';
import Refusal from './Refusal';
import { metaLine } from './format';

/** The Vault tab of the substrate's Nodes station, where a credential is added. */
const VAULT_PAGE = 'admin.php?page=newspack-nodes-station&tab=vault';

/** The rows list the toggle opens and closes. */
const ROWS_ID = 'newspack-intelligence-health-rows';

/** How long the projector may stay behind the ontology before it is a fault. */
const BEHIND_FAULT_S = 300;

/** The prefix of a source row's key, which its name follows. */
const SOURCE_ROW = 'source:';

/** Names the health strip's pair of role writes ending a client. */
const END_CLIENT = 'health:former-client';

/** @typedef {{key: string, label: string, state: string, tone: string, fault: boolean, unknown: boolean, idle: boolean, text: import('react').ReactNode, fix: ?import('react').ReactNode}} HealthRow */

/** @typedef {{word: string, tone: string, fault?: boolean, unknown?: boolean, text: Function, fix?: Function}} HealthState */

/**
 * The step a reader without a fix's role reads in its place.
 *
 * @param {Object} props
 * @param {string} props.action What an administrator can do.
 * @return {import('react').ReactElement} The step.
 */
function Step( { action } ) {
	return (
		<span className="newspack-nodes-status is-muted">
			{ sprintf(
				/* translators: %s: the fix, as an administrator applies it. */
				__( 'An administrator can %s.', 'newspack-intelligence' ),
				action
			) }
		</span>
	);
}

/**
 * A fix sent as one write, with its answer beside it, for a reader holding
 * its role; its text step for anyone else, who mounts no write. One button
 * sends `args` under `label`, or one per entry of `choices`.
 *
 * @param {Object}                     props
 * @param {boolean}                    props.can       Whether the reader holds the role.
 * @param {string}                     [props.label]   The button's words.
 * @param {string}                     props.action    The text step's action.
 * @param {string}                     props.verb      The write verb.
 * @param {string}                     [props.ci]      The CI it lives on.
 * @param {(ok: string) => void}       props.refresh   Holds its row and re-reads the strip once it lands.
 * @param {Object<string, string>}     [props.args]    Its named args.
 * @param {Array<FixChoice>}           [props.choices] One button each, in place of `label` and `args`.
 * @param {{busy: string, ok: string}} props.texts     Its answer's words.
 * @return {import('react').ReactElement} The fix.
 */
function Fix( {
	can,
	action,
	label,
	args = {},
	choices = [ { label, args } ],
	...send
} ) {
	return can ? (
		<SendFix choices={ choices } { ...send } />
	) : (
		<Step action={ action } />
	);
}

/**
 * One button of a fix: its words and the named args it sends.
 *
 * @typedef {Object} FixChoice
 * @property {string}                 label The button's words.
 * @property {Object<string, string>} args  Its named args.
 */

/**
 * The buttons sending a fix's one write, each its own args, and the answer
 * the last send landed with.
 *
 * @param {Object}                     props
 * @param {Array<FixChoice>}           props.choices The buttons.
 * @param {string}                     props.verb    The write verb.
 * @param {string}                     [props.ci]    The CI it lives on.
 * @param {(ok: string) => void}       props.refresh Holds its row and re-reads the strip once it lands.
 * @param {{busy: string, ok: string}} props.texts   Its answer's words.
 * @return {import('react').ReactElement} The buttons and their answer.
 */
function SendFix( { choices, verb, ci, refresh, texts } ) {
	const write = useIntelWrite( verb, {
		ci,
		refresh: () => refresh( texts.ok ),
	} );
	const status = answerStatus(
		null !== write.answeredArgs ? { error: write.refusal } : null,
		texts,
		write.pending
	);
	return (
		<>
			{ choices.map( ( choice ) => (
				<button
					key={ choice.label }
					type="button"
					className="button button-small"
					disabled={ write.pending }
					onClick={ () => write.run( choice.args ) }
				>
					{ choice.label }
				</button>
			) ) }
			{ status.text && (
				<span
					className={ `newspack-nodes-status ${ status.tone }` }
					role="status"
				>
					{ status.text }
				</span>
			) }
		</>
	);
}

/**
 * The Vault link for a reader holding manage, its text step for anyone else.
 *
 * @param {Object}  props
 * @param {boolean} props.can     Whether the reader holds manage.
 * @param {string}  props.action  The text step's action.
 * @param {string}  [props.label] The link's words.
 * @return {import('react').ReactElement} The fix.
 */
function VaultFix( {
	can,
	action,
	label = __( 'Open the Vault', 'newspack-intelligence' ),
} ) {
	return can ? (
		<a href={ VAULT_PAGE }>{ label }</a>
	) : (
		<Step action={ action } />
	);
}

/**
 * The button marking a dead client feed's client a former client, and the
 * refusal its pair met, on the row of the client it refused alone, for a
 * curator; its text step for anyone else. The client's rows leave the strip
 * from the click (`useEndClient`), and while any pair is owed its replies
 * every other such button does nothing.
 *
 * @param {Object}                             props
 * @param {{entity_key: string, name: string}} props.client    The client.
 * @param {?Object}                            props.endClient `useEndClient()`, null for a reader without tune.
 * @return {import('react').ReactElement} The fix.
 */
function FormerClientFix( { client, endClient } ) {
	if ( ! endClient ) {
		return (
			<Step
				action={ __(
					'mark it a former client',
					'newspack-intelligence'
				) }
			/>
		);
	}
	const refused = client.entity_key === endClient.asked && endClient.refused;
	return (
		<>
			<button
				type="button"
				className="button button-small"
				aria-disabled={ null !== endClient.owed }
				onClick={ ( event ) =>
					endClient.run( client, event.currentTarget )
				}
			>
				{ __( 'Mark former client', 'newspack-intelligence' ) }
			</button>
			{ refused && (
				<span className="newspack-nodes-status is-error" role="status">
					{ refused }
				</span>
			) }
		</>
	);
}

/**
 * A failing row's cause: its error, when the reader may read it, and since
 * when it has failed.
 *
 * @param {{last_error?: ?string, failing_since?: ?number}} row The row.
 * @return {string} The cause.
 */
function failingText( { last_error: error, failing_since: since } ) {
	if ( error ) {
		return metaLine(
			error,
			since
				? sprintf(
						/* translators: %s: a time in the reader's zone. */
						__( 'failing since %s', 'newspack-intelligence' ),
						formatLocalDateTime( since )
				  )
				: null
		);
	}
	return since
		? sprintf(
				/* translators: %s: a time in the reader's zone. */
				__( 'Failing since %s', 'newspack-intelligence' ),
				formatLocalDateTime( since )
		  )
		: __( 'Failing.', 'newspack-intelligence' );
}

/**
 * Why items wait to resolve, the count unknown when it is not in any snapshot.
 *
 * @param {{held: ?number, since: ?number}} backlog The slice.
 * @return {string} The cause.
 */
function stoppedText( { held, since } ) {
	if ( null === held ) {
		return __(
			'Resolving is stopped: the ontology is empty. An unknown number of items are waiting.',
			'newspack-intelligence'
		);
	}
	if ( ! since ) {
		return sprintf(
			/* translators: %d: items waiting to resolve. */
			_n(
				'Resolving is stopped: the ontology is empty. %d item is waiting.',
				'Resolving is stopped: the ontology is empty. %d items are waiting.',
				held,
				'newspack-intelligence'
			),
			held
		);
	}
	return sprintf(
		/* translators: 1: items waiting to resolve, 2: a time in the reader's zone. */
		_n(
			'Resolving is stopped: the ontology is empty. %1$d item is waiting, since %2$s.',
			'Resolving is stopped: the ontology is empty. %1$d items are waiting, since %2$s.',
			held,
			'newspack-intelligence'
		),
		held,
		formatLocalDateTime( since )
	);
}

/**
 * What waits while nothing holds: the later queue's entries, the
 * extractor's retries among them, or that their number is not known.
 *
 * @param {{held: ?number}} backlog The slice.
 * @return {string} The cause.
 */
function clearText( { held } ) {
	if ( null === held ) {
		return __(
			'Resolving is running; how many items wait to retry is not known.',
			'newspack-intelligence'
		);
	}
	if ( 0 === held ) {
		return __( 'Nothing waiting.', 'newspack-intelligence' );
	}
	return sprintf(
		/* translators: %d: items waiting to resolve or retry. */
		_n(
			'%d item is waiting to resolve or retry.',
			'%d items are waiting to resolve or retry.',
			held,
			'newspack-intelligence'
		),
		held
	);
}

/**
 * The model calls the extraction slice's queue counts, every NER and
 * extraction call alike: "N calls waiting (N backlog, N new) · N in flight",
 * a class or a part that holds no call left out, and '' while the queue
 * holds none or is not known.
 *
 * @param {?{new: number, backlog: number, in_flight: number}} queue The queue.
 * @return {string} The counts.
 */
function queueText( queue ) {
	if ( ! queue ) {
		return '';
	}
	const count = pageFormat( Intl.NumberFormat );
	const waiting = queue.new + queue.backlog;
	const classes = [
		0 < queue.backlog &&
			sprintf(
				/* translators: %s: model calls waiting behind new work. */
				__( '%s backlog', 'newspack-intelligence' ),
				count.format( queue.backlog )
			),
		0 < queue.new &&
			sprintf(
				/* translators: %s: model calls for newly recorded items. */
				__( '%s new', 'newspack-intelligence' ),
				count.format( queue.new )
			),
	].filter( Boolean );
	return metaLine(
		0 < waiting
			? sprintf(
					/* translators: 1: model calls waiting, 2: the classes among them, as "5 backlog, 2 new". */
					_n(
						'%1$s call waiting (%2$s)',
						'%1$s calls waiting (%2$s)',
						waiting,
						'newspack-intelligence'
					),
					count.format( waiting ),
					pageFormat(
						Intl.ListFormat,
						/** @type {Intl.ListFormatOptions} */ ( {
							type: 'unit',
							style: 'short',
						} )
					).format( classes )
			  )
			: '',
		0 < queue.in_flight
			? sprintf(
					/* translators: %s: model calls sent and not yet answered. */
					_n(
						'%s in flight',
						'%s in flight',
						queue.in_flight,
						'newspack-intelligence'
					),
					count.format( queue.in_flight )
			  )
			: ''
	);
}

/**
 * An idle source's cause: the Vault entry it lacks, the reason it names,
 * or, redacted, that it has no credential.
 *
 * @param {Object}                        source              The source's row.
 * @param {?{url: string, group: string}} [source.credential] The entry it lacks.
 * @param {?string}                       [source.reason]     Why it cannot fetch.
 * @return {string} The cause.
 */
function idleText( { credential, reason } ) {
	if ( credential ) {
		return sprintf(
			/* translators: 1: the origin a source fetches, 2: its Vault group. */
			__(
				'No Vault entry for %1$s in group %2$s.',
				'newspack-intelligence'
			),
			credential.url,
			credential.group
		);
	}
	return (
		reason ||
		__(
			'This source has no credential to fetch with.',
			'newspack-intelligence'
		)
	);
}

/** A row whose health is not known, the base of every unknown state. */
const UNKNOWN = {
	word: __( 'unknown', 'newspack-intelligence' ),
	tone: 'is-neutral',
	unknown: true,
};

/** A worker running, whether busy or idle. */
const RUNNING = {
	word: __( 'live', 'newspack-intelligence' ),
	tone: 'is-success',
	text: () => __( 'Running.', 'newspack-intelligence' ),
};

/**
 * Every row's states, by the word its slice answers. A state that is not
 * in any table reads as unknown, never as healthy.
 */
const STATES = {
	collection: {
		live: RUNNING,
		idle: { ...RUNNING, word: __( 'idle', 'newspack-intelligence' ) },
		inactive: {
			word: __( 'inactive', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: ( { topologies } ) =>
				topologies.length
					? __( 'Collection is off.', 'newspack-intelligence' )
					: createInterpolateElement(
							__(
								'Collection is off; <code>wp nodes activate</code> turns on the topology that composes Intelligence.',
								'newspack-intelligence'
							),
							{ code: <code /> }
					  ),
			fix: ( { topologies }, ctx ) =>
				topologies.length > 0 && (
					<Fix
						can={ ctx.canManage }
						choices={ topologies.map( ( name ) => ( {
							label: name,
							args: { name },
						} ) ) }
						action={ __(
							'activate collection',
							'newspack-intelligence'
						) }
						verb="activate"
						ci="topologies"
						refresh={ ctx.refresh }
						texts={ {
							busy: __( 'Activating…', 'newspack-intelligence' ),
							ok: __( 'Activated.', 'newspack-intelligence' ),
						} }
					/>
				),
		},
		unconfigured: {
			word: __( 'no lists file', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: ( { file } ) =>
				file
					? sprintf(
							/* translators: %s: the path the lists file belongs at. */
							__(
								'No lists file: create %s, empty if this site has no settings of its own.',
								'newspack-intelligence'
							),
							file
					  )
					: __(
							'An administrator can create the lists file; an empty one will do.',
							'newspack-intelligence'
					  ),
		},
		stale: {
			word: __( 'stale', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: () =>
				__(
					'The worker has stopped sending its heartbeat.',
					'newspack-intelligence'
				),
			fix: ( { topology }, ctx ) => (
				<Fix
					can={ ctx.canManage }
					label={ __( 'Restart', 'newspack-intelligence' ) }
					action={ __(
						'restart the worker',
						'newspack-intelligence'
					) }
					verb="restart"
					ci="workers"
					refresh={ ctx.refresh }
					args={ { types: topology } }
					texts={ {
						busy: __( 'Restarting…', 'newspack-intelligence' ),
						ok: __( 'Restarted.', 'newspack-intelligence' ),
					} }
				/>
			),
		},
		down: {
			word: __( 'down', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: () =>
				__(
					'The fleet revives a down worker within a minute; if it stays down, run `wp nodes doctor`.',
					'newspack-intelligence'
				),
		},
		held: {
			word: __( 'held', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: () =>
				__(
					'A deploy hold is in place; `wp nodes start` lifts it.',
					'newspack-intelligence'
				),
		},
	},
	source: {
		ok: {
			word: __( 'ok', 'newspack-intelligence' ),
			tone: 'is-success',
			text: ( source ) =>
				metaLine(
					source.last_ok_at
						? sprintf(
								/* translators: %s: a time in the reader's zone. */
								__(
									'Last answered %s',
									'newspack-intelligence'
								),
								formatLocalDateTime( source.last_ok_at )
						  )
						: null,
					source.failed
						? sprintf(
								/* translators: 1: requests that failed, 2: requests the pass sent. */
								_n(
									'%1$d of %2$d request failed',
									'%1$d of %2$d requests failed',
									source.requests,
									'newspack-intelligence'
								),
								source.failed,
								source.requests
						  )
						: null
				),
		},
		failing: {
			word: __( 'failing', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: failingText,
			fix: ( source, ctx ) =>
				ctx.firstFailing === source && (
					<Fix
						can={ ctx.canManage }
						label={ __(
							'Fetch all sources now',
							'newspack-intelligence'
						) }
						action={ __(
							'fetch all sources now',
							'newspack-intelligence'
						) }
						verb="fetch_now"
						refresh={ ctx.refresh }
						texts={ {
							busy: __( 'Asking…', 'newspack-intelligence' ),
							ok: __( 'Fetching.', 'newspack-intelligence' ),
						} }
					/>
				),
		},
		idle: {
			word: __( 'idle', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: idleText,
			fix: ( { credential, reason }, ctx ) =>
				( credential || ! reason ) && (
					<VaultFix
						can={ ctx.canManage }
						label={ __(
							'Add credential',
							'newspack-intelligence'
						) }
						action={ __(
							'add its credential',
							'newspack-intelligence'
						) }
					/>
				),
		},
		unknown: {
			...UNKNOWN,
			text: () =>
				__( 'No pass has reported yet.', 'newspack-intelligence' ),
		},
	},
	dead: {
		dead: {
			word: __( 'dead', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: ( { name, domain, since } ) =>
				sprintf(
					/* translators: 1: the client, 2: its feed's host, 3: a time in the reader's zone. */
					__(
						'%1$s (%2$s) has not resolved since %3$s.',
						'newspack-intelligence'
					),
					name,
					domain,
					formatLocalDateTime( since )
				),
			fix: ( { entity_key: entityKey, name }, ctx ) => (
				<FormerClientFix
					client={ { entity_key: entityKey, name } }
					endClient={ ctx.endClient }
				/>
			),
		},
	},
	backlog: {
		'': {
			word: __( 'clear', 'newspack-intelligence' ),
			tone: 'is-success',
			text: clearText,
			idle: ( { held } ) => 0 === held,
		},
		no_live_entity: {
			word: __( 'held', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: stoppedText,
			fix: ( backlog, ctx ) => (
				<Fix
					can={ ctx.canTune }
					label={ __( 'Seed the ontology', 'newspack-intelligence' ) }
					action={ __(
						'seed the ontology',
						'newspack-intelligence'
					) }
					verb="seed_ontology"
					refresh={ ctx.refresh }
					texts={ {
						busy: __( 'Seeding…', 'newspack-intelligence' ),
						ok: __( 'Seeded.', 'newspack-intelligence' ),
					} }
				/>
			),
		},
		behind_ontology: {
			word: __( 'catching up', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: () =>
				__( 'Catching up on the ontology.', 'newspack-intelligence' ),
		},
		behind_long: {
			word: __( 'behind', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: ( { since } ) =>
				sprintf(
					/* translators: %s: a time in the reader's zone. */
					__(
						'Resolving is held: the projector has been behind the ontology since %s.',
						'newspack-intelligence'
					),
					formatLocalDateTime( since )
				),
		},
	},
	dropped: {
		dropped: {
			word: __( 'dropped', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: ( { first_day: first, last_day: last } ) =>
				sprintf(
					/* translators: 1: the first UTC day, 2: the last. */
					__(
						'Items parked from %1$s to %2$s were dropped before they resolved.',
						'newspack-intelligence'
					),
					first,
					last
				),
			fix: ( { first_day: since, last_day: until }, ctx ) => (
				<Fix
					can={ ctx.canManage }
					label={ __( 'Re-resolve', 'newspack-intelligence' ) }
					action={ __(
						're-resolve those days',
						'newspack-intelligence'
					) }
					verb="reresolve"
					refresh={ ctx.refresh }
					args={ { since, until } }
					texts={ {
						busy: __( 'Asking…', 'newspack-intelligence' ),
						ok: __( 'Re-resolving.', 'newspack-intelligence' ),
					} }
				/>
			),
		},
	},
	calls: {
		busy: {
			word: __( 'busy', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: queueText,
		},
	},
	extraction: {
		call: {
			word: __( 'AI proxy', 'newspack-intelligence' ),
			tone: 'is-success',
			text: ( { last_ok_at: lastOk } ) =>
				lastOk
					? sprintf(
							/* translators: %s: a time in the reader's zone. */
							__(
								'By the AI proxy; last extraction %s.',
								'newspack-intelligence'
							),
							formatLocalDateTime( lastOk )
					  )
					: __( 'By the AI proxy.', 'newspack-intelligence' ),
		},
		failing: {
			word: __( 'failing', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: failingText,
			fix: ( extraction, ctx ) => (
				<VaultFix
					can={ ctx.canManage }
					action={ __(
						'check its Vault entry',
						'newspack-intelligence'
					) }
				/>
			),
		},
		heuristic: {
			word: __( 'heuristic', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: ( { vault_group: vaultGroup, unset } ) => {
				if ( unset?.length ) {
					return sprintf(
						/* translators: %s: the model-call node's setters left unset, comma-separated. */
						__(
							'Extracting with the heuristic only: the AI proxy is not configured; the lists file sets no %s.',
							'newspack-intelligence'
						),
						unset.join( ', ' )
					);
				}
				return vaultGroup
					? sprintf(
							/* translators: %s: the Vault group the model calls take their credential from. */
							__(
								'Extracting with the heuristic only: Vault group %s holds no entry for the AI proxy.',
								'newspack-intelligence'
							),
							vaultGroup
					  )
					: __(
							'Extracting with the heuristic only: the AI proxy has no Vault entry.',
							'newspack-intelligence'
					  );
			},
			fix: ( { unset }, ctx ) =>
				unset?.length ? (
					<Step
						action={ __(
							'set them on intel:llm in the site’s lists file',
							'newspack-intelligence'
						) }
					/>
				) : (
					<VaultFix
						can={ ctx.canManage }
						action={ __(
							'add its Vault entry',
							'newspack-intelligence'
						) }
					/>
				),
		},
		unknown: {
			...UNKNOWN,
			text: ( { last_mode: lastMode } ) => {
				if ( 'heuristic' === lastMode ) {
					return __(
						'No extraction has reported since the cache was cleared; the newest used the heuristic.',
						'newspack-intelligence'
					);
				}
				if ( 'call' === lastMode ) {
					return __(
						'No extraction has reported since the cache was cleared; the newest used the AI proxy.',
						'newspack-intelligence'
					);
				}
				return __(
					'No extraction has reported yet.',
					'newspack-intelligence'
				);
			},
		},
	},
};

/**
 * One row, built from the state its table holds for `state`, or a neutral
 * unknown row naming a state the table does not hold. A row whose fix landed
 * while it still reads the state the fix was sent against keeps its fault,
 * and shows the fix's answer where the fix was, so it is not sent twice.
 *
 * @param {string} key    Unique among the rows.
 * @param {string} label  What the row is about.
 * @param {string} table  The row's table in `STATES`.
 * @param {*}      state  The state its slice answers.
 * @param {Object} answer What the row's cause and fix read.
 * @param {Object} ctx    The roles, the holds and `landed` the fixes take.
 * @return {HealthRow} The row.
 */
function healthRow( key, label, table, state, answer, ctx ) {
	const states = STATES[ table ];
	const meant = Object.prototype.hasOwnProperty.call( states, state )
		? states[ state ]
		: {
				...UNKNOWN,
				text: () =>
					sprintf(
						/* translators: %s: the state a slice answered. */
						__(
							'State not recognised: %s.',
							'newspack-intelligence'
						),
						String( state )
					),
		  };
	const hold = ctx.fixed.get( key );
	const refresh = ( ok ) => ctx.landed( key, state, ok );
	return {
		key,
		label,
		state: meant.word,
		tone: meant.tone,
		fault: true === meant.fault,
		unknown: true === meant.unknown,
		idle: true === meant.idle?.( answer ),
		text: meant.text( answer, ctx ),
		fix:
			hold?.state === state ? (
				<span className="newspack-nodes-status is-muted" role="status">
					{ sprintf(
						/* translators: %s: the fix's answer, such as "Seeded.". */
						__(
							'%s Fix sent; Refresh to check.',
							'newspack-intelligence'
						),
						hold.text
					) }
				</span>
			) : (
				meant.fix?.( answer, { ...ctx, refresh } ) || null
			),
	};
}

/**
 * The extractor's state: its mode, or failing while a call fails.
 *
 * @param {{mode: string, failing_since?: ?number}} extraction The slice.
 * @return {string} The state.
 */
const extractionState = ( { mode, failing_since: since } ) =>
	'call' === mode && since ? 'failing' : mode;

/**
 * The backlog's state: its reason, or `behind_long` once the projector has
 * stood behind the ontology longer than BEHIND_FAULT_S since a known start.
 *
 * @param {{reason: string, since: ?number}} backlog The slice.
 * @param {number}                           now     The second it is now.
 * @return {string} The state.
 */
const backlogState = ( { reason, since }, now ) =>
	'behind_ontology' === reason && since && now - since > BEHIND_FAULT_S
		? 'behind_long'
		: reason;

/**
 * A row as it reads while collection is off: listed, but neither a fault
 * nor coloured.
 *
 * @param {HealthRow} row The row.
 * @return {HealthRow} The row, neutral.
 */
const offRow = ( row ) => ( { ...row, fault: false, tone: 'is-neutral' } );

/**
 * Every row the answered slices hold, as `healthRow()` takes it: collection,
 * the backlog and its dropped days, extraction, the model calls while its
 * queue holds any, then each source and each dead client feed.
 *
 * @param {Object}  answers            The four slices' answers, each null while owed.
 * @param {?Object} answers.collection The collection slice.
 * @param {?Object} answers.backlog    The backlog slice.
 * @param {?Object} answers.extraction The extraction slice.
 * @param {?Object} answers.sources    The sources slice.
 * @return {Array<{key: string, label: string, table: string, state: *, answer: Object}>} The rows' specs.
 */
function rowSpecs( { collection, backlog, extraction, sources } ) {
	const spec = ( key, label, table, state, answer ) => ( {
		key,
		label,
		table,
		state,
		answer,
	} );
	return [
		collection &&
			spec(
				'collection',
				__( 'Collection', 'newspack-intelligence' ),
				'collection',
				collection.state,
				collection
			),
		backlog &&
			spec(
				'backlog',
				__( 'Waiting to resolve', 'newspack-intelligence' ),
				'backlog',
				backlogState( backlog, Math.floor( Date.now() / 1000 ) ),
				backlog
			),
		backlog?.dropped &&
			spec(
				'dropped',
				__( 'Dropped', 'newspack-intelligence' ),
				'dropped',
				'dropped',
				backlog.dropped
			),
		extraction &&
			spec(
				'extraction',
				__( 'Extraction', 'newspack-intelligence' ),
				'extraction',
				extractionState( extraction ),
				extraction
			),
		queueText( extraction?.queue ) &&
			spec(
				'calls',
				__( 'Model calls', 'newspack-intelligence' ),
				'calls',
				'busy',
				extraction.queue
			),
		...( sources?.sources ?? [] ).map( ( one ) =>
			spec(
				`${ SOURCE_ROW }${ one.name }`,
				one.name,
				'source',
				one.state,
				one
			)
		),
		...( sources?.dead_clients ?? [] ).map( ( one ) =>
			spec(
				`dead:${ one.domain }`,
				__( 'Client feed', 'newspack-intelligence' ),
				'dead',
				'dead',
				one
			)
		),
	].filter( Boolean );
}

/**
 * Every row the answered slices hold, built; all but collection's neutral
 * while collection is off.
 *
 * @param {Object} answers The four slices' answers, as `rowSpecs()` takes them.
 * @param {Object} ctx     The roles, the holds and `landed` the fixes take.
 * @return {Array<HealthRow>} The rows.
 */
function healthRows( answers, ctx ) {
	const fixes = {
		...ctx,
		firstFailing: ( answers.sources?.sources ?? [] ).find(
			( one ) => 'failing' === one.state
		),
	};
	const rows = rowSpecs( answers ).map( ( one ) =>
		healthRow( one.key, one.label, one.table, one.state, one.answer, fixes )
	);
	if ( 'inactive' !== answers.collection?.state ) {
		return rows;
	}
	return rows.map( ( one ) =>
		'collection' === one.key ? one : offRow( one )
	);
}

/**
 * How many sources the strip collects from: all of them, or how many have
 * reported and the names of those that have not.
 *
 * @param {number}        total      Every source the slice lists.
 * @param {Array<string>} unreported The names of those that have not reported
 *                                   yet.
 * @return {string} The sentence.
 */
function collectingText( total, unreported ) {
	if ( ! unreported.length ) {
		return sprintf(
			/* translators: %d: the sources collected from. */
			_n(
				'Collecting from %d source.',
				'Collecting from %d sources.',
				total,
				'newspack-intelligence'
			),
			total
		);
	}
	return sprintf(
		/* translators: 1: the sources that have reported, 2: every source, as "3 sources", 3: the names of those that have not. */
		_n(
			'Collecting from %1$d of %2$s; %3$s has not reported yet.',
			'Collecting from %1$d of %2$s; %3$s have not reported yet.',
			unreported.length,
			'newspack-intelligence'
		),
		total - unreported.length,
		sprintf(
			/* translators: %d: every source the strip lists. */
			_n( '%d source', '%d sources', total, 'newspack-intelligence' ),
			total
		),
		listFormat().format( unreported )
	);
}

/**
 * A conjunction list in the page's own language.
 *
 * @return {Intl.ListFormat} The format.
 */
const listFormat = () =>
	pageFormat(
		Intl.ListFormat,
		/** @type {Intl.ListFormatOptions} */ ( {
			type: 'conjunction',
		} )
	);

/**
 * An `Intl` format in the page's own language, `document.documentElement`'s
 * `lang`, or the default one when that is empty or not a language tag, the
 * one input the constructor's RangeError refuses here.
 *
 * @template T
 * @param {new ( locale?: string, options?: Object ) => T} Format    The format's constructor.
 * @param {Object}                                         [options] Its options.
 * @return {T} The format.
 */
function pageFormat( Format, options ) {
	try {
		return new Format(
			document.documentElement.lang || undefined,
			options
		);
	} catch {
		return new Format( undefined, options );
	}
}

/**
 * The strip's one line, the first that holds of: the worst fault; a slice
 * refused; a slice owed; collection off, which outranks the unknown rows its
 * expired snapshots leave; a row whose health is not known but a source that
 * has not reported yet; else how many sources it collects from, naming each
 * source that has not reported yet, the backlog row's cause, unless the row
 * is idle while the model-call row stands, and that row's counts.
 *
 * @param {Object}           strip            What the line reads.
 * @param {Array<HealthRow>} strip.faults     The fault rows, worst first.
 * @param {Array<HealthRow>} strip.rows       Every row.
 * @param {Array<Object>}    strip.reads      The four reads.
 * @param {?Object}          strip.collection The collection slice.
 * @param {?Object}          strip.sources    The sources slice.
 * @return {import('react').ReactNode} The line.
 */
function healthLine( { faults, rows, reads, collection, sources } ) {
	if ( faults.length ) {
		return faults[ 0 ].text;
	}
	if ( reads.some( ( read ) => read.error ) ) {
		return __(
			'The pipeline’s health could not be read in full.',
			'newspack-intelligence'
		);
	}
	if ( reads.some( ( read ) => read.loading ) ) {
		return __( 'Reading the pipeline’s health…', 'newspack-intelligence' );
	}
	if ( 'inactive' === collection.state ) {
		return __( 'Collection is off.', 'newspack-intelligence' );
	}
	const unreported = sources.sources
		.filter( ( one ) => 'unknown' === one.state )
		.map( ( one ) => one.name );
	const reportless = new Set(
		unreported.map( ( name ) => `${ SOURCE_ROW }${ name }` )
	);
	if ( rows.some( ( row ) => row.unknown && ! reportless.has( row.key ) ) ) {
		return __(
			'The pipeline’s health is not fully known.',
			'newspack-intelligence'
		);
	}
	const waiting = rows.find( ( row ) => 'backlog' === row.key );
	const calls = rows.find( ( row ) => 'calls' === row.key );
	return [
		collectingText( sources.sources.length, unreported ),
		waiting.idle && calls ? '' : waiting.text,
		calls?.text,
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * The pair of role writes ending a client, mounted for a curator alone, so
 * any other reader mounts no write; it hands `useEndClient()` over the
 * sources slice to `children`, its `run( client, button )` also taking the
 * button pressed, and doing nothing while a pair is owed its replies. Once
 * the client's rows leave, focus moves to the row now
 * in the pressed row's place, else the last row, else the strip, as the
 * review queues move it.
 *
 * @param {Object}                                              props
 * @param {Object}                                              props.sources  The sources slice's read.
 * @param {() => void}                                          props.refresh  Re-reads the strip once a pair is answered.
 * @param {{current: ?HTMLElement}}                             props.strip    The strip's section.
 * @param {(endClient: Object) => import('react').ReactElement} props.children Renders the strip.
 * @return {import('react').ReactElement} The strip.
 */
function EndingClients( { sources, refresh, strip, children } ) {
	const endClient = useEndClient(
		END_CLIENT,
		sources,
		'dead_clients',
		refresh
	);
	const pressed = useRef( null );
	useLayoutEffect( () => {
		if ( null === pressed.current ) {
			return;
		}
		const list = strip.current.querySelector( 'ul' );
		const left = list.hidden ? [] : list.querySelectorAll( 'li' );
		(
			left[ pressed.current ] ??
			left[ left.length - 1 ] ??
			strip.current
		).focus();
		pressed.current = null;
	} );
	return children( {
		...endClient,
		run: ( client, button ) => {
			if ( null !== endClient.owed ) {
				return;
			}
			pressed.current = [
				...strip.current.querySelectorAll( 'li' ),
			].indexOf( button.closest( 'li' ) );
			endClient.run( client );
		},
	} );
}

/**
 * The health strip above the Intelligence page's tabs: one line while
 * nothing is wrong, open on a fault with the faults first and its line
 * naming the worst. Each of the four `health` slices is read on mount,
 * again once any fix lands, and on Refresh; nothing polls. A fix shows for
 * a reader holding its role, as a button whose answer shows beside it, and
 * as its text step for anyone else; the server's role gate decides. Each
 * dead client feed offers a curator to mark its client a former client.
 * Collection off is neutral: it neither opens nor colours the strip, and
 * while it is off the other rows do not either; nor does a projector catching
 * up on the ontology for under BEHIND_FAULT_S.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune   Whether the reader may seed the ontology and end a client.
 * @param {boolean} props.canManage Whether the reader may run the operator fixes.
 * @return {import('react').ReactElement} The strip.
 */
export default function HealthStrip( { canTune, canManage } ) {
	const collection = useIntelRead( 'health:collection', 'health', [
		'collection',
	] );
	const sources = useIntelRead( 'health:sources', 'health', [ 'sources' ] );
	const backlog = useIntelRead( 'health:backlog', 'health', [ 'backlog' ] );
	const extraction = useIntelRead( 'health:extraction', 'health', [
		'extraction',
	] );
	const reads = { collection, sources, backlog, extraction };
	const refresh = () =>
		Object.values( reads ).forEach( ( read ) => read.refresh() );
	const section = useRef( null );
	const strip = ( endClient ) => (
		<Strip
			reads={ reads }
			refresh={ refresh }
			roles={ { canTune, canManage } }
			section={ section }
			endClient={ endClient }
		/>
	);

	return canTune ? (
		<EndingClients
			sources={ sources }
			refresh={ refresh }
			strip={ section }
		>
			{ strip }
		</EndingClients>
	) : (
		strip( null )
	);
}

/**
 * The strip itself, over the four reads, the sources slice read through
 * `endClient` for a curator. Once a fix lands its row stays a fault and shows
 * the fix's answer where the fix was, for as long as a read still answers the
 * state it fixed, since the strip reads again only on a landed fix or on
 * Refresh; a read answering another state, or Refresh, ends the hold. A
 * reader's open or close holds until the set of faults changes.
 *
 * @param {Object}                                 props
 * @param {Object<string, Object>}                 props.reads     The four reads, by slice.
 * @param {() => void}                             props.refresh   Re-reads every slice.
 * @param {{canTune: boolean, canManage: boolean}} props.roles     The reader's roles.
 * @param {{current: ?HTMLElement}}                props.section   The strip's section.
 * @param {?Object}                                props.endClient `useEndClient()`, null for a reader without tune.
 * @return {import('react').ReactElement} The strip.
 */
function Strip( { reads, refresh, roles, section, endClient } ) {
	const { collection, sources, backlog, extraction } = reads;
	const listed = endClient ? endClient.read.answer : sources.answer;
	const answers = useMemo(
		() => ( {
			collection: collection.answer,
			backlog: backlog.answer,
			extraction: extraction.answer,
			sources: listed,
		} ),
		[ collection.answer, backlog.answer, extraction.answer, listed ]
	);
	// Each row whose fix landed => the state it fixed and the fix's answer.
	const [ fixed, setFixed ] = useState( () => new Map() );
	const landed = ( key, state, text ) => {
		setFixed( ( was ) => new Map( was ).set( key, { state, text } ) );
		refresh();
	};
	useEffect( () => {
		setFixed( ( was ) => {
			if ( 0 === was.size ) {
				return was;
			}
			const now = new Map(
				rowSpecs( answers ).map( ( one ) => [ one.key, one.state ] )
			);
			const kept = [ ...was ].filter(
				( [ key, { state } ] ) => now.get( key ) === state
			);
			return kept.length === was.size ? was : new Map( kept );
		} );
	}, [ answers ] );

	// The reader's choice, beside the faults it was made against.
	const [ choice, setChoice ] = useState( null );
	const rows = healthRows( answers, { ...roles, fixed, landed, endClient } );
	const faults = rows.filter( ( row ) => row.fault );
	const ordered = [ ...faults, ...rows.filter( ( row ) => ! row.fault ) ];
	const signature = faults.map( ( row ) => row.key ).join( '|' );
	const open =
		choice?.signature === signature ? choice.open : 0 < faults.length;
	const all = Object.values( reads );

	return (
		<section
			ref={ section }
			tabIndex={ -1 }
			className={ `newspack-nodes-banner newspack-intelligence__health${
				faults.length ? ' is-warning' : ''
			}` }
			aria-label={ __( 'Pipeline health', 'newspack-intelligence' ) }
		>
			<div className="newspack-intelligence__health-line">
				<span role="status">
					{ healthLine( {
						faults,
						rows,
						reads: all,
						collection: collection.answer,
						sources: listed,
					} ) }
				</span>
				<button
					type="button"
					className="button button-small"
					aria-expanded={ open }
					aria-controls={ ROWS_ID }
					onClick={ () => setChoice( { signature, open: ! open } ) }
				>
					{ open
						? __( 'Hide details', 'newspack-intelligence' )
						: __( 'Show details', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className="button button-small"
					onClick={ () => {
						setFixed( new Map() );
						refresh();
					} }
				>
					{ __( 'Refresh', 'newspack-intelligence' ) }
				</button>
			</div>
			{ all.map( ( read, i ) => (
				<Refusal key={ i } error={ read.error } />
			) ) }
			<Refusal
				error={
					endClient?.unrecorded &&
					unrecordedText( endClient.unrecorded )
				}
			/>
			<ul
				id={ ROWS_ID }
				className="newspack-intelligence__health-rows"
				hidden={ ! open }
			>
				{ ordered.map( ( row ) => (
					<li
						key={ row.key }
						className="newspack-intelligence__health-row"
						tabIndex={ -1 }
					>
						<strong>{ row.label }</strong>
						<span
							className={ `newspack-nodes-status-badge is-pill ${ row.tone }` }
						>
							{ row.state }
						</span>
						<span>{ row.text }</span>
						{ row.fix }
					</li>
				) ) }
			</ul>
		</section>
	);
}
