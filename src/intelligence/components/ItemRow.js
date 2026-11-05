import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Cite, { EntityChip } from './Cite';
import Pager from './Pager';
import { metaLine, utcDay } from './format';

/**
 * One item as `search` and `timeline` answer it: its title citing its URL,
 * its source, kind and day, its summary, a toggle showing its excerpt (`Excerpt`),
 * a chip per linked entity but a proposal another of its name stands for
 * (`chips()`), and, for a caller who may curate, the control saving it to a collection, then any control the
 * view adds.
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
			<Excerpt item={ item } />
			{ 0 < ( item.entities ?? [] ).length && (
				<ul className="newspack-intelligence__chips">
					{ chips( item.entities ).map( ( entity ) => (
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
 * The body's opening behind a toggle, collapsed at first; none when it is
 * empty or says nothing its summary or title does not (`repeats()`).
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {?import('react').ReactElement} The toggle and the excerpt it shows.
 */
function Excerpt( { item } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! item.excerpt || repeats( item ) ) {
		return null;
	}
	return (
		<>
			<button
				type="button"
				className="button button-small"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ open
					? __( 'Show less', 'newspack-intelligence' )
					: __( 'Show more', 'newspack-intelligence' ) }
			</button>
			{ open && <p>{ item.excerpt }</p> }
		</>
	);
}

/**
 * Whether an item's summary or title already holds its whole excerpt, case
 * aside.
 *
 * @param {Object} item The item.
 * @return {boolean} Whether the excerpt adds nothing.
 */
function repeats( item ) {
	const excerpt = item.excerpt.toLowerCase();
	return [ item.summary, item.title ].some( ( shown ) =>
		( shown ?? '' ).toLowerCase().includes( excerpt )
	);
}

/**
 * An item's entities as chips, in the answer's order, a proposal left out
 * when another entity of its name is not `proposed`, and only the first of
 * several proposals of one name kept; two entities that are not proposed
 * keep a chip each, whatever their names.
 *
 * @param {Object[]} entities The item's entities, in the answer's order.
 * @return {Object[]} The entities to chip.
 */
function chips( entities ) {
	const label = ( entity ) => entity.name || entity.entity_key;
	const shown = new Set(
		entities
			.filter( ( entity ) => 'proposed' !== entity.state )
			.map( label )
	);
	return entities.filter( ( entity ) => {
		if ( 'proposed' !== entity.state ) {
			return true;
		}
		if ( shown.has( label( entity ) ) ) {
			return false;
		}
		shown.add( label( entity ) );
		return true;
	} );
}

/**
 * Each order `search` answers in: what a page in it says, the order the
 * switch offers instead, and the switch's label.
 *
 * @return {Object<string, {shown: string, other: string, label: string}>} The orders.
 */
const sorts = () => ( {
	importance: {
		shown: __( 'Ranked by importance', 'newspack-intelligence' ),
		other: 'time',
		label: __( 'Show newest first', 'newspack-intelligence' ),
	},
	time: {
		shown: __( 'Newest first', 'newspack-intelligence' ),
		other: 'importance',
		label: __( 'Rank by importance', 'newspack-intelligence' ),
	},
} );

/**
 * One page of the items a read answered, each an `ItemRow`, then the pager
 * over the page starts its view holds; given `onSort`, the order the answer
 * says it is in, and the switch to the other.
 *
 * @param {Object}                                          props
 * @param {{items: Object[], total: number, sort: ?string}} props.answer       The read's answer.
 * @param {number[]}                                        props.starts       Each page's first row, the current last.
 * @param {(starts: number[]) => void}                      props.onStarts     Replaces the stack.
 * @param {(key: string) => void}                           props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                          [props.onSave]     Saves an item; absent for a reader.
 * @param {(sort: string) => void}                          [props.onSort]     Asks for the other order; absent for a page that does not sort.
 * @return {import('react').ReactElement} The page.
 */
export function ItemPage( {
	answer,
	starts,
	onStarts,
	onOpenEntity,
	onSave,
	onSort,
} ) {
	const order = onSort && sorts()[ answer.sort ];
	return (
		<>
			{ order && (
				<p className="newspack-nodes-status is-muted">
					<span>{ order.shown }</span>{ ' ' }
					<button
						type="button"
						className="button button-small"
						onClick={ () => onSort( order.other ) }
					>
						{ order.label }
					</button>
				</p>
			) }
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
