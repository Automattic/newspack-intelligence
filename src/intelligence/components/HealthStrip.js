import {
	createInterpolateElement,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Notice, Popover, VisuallyHidden } from '@wordpress/components';
import { answerStatus } from '@newspack-nodes/shared/utils/answerStatus';
import { formatLocalDateTime } from '@newspack-nodes/shared/utils/formatUtils';
import { HeaderSlot } from '@newspack-nodes/shared/components/HeaderSlot';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { HEALTH_READS, useIntelWrite } from '../hooks/useIntelCommand';
import { unrecordedText, useEndClient } from '../hooks/useEndClient';
import { usePageRead } from '../hooks/usePageRead';
import { useWriteControl } from '@newspack-nodes/shared/composition/page';
import EndClientDialog from './EndClientDialog';
import Refusal from './Refusal';
import Unread from '@newspack-nodes/shared/components/Unread';
import Sheet from './Sheet';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import UndoSnackbar from './UndoSnackbar';
import { metaLine, pageFormat } from './format';
import { word, words } from './words';

/** The Vault tab of the substrate's Nodes station, where a credential is added. */
const VAULT_PAGE = 'admin.php?page=newspack-nodes-station&tab=vault';

/**
 * The failed share of a source's requests at which it reads as failing,
 * though it still collects.
 *
 * @testonly Tests hold the threshold to its value; the strip reads it itself.
 */
export const FAILING_SHARE = 0.1;

/** How long the projector may stay behind the ontology before it is a fault. */
const BEHIND_FAULT_S = 300;

/** The prefix of a source row's key, which its name follows. */
const SOURCE_ROW = 'source:';

/** @typedef {{key: string, label: string, table: string, answer: Object, state: string, tone: string, fault: boolean, unknown: boolean, idle: boolean, text: import('react').ReactNode, fix: (scope: string) => ?import('react').ReactNode}} HealthRow */

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
 * @param {string}                     props.scope     The health's own scope, which names the write.
 * @param {(ok: string) => void}       props.refresh   Holds its row once it lands; its write re-reads the strip.
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
		// A new state may name a new write, which needs nodes of its own.
		<SendFix
			key={ `${ send.ci ?? '' }:${ send.verb }:${ send.scope }` }
			choices={ choices }
			{ ...send }
		/>
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
 * @param {string}                     props.scope   The health's own scope; the strip and a block each send under theirs.
 * @param {(ok: string) => void}       props.refresh Holds its row once it lands; its write re-reads the strip.
 * @param {{busy: string, ok: string}} props.texts   Its answer's words.
 * @return {import('react').ReactElement} The buttons and their answer.
 */
