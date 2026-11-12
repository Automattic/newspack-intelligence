/**
 * An item list's table: one column per thing a row's card shows in brief,
 * each drawn as the card draws it. The title cell carries the card's menu,
 * Save and the exclusion within it, its other copies and how it was read,
 * and the Clients cell its pills, removable as the card's are. The summary,
 * the excerpt and why an item ranks stay on the card.
 */

import { __ } from '@wordpress/i18n';
import Cite from './Cite';
import Refusal from './Refusal';
import {
	ItemDay,
	ItemKind,
	ItemSource,
	Pills,
	clientsOf,
	importanceText,
	kindCheckText,
	removerOf,
	todayUtc,
	useCopies,
	useListWrites,
	useRowMenu,
} from './ItemRow';

/** The table's columns by field id, the title first. */
export const COLUMNS = [
	'title',
	'item_kind',
	'item_source',
	'time',
	'clients',
	'importance',
];

/**
 * A cell drawing `Draw` for an item still shown, and nothing for one
 * excluded; `Draw` takes the cell's props and the list's writes.
 *
 * @param {import('react').ComponentType<Object>} Draw The cell's content.
 * @return {import('react').ComponentType<Object>} The cell.
 */
const shown = ( Draw ) =>
	function Cell( props ) {
		const writes = useListWrites();
		return writes.excluded( props.item ) ? null : (
			<Draw { ...props } writes={ writes } />
		);
	};

/**
 * The title cell, one block stacking its parts in the flex row DataViews
 * draws it in: any refusal the row's writes met, the title citing its URL
 * beside the row's menu, which offers Save and the exclusion where the
 * reader may use them, the toggle opening its other copies, and how it was
 * read while that is open; "Excluded." for an item excluded.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item         The item.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves the item; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune.
 * @return {import('react').ReactElement} The cell.
 */
function TitleCell( { item, onOpenEntity, onSave, canTune } ) {
	const writes = useListWrites();
	const copies = useCopies( item, todayUtc() );
	const { menu, drawer } = useRowMenu( item, {
		onOpenEntity,
		onSave,
		onExclude: canTune ? () => writes.exclude( item ) : undefined,
		canTune,
	} );
	if ( writes.excluded( item ) ) {
		return (
			<p className="newspack-nodes-status is-muted" role="status">
				{ __( 'Excluded.', 'newspack-intelligence' ) }
			</p>
		);
	}
	return (
		<div>
			<Refusal error={ writes.refusalOf( item ) } />
			<div className="newspack-intelligence__item-head">
				<strong className="newspack-intelligence__item-title">
					<Cite url={ item.url } title={ item.title } />
				</strong>
				{ menu }
			</div>
			{ copies.toggle && (
				<p className="newspack-nodes-status is-muted">
					{ copies.toggle }
				</p>
			) }
			{ copies.list }
			{ drawer }
		</div>
	);
}

/**
 * An item's open clients as its pills, each removable for a reader holding
 * tune.
 *
 * @param {Object}                props
 * @param {Object}                props.item         The item.
 * @param {Object}                props.writes       The list's writes.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @param {boolean}               [props.canTune]    Whether the reader holds tune.
 * @return {?import('react').ReactElement} The pills.
 */
function ClientPills( { item, writes, onOpenEntity, canTune } ) {
	return (
		<Pills
			lead={ clientsOf( writes.entitiesOf( item ) ) }
			rest={ [] }
			onOpenEntity={ onOpenEntity }
			onRemove={ removerOf( writes, item, canTune ) }
		/>
	);
}

/**
 * The kind cell, one block stacking the kind's badge over its kind check
 * (`kindCheckText()`), as the card's meta line shows both.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The cell.
 */
function KindCell( { item } ) {
	return (
		<div>
			<ItemKind item={ item } />
			{ item.kind_check && (
				<p className="newspack-nodes-status is-muted">
					{ kindCheckText( item.kind_check, todayUtc() ) }
				</p>
			) }
		</div>
	);
}

/**
 * An item's importance in words.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {?string} The words.
 */
const Importance = ( { item } ) => importanceText( item );

/** Each column's cell, by field id. */
const CELLS = {
	title: TitleCell,
	item_kind: shown( KindCell ),
	item_source: shown( ItemSource ),
	time: shown( ItemDay ),
	clients: shown( ClientPills ),
	importance: shown( Importance ),
};

/**
 * The table's fields: each column headed and drawn, none sorted, hidden or
 * moved from its header, since Search's Sort select is the one order.
 *
 * @return {Object[]} The DataViews fields.
 */
export function tableFields() {
	const labels = {
		title: __( 'Title', 'newspack-intelligence' ),
		item_kind: __( 'Kind', 'newspack-intelligence' ),
		item_source: __( 'Source', 'newspack-intelligence' ),
		time: __( 'Date', 'newspack-intelligence' ),
		clients: __( 'Clients', 'newspack-intelligence' ),
		importance: __( 'Importance', 'newspack-intelligence' ),
	};
	return COLUMNS.map( ( id ) => ( {
		id,
		label: labels[ id ],
		render: CELLS[ id ],
		enableHiding: false,
		enableSorting: false,
	} ) );
}
