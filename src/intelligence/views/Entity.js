import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import Refusal from '../components/Refusal';
import ItemRow from '../components/ItemRow';
import Pager from '../components/Pager';
import { metaLine, utcDay } from '../components/format';

/**
 * The options a closed list offers a select.
 *
 * @param {string[]} values The list.
 * @return {{label: string, value: string}[]} The options.
 */
const optionsOf = ( values ) =>
	values.map( ( value ) => ( { label: value, value } ) );

/**
 * One curation form: a legend naming it, the fields it collects and the
 * button sending them. Enter in a field sends too.
 *
 * @param {Object}                    props
 * @param {string}                    props.legend   Names the form and its button.
 * @param {() => void}                props.onSubmit Sends the form.
 * @param {import('react').ReactNode} props.children The fields.
 * @return {import('react').ReactElement} The form.
 */
function EditForm( { legend, onSubmit, children } ) {
	return (
		<form
			className="newspack-intelligence__edit"
			role="group"
			aria-label={ legend }
			{ ...submitProps( onSubmit ) }
		>
			<strong>{ legend }</strong>
			{ children }
			<button type="submit" className="button">
				{ legend }
			</button>
		</form>
	);
}

/**
 * A form adding a value of one kind to the entity: a name or an identifier.
 *
 * @param {Object}                                props
 * @param {string}                                props.legend   Names the form.
 * @param {string[]}                              props.kinds    The kinds it offers.
 * @param {(kind: string, value: string) => void} props.onSubmit Sends the pair.
 * @return {import('react').ReactElement} The form.
 */
function KindValueForm( { legend, kinds, onSubmit } ) {
	const [ kind, setKind ] = useState( kinds[ 0 ] );
	const [ value, setValue ] = useState( '' );
	return (
		<EditForm legend={ legend } onSubmit={ () => onSubmit( kind, value ) }>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Kind', 'newspack-intelligence' ) }
				options={ optionsOf( kinds ) }
				value={ kind }
				onChange={ setKind }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Value', 'newspack-intelligence' ) }
				value={ value }
				onChange={ setValue }
			/>
		</EditForm>
	);
}

/**
 * The form opening a role on the entity from now.
 *
 * @param {Object}                 props
 * @param {string[]}               props.roles    The roles it offers.
 * @param {(role: string) => void} props.onSubmit Sends the role.
 * @return {import('react').ReactElement} The form.
 */
function RoleForm( { roles, onSubmit } ) {
	const [ role, setRole ] = useState( roles[ 0 ] );
	return (
		<EditForm
			legend={ __( 'Assert role', 'newspack-intelligence' ) }
			onSubmit={ () => onSubmit( role ) }
		>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Role', 'newspack-intelligence' ) }
				options={ optionsOf( roles ) }
				value={ role }
				onChange={ setRole }
			/>
		</EditForm>
	);
}

/**
 * One role row: open from its first day, or held between two.
 *
 * @param {Object} role The role, `to_ts` 0 while open.
 * @return {string} The row's text.
 */
