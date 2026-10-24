import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import Answer from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import Refusal from '../components/Refusal';
import { metaLine } from '../components/format';

/**
 * The control merging one proposed entity into another the curator names.
 *
 * @param {Object}                 props
 * @param {(into: string) => void} props.onMerge  Sends the merge.
 * @param {boolean}                props.disabled While a judgment of the queue waits.
 * @return {import('react').ReactElement} The form.
 */
function MergeForm( { onMerge, disabled } ) {
	const [ into, setInto ] = useState( '' );
	return (
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
			<button type="submit" className="button" disabled={ disabled }>
				{ __( 'Merge', 'newspack-intelligence' ) }
			</button>
		</form>
	);
}

/**
 * A judgment's two buttons, and any further control the row takes.
 *
 * @param {Object}                    props
 * @param {string}                    props.yes        The affirming button's label.
 * @param {() => void}                props.onYes      Affirms.
 * @param {() => void}                props.onNo       Rejects.
 * @param {boolean}                   props.disabled   While a judgment of the queue waits.
 * @param {import('react').ReactNode} [props.children] A further control.
 * @return {import('react').ReactElement} The buttons.
 */
function Judge( { yes, onYes, onNo, disabled, children } ) {
	return (
		<span className="newspack-intelligence__judge">
			<button
				type="button"
				className="button"
				onClick={ onYes }
				disabled={ disabled }
			>
				{ yes }
			</button>
			<button
				type="button"
				className="button button-link-delete"
				onClick={ onNo }
				disabled={ disabled }
			>
				{ __( 'Reject', 'newspack-intelligence' ) }
			</button>
			{ children }
		</span>
	);
}

/**
 * Review: the proposed links and the proposed entities, each row with the
 * items citing it, and for a curator the judgments each takes — confirm or
 * reject a link; accept, reject or merge an entity. A judgment that lands
 * re-reads its queue, a refused one shows the refusal, and a queue's rows
 * hold while a judgment of it waits.
 *
 * @param {Object}                props
 * @param {boolean}               props.canTune      Whether to offer judgments.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {import('react').ReactElement} The view.
 */
export default function Review( { canTune, onOpenEntity } ) {
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
						{ answer.links.map( ( link ) => {
							const pair = {
								item: link.item_key,
								entity: link.entity_key,
							};
							return (
								<li
									key={ `${ link.item_key } ${ link.entity_key }` }
									className="newspack-intelligence__item"
								>
									<div className="newspack-intelligence__item-head">
										<strong>
											<Cite
												url={ link.url }
												title={ link.title }
											/>
										</strong>
										<EntityChip
											entityKey={ link.entity_key }
											name={ link.name }
											onOpen={ onOpenEntity }
										/>
									</div>
									<p className="newspack-nodes-status is-muted">
										{ metaLine(
											link.role,
											link.method,
											link.confidence
										) }
									</p>
									{ canTune && (
										<Judge
											yes={ __(
												'Confirm',
												'newspack-intelligence'
											) }
											onYes={ () =>
												confirmLink.run( pair )
											}
											onNo={ () =>
												rejectLink.run( pair )
											}
											disabled={ linkWaits }
										/>
									) }
								</li>
							);
						} ) }
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
										<EntityChip
											entityKey={ key }
											name={ entity.name }
											onOpen={ onOpenEntity }
										/>
										<span className="newspack-nodes-status is-muted">
											{ metaLine( entity.type, key ) }
										</span>
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
									{ canTune && (
										<Judge
											yes={ __(
												'Accept',
												'newspack-intelligence'
											) }
											onYes={ () =>
												acceptEntity.run( { key } )
											}
											onNo={ () =>
												rejectEntity.run( { key } )
											}
											disabled={ entityWaits }
										>
											<MergeForm
												onMerge={ ( into ) =>
													mergeEntities.run( {
														from: key,
														into,
													} )
												}
												disabled={ entityWaits }
											/>
										</Judge>
									) }
								</li>
							);
						} ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}
