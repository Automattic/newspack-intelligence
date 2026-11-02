import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { answerStatus } from '@newspack-nodes/shared/utils/answerStatus';
import { formatLocalDateTime } from '@newspack-nodes/shared/utils/formatUtils';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
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

/** @typedef {{key: string, label: string, state: string, tone: string, fault: boolean, unknown: boolean, text: string, fix: ?import('react').ReactNode}} HealthRow */

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
 * its role; its text step for anyone else, who mounts no write.
 *
 * @param {Object}                     props
 * @param {boolean}                    props.can     Whether the reader holds the role.
 * @param {string}                     props.label   The button's words.
 * @param {string}                     props.action  The text step's action.
 * @param {string}                     props.verb    The write verb.
 * @param {string}                     [props.ci]    The CI it lives on.
 * @param {() => void}                 props.refresh Re-reads the strip once it lands.
 * @param {Object<string, string>}     [props.args]  Its named args.
 * @param {{busy: string, ok: string}} props.texts   Its answer's words.
 * @return {import('react').ReactElement} The fix.
 */
function Fix( { can, action, ...send } ) {
	return can ? <SendFix { ...send } /> : <Step action={ action } />;
}

/**
 * The button sending a fix's write, and the answer it landed with.
 *
 * @param {Object}                     props
 * @param {string}                     props.label   The button's words.
 * @param {string}                     props.verb    The write verb.
 * @param {string}                     [props.ci]    The CI it lives on.
 * @param {() => void}                 props.refresh Re-reads the strip once it lands.
 * @param {Object<string, string>}     [props.args]  Its named args.
 * @param {{busy: string, ok: string}} props.texts   Its answer's words.
 * @return {import('react').ReactElement} The button and its answer.
 */
