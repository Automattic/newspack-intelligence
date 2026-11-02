import { __ } from '@wordpress/i18n';
import Cite, { EntityChip } from './Cite';
import Pager from './Pager';
import { metaLine, utcDay } from './format';

/**
 * One item as `search` and `timeline` answer it: its title citing its URL,
 * its source, kind and day, its summary, a chip per linked entity, and, for a
 * caller who may curate, the control saving it to a collection, then any
 * control the view adds.
 *
 * @param {Object}                    props
 * @param {Object}                    props.item         The item.
 * @param {(key: string) => void}     props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}    [props.onSave]     Saves the item; absent for a reader.
 * @param {import('react').ReactNode} [props.children]   The view's own controls for the item.
 * @return {import('react').ReactElement} The row.
 */
export default function ItemRow( { item, onOpenEntity, onSave, children } ) {
	return (
		<li className="newspack-intelligence__item">
			<div className="newspack-intelligence__item-head">
				<strong>
					<Cite url={ item.url } title={ item.title } />
				</strong>
				{ onSave && (
					<button
						type="button"
						className="button button-small"
						onClick={ () => onSave( item ) }
					>
						{ __( 'Save to collection', 'newspack-intelligence' ) }
					</button>
				) }
				{ children }
			</div>
			<p className="newspack-nodes-status is-muted">
				{ metaLine( item.source, item.kind, utcDay( item.item_ts ) ) }
			</p>
			{ item.summary && <p>{ item.summary }</p> }
			{ 0 < ( item.entities ?? [] ).length && (
				<ul className="newspack-intelligence__chips">
					{ item.entities.map( ( entity ) => (
						<li key={ entity.entity_key }>
							<EntityChip
								entityKey={ entity.entity_key }
								name={ entity.name }
								onOpen={ onOpenEntity }
							/>
						</li>
					) ) }
				</ul>
			) }
		</li>
	);
}

/**
 * One page of the items a read answered, each an `ItemRow`, then the pager
 * over the page starts its view holds.
 *
 * @param {Object}                           props
 * @param {{items: Object[], total: number}} props.answer       The read's answer.
 * @param {number[]}                         props.starts       Each page's first row, the current last.
 * @param {(starts: number[]) => void}       props.onStarts     Replaces the stack.
 * @param {(key: string) => void}            props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}           [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The page.
 */
export function ItemPage( { answer, starts, onStarts, onOpenEntity, onSave } ) {
	return (
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
				onStarts={ onStarts }
				shown={ answer.items.length }
				total={ answer.total }
			/>
		</>
	);
}
