import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { archive } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import Cite, { EntityChip } from './Cite';
import Pager from './Pager';
import { metaLine, utcDay } from './format';

/**
 * The control saving an item to a folder, for a caller who may curate;
 * nothing for a reader.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item     The item.
 * @param {(item: Object) => void} [props.onSave] Saves the item; absent for a reader.
 * @return {?import('react').ReactElement} The button.
 */
export function SaveButton( { item, onSave } ) {
	return onSave ? (
		<Button
			size="small"
			icon={ archive }
			className="newspack-intelligence__save"
			label={ __( 'Save to folder', 'newspack-intelligence' ) }
			onClick={ () => onSave( item ) }
		/>
	) : null;
}

/**
 * One item as `search` and `timeline` answer it: its title citing its URL,
 * its source, kind and day, its summary, a toggle showing its excerpt (`Excerpt`),
 * its entities as chips (`Chips`), and, for a caller who may curate, the
 * control saving it to a folder, then any control the view adds.
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
				<SaveButton item={ item } onSave={ onSave } />
				{ children }
			</div>
			<p className="newspack-nodes-status is-muted">
				{ metaLine( item.source, item.kind, utcDay( item.item_ts ) ) }
			</p>
			{ item.summary && <p>{ item.summary }</p> }
			<Excerpt item={ item } />
			<Chips
				entities={ item.entities ?? [] }
				onOpenEntity={ onOpenEntity }
			/>
		</li>
	);
}

/** The link roles naming an entity an item is about, as the server spells them. */
const NAMING_ROLES = [ 'subject', 'mention' ];

/**
 * Whether an entity leads an item's chips: an open client, or a subject.
 *
 * @param {{client: boolean, role: string}} entity The entity.
 * @return {boolean} Whether it leads.
 */
const leads = ( entity ) => entity.client || 'subject' === entity.role;

/**
 * An item's entities as chips (`chips()`): each it is about as a client or a
 * subject, then its mentions behind a "+N more" toggle; none for an entity
 * linked by a role that does not name it.
 *
 * @param {Object}                props
 * @param {Object[]}              props.entities     The item's entities, in the answer's order.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {?import('react').ReactElement} The chips.
 */
function Chips( { entities, onOpenEntity } ) {
	const [ open, setOpen ] = useState( false );
	const named = chips( entities ).filter( ( entity ) =>
		NAMING_ROLES.includes( entity.role )
	);
	if ( 0 === named.length ) {
		return null;
	}
	const lead = named.filter( leads );
	const rest = named.filter( ( entity ) => ! leads( entity ) );
	return (
		<div className="newspack-intelligence__actions">
			<ul className="newspack-intelligence__chips">
				{ ( open ? [ ...lead, ...rest ] : lead ).map( ( entity ) => (
					<li key={ entity.entity_key }>
						<EntityChip
							entityKey={ entity.entity_key }
							name={ entity.name }
							onOpen={ onOpenEntity }
						/>
					</li>
				) ) }
			</ul>
			{ 0 < rest.length && (
				<button
					type="button"
					className="button-link"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ open
						? __( 'Show fewer', 'newspack-intelligence' )
						: sprintf(
								/* translators: %d: entities the item mentions, hidden. */
								__( '+%d more', 'newspack-intelligence' ),
								rest.length
						  ) }
				</button>
			) }
		</div>
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
			<Button
				variant="link"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ open
					? __( 'Show less', 'newspack-intelligence' )
					: __( 'Show more', 'newspack-intelligence' ) }
			</Button>
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
