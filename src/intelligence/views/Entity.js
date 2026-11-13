import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, DropdownMenu, TextControl } from '@wordpress/components';
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
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import EditDrawer from '../components/EditDrawer';
import EndClientDialog from '../components/EndClientDialog';
import ChurnNote from '../components/ChurnNote';
import Refusal from '../components/Refusal';
import { SectionHeader } from '@newspack-nodes/shared/components/Section';
import UndoSnackbar from '../components/UndoSnackbar';
import { entityName, itemKey, shownDay, utcDay } from '../components/format';
import { word } from '../components/words';
import { EntityRecord } from '../blocks/record';
import { editLayoutControl } from '@newspack-nodes/shared/composition/page';
import { PAGES } from '../layout/editMode';
import IntelligenceLayout from '../layout/IntelligenceLayout';

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
 * it: the way back; the shared `SectionHeader`, the name with **Watch**, for a
 * curator **Edit**, the Edit drawer (`EditDrawer`), and ⋯ offering **Edit
 * layout** and, for a client, a churn-risk note at its end; the type line
 * and the entity's website; then the client's churn-risk note. Below the
 * header the page's profile layout renders, its blocks sharing the header's
 * one `entity` read (`EntityRecord`). Ending the client from the drawer asks
 * first (`EndClientDialog`), listing the churn reports the client's status
 * names; **Not about this client** rejects a report's link to the client
 * after the undo window. Every edit that lands
 * re-reads the record. A refused record reads as a profile not available.
 *
 * @param {Object}                                                props
 * @param {string}                                                props.entityKey  The entity shown.
 * @param {boolean}                                               props.canTune    Whether to offer curation.
 * @param {Object}                                                props.vocabulary The ontology's closed lists.
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
									<SectionHeader
										title={ entityName( entity ) }
										actions={
											<>
												<button
													type="button"
													className="button"
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
												</button>
												{ canTune && (
													<EditDrawer
														entity={ entity }
														vocabulary={
															vocabulary
														}
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
															editLayoutControl(
																{
																	page: 'entity',
																	entity_key:
																		entityKey,
																},
																PAGES
															),
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
											</>
										}
									/>
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
									{ shownNote }
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
						onEnd={ churn.end }
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

				<IntelligenceLayout
					layout={ layout }
					onRestored={ onRestored }
					page={ {
						page: 'entity',
						lens_key: null,
						entity_key: entityKey,
						since: null,
						actions,
					} }
				/>
			</div>
		</EntityRecord.Provider>
	);
}