function roleText( role ) {
	return 0 === role.to_ts
		? sprintf(
				/* translators: 1: role, 2: first UTC day. */
				__( '%1$s since %2$s', 'newspack-intelligence' ),
				role.role,
				utcDay( role.from_ts )
		  )
		: sprintf(
				/* translators: 1: role, 2: first UTC day, 3: last UTC day. */
				__( '%1$s %2$s – %3$s', 'newspack-intelligence' ),
				role.role,
				utcDay( role.from_ts ),
				utcDay( role.to_ts )
		  );
}

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
 * Entity: one entity's record, its timeline a page at a time, the entities
 * sharing its items and the claims naming it, each from its own read; and,
 * once the record has answered, for a curator, the forms adding a name or
 * identifier and asserting or ending a role. Every edit that lands re-reads
 * the record.
 *
 * @param {Object}                 props
 * @param {string}                 props.entityKey    The entity shown.
 * @param {boolean}                props.canTune      Whether to offer curation.
 * @param {Object}                 props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Entity( {
	entityKey,
	canTune,
	vocabulary,
	onOpenEntity,
	onSave,
} ) {
	const about = [ entityKey ];
	const [ starts, setStarts ] = useState( [ 0 ] );
	const offset = starts[ starts.length - 1 ];
	const record = useIntelRead( 'entity:record', 'entity', about );
	const timeline = useIntelRead(
		'entity:timeline',
		'timeline',
		formatCommandArgs( about, 0 < offset ? { offset } : {} )
	);
	const related = useIntelRead( 'entity:related', 'related', about );
	const claims = useIntelRead( 'entity:claims', 'claims', about );

	const edit = { refresh: record.refresh };
	const addName = useIntelWrite( 'add_name', edit );
	const addIdentifier = useIntelWrite( 'add_identifier', edit );
	const assertRole = useIntelWrite( 'assert_role', edit );
	const endRole = useIntelWrite( 'end_role', edit );
	const edits = [ addName, addIdentifier, assertRole, endRole ];

	return (
		<div className="newspack-intelligence__view">
			<Answer read={ record }>
				{ ( entity ) => (
					<>
						<h2>{ entity.name || entity.entity_key }</h2>
						<p className="newspack-nodes-status is-muted">
							{ metaLine(
								entity.type,
								entity.state,
								entity.entity_key
							) }
						</p>
						{ 0 < entity.merged_from.length && (
							<p>
								{ sprintf(
									/* translators: %s: the keys merged into this entity. */
									__(
										'Merged from %s',
										'newspack-intelligence'
									),
									entity.merged_from.join( ', ' )
								) }
							</p>
						) }
						<p>{ countsText( entity.counts ) }</p>
						<ul className="newspack-intelligence__facts">
							{ entity.names.map( ( name ) => (
								<li
									key={ `name ${ name.kind } ${ name.value }` }
								>
									{ `${ name.kind }: ${ name.value }` }
								</li>
							) ) }
							{ entity.identifiers.map( ( id ) => (
								<li key={ `id ${ id.kind } ${ id.value }` }>
									{ `${ id.kind }: ${ id.value }` }
								</li>
							) ) }
							{ entity.roles.map( ( role ) => (
								<li
									key={ `role ${ role.role } ${ role.from_ts }` }
								>
									{ roleText( role ) }
									{ canTune && 0 === role.to_ts && (
										<button
											type="button"
											className="button button-small"
											onClick={ () =>
												endRole.run( {
													key: entityKey,
													role: role.role,
												} )
											}
										>
											{ sprintf(
												/* translators: %s: the role ended. */
												__(
													'End %s',
													'newspack-intelligence'
												),
												role.role
											) }
										</button>
									) }
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
						{ canTune && (
							<div className="newspack-intelligence__edits">
								<KindValueForm
									legend={ __(
										'Add name',
										'newspack-intelligence'
									) }
									kinds={ vocabulary.nameKinds }
									onSubmit={ ( kind, value ) =>
										addName.run( {
											key: entityKey,
											kind,
											value,
										} )
									}
								/>
								<KindValueForm
									legend={ __(
										'Add identifier',
										'newspack-intelligence'
									) }
									kinds={ vocabulary.identifierKinds }
									onSubmit={ ( kind, value ) =>
										addIdentifier.run( {
											key: entityKey,
											kind,
											value,
										} )
									}
								/>
								<RoleForm
									roles={ vocabulary.roles }
									onSubmit={ ( role ) =>
										assertRole.run( {
											key: entityKey,
											role,
										} )
									}
								/>
							</div>
						) }
						{ edits.map( ( write, i ) => (
							<Refusal key={ i } error={ write.refusal } />
						) ) }
					</>
				) }
			</Answer>

			<Answer
				title={ __( 'Timeline', 'newspack-intelligence' ) }
				level={ 3 }
				read={ timeline }
				rows="items"
				empty={ __(
					'No readable item links this entity.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<>
						<ul className="newspack-intelligence__items">
							{ answer.items.map( ( item ) => (
								<ItemRow
									key={ item.item_key }
									item={ item }
									onOpenEntity={ onOpenEntity }
									onSave={ onSave }
								/>
							) ) }
						</ul>
						<Pager
							starts={ starts }
							onStarts={ setStarts }
							shown={ answer.items.length }
							total={ answer.total }
						/>
					</>
				) }
			</Answer>

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
							<li key={ other.entity_key }>
								<EntityChip
									entityKey={ other.entity_key }
									name={ other.name }
									onOpen={ onOpenEntity }
								/>{ ' ' }
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
							<li key={ `${ claim.item_key } ${ i }` }>
								{ `${ claim.subject } ${ claim.predicate } ${ claim.object } ` }
								<Cite
									url={ claim.url }
									title={ claim.item_key }
								/>
							</li>
						) ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}