function SendFix( { choices, verb, ci, scope, refresh, texts } ) {
	const write = useIntelWrite( verb, {
		ci,
		scope: `${ scope }:${ verb }`,
		dirties: HEALTH_READS,
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
				<FixButton
					key={ choice.label }
					disabled={ write.pending }
					onClick={ () => write.run( choice.args ) }
				>
					{ choice.label }
				</FixButton>
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
 * A fix's button, inert while its layout is edited.
 *
 * @param {Object}                    props
 * @param {() => void}                props.onClick    The write.
 * @param {boolean}                   [props.disabled] Whether a send is owed.
 * @param {import('react').ReactNode} props.children   Its words.
 * @return {import('react').ReactElement} The button.
 */
function FixButton( { onClick, disabled, children } ) {
	return (
		<button
			type="button"
			className="button button-small"
			disabled={ disabled }
			{ ...useWriteControl( onClick ) }
		>
			{ children }
		</button>
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
 * The button asking whether to mark a dead client feed's client a former
 * client, and the refusal its pair met, on the row of the client it refused
 * alone, for a curator; its text step for anyone else. The client's rows
 * leave the strip once the dialog confirms (`useEndClient`), and while any
 * pair is owed its replies every other such button does nothing.
 *
 * @param {Object}                                                            props
 * @param {{entity_key: string, name: string, domain: string, since: number}} props.client    The client, by its dead feed.
 * @param {?Object}                                                           props.endClient `useEndClient()`, null for a reader without tune.
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
			<MarkFormerClient
				owed={ null !== endClient.owed }
				onAsk={ ( button ) => endClient.ask( client, button ) }
			/>
			{ refused && (
				<span className="newspack-nodes-status is-error" role="status">
					{ refused }
				</span>
			) }
		</>
	);
}

/**
 * The button asking to mark a client a former one, held while a pair is
 * owed and inert while its layout is edited.
 *
 * @param {Object}                        props
 * @param {boolean}                       props.owed  Whether a pair is owed its replies.
 * @param {(button: HTMLElement) => void} props.onAsk Asks, given the button pressed.
 * @return {import('react').ReactElement} The button.
 */
function MarkFormerClient( { owed, onAsk } ) {
	const button = useRef( null );
	return (
		<button
			ref={ button }
			type="button"
			className="button button-small"
			aria-disabled={ owed }
			{ ...useWriteControl( () => onAsk( button.current ) ) }
		>
			{ __( 'Mark former client', 'newspack-intelligence' ) }
		</button>
	);
}

/**
 * How long ago an instant was, in the largest whole unit: "just now",
 * "2 min ago", "3 h ago" or "4 d ago".
 *
 * @param {number} ts Seconds since the epoch.
 * @return {string} The words.
 */
function agoText( ts ) {
	const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
	if ( seconds < 60 ) {
		return __( 'just now', 'newspack-intelligence' );
	}
	if ( seconds < 3600 ) {
		const minutes = Math.floor( seconds / 60 );
		return sprintf(
			/* translators: %d: minutes ago. */
			_n( '%d min ago', '%d min ago', minutes, 'newspack-intelligence' ),
			minutes
		);
	}
	if ( seconds < 86400 ) {
		const hours = Math.floor( seconds / 3600 );
		return sprintf(
			/* translators: %d: hours ago. */
			_n( '%d h ago', '%d h ago', hours, 'newspack-intelligence' ),
			hours
		);
	}
	const days = Math.floor( seconds / 86400 );
	return sprintf(
		/* translators: %d: days ago. */
		_n( '%d d ago', '%d d ago', days, 'newspack-intelligence' ),
		days
	);
}

/**
 * An instant as how long ago it was, its absolute time in the `title` and
 * in the words a screen reader reads after it.
 *
 * @param {Object} props
 * @param {number} props.ts Seconds since the epoch.
 * @return {import('react').ReactElement} The time.
 */
function Ago( { ts } ) {
	const absolute = formatLocalDateTime( ts );
	return (
		<>
			<time
				dateTime={ new Date( ts * 1000 ).toISOString() }
				title={ absolute }
			>
				{ agoText( ts ) }
			</time>
			<VisuallyHidden>{ ` (${ absolute })` }</VisuallyHidden>
		</>
	);
}

/**
 * A source's last answer and the share of its requests that failed, the
 * count muted while the share stays under `FAILING_SHARE`.
 *
 * @param {{last_ok_at?: ?number, failed?: number, requests?: number}} source The source's row.
 * @return {import('react').ReactNode} The cause.
 */
function answeredText( { last_ok_at: lastOk, failed, requests } ) {
	const count =
		failed > 0 ? (
			<span
				className={
					failingShare( { failed, requests } )
						? undefined
						: 'newspack-nodes-status is-muted'
				}
			>
				{ sprintf(
					/* translators: 1: requests that failed, 2: requests the pass sent. */
					__( '%1$d of %2$d failed', 'newspack-intelligence' ),
					failed,
					requests
				) }
			</span>
		) : null;
	if ( ! lastOk ) {
		return count;
	}
	return (
		<>
			<Ago ts={ lastOk } />
			{ count && ', ' }
			{ count }
		</>
	);
}

/**
 * Whether a source's failed share of its requests reaches `FAILING_SHARE`.
 *
 * @param {{failed?: number, requests?: number}} source The source's row.
 * @return {boolean} Whether it does.
 */
const failingShare = ( { failed, requests } ) =>
	requests > 0 && failed / requests >= FAILING_SHARE;

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
 * extraction call alike: "N calls waiting (N backlog, N new), N in flight",
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

/**
 * Whether an idle source waits on a credential rather than a reason it names.
 *
 * @param {Object}  source              The source's row.
 * @param {?Object} [source.credential] The entry it lacks.
 * @param {?string} [source.reason]     Why it cannot fetch.
 * @return {boolean} Whether it does.
 */
const wantsCredential = ( { credential, reason } ) =>
	Boolean( credential || ! reason );

/**
 * The Vault link an idle source waiting on a credential offers.
 *
 * @param {Object} source The source's row.
 * @param {Object} ctx    The roles the fix reads.
 * @return {?import('react').ReactElement|false} The fix.
 */
const credentialFix = ( source, ctx ) =>
	wantsCredential( source ) && (
		<VaultFix
			can={ ctx.canManage }
			label={ __( 'Add credential', 'newspack-intelligence' ) }
			action={ __( 'add its credential', 'newspack-intelligence' ) }
		/>
	);

/** A row whose health is not known, the base of every unknown state. */
const UNKNOWN = {
	word: __( 'Unknown', 'newspack-intelligence' ),
	tone: 'is-neutral',
	unknown: true,
};

/** A worker running, whether busy or idle. */
const RUNNING = {
	word: __( 'Running', 'newspack-intelligence' ),
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
		idle: { ...RUNNING, word: __( 'Idle', 'newspack-intelligence' ) },
		inactive: {
			word: __( 'Off', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: ( { topologies } ) =>
				topologies.length
					? __( 'Collecting is off.', 'newspack-intelligence' )
					: createInterpolateElement(
							__(
								'Collecting is off; <code>wp nodes activate</code> turns on the topology that composes Intelligence.',
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
							'turn collecting on',
							'newspack-intelligence'
						) }
						verb="activate"
						ci="topologies"
						refresh={ ctx.refresh }
						scope={ ctx.scope }
						texts={ {
							busy: __( 'Activating…', 'newspack-intelligence' ),
							ok: __( 'Activated.', 'newspack-intelligence' ),
						} }
					/>
				),
		},
		unconfigured: {
			word: __( 'No lists file', 'newspack-intelligence' ),
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
			word: __( 'Stale', 'newspack-intelligence' ),
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
					scope={ ctx.scope }
					args={ { types: topology } }
					texts={ {
						busy: __( 'Restarting…', 'newspack-intelligence' ),
						ok: __( 'Restarted.', 'newspack-intelligence' ),
					} }
				/>
			),
		},
		down: {
			word: __( 'Down', 'newspack-intelligence' ),
			tone: 'is-error',
			fault: true,
			text: () =>
				__(
					'The fleet revives a down worker within a minute; if it stays down, run `wp nodes doctor`.',
					'newspack-intelligence'
				),
		},
		held: {
			word: __( 'Held', 'newspack-intelligence' ),
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
		ok: { ...RUNNING, text: answeredText },
		partial: {
			word: __( 'Failing', 'newspack-intelligence' ),
			tone: 'is-warning',
			text: answeredText,
		},
		failing: {
			word: __( 'Failing', 'newspack-intelligence' ),
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
						scope={ ctx.scope }
						texts={ {
							busy: __( 'Asking…', 'newspack-intelligence' ),
							ok: __( 'Fetching.', 'newspack-intelligence' ),
						} }
					/>
				),
		},
		idle: {
			word: __( 'Stopped', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: idleText,
			fix: credentialFix,
		},
		unset: {
			word: __( 'Not set up', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: idleText,
			fix: credentialFix,
		},
		unknown: {
			...UNKNOWN,
			text: () =>
				__( 'No pass has reported yet.', 'newspack-intelligence' ),
		},
	},
	dead: {
		dead: {
			word: __( 'Not answering', 'newspack-intelligence' ),
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
			fix: ( one, ctx ) => (
				<FormerClientFix client={ one } endClient={ ctx.endClient } />
			),
		},
	},
	backlog: {
		'': {
			word: __( 'Up to date', 'newspack-intelligence' ),
			tone: 'is-success',
			text: clearText,
			idle: ( { held } ) => 0 === held,
		},
		no_live_entity: {
			word: __( 'Held', 'newspack-intelligence' ),
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
					scope={ ctx.scope }
					texts={ {
						busy: __( 'Seeding…', 'newspack-intelligence' ),
						ok: __( 'Seeded.', 'newspack-intelligence' ),
					} }
				/>
			),
		},
		behind_ontology: {
			word: __( 'Catching up', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: () =>
				__( 'Catching up on the ontology.', 'newspack-intelligence' ),
		},
		behind_long: {
			word: __( 'Behind', 'newspack-intelligence' ),
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
			word: __( 'Skipped', 'newspack-intelligence' ),
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
					scope={ ctx.scope }
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
			word: __( 'Busy', 'newspack-intelligence' ),
			tone: 'is-neutral',
			text: queueText,
		},
	},
	extraction: {
		call: {
			word: __( 'Running', 'newspack-intelligence' ),
			tone: 'is-success',
			text: ( { last_ok_at: lastOk } ) =>
				lastOk
					? sprintf(
							/* translators: %s: a time in the reader's zone. */
							__(
								'By the model; last extraction %s.',
								'newspack-intelligence'
							),
							formatLocalDateTime( lastOk )
					  )
					: __( 'By the model.', 'newspack-intelligence' ),
		},
		failing: {
			word: __( 'Failing', 'newspack-intelligence' ),
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
			word: __( 'Heuristic only', 'newspack-intelligence' ),
			tone: 'is-warning',
			fault: true,
			text: ( { vault_group: vaultGroup, unset } ) => {
				if ( unset?.length ) {
					return sprintf(
						/* translators: %s: the model-call node's setters left unset, comma-separated. */
						__(
							'Extracting with the heuristic only: the model call is not configured; the lists file sets no %s.',
							'newspack-intelligence'
						),
						unset.join( ', ' )
					);
				}
				return vaultGroup
					? sprintf(
							/* translators: %s: the Vault group the model calls take their credential from. */
							__(
								'Extracting with the heuristic only: Vault group %s holds no entry for the model call.',
								'newspack-intelligence'
							),
							vaultGroup
					  )
					: __(
							'Extracting with the heuristic only: the model call has no Vault entry.',
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
						'No extraction has reported since the cache was cleared; the newest used the model.',
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
		table,
		answer,
		state: meant.word,
		tone: meant.tone,
		fault: true === meant.fault,
		unknown: true === meant.unknown,
		idle: true === meant.idle?.( answer ),
		text: meant.text( answer, ctx ),
		fix: ( scope ) =>
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
				meant.fix?.( answer, { ...ctx, refresh, scope } ) || null
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
 * A source's state: its own; `partial` while it answers with a failed share
 * at `FAILING_SHARE` or above; or `unset` while it waits on a credential it
 * has never collected with.
 *
 * @param {{state: string, failed?: number, requests?: number, last_ok_at?: ?number, credential?: ?Object, reason?: ?string}} source The source's row.
 * @return {string} The state.
 */
function sourceState( source ) {
	if ( 'ok' === source.state && failingShare( source ) ) {
		return 'partial';
	}
	return 'idle' === source.state &&
		! source.last_ok_at &&
		wantsCredential( source )
		? 'unset'
		: source.state;
}

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
				word( 'healthWords', 'collection' ),
				'collection',
				collection.state,
				collection
			),
		backlog &&
			spec(
				'backlog',
				word( 'healthWords', 'backlog' ),
				'backlog',
				backlogState( backlog, Math.floor( Date.now() / 1000 ) ),
				backlog
			),
		backlog?.dropped &&
			spec(
				'dropped',
				word( 'healthWords', 'dropped' ),
				'dropped',
				'dropped',
				backlog.dropped
			),
		extraction &&
			spec(
				'extraction',
				word( 'healthWords', 'extraction' ),
				'extraction',
				extractionState( extraction ),
				extraction
			),
		queueText( extraction?.queue ) &&
			spec(
				'calls',
				word( 'healthWords', 'calls' ),
				'calls',
				'busy',
				extraction.queue
			),
		...( sources?.sources ?? [] ).map( ( one ) =>
			spec(
				`${ SOURCE_ROW }${ one.name }`,
				words( 'sourceWords' )[ one.name ] ?? one.name,
				'source',
				sourceState( one ),
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
		return __( 'Collecting is off.', 'newspack-intelligence' );
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
		collectingText(
			sources.sources.filter( ( one ) => 'unset' !== sourceState( one ) )
				.length,
			unreported
		),
		waiting.idle && calls ? '' : waiting.text,
		calls?.text,
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * The pair of role writes ending a client, mounted for an operator alone, so
 * any other reader mounts no write; it hands `useEndClient()` over the
 * sources slice to `children`, its `ask( client, button )` also taking the
 * button pressed, and doing nothing while a pair is owed its replies, and
 * shows the End client dialog it asks, the client's dead feed its evidence,
 * and the snackbar saying a client it ended is now a former one.
 * Once the dialog confirms and the client's rows leave, focus moves to the
 * row now in the pressed row's place, else the last row, else the panel,
 * as the review queues move it, while the panel is open.
 *
 * @param {Object}                                              props
 * @param {string}                                              props.scope    Names the pair's nodes.
 * @param {Object}                                              props.sources  The sources slice's read.
 * @param {{current: ?HTMLElement}}                             props.panel    The details panel, while it is open.
 * @param {(endClient: Object) => import('react').ReactElement} props.children Renders the health.
 * @return {import('react').ReactElement} The health.
 */
function EndingClients( { scope, sources, panel, children } ) {
	const endClient = useEndClient(
		`${ scope }:former-client`,
		sources,
		'dead_clients'
	);
	const asked = useRef( null );
	const pressed = useRef( null );
	useLayoutEffect( () => {
		if ( null === pressed.current ) {
			return;
		}
		const at = pressed.current;
		pressed.current = null;
		if ( ! panel.current ) {
			return;
		}
		const left = panel.current.querySelectorAll( 'li' );
		( left[ at ] ?? left[ left.length - 1 ] ?? panel.current ).focus();
	} );
	// A dead feed's fix asks about the feed's whole row.
	const asking =
		/** @type {?{entity_key: string, name: string, domain: string, since: number}} */ (
			endClient.asking
		);
	return (
		<>
			{ children( {
				...endClient,
				ask: ( client, button ) => {
					if ( null !== endClient.owed ) {
						return;
					}
					asked.current = panel.current
						? [ ...panel.current.querySelectorAll( 'li' ) ].indexOf(
								button.closest( 'li' )
						  )
						: -1;
					endClient.ask( client );
				},
			} ) }
			{ asking && (
				<EndClientDialog
					name={ asking.name }
					evidence={ [
						{
							item_key: null,
							title: asking.domain,
							url: null,
							source: __(
								'Client feed',
								'newspack-intelligence'
							),
							day: formatLocalDateTime( asking.since ),
							role: null,
						},
					] }
					onEnd={ () => {
						pressed.current = asked.current;
						endClient.end();
					} }
					onClose={ endClient.cancel }
				/>
			) }
			<UndoSnackbar latest={ endClient.ended } />
		</>
	);
}

/**
 * Collection's health, the page's four `health` reads, which the page asks
 * on load, again once any fix lands, and on Refresh; nothing polls. An operator's reads run
 * through `EndingClients`, so a client it ends leaves at once; every other
 * reader mounts no write. `children` renders what is read.
 *
 * @param {Object}                                        props
 * @param {string}                                        props.scope    Names its writes, so the strip and a block never share a Fetcher.
 * @param {boolean}                                       props.operator Whether the reader may run the fixes.
 * @param {(health: Health) => import('react').ReactNode} props.children Renders it.
 * @return {import('react').ReactElement} The health.
 */
function HealthReads( { scope, operator, children } ) {
	const collection = usePageRead( 'collection' );
	const sources = usePageRead( 'sources' );
	const backlog = usePageRead( 'backlog' );
	const extraction = usePageRead( 'extraction' );
	const reads = { collection, sources, backlog, extraction };
	const panel = useRef( null );
	const read = ( endClient ) => (
		<HealthRows
			scope={ scope }
			reads={ reads }
			operator={ operator }
			panel={ panel }
			endClient={ endClient }
		>
			{ children }
		</HealthRows>
	);
	return operator ? (
		<EndingClients scope={ scope } sources={ sources } panel={ panel }>
			{ read }
		</EndingClients>
	) : (
		read( null )
	);
}

/**
 * What a render of the health reads.
 *
 * @typedef {Object} Health
 * @property {string}                  scope     Names the fixes' writes; the notice draws its own under `<scope>:notice`.
 * @property {Object<string, Object>}  reads     The four reads, by slice.
 * @property {?Object}                 listed    The sources slice, less any client being ended.
 * @property {Array<HealthRow>}        rows      Every row.
 * @property {Array<HealthRow>}        faults    The fault rows, worst first.
 * @property {() => void}              refresh   Re-reads every slice and lets every fix's hold go.
 * @property {boolean}                 operator  Whether the reader may run the fixes.
 * @property {{current: ?HTMLElement}} panel     The details panel, while it is open.
 * @property {?Object}                 endClient `useEndClient()`, null for a reader.
 */

/**
 * The rows the four reads hold, the sources slice read through `endClient`
 * for an operator. Once a fix lands its row stays a fault and shows the
 * fix's answer where the fix was, for as long as a read still answers the
 * state it fixed, since the strip reads again only on a landed fix or on
 * Refresh; a read answering another state, or Refresh, ends the hold.
 *
 * @param {Object}                                        props
 * @param {string}                                        props.scope     Names the fixes' writes, passed on in `Health`.
 * @param {Object<string, Object>}                        props.reads     The four reads, by slice.
 * @param {boolean}                                       props.operator  Whether the reader may run the fixes.
 * @param {{current: ?HTMLElement}}                       props.panel     The details panel.
 * @param {?Object}                                       props.endClient `useEndClient()`, null for a reader.
 * @param {(health: Health) => import('react').ReactNode} props.children  Renders them.
 * @return {import('react').ReactNode} The health.
 */
function HealthRows( { scope, reads, operator, panel, endClient, children } ) {
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
	const landed = ( key, state, text ) =>
		setFixed( ( was ) => new Map( was ).set( key, { state, text } ) );
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

	const rows = healthRows( answers, {
		canTune: operator,
		canManage: operator,
		fixed,
		landed,
		endClient,
	} );
	return children( {
		scope,
		reads,
		listed,
		rows,
		faults: rows.filter( ( row ) => row.fault ),
		refresh: () => {
			setFixed( new Map() );
			Object.values( reads ).forEach( ( read ) => read.refresh() );
		},
		operator,
		panel,
		endClient,
	} );
}

/**
 * The details panel: its line, every row with the faults first, each fix
 * for an operator and its text step for anyone else, and Refresh. With
 * `detail` "summary" it leaves each source's row out.
 *
 * @param {Object}                props
 * @param {Health}                props.health What is read.
 * @param {('summary'|'sources')} props.detail Whether it lists each source.
 * @return {import('react').ReactElement} The panel.
 */
function HealthPanel( { health, detail } ) {
	const {
		scope,
		reads,
		listed,
		rows,
		faults,
		refresh,
		panel,
		endClient,
		operator,
	} = health;
	const shown = rows.filter(
		( row ) => 'sources' === detail || ! row.key.startsWith( SOURCE_ROW )
	);
	const ordered = [
		...shown.filter( ( row ) => row.fault ),
		...shown.filter( ( row ) => ! row.fault ),
	];
	const all = Object.values( reads );
	return (
		<section
			ref={ panel }
			tabIndex={ -1 }
			className="newspack-intelligence__health"
			aria-label={ __( 'Collection', 'newspack-intelligence' ) }
		>
			<div className="newspack-intelligence__health-line">
				<span role="status">
					{ healthLine( {
						faults,
						rows,
						reads: all,
						collection: reads.collection.answer,
						sources: listed,
					} ) }
				</span>
				<button
					type="button"
					className="button button-small"
					onClick={ refresh }
				>
					{ __( 'Refresh', 'newspack-intelligence' ) }
				</button>
			</div>
			{ all.map( ( read, i ) => (
				<Unread
					operator={ operator }
					key={ i }
					error={ read.error }
					refresh={ read.refresh }
				/>
			) ) }
			<Refusal
				error={
					endClient?.unrecorded &&
					unrecordedText( endClient.unrecorded )
				}
			/>
			<ul className="newspack-intelligence__health-rows">
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
						{ row.fix( scope ) }
					</li>
				) ) }
				{ Object.entries( reads )
					.filter( ( [ , read ] ) => read.loading )
					.map( ( [ slice ] ) => (
						<li
							key={ `owed-${ slice }` }
							className="newspack-intelligence__health-row"
						>
							<Skeleton shape="line" count={ 1 } />
						</li>
					) ) }
			</ul>
		</section>
	);
}

/**
 * The health a `health` block draws inline, its panel at `detail`, its fixes
 * for an operator alone.
 *
 * @param {Object}                props
 * @param {boolean}               props.operator Whether the reader may run the fixes.
 * @param {('summary'|'sources')} props.detail   Whether it lists each source.
 * @return {import('react').ReactElement} The panel.
 */
export function HealthBlock( { operator, detail } ) {
	return (
		<HealthReads scope="health-block" operator={ operator }>
			{ ( health ) => (
				<HealthPanel health={ health } detail={ detail } />
			) }
		</HealthReads>
	);
}

/**
 * Collection's status as the page's header shows it to every reader, and
 * the fault it shows under the tab bar on every screen. An operator's header
 * offers **Details**, the panel in a popover, or a bottom sheet when the page
 * is narrow, and the fault notice names the worst fault with its fix; any
 * other reader reads a fault as one sentence with no controls. Collection
 * off is neutral, and while it is off no other row is a fault; nor is a
 * projector catching up on the ontology for under BEHIND_FAULT_S.
 *
 * @param {Object}   props
 * @param {boolean}  props.canManage Whether the reader may run the operator fixes.
 * @param {?Element} props.slot      The header's controls slot, as `HeaderSlot` takes it.
 * @return {import('react').ReactElement} The status and the notice.
 */
export default function HealthStrip( { canManage, slot } ) {
	return (
		<HealthReads scope="health" operator={ canManage }>
			{ ( health ) => (
				<>
					<HeaderSlot slot={ slot }>
						<HeaderStatus health={ health } />
					</HeaderSlot>
					<FaultNotice health={ health } />
				</>
			) }
		</HealthReads>
	);
}

/**
 * The header's phrase, its tone a dot before it, and for an operator
 * **Details**, which opens the panel, and **Refresh** when a read failed.
 * Under 600 px a healthy phrase is its time alone.
 *
 * @param {Object} props
 * @param {Health} props.health What is read.
 * @return {import('react').ReactElement} The status.
 */
function HeaderStatus( { health } ) {
	const narrow = useNarrow();
	const [ anchor, setAnchor ] = useState( null );
	const [ open, setOpen ] = useState( false );
	const { reads, faults, operator, refresh } = health;
	const failed = reads.collection.error || reads.sources.error;
	const { text, tone } = headerPhrase( health, narrow );
	const close = () => setOpen( false );
	return (
		<div className="newspack-intelligence__status">
			<span
				className={ `newspack-nodes-status-indicator ${
					faults.length ? 'is-warning' : tone
				}` }
				aria-hidden="true"
			/>
			<span className="newspack-intelligence__collecting">{ text }</span>
			{ operator && failed && (
				<button
					type="button"
					className="button button-small"
					onClick={ refresh }
				>
					{ __( 'Refresh', 'newspack-intelligence' ) }
				</button>
			) }
			{ operator && (
				<button
					ref={ setAnchor }
					type="button"
					className="button button-small"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ __( 'Details', 'newspack-intelligence' ) }
				</button>
			) }
			{ open && narrow && (
				<Sheet
					title={ __( 'Collection', 'newspack-intelligence' ) }
					closeLabel={ __( 'Close', 'newspack-intelligence' ) }
					onClose={ close }
				>
					<HealthPanel health={ health } detail="sources" />
				</Sheet>
			) }
			{ open && ! narrow && anchor && (
				<Popover
					anchor={ anchor }
					placement="bottom-end"
					className="newspack-intelligence__health-popover"
					onClose={ () => ! health.endClient?.asking && close() }
				>
					<HealthPanel health={ health } detail="sources" />
				</Popover>
			) }
		</div>
	);
}

/**
 * The header's words and tone: checking while the collection or the
 * sources slice is owed, that it could not be read, that collection is off,
 * the items waiting to match, else collecting and when a source last
 * answered; under 600 px that time alone.
 *
 * @param {Health}  health What is read.
 * @param {boolean} narrow Whether the page is narrow.
 * @return {{text: import('react').ReactNode, tone: string}} The phrase.
 */
function headerPhrase( { reads, listed }, narrow ) {
	const { collection, backlog } = reads;
	if ( collection.error || reads.sources.error ) {
		return {
			text: __(
				'Collection status could not be read.',
				'newspack-intelligence'
			),
			tone: 'is-warning',
		};
	}
	if ( ! collection.answer || ! listed ) {
		return {
			text: __( 'Checking collection…', 'newspack-intelligence' ),
			tone: 'is-neutral',
		};
	}
	if ( 'inactive' === collection.answer.state ) {
		return {
			text: __( 'Collection is off.', 'newspack-intelligence' ),
			tone: 'is-neutral',
		};
	}
	const held = backlog.answer?.held;
	if ( '' === backlog.answer?.reason && held > 0 ) {
		return {
			text: sprintf(
				/* translators: %d: items waiting to be matched. */
				_n(
					'Collecting; %d item waiting',
					'Collecting; %d items waiting',
					held,
					'newspack-intelligence'
				),
				held
			),
			tone: 'is-success',
		};
	}
	const latest = Math.max(
		0,
		...listed.sources.map( ( one ) => one.last_ok_at ?? 0 )
	);
	if ( ! latest ) {
		return {
			text: word( 'healthWords', 'collection' ),
			tone: 'is-success',
		};
	}
	if ( narrow ) {
		return { text: <Ago ts={ latest } />, tone: 'is-success' };
	}
	return {
		text: createInterpolateElement(
			__( 'Collecting, updated <ago />', 'newspack-intelligence' ),
			{ ago: <Ago ts={ latest } /> }
		),
		tone: 'is-success',
	};
}

/**
 * The worst fault, pinned under the tab bar: for an operator its row's
 * label, state, cause and fix; for any other reader, when a collection or a
 * source row is at fault, one sentence saying when sources stopped updating.
 *
 * @param {Object} props
 * @param {Health} props.health What is read.
 * @return {?import('react').ReactElement} The notice.
 */
function FaultNotice( { health } ) {
	const { faults, operator, listed } = health;
	if ( operator ) {
		const worst = faults[ 0 ];
		const said = worst && `${ worst.label }: ${ worst.state }`;
		// A Notice given no string renders its children again to speak them.
		return worst ? (
			<Notice
				status={ 'is-error' === worst.tone ? 'error' : 'warning' }
				isDismissible={ false }
				className="newspack-intelligence__fault"
				spokenMessage={
					'string' === typeof worst.text
						? `${ said } ${ worst.text }`
						: said
				}
			>
				<p>
					<strong>{ said }</strong> { worst.text }
				</p>
				{ worst.fix( `${ health.scope }:notice` ) }
			</Notice>
		) : null;
	}
	const stopped = faults.filter( ( row ) =>
		[ 'collection', 'source' ].includes( row.table )
	);
	if ( ! stopped.length ) {
		return null;
	}
	const at = stoppedAt( stopped, listed?.sources ?? [] );
	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="newspack-intelligence__fault"
		>
			<p title={ at ? formatLocalDateTime( at ) : undefined }>
				{ at
					? sprintf(
							/* translators: %s: a time of day in the reader's zone. */
							__(
								'Some sources stopped updating at %s, so recent items may be missing.',
								'newspack-intelligence'
							),
							pageFormat(
								Intl.DateTimeFormat,
								/** @type {Intl.DateTimeFormatOptions} */ ( {
									hour: '2-digit',
									minute: '2-digit',
									hourCycle: 'h23',
								} )
							).format( new Date( at * 1000 ) )
					  )
					: __(
							'Some sources stopped updating, so recent items may be missing.',
							'newspack-intelligence'
					  ) }
			</p>
		</Notice>
	);
}

/**
 * When the faulted sources stopped updating: the earliest last answer among
 * them, a collection fault counting the newest any source gave; 0 while
 * every time is unknown.
 *
 * @param {Array<HealthRow>} stopped The collection and source faults.
 * @param {Array<Object>}    sources Every source's row.
 * @return {number} Seconds since the epoch, or 0.
 */
function stoppedAt( stopped, sources ) {
	const times = stopped
		.map( ( row ) =>
			'source' === row.table
				? row.answer.last_ok_at ?? 0
				: Math.max(
						0,
						...sources.map( ( one ) => one.last_ok_at ?? 0 )
				  )
		)
		.filter( Boolean );
	return times.length ? Math.min( ...times ) : 0;
}
