import {
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, CardBody, CardHeader } from '@wordpress/components';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { unrecordedText, useEndClient } from '../hooks/useEndClient';
import Answer, { AnswerBody } from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import EditDrawer from '../components/EditDrawer';
import Refusal from '../components/Refusal';
import { ItemPage, SaveButton } from '../components/ItemRow';
import { SignalTrend, signalValue } from '../components/TrendTable';
import { metaLine, roleText, utcDay } from '../components/format';

/**
 * Whether a role row held any time; a closed row ending where it starts is
 * an import's same-day correction, not a period the entity held the role.
 * An open row, `to_ts` 0, always holds time, even one a seed asserted from 0.
 *
 * @param {Object} role The role row.
 * @return {boolean} Whether it held time.
 */
const heldTime = ( role ) => 0 === role.to_ts || role.from_ts !== role.to_ts;

/**
 * What an entity is called where it heads its view and labels its tab: its
 * name, else its key.
 *
 * @param {{name: ?string, entity_key: string}} entity The record.
 * @return {string} The name.
 */
const entityName = ( entity ) => entity.name || entity.entity_key;

/**
 * The entity's counts: readable items linking it and links awaiting review.
 *
 * @param {{items: number, proposed_links: number}} counts The counts.
 * @return {string} The line.
 */
function countsText( counts ) {
	return [
		sprintf(
			/* translators: %d: readable items linking the entity. */
			_n( '%d item', '%d items', counts.items, 'newspack-intelligence' ),
			counts.items
		),
		sprintf(
			/* translators: %d: links awaiting review. */
			_n(
				'%d proposed link',
				'%d proposed links',
				counts.proposed_links,
				'newspack-intelligence'
			),
			counts.proposed_links
		),
	].join( ', ' );
}

/**
 * A client's latest signal, the first the signals read lists, or "Steady"
 * when it lists none; nothing until that read answers.
 *
 * @param {Object[]|undefined} signals The signals read's rows.
 * @return {string|undefined} The part.
 */
function latestSignal( signals ) {
	if ( undefined === signals ) {
		return undefined;
	}
	return 0 === signals.length
		? __( 'Steady', 'newspack-intelligence' )
		: sprintf(
				/* translators: 1: the signal's axis and kind, 2: the first UTC day of its week. */
				__(
					'Latest signal: %1$s, week of %2$s',
					'newspack-intelligence'
				),
				signalValue( signals[ 0 ] ),
				signals[ 0 ].bucket
		  );
}

/**
 * A client's status line: when it became a client, its latest signal, how
 * many items about it report it churned, are churn signals, feature
 * requests and complaints, and when the newest of them is dated.
 *
 * @param {Object}             status  The record's `status`.
 * @param {Object[]|undefined} signals The signals read's rows.
 * @return {string} The line.
 */
function statusText( status, signals ) {
	const {
		churned,
		churn_signal: churns,
		feature_request: asks,
		complaint: complaints,
	} = status.kinds;
	return metaLine(
		sprintf(
			/* translators: %s: the first UTC day of its client role. */
			__( 'Client since %s', 'newspack-intelligence' ),
			utcDay( status.client_since )
		),
		latestSignal( signals ),
		[
			sprintf(
				/* translators: %d: items about it reporting that it has left or is leaving. */
				_n(
					'%d churn report',
					'%d churn reports',
					churned,
					'newspack-intelligence'
				),
				churned
			),
			sprintf(
				/* translators: %d: items about it that are churn signals. */
				_n(
					'%d churn signal',
					'%d churn signals',
					churns,
					'newspack-intelligence'
				),
				churns
			),
			sprintf(
				/* translators: %d: items about it that are feature requests. */
				_n(
					'%d feature request',
					'%d feature requests',
					asks,
					'newspack-intelligence'
				),
				asks
			),
			sprintf(
				/* translators: %d: items about it that are complaints. */
				_n(
					'%d complaint',
					'%d complaints',
					complaints,
					'newspack-intelligence'
				),
				complaints
			),
		].join( ', ' ),
		null === status.last_item_ts
			? __( 'No item about it yet', 'newspack-intelligence' )
			: sprintf(
					/* translators: %s: the UTC day of the newest item about it. */
					__( 'Last item %s', 'newspack-intelligence' ),
					utcDay( status.last_item_ts )
			  )
	);
}

