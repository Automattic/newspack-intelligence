import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { Icon, check, closeSmall } from '@wordpress/icons';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import Refusal from '../components/Refusal';
import { metaLine } from '../components/format';

/**
 * A proposed link in words: what the entity is to the item, how it was
 * found and how sure the finding was, in the words the page localizes. A
 * role or method the words leave out reads as given.
 *
 * @param {Object} link       The proposed link.
 * @param {Object} vocabulary The localized vocabulary.
 * @return {string} The sentence.
 */
function howFound( link, vocabulary ) {
	return sprintf(
		/* translators: 1: what the entity is to the item, 2: how it was found, 3: confidence, 0 to 100. */
		__( '%1$s, found by %2$s (%3$d%%)', 'newspack-intelligence' ),
		vocabulary.linkRoleWords?.[ link.role ] ?? link.role,
		vocabulary.methodWords?.[ link.method ] ?? link.method,
		Math.round( Number( link.confidence ) * 100 )
	);
}

/**
 * One proposal as a single control: the entity's chip, then for a curator
 * the affirming and rejecting glyphs, joined into one pill.
 *
 * A curator's `judge` names the affirming glyph for the entity (`yes`),
 * affirms (`onYes`), rejects (`onNo`), and is `disabled` while a judgment
 * of the queue waits.
 *
 * @param {Object}                                                                                          props
 * @param {string}                                                                                          props.entityKey     The entity's key.
 * @param {?string}                                                                                         props.name          Its name.
 * @param {string}                                                                                          [props.description] What the proposal rests on.
 * @param {(key: string) => void}                                                                           props.onOpen        Opens the entity.
 * @param {?{yes: (label: string) => string, onYes: () => void, onNo: () => void, disabled: boolean}|false} props.judge         A curator's judgment, or none.
 * @return {import('react').ReactElement} The pill.
 */
function Judgment( { entityKey, name, description, onOpen, judge } ) {
	const label = name || entityKey;
	return (
		<span className="newspack-intelligence__judgment">
			<EntityChip
				entityKey={ entityKey }
				name={ name }
				description={ description }
				onOpen={ onOpen }
			/>
			{ judge && (
				<>
					<button
						type="button"
						className="button button-small"
						aria-label={ judge.yes( label ) }
						onClick={ judge.onYes }
						disabled={ judge.disabled }
					>
						<Icon icon={ check } size={ 18 } />
					</button>
					<button
						type="button"
						className="button button-small"
						aria-label={ sprintf(
							/* translators: %s: the entity's name. */
							__( 'Reject %s', 'newspack-intelligence' ),
							label
						) }
						onClick={ judge.onNo }
						disabled={ judge.disabled }
					>
						<Icon icon={ closeSmall } size={ 18 } />
					</button>
				</>
			) }
		</span>
	);
}

/**
 * The control merging one proposed entity into another the curator names,
 * closed until asked for.
 *
 * @param {Object}                 props
 * @param {string}                 props.label    The entity's name.
 * @param {(into: string) => void} props.onMerge  Sends the merge.
 * @param {boolean}                props.disabled While a judgment of the queue waits.
 * @return {import('react').ReactElement} The control.
 */
function Merge( { label, onMerge, disabled } ) {
	const [ open, setOpen ] = useState( false );
	const [ into, setInto ] = useState( '' );
	return (
		<>
			<button
				type="button"
				className="button button-small"
				aria-expanded={ open }
				aria-label={ sprintf(
					/* translators: %s: the entity's name. */
					__( 'Merge %s…', 'newspack-intelligence' ),
					label
				) }
				onClick={ () => setOpen( ! open ) }
				disabled={ disabled }
			>
				{ __( 'Merge…', 'newspack-intelligence' ) }
			</button>
			{ open && (
				<form
					className="newspack-intelligence__merge"
					{ ...submitProps( () => onMerge( into ) ) }
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Merge into', 'newspack-intelligence' ) }
						placeholder="organization:the-valley-times"
						value={ into }
						onChange={ setInto }
					/>
					<button
						type="submit"
						className="button"
						disabled={ disabled }
					>
						{ __( 'Merge', 'newspack-intelligence' ) }
					</button>
				</form>
			) }
		</>
	);
}

/**
 * The proposed links as one row per item, in the order the queue answers
 * them, each row holding the links proposed for that item.
 *
 * @param {Array<Object>} links The queue's links.
 * @return {Array<Array<Object>>} The links, grouped by item.
 */
function byItem( links ) {
	const rows = new Map();
	for ( const link of links ) {
		const row = rows.get( link.item_key ) ?? [];
		row.push( link );
		rows.set( link.item_key, row );
	}
	return [ ...rows.values() ];
}

