import {
	useId,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	DropdownMenu,
	Notice,
	TextControl,
} from '@wordpress/components';
import { chevronLeft, moreHorizontal } from '@wordpress/icons';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import {
	CLIENT_READS,
	ENTITY_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { unrecordedText, useEndClient } from '../hooks/useEndClient';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { useHeld } from '../hooks/useHeld';
import { AnswerBody } from '../components/Answer';
import Skeleton from '../components/Skeleton';
import EditDrawer from '../components/EditDrawer';
import EndClientDialog from '../components/EndClientDialog';
import ChurnNote from '../components/ChurnNote';
import Refusal from '../components/Refusal';
import UndoSnackbar from '../components/UndoSnackbar';
import {
	entityName,
	itemKey,
	safeHref,
	shownDay,
	utcDay,
} from '../components/format';
import { word } from '../components/words';
import { jumpsOf } from '../blocks';
import { EntityRecord } from '../blocks/record';
import { editLayoutControl } from '../layout/LayoutMenu';
import PageLayout from '../layout/PageLayout';

/**
 * The UTC year of a Unix time.
 *
 * @param {number} ts Seconds since the epoch.
 * @return {string} The year.
 */
const yearOf = ( ts ) => utcDay( ts ).slice( 0, 4 );

/**
 * What an entity is, in words: a client since the year it became one, a
 * former client over the years it was one, else its type's word.
 *
 * @param {Object} entity The record.
 * @return {string} The type line.
 */
function typeLine( entity ) {
	if ( entity.status ) {
		return sprintf(
			/* translators: 1: the client role's word, 2: the year it began. */
			__( '%1$s since %2$s', 'newspack-intelligence' ),
			word( 'roleWords', 'client' ),
			yearOf( entity.status.client_since )
		);
	}
	const former = entity.roles.some(
		( role ) => 'former_client' === role.role && 0 === role.to_ts
	);
	if ( ! former ) {
		return word( 'typeWords', entity.type );
	}
	const held = entity.roles.filter(
		( role ) =>
			'client' === role.role &&
			0 !== role.to_ts &&
			role.from_ts !== role.to_ts
	);
	if ( 0 === held.length ) {
		return word( 'roleWords', 'former_client' );
	}
	return sprintf(
		/* translators: 1: the former client role's word, 2: the year it became a client, 3: the year it stopped. */
		__( '%1$s, %2$s to %3$s', 'newspack-intelligence' ),
		word( 'roleWords', 'former_client' ),
		yearOf( Math.min( ...held.map( ( role ) => role.from_ts ) ) ),
		yearOf( Math.max( ...held.map( ( role ) => role.to_ts ) ) )
	);
}

/**
 * The form keeping a client's churn-risk note: what puts it at risk, who
 * owns it, the next step and its day. Saving an empty text clears the note.
 *
 * @param {Object}                 props
 * @param {?Object}                props.note     The note it edits, or null.
 * @param {(note: Object) => void} props.onSave   Sends the note.
 * @param {() => void}             props.onCancel Closes the form.
 * @return {import('react').ReactElement} The form.
 */
function NoteForm( { note, onSave, onCancel } ) {
	const [ draft, setDraft ] = useState( () => ( {
		text: note?.text ?? '',
		owner: note?.owner ?? '',
		next_step: note?.next_step ?? '',
		day: note?.day ?? '',
	} ) );
	/**
	 * One of the note's fields.
	 *
	 * @param {string}          name  The field.
	 * @param {string}          label Its label.
	 * @param {'text' | 'date'} type  Its input type.
	 * @return {import('react').ReactElement} The control.
	 */
	const field = ( name, label, type ) => (
		<TextControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			type={ type }
			label={ label }
			value={ draft[ name ] }
			onChange={ ( value ) => setDraft( { ...draft, [ name ]: value } ) }
		/>
	);
	const legend = __( 'Churn-risk note', 'newspack-intelligence' );
	return (
		<form
			className="newspack-intelligence__edit"
			role="group"
			aria-label={ legend }
			{ ...submitProps( () => onSave( draft ) ) }
		>
			<strong>{ legend }</strong>
			{ field(
				'text',
				__( 'What puts it at risk', 'newspack-intelligence' ),
				'text'
			) }
			{ field( 'owner', __( 'Owner', 'newspack-intelligence' ), 'text' ) }
			{ field(
				'next_step',
				__( 'Next step', 'newspack-intelligence' ),
				'text'
			) }
			{ field( 'day', __( 'By', 'newspack-intelligence' ), 'date' ) }
			<div className="newspack-intelligence__actions">
				<Button variant="primary" type="submit">
					{ __( 'Save note', 'newspack-intelligence' ) }
				</Button>
				<Button variant="tertiary" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</Button>
			</div>
		</form>
	);
}

/**
 * The churn reports a client's status names: each report with a link to its
 * item and, for a curator, **Not about this client**; then the client's
 * churn-risk note; then, for a curator, **End client…**, which asks first.
 *
 * @param {Object}                    props
 * @param {Object[]}                  props.reports    The churn reports shown.
 * @param {string}                    props.operator   Who runs the deployment, whom a client leaves; '' for none.
 * @param {import('react').ReactNode} props.note       The client's churn-risk note.
 * @param {?() => void}               props.onEnd      Asks whether to end the client; null for a reader.
 * @param {?(item: Object) => void}   props.onNotAbout Says a report is not about the client; null for a reader.
 * @return {import('react').ReactElement} The notice.
 */
function ChurnNotice( { reports, operator, note, onEnd, onNotAbout } ) {
	return (
		<Notice status="warning" isDismissible={ false }>
			<strong>{ __( 'Possible churn', 'newspack-intelligence' ) }</strong>
			{ reports.map( ( item ) => {
				const href = safeHref( item.url );
				const source = word( 'sourceWords', item.source );
				const day = shownDay( item.day );
				return (
					<p
						key={ item.item_key }
						className="newspack-intelligence__actions"
					>
						<span>
							{ operator
								? sprintf(
										/* translators: 1: the item's title, 2: its source, 3: its day, 4: who runs the deployment. */
										__(
											'“%1$s” (%2$s, %3$s) says this client is leaving %4$s.',
											'newspack-intelligence'
										),
										item.title,
										source,
										day,
										operator
								  )
								: sprintf(
										/* translators: 1: the item's title, 2: its source, 3: its day. */
										__(
											'“%1$s” (%2$s, %3$s) says this client is leaving.',
											'newspack-intelligence'
										),
										item.title,
										source,
										day
								  ) }
						</span>
						{ href && (
							<a
								href={ href }
								className="button button-small"
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Open item', 'newspack-intelligence' ) }
							</a>
						) }
						{ onNotAbout && (
							<Button
								variant="secondary"
								size="small"
								onClick={ () => onNotAbout( item ) }
							>
								{ __(
									'Not about this client',
									'newspack-intelligence'
								) }
							</Button>
						) }
					</p>
				);
			} ) }
			{ note }
			{ onEnd && (
				<Button variant="secondary" size="small" onClick={ onEnd }>
					{ __( 'End client…', 'newspack-intelligence' ) }
				</Button>
			) }
		</Notice>
	);
}

/**
 * Links naming each heading of the layout, each moving focus to the heading
 * its id names in the layout below.
 *
 * @param {Object}                                 props
 * @param {Array<{text: string, id: string}>}      props.headings The headings.
 * @param {import('react').RefObject<HTMLElement>} props.layout   The layout's region.
 * @return {?import('react').ReactElement} The links.
 */
function JumpLinks( { headings, layout } ) {
	if ( 0 === headings.length ) {
		return null;
	}
	const jump = ( id ) => {
		const target = layout.current.ownerDocument.getElementById( id );
		if ( target && layout.current.contains( target ) ) {
			target.tabIndex = -1;
			target.focus();
		}
	};
	return (
		<nav
			className="newspack-intelligence__jumps"
			aria-label={ __( 'On this profile', 'newspack-intelligence' ) }
		>
			{ headings.map( ( { text, id } ) => (
				<Button key={ id } variant="link" onClick={ () => jump( id ) }>
					{ text }
				</Button>
			) ) }
		</nav>
	);
}

/**
 * The way back to the view that opened the profile.
 *
 * @param {Object}                              props
 * @param {{label: string, onBack: () => void}} props.back Names the view and returns to it.
 * @return {import('react').ReactElement} The link.
 */
function BackLink( { back } ) {
	return (
		<Button
			variant="link"
			icon={ chevronLeft }
			className="newspack-intelligence__back"
			onClick={ back.onBack }
		>
			{ sprintf(
				/* translators: %s: the view that opened the profile. */
				__( 'Back to %s', 'newspack-intelligence' ),
				back.label
			) }
		</Button>
	);
}

/**
 * Entity: one entity's profile. Its header is fixed, so no layout can hide
 * it: the way back, the name, the type line and the entity's website, then
 * **Watch**, for a curator **Edit**, the Edit drawer (`EditDrawer`), and ⋯
 * offering **Edit layout** and, for a client, a churn-risk note; then, while
 * the client's status names a churn report since it began, the churn notice
 * naming each (`ChurnNotice`) with the client's churn-risk note, else the
 * note alone; then jump links to the layout's headings. Below the header the
 * page's profile layout renders, its blocks sharing the header's one
 * `entity` read (`EntityRecord`). Ending the client, from the notice or the
 * drawer, asks first (`EndClientDialog`); **Not about this client** rejects a
 * report's link to the client after the undo window. Every edit that lands
 * re-reads the record. A refused record reads as a profile not available.
 *
 * @param {Object}                                                props
 * @param {string}                                                props.entityKey  The entity shown.
 * @param {boolean}                                               props.canTune    Whether to offer curation.
 * @param {Object}                                                props.vocabulary The ontology's closed lists.
 * @param {{name: string, heading: string}}                       props.operator   Who runs the deployment, whom the churn notice names.
 * @param {Object}                                                props.layout     What the page data answered for the profile's context.
 * @param {{label: string, onBack: () => void}}                   props.back       Names the view that opened the profile and returns to it.
 * @param {(target: Object, name: string) => void}                props.onWatch    Opens the watch form on a target.
 * @param {Partial<import('../layout/PageContext').IntelActions>} props.actions    What the blocks' rows open.
 * @param {(layout: Object) => void}                              props.onRestored Takes the default a curator restored.
 * @return {import('react').ReactElement} The view.
 */
export default function Entity( {
	entityKey,
	canTune,
	vocabulary,
	operator,
	layout,
	back,
	onWatch,
	actions,
	onRestored,
} ) {
	const record = useIntelRead( 'entity:record', 'entity', [ entityKey ] );
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
		'clients'
	);
	// The record lists its churn reports while its status answers.
	const listed = useMemo(
		() =>
			record.answer && {
				items: record.answer.status?.churn_open
					? record.answer.status.churn_items
					: [],
			},
		[ record.answer ]
	);
	const reports = useHeld( { ...record, answer: listed }, 'items', itemKey );
	const deferred = useDeferredWrite();
	const rejectLink = useIntelWrite( 'reject_link', {
		dirties: ENTITY_READS,
		scope: 'entity:churn:reject_link',
		onRefused: reports.refused,
	} );
	const evidence = ( reports.read.answer?.items ?? [] ).filter(
		( item ) =>
			! deferred.hidden.has( item.item_key ) &&
			! deferred.hidden.has( entityKey )
	);
	const notAbout = ( key, items, name ) =>
		deferred.defer(
			key,
			() =>
				items.forEach( ( item ) =>
					reports.hold( item.item_key, null, () =>
						rejectLink.run( {
							item: item.item_key,
							entity: entityKey,
						} )
					)
				),
			sprintf(
				/* translators: %s: the client's name. */
				__( 'Removed the link to %s.', 'newspack-intelligence' ),
				name
			)
		);
	const [ noting, setNoting ] = useState( false );
	const noteChurn = useIntelWrite( 'note_churn', {
		dirties: CLIENT_READS,
		refresh: () => setNoting( false ),
	} );
	const addName = useIntelWrite( 'add_name', { dirties: CLIENT_READS } );
	const addIdentifier = useIntelWrite( 'add_identifier', {
		dirties: CLIENT_READS,
	} );
	const assertRole = useIntelWrite( 'assert_role', {
		dirties: CLIENT_READS,
	} );
	const endRole = useIntelWrite( 'end_role', { dirties: CLIENT_READS } );
	const refusals = [
		addName,
		addIdentifier,
		assertRole,
		endRole,
		rejectLink,
		noteChurn,
	]
		.map( ( write ) => write.refusal )
		.concat(
			churn.refused,
			churn.unrecorded && unrecordedText( churn.unrecorded )
		);
	// The heading takes focus once the churn notice the reader ended leaves.
	const heading = useRef( null );
	const ending = useRef( false );
	useLayoutEffect( () => {
		if ( ending.current && heading.current ) {
			heading.current.focus();
			ending.current = false;
		}
	} );
	const region = useRef( null );
	const jumpPrefix = useId();

	if ( record.error ) {
		return (
			<div className="newspack-intelligence__view">
				<p className="newspack-nodes-empty-state">
					{ __(
						'This profile is not available to you, or it no longer exists.',
						'newspack-intelligence'
					) }{ ' ' }
					<BackLink back={ back } />
				</p>
			</div>
		);
	}

	const ask = ( entity ) => () =>
		churn.ask( { entity_key: entityKey, name: entityName( entity ) } );

	return (
		<EntityRecord.Provider value={ record }>
			<div className="newspack-intelligence__view">
				<header className="newspack-intelligence__profile-head">
					<BackLink back={ back } />
					<AnswerBody
						read={ record }
						skeleton={ <Skeleton shape="line" count={ 2 } /> }
					>
						{ ( entity ) => {
							const note = entity.status?.churn_note ?? null;
							const editNote = canTune
								? () => setNoting( true )
								: null;
							const shownNote = note && (
								<ChurnNote note={ note } onEdit={ editNote } />
							);
							const domains = entity.identifiers.filter(
								( id ) => 'domain' === id.kind
							);
							return (
								<>
									<div className="newspack-intelligence__profile-title">
										<h2 ref={ heading } tabIndex={ -1 }>
											{ entityName( entity ) }
										</h2>
										<div className="newspack-intelligence__actions">
											<Button
												variant="secondary"
												onClick={ () =>
													onWatch(
														{
															kind: 'entity',
															key: entityKey,
														},
														entityName( entity )
													)
												}
											>
												{ __(
													'Watch',
													'newspack-intelligence'
												) }
											</Button>
											{ canTune && (
												<EditDrawer
													entity={ entity }
													vocabulary={ vocabulary }
													writes={ {
														addName,
														addIdentifier,
														assertRole,
														endRole,
													} }
													onEndClient={ ask(
														entity
													) }
												/>
											) }
											{ canTune && (
												<DropdownMenu
													icon={ moreHorizontal }
													label={ __(
														'Profile actions',
														'newspack-intelligence'
													) }
													toggleProps={ {
														size: 'compact',
													} }
													controls={ [
														editLayoutControl( {
															page: 'entity',
															entity_key:
																entityKey,
														} ),
														...( entity.status &&
														! note
															? [
																	{
																		title: __(
																			'Add a churn-risk note',
																			'newspack-intelligence'
																		),
																		onClick:
																			() =>
																				setNoting(
																					true
																				),
																	},
															  ]
															: [] ),
													] }
												/>
											) }
										</div>
									</div>
									<p className="newspack-intelligence__type-line">
										<span className="newspack-nodes-status-badge is-pill">
											{ typeLine( entity ) }
										</span>
										{ domains.map( ( id ) => (
											<span key={ id.value }>
												{ `${ word(
													'identifierWords',
													id.kind
												) } ${ id.value }` }
											</span>
										) ) }
									</p>
									{ 0 < evidence.length &&
									0 < churn.read.answer.clients.length ? (
										<ChurnNotice
											reports={ evidence }
											operator={ operator.name }
											note={ shownNote }
											onEnd={
												canTune ? ask( entity ) : null
											}
											onNotAbout={
												canTune
													? ( item ) =>
															notAbout(
																item.item_key,
																[ item ],
																entityName(
																	entity
																)
															)
													: null
											}
										/>
									) : (
										shownNote
									) }
									{ noting && (
										<NoteForm
											note={ note }
											onCancel={ () =>
												setNoting( false )
											}
											onSave={ ( draft ) =>
												noteChurn.run( {
													entity: entityKey,
													...draft,
												} )
											}
										/>
									) }
									{ refusals.map( ( refusal, i ) => (
										<Refusal key={ i } error={ refusal } />
									) ) }
								</>
							);
						} }
					</AnswerBody>
					{ ! layout.error && (
						<JumpLinks
							headings={
								jumpsOf( layout.blocks, jumpPrefix ).headings
							}
							layout={ region }
						/>
					) }
				</header>

				{ churn.asking && (
					<EndClientDialog
						name={ churn.asking.name }
						evidence={ evidence.map( ( item ) => ( {
							...item,
							source: word( 'sourceWords', item.source ),
							day: shownDay( item.day ),
							role: 'subject',
						} ) ) }
						onEnd={ () => {
							ending.current = true;
							churn.end();
						} }
						onNotAbout={
							0 < evidence.length
								? () => {
										churn.cancel();
										notAbout(
											entityKey,
											evidence,
											churn.asking.name
										);
								  }
								: undefined
						}
						onClose={ churn.cancel }
					/>
				) }
				{ deferred.latest ? (
					<UndoSnackbar
						latest={ deferred.latest }
						onUndo={ deferred.undo }
					/>
				) : (
					<UndoSnackbar latest={ churn.ended } />
				) }

				<div ref={ region }>
					<PageLayout
						layout={ layout }
						onRestored={ onRestored }
						jumpPrefix={ jumpPrefix }
						page={ {
							page: 'entity',
							lens_key: null,
							entity_key: entityKey,
							since: null,
							actions,
						} }
					/>
				</div>
			</div>
		</EntityRecord.Provider>
	);
}