/**
 * The question a client with a churn report raises, for a curator: one
 * click ends its client role, and only once that lands opens a former
 * client one (`useEndClient`), the prompt leaving the status line from
 * the click until a read no longer lists the client, and focus moving to
 * the entity's heading.
 *
 * @param {Object}     props
 * @param {() => void} props.onEnd Ends the client role.
 * @return {import('react').ReactElement} The prompt.
 */
function ChurnPrompt( { onEnd } ) {
	return (
		<>
			{ ' · ' }
			<span>
				{ __(
					'This client has a churn report.',
					'newspack-intelligence'
				) }
			</span>{ ' ' }
			<button
				type="button"
				className="button button-small"
				onClick={ onEnd }
			>
				{ __( 'End client?', 'newspack-intelligence' ) }
			</button>
		</>
	);
}

/**
 * What an entity holds: the keys merged into it, its names, identifiers and
 * every role row that held time, and the topics it is tagged with.
 *
 * @param {Object}                props
 * @param {Object}                props.entity       The record.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {import('react').ReactElement} The section.
 */
function Details( { entity, onOpenEntity } ) {
	const title = __( 'Details', 'newspack-intelligence' );
	return (
		<Card as="section" aria-label={ title }>
			<CardHeader>
				<h3>{ title }</h3>
			</CardHeader>
			<CardBody>
				{ 0 < entity.merged_from.length && (
					<p>
						{ sprintf(
							/* translators: %s: the keys merged into this entity. */
							__( 'Merged from %s', 'newspack-intelligence' ),
							entity.merged_from.join( ', ' )
						) }
					</p>
				) }
				<ul className="newspack-intelligence__facts">
					{ entity.names.map( ( name ) => (
						<li key={ `name ${ name.kind } ${ name.value }` }>
							{ `${ name.kind }: ${ name.value }` }
						</li>
					) ) }
					{ entity.identifiers.map( ( id ) => (
						<li key={ `id ${ id.kind } ${ id.value }` }>
							{ `${ id.kind }: ${ id.value }` }
						</li>
					) ) }
					{ entity.roles.filter( heldTime ).map( ( role ) => (
						<li key={ `role ${ role.role } ${ role.from_ts }` }>
							{ roleText( role ) }
						</li>
					) ) }
				</ul>
				{ 0 < entity.topics.length && (
					<ul className="newspack-intelligence__chips">
						{ entity.topics.map( ( topic ) => (
							<li key={ topic.topic_key }>
								<EntityChip
									entityKey={ topic.topic_key }
									name={ topic.name }
									onOpen={ onOpenEntity }
								/>
							</li>
						) ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The items an entity's own site published, newest first, a page at a time.
 *
 * @param {Object}                 props
 * @param {string}                 props.entityKey    The entity.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The page.
 */
function PublishedItems( { entityKey, onOpenEntity, onSave } ) {
	const [ starts, setStarts ] = useState( [ 0 ] );
	const offset = starts[ starts.length - 1 ];
	const read = useIntelRead(
		'entity:published',
		'timeline',
		formatCommandArgs( [ entityKey ], {
			published: true,
			...( 0 < offset ? { offset } : {} ),
		} )
	);
	return (
		<AnswerBody
			read={ read }
			rows="items"
			empty={ __(
				'No readable item was published by this entity.',
				'newspack-intelligence'
			) }
		>
			{ ( answer ) => (
				<ItemPage
					answer={ answer }
					starts={ starts }
					onStarts={ setStarts }
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
				/>
			) }
		</AnswerBody>
	);
}

/**
 * What an entity's own site published, as a section of its own, collapsed
 * until opened and read only then.
 *
 * @param {Object}                 props
 * @param {string}                 props.entityKey    The entity.
 * @param {string}                 props.name         The entity's name.
 * @param {number}                 props.count        How many items it published.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The section.
 */
function Published( { entityKey, name, count, onOpenEntity, onSave } ) {
	const [ open, setOpen ] = useState( false );
	const title = sprintf(
		/* translators: 1: the entity's name, 2: items its own site published. */
		__( 'Published by %1$s (%2$d)', 'newspack-intelligence' ),
		name,
		count
	);
	return (
		<Card as="section" aria-label={ title }>
			<CardHeader>
				<h3>
					<button
						type="button"
						className="button-link"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ title }
					</button>
				</h3>
			</CardHeader>
			{ open && (
				<CardBody>
					<PublishedItems
						entityKey={ entityKey }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
					/>
				</CardBody>
			) }
		</Card>
	);
}

/**
 * Entity: one entity's record, headed by its name and, for a client, its
 * status line, then its signals on both axes beside its trend's sparkline,
 * quiet or not (`SignalTrend`), its timeline of the items
 * about it a page at a time, what it holds (`Details`), then, collapsed,
 * the items its own site published, the entities sharing its items and the
 * claims naming it, each from its own read. Once the record has answered,
 * a curator finds its curation in the Edit drawer (`EditDrawer`), and, for
 * a client with a churn report since it began, the question in its status
 * line ending its client role (`ChurnPrompt`). Every edit that lands re-reads
 * the record. The name the record answers is handed to `onNamed` with the
 * entity's key, so the page can label its tab without reading again.
 *
 * @param {Object}                                       props
 * @param {string}                                       props.entityKey    The entity shown.
 * @param {boolean}                                      props.canTune      Whether to offer curation.
 * @param {Object}                                       props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}                        props.onOpenEntity Opens an entity.
 * @param {(named: {key: string, name: string}) => void} props.onNamed      Takes the name the record answers.
 * @param {(item: Object) => void}                       [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Entity( {
	entityKey,
	canTune,
	vocabulary,
	onOpenEntity,
	onNamed,
	onSave,
} ) {
	const about = [ entityKey ];
	const [ starts, setStarts ] = useState( [ 0 ] );
	const offset = starts[ starts.length - 1 ];
	const record = useIntelRead( 'entity:record', 'entity', about );
	const signals = useIntelRead(
		'entity:signals',
		'signals',
		formatCommandArgs( [], { entity: entityKey } )
	);
	const timeline = useIntelRead(
		'entity:timeline',
		'timeline',
		formatCommandArgs( about, 0 < offset ? { offset } : {} )
	);
	const related = useIntelRead( 'entity:related', 'related', about );
	const claims = useIntelRead( 'entity:claims', 'claims', about );
	const named =
		record.answer && ! record.error ? entityName( record.answer ) : null;
	useEffect( () => {
		if ( ! record.loading ) {
			onNamed( { key: entityKey, name: named } );
		}
	}, [ entityKey, record.loading, named, onNamed ] );

	// The record lists its entity as a client while its status answers.
	const clients = useMemo(
		() =>
			record.answer && {
				clients: record.answer.status
					? [ { entity_key: entityKey } ]
					: [],
			},
		[ record.answer, entityKey ]
	);
	const churn = useEndClient(
		'entity:churn',
		{ ...record, answer: clients },
		'clients',
		record.refresh
	);
	const edit = { refresh: record.refresh };
	const addName = useIntelWrite( 'add_name', edit );
	const addIdentifier = useIntelWrite( 'add_identifier', edit );
	const assertRole = useIntelWrite( 'assert_role', edit );
	const endRole = useIntelWrite( 'end_role', edit );
	const refusals = [ addName, addIdentifier, assertRole, endRole ]
		.map( ( write ) => write.refusal )
		.concat(
			churn.refused,
			churn.unrecorded && unrecordedText( churn.unrecorded )
		);
	// The heading takes focus once the churn prompt the reader pressed leaves.
	const heading = useRef( null );
	const ended = useRef( false );
	useLayoutEffect( () => {
		if ( ended.current && heading.current ) {
			heading.current.focus();
			ended.current = false;
		}
	} );

	return (
		<div className="newspack-intelligence__view">
			<Answer read={ record }>
				{ ( entity ) => (
					<>
						<h2 ref={ heading } tabIndex={ -1 }>
							{ entityName( entity ) }
						</h2>
						{ entity.status && (
							<p className="newspack-nodes-status">
								{ statusText(
									entity.status,
									signals.answer?.signals
								) }
								{ canTune &&
									entity.status.churn_open &&
									0 < churn.read.answer.clients.length && (
										<ChurnPrompt
											onEnd={ () => {
												ended.current = true;
												churn.run( {
													entity_key: entityKey,
													name: entityName( entity ),
												} );
											} }
										/>
									) }
							</p>
						) }
						<p className="newspack-nodes-status is-muted">
							{ metaLine(
								entity.type,
								entity.state,
								entity.entity_key
							) }
						</p>
						<p>{ countsText( entity.counts ) }</p>
						{ canTune && (
							<EditDrawer
								entityKey={ entityKey }
								name={ entityName( entity ) }
								openRoles={ entity.roles.filter(
									( role ) => 0 === role.to_ts
								) }
								vocabulary={ vocabulary }
								writes={ {
									addName,
									addIdentifier,
									assertRole,
									endRole,
								} }
							/>
						) }
						{ refusals.map( ( refusal, i ) => (
							<Refusal key={ i } error={ refusal } />
						) ) }
					</>
				) }
			</Answer>

			<Answer
				title={ __( 'Signals', 'newspack-intelligence' ) }
				level={ 3 }
				read={ signals }
			>
				{ ( answer ) => (
					<SignalTrend
						answer={ answer }
						value={ signalValue }
						onSave={ onSave }
					/>
				) }
			</Answer>

			<Answer
				title={ __( 'Timeline', 'newspack-intelligence' ) }
				level={ 3 }
				read={ timeline }
				rows="items"
				empty={ __(
					'No readable item is about this entity.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ItemPage
						answer={ answer }
						starts={ starts }
						onStarts={ setStarts }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
					/>
				) }
			</Answer>

			{ record.answer?.available && (
				<Details
					entity={ record.answer }
					onOpenEntity={ onOpenEntity }
				/>
			) }

			{ record.answer && 0 < record.answer.published.count && (
				<Published
					entityKey={ entityKey }
					name={ entityName( record.answer ) }
					count={ record.answer.published.count }
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
				/>
			) }

			<Answer
				title={ __( 'Related', 'newspack-intelligence' ) }
				level={ 3 }
				read={ related }
				rows="entities"
				empty={ __(
					'No other entity shares an item with this one.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.entities.map( ( other ) => (
							<li
								key={ other.entity_key }
								className="newspack-intelligence__actions"
							>
								<EntityChip
									entityKey={ other.entity_key }
									name={ other.name }
									onOpen={ onOpenEntity }
								/>
								{ metaLine(
									other.type,
									sprintf(
										/* translators: %d: items both entities link. */
										_n(
											'%d shared item',
											'%d shared items',
											other.shared_items,
											'newspack-intelligence'
										),
										other.shared_items
									)
								) }
							</li>
						) ) }
					</ul>
				) }
			</Answer>

			<Answer
				title={ __( 'Claims', 'newspack-intelligence' ) }
				level={ 3 }
				read={ claims }
				rows="claims"
				empty={ __(
					'No claim names this entity.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.claims.map( ( claim, i ) => (
							<li
								key={ `${ claim.item_key } ${ i }` }
								className="newspack-intelligence__actions"
							>
								<EntityChip
									entityKey={ claim.subject }
									name={ claim.subject_name }
									onOpen={ onOpenEntity }
								/>{ ' ' }
								<span>{ claim.predicate }</span>{ ' ' }
								{ null === claim.object_name ? (
									<span>{ claim.object }</span>
								) : (
									<EntityChip
										entityKey={ claim.object }
										name={ claim.object_name }
										onOpen={ onOpenEntity }
									/>
								) }{ ' ' }
								<Cite url={ claim.url } title={ claim.title } />{ ' ' }
								<SaveButton item={ claim } onSave={ onSave } />
							</li>
						) ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}