/**
 * Review: the proposed links and the proposed entities, each row with the
 * items citing it, and for a curator the judgments each takes — confirm or
 * reject a link; accept, reject or merge an entity. A link row is one item
 * with a pill per entity proposed for it. A judgment that lands re-reads its
 * queue, a refused one shows the refusal, and a queue's rows hold while a
 * judgment of it waits.
 *
 * @param {Object}                props
 * @param {boolean}               props.canTune      Whether to offer judgments.
 * @param {Object}                props.vocabulary   The localized vocabulary.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {import('react').ReactElement} The view.
 */
export default function Review( { canTune, vocabulary, onOpenEntity } ) {
	const links = useIntelRead( 'review:links', 'review', [ 'links' ] );
	const entities = useIntelRead( 'review:entities', 'review', [
		'entities',
	] );

	const relink = { refresh: links.refresh };
	const rejudge = { refresh: entities.refresh };
	const confirmLink = useIntelWrite( 'confirm_link', relink );
	const rejectLink = useIntelWrite( 'reject_link', relink );
	const acceptEntity = useIntelWrite( 'accept_entity', rejudge );
	const rejectEntity = useIntelWrite( 'reject_entity', rejudge );
	const mergeEntities = useIntelWrite( 'merge_entities', rejudge );
	const linkJudgments = [ confirmLink, rejectLink ];
	const entityJudgments = [ acceptEntity, rejectEntity, mergeEntities ];
	const linkWaits = linkJudgments.some( ( write ) => write.pending );
	const entityWaits = entityJudgments.some( ( write ) => write.pending );

	return (
		<div className="newspack-intelligence__view">
			{ [ ...linkJudgments, ...entityJudgments ].map( ( write, i ) => (
				<Refusal key={ i } error={ write.refusal } />
			) ) }
			<Answer
				title={ __( 'Proposed links', 'newspack-intelligence' ) }
				read={ links }
				rows="links"
				empty={ __(
					'No proposed link awaits review.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__items">
						{ byItem( answer.links ).map( ( row ) => (
							<li
								key={ row[ 0 ].item_key }
								className="newspack-intelligence__item"
							>
								<strong>
									<Cite
										url={ row[ 0 ].url }
										title={ row[ 0 ].title }
									/>
								</strong>
								<div className="newspack-intelligence__judgments">
									{ row.map( ( link ) => {
										const pair = {
											item: link.item_key,
											entity: link.entity_key,
										};
										return (
											<Judgment
												key={ link.entity_key }
												entityKey={ link.entity_key }
												name={ link.name }
												description={ howFound(
													link,
													vocabulary
												) }
												onOpen={ onOpenEntity }
												judge={
													canTune && {
														yes: ( label ) =>
															sprintf(
																/* translators: %s: the entity's name. */
																__(
																	'Confirm %s',
																	'newspack-intelligence'
																),
																label
															),
														onYes: () =>
															confirmLink.run(
																pair
															),
														onNo: () =>
															rejectLink.run(
																pair
															),
														disabled: linkWaits,
													}
												}
											/>
										);
									} ) }
								</div>
							</li>
						) ) }
					</ul>
				) }
			</Answer>
			<Answer
				title={ __( 'Proposed entities', 'newspack-intelligence' ) }
				read={ entities }
				rows="entities"
				empty={ __(
					'No proposed entity awaits review.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__items">
						{ answer.entities.map( ( entity ) => {
							const key = entity.entity_key;
							return (
								<li
									key={ key }
									className="newspack-intelligence__item"
								>
									<div className="newspack-intelligence__item-head">
										<Judgment
											entityKey={ key }
											name={ entity.name }
											onOpen={ onOpenEntity }
											judge={
												canTune && {
													yes: ( label ) =>
														sprintf(
															/* translators: %s: the entity's name. */
															__(
																'Accept %s',
																'newspack-intelligence'
															),
															label
														),
													onYes: () =>
														acceptEntity.run( {
															key,
														} ),
													onNo: () =>
														rejectEntity.run( {
															key,
														} ),
													disabled: entityWaits,
												}
											}
										/>
										<span className="newspack-nodes-status is-muted">
											{ metaLine( entity.type, key ) }
										</span>
										{ canTune && (
											<Merge
												label={ entity.name || key }
												onMerge={ ( into ) =>
													mergeEntities.run( {
														from: key,
														into,
													} )
												}
												disabled={ entityWaits }
											/>
										) }
									</div>
									<ul className="newspack-intelligence__facts">
										{ entity.items.map( ( item ) => (
											<li key={ item.item_key }>
												<Cite
													url={ item.url }
													title={ item.title }
												/>
											</li>
										) ) }
									</ul>
								</li>
							);
						} ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}