function SendFix( { label, verb, ci, refresh, args = {}, texts } ) {
	const write = useIntelWrite( verb, { ci, refresh } );
	const status = answerStatus(
		null !== write.answeredArgs ? { error: write.refusal } : null,
		texts,
		write.pending
	);
	return (
		<>
			<button
				type="button"
				className="button button-small"
				disabled={ write.pending }
				onClick={ () => write.run( args ) }
			>
				{ label }
			</button>
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
 * @param {boolean} props.can    Whether the reader holds manage.
 * @param {string}  props.action The text step's action.
 * @return {import('react').ReactElement} The fix.
 */
function VaultFix( { can, action } ) {
	return can ? (
		<a href={ VAULT_PAGE }>
			{ __( 'Open the Vault', 'newspack-intelligence' ) }
		</a>
	) : (
		<Step action={ action } />
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
 * Why items wait to resolve, the count unknown when no snapshot holds it.
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
 * Every row's states, by the word its slice answers. A state no table
 * holds reads as unknown, never as healthy.
 */
const STATES = {
	collection: {
		live: RUNNING,
		idle: { ...RUNNING, word: __( 'idle', 'newspack-intelligence' ) },
		inactive: {
			word: __( 'inactive', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: () => __( 'Collection is off.', 'newspack-intelligence' ),
			fix: ( { topology }, ctx ) => (
				<Fix
					can={ ctx.canManage }
					label={ __( 'Activate', 'newspack-intelligence' ) }
					action={ __(
						'activate collection',
						'newspack-intelligence'
					) }
					verb="activate"
					ci="topologies"
					refresh={ ctx.refresh }
					args={ { name: topology } }
					texts={ {
						busy: __( 'Activating…', 'newspack-intelligence' ),
						ok: __( 'Activated.', 'newspack-intelligence' ),
					} }
				/>
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
	backlog: {
		'': {
			word: __( 'clear', 'newspack-intelligence' ),
			tone: 'is-success',
			text: clearText,
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
			text: ( { vault_id: vaultId } ) =>
				vaultId
					? sprintf(
							/* translators: %s: the extractor's Vault entry id. */
							__(
								'Extracting with the heuristic only: no Vault entry %s.',
								'newspack-intelligence'
							),
							vaultId
					  )
					: __(
							'Extracting with the heuristic only: the AI proxy has no Vault entry.',
							'newspack-intelligence'
					  ),
			fix: ( extraction, ctx ) => (
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
 * unknown row naming a state the table does not hold.
 *
 * @param {string} key    Unique among the rows.
 * @param {string} label  What the row is about.
 * @param {string} table  The row's table in `STATES`.
 * @param {*}      state  The state its slice answers.
 * @param {Object} answer What the row's cause and fix read.
 * @param {Object} ctx    The roles and refresh the fixes take.
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
	return {
		key,
		label,
		state: meant.word,
		tone: meant.tone,
		fault: true === meant.fault,
		unknown: true === meant.unknown,
		text: meant.text( answer, ctx ),
		fix: meant.fix?.( answer, ctx ) || null,
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
 * Every row the answered slices hold: collection, the backlog and its
 * dropped days, extraction, then each source; all but collection's neutral
 * while collection is off.
 *
 * @param {Object}  answers            The four slices' answers, each null while owed.
 * @param {?Object} answers.collection The collection slice.
 * @param {?Object} answers.backlog    The backlog slice.
 * @param {?Object} answers.extraction The extraction slice.
 * @param {?Object} answers.sources    The sources slice.
 * @param {Object}  ctx                The roles and refresh the fixes take.
 * @return {Array<HealthRow>} The rows.
 */
function healthRows( { collection, backlog, extraction, sources }, ctx ) {
	const listed = sources?.sources ?? [];
	const fixes = {
		...ctx,
		firstFailing: listed.find( ( one ) => 'failing' === one.state ),
	};
	const rows = [
		collection &&
			healthRow(
				'collection',
				__( 'Collection', 'newspack-intelligence' ),
				'collection',
				collection.state,
				collection,
				fixes
			),
		backlog &&
			healthRow(
				'backlog',
				__( 'Waiting to resolve', 'newspack-intelligence' ),
				'backlog',
				backlogState( backlog, Math.floor( Date.now() / 1000 ) ),
				backlog,
				fixes
			),
		backlog?.dropped &&
			healthRow(
				'dropped',
				__( 'Dropped', 'newspack-intelligence' ),
				'dropped',
				'dropped',
				backlog.dropped,
				fixes
			),
		extraction &&
			healthRow(
				'extraction',
				__( 'Extraction', 'newspack-intelligence' ),
				'extraction',
				extractionState( extraction ),
				extraction,
				fixes
			),
		...listed.map( ( one ) =>
			healthRow(
				`${ SOURCE_ROW }${ one.name }`,
				one.name,
				'source',
				one.state,
				one,
				fixes
			)
		),
	].filter( Boolean );
	if ( 'inactive' !== collection?.state ) {
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
 * @param {Array<string>} unreported The names of those no pass has reported.
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
 * A conjunction list in the page's own language, `document.documentElement`'s
 * `lang`, or the default one when that is empty or not a language tag, the
 * one input the constructor's RangeError refuses here.
 *
 * @return {Intl.ListFormat} The format.
 */
function listFormat() {
	/** @type {Intl.ListFormatOptions} */
	const options = { type: 'conjunction' };
	try {
		return new Intl.ListFormat(
			document.documentElement.lang || undefined,
			options
		);
	} catch {
		return new Intl.ListFormat( undefined, options );
	}
}

/**
 * The strip's one line, the first that holds of: the worst fault; a slice
 * refused; a slice owed; collection off, which outranks the unknown rows
 * its expired snapshots leave; a row whose health is not known but a
 * source no pass has reported; else how many sources it collects from,
 * naming each source no pass has reported, and the backlog row's cause.
 *
 * @param {Object}           strip            What the line reads.
 * @param {Array<HealthRow>} strip.faults     The fault rows, worst first.
 * @param {Array<HealthRow>} strip.rows       Every row.
 * @param {Array<Object>}    strip.reads      The four reads.
 * @param {?Object}          strip.collection The collection slice.
 * @param {?Object}          strip.sources    The sources slice.
 * @return {string} The line.
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
	const backlog = rows.find( ( row ) => 'backlog' === row.key );
	return `${ collectingText( sources.sources.length, unreported ) } ${
		backlog.text
	}`;
}

/**
 * The health strip above the Intelligence page's tabs: one line while
 * nothing is wrong, open on a fault with the faults first and its line
 * naming the worst. Each of the four `health` slices is read on mount,
 * again once any fix lands, and on Refresh; nothing polls. A fix shows for
 * a reader holding its role, as a button whose answer shows beside it, and
 * as its text step for anyone else; the server's role gate decides.
 * Collection off is neutral: it neither opens nor colours the strip, and
 * while it is off no other row does either; nor does a projector catching
 * up on the ontology for under BEHIND_FAULT_S. A
 * reader's open or close holds until the set of faults changes.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune   Whether the reader may seed the ontology.
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
	const reads = [ collection, sources, backlog, extraction ];
	const refresh = () => reads.forEach( ( read ) => read.refresh() );

	const ctx = { canTune, canManage, refresh };

	const rows = healthRows(
		{
			collection: collection.answer,
			backlog: backlog.answer,
			extraction: extraction.answer,
			sources: sources.answer,
		},
		ctx
	);
	const faults = rows.filter( ( row ) => row.fault );
	const ordered = [ ...faults, ...rows.filter( ( row ) => ! row.fault ) ];
	const signature = faults.map( ( row ) => row.key ).join( '|' );

	// The reader's choice, beside the faults it was made against.
	const [ choice, setChoice ] = useState( null );
	const open =
		choice?.signature === signature ? choice.open : 0 < faults.length;

	return (
		<section
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
						reads,
						collection: collection.answer,
						sources: sources.answer,
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
					onClick={ refresh }
				>
					{ __( 'Refresh', 'newspack-intelligence' ) }
				</button>
			</div>
			{ reads.map( ( read, i ) => (
				<Refusal key={ i } error={ read.error } />
			) ) }
			<ul
				id={ ROWS_ID }
				className="newspack-intelligence__health-rows"
				hidden={ ! open }
			>
				{ ordered.map( ( row ) => (
					<li
						key={ row.key }
						className="newspack-intelligence__health-row"
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
