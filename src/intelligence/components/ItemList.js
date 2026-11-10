/**
 * The one DataViews list every item list draws: each item by its row in the
 * layout the view names, the caller's own words when there is none, and a
 * pager only when there is a second page.
 */

import { Button, CheckboxControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews/wp';
import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import ItemRow from './ItemRow';

/** The one field every layout draws an item by. */
const ROW = 'item';

/** What the one cell draws each row with, set by the list around it. */
const RowContext = createContext( null );

/**
 * An item's key, which DataViews tells rows and selections apart by.
 *
 * @param {Object} item The item.
 * @return {string} Its key.
 */
const itemKey = ( item ) => item.item_key;

/**
 * One item's row, as the list names it, after its checkbox where the list
 * checks rows. A component of its own, so every render keeps the row.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The row.
 */
function ItemCell( { item } ) {
	const {
		Row,
		rowProps,
		selectLabel,
		getItemId,
		selection,
		onChangeSelection,
	} = useContext( RowContext );
	const row = <Row item={ item } { ...rowProps } />;
	if ( ! selectLabel ) {
		return row;
	}
	const id = getItemId( item );
	const checked = selection.includes( id );
	return (
		<div className="newspack-intelligence__checkable">
			<CheckboxControl
				aria-label={ selectLabel( item ) }
				checked={ checked }
				onChange={ () =>
					onChangeSelection(
						checked
							? selection.filter( ( one ) => id !== one )
							: [ ...selection, id ]
					)
				}
			/>
			{ row }
		</div>
	);
}

/**
 * "Page %1$d of %2$d" with Previous and Next, drawn only past one page.
 *
 * @param {Object}                 props
 * @param {Object}                 props.view         The view.
 * @param {(view: Object) => void} props.onChangeView Moves the view.
 * @param {number}                 props.totalPages   The pages there are.
 * @return {?import('react').ReactElement} The pager.
 */
function ItemPager( { view, onChangeView, totalPages } ) {
	if ( totalPages <= 1 ) {
		return null;
	}
	const page = view.page ?? 1;
	const go = ( to ) => onChangeView( { ...view, page: to } );
	return (
		<div className="newspack-intelligence__pager">
			<span className="newspack-nodes-status is-muted">
				{ sprintf(
					/* translators: 1: this page, 2: the pages there are. */
					__( 'Page %1$d of %2$d', 'newspack-intelligence' ),
					page,
					totalPages
				) }
			</span>
			{ 1 < page && (
				<Button variant="secondary" onClick={ () => go( page - 1 ) }>
					{ __( 'Previous', 'newspack-intelligence' ) }
				</Button>
			) }
			{ page < totalPages && (
				<Button variant="secondary" onClick={ () => go( page + 1 ) }>
					{ __( 'Next', 'newspack-intelligence' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * `<ItemList items view onChangeView layout empty paginationInfo actions?
 * render? rowProps? label? fields? header? getItemId? selection?
 * onChangeSelection? selectLabel? />`: DataViews over the items, keyed by
 * `getItemId`, `item_key` unless named. `layout` names one layout, or several
 * with the first the default; `view.type` picks among them. Each item draws
 * as `render` (a component taking `item` and `rowProps`), ItemRow by
 * default, in the layout's title place, headed `label`, so `fields` adds
 * only further columns. `actions`
 * pass through, `supportsBulk` and `isPrimary` with them. `header` stands
 * above the list inside DataViews, where `DataViews.Search`,
 * `DataViews.FiltersToggle` and `DataViews.FiltersToggled` read the view;
 * any other DataViews prop passes through.
 *
 * Given `selectLabel`, the rows keep their own controls and the actions act
 * on a checked run alone: each row draws a checkbox named `selectLabel(
 * item )` that adds it to `selection` or takes it out, through
 * `onChangeSelection`; `DataViews.BulkActionToolbar` stands above the list;
 * and the list carries `newspack-intelligence__run-only`, which hides the
 * action cells DataViews draws in each row.
 *
 * @typedef {Object} ItemListProps
 * @property {Object[]}                                      items               The page's items.
 * @property {Object}                                        view                The DataViews view: `type`, `page`, `perPage`, and any `sort`, `search`, `filters` or `groupBy`.
 * @property {(view: Object) => void}                        onChangeView        Takes the view moved.
 * @property {string | string[]}                             [layout]            `list`, `activity` or `table`, or several, the first the default.
 * @property {import('react').ReactNode}                     empty               What an empty list says, in place of DataViews' own.
 * @property {{totalItems: number, totalPages: number}}      paginationInfo      The server's total, and the pages it makes.
 * @property {Object[]}                                      [actions]           DataViews actions.
 * @property {import('react').ComponentType<{item: Object}>} [render]            The row.
 * @property {Object}                                        [rowProps]          Props each row takes beside its item.
 * @property {string}                                        [label]             The row column's heading, "Item" unless named.
 * @property {Object[]}                                      [fields]            Further DataViews fields, as columns or filters.
 * @property {import('react').ReactNode}                     [header]            Controls above the list.
 * @property {(item: Object) => string}                      [getItemId]         An item's key, its `item_key` unless named.
 * @property {string[]}                                      [selection]         The keys of the checked run.
 * @property {(selection: string[]) => void}                 [onChangeSelection] Takes the run changed.
 * @property {(item: Object) => string}                      [selectLabel]       Names a row's checkbox; given, the rows are checked into a run.
 *
 * @param    {ItemListProps & Object<string, any>}           props               Its own props, and any DataViews prop, which passes through.
 * @return {import('react').ReactElement} The list.
 */
export default function ItemList( {
	items,
	view,
	onChangeView,
	layout = 'list',
	empty,
	paginationInfo,
	actions = [],
	render: Row = ItemRow,
	rowProps = {},
	label = __( 'Item', 'newspack-intelligence' ),
	fields = [],
	header = null,
	getItemId = itemKey,
	selection,
	onChangeSelection,
	selectLabel,
	...rest
} ) {
	const layouts = [].concat( layout );
	return (
		<RowContext.Provider
			value={ {
				Row,
				rowProps,
				selectLabel,
				getItemId,
				selection,
				onChangeSelection,
			} }
		>
			<DataViews
				data={ items }
				getItemId={ getItemId }
				view={ { ...view, titleField: ROW } }
				onChangeView={ onChangeView }
				fields={ [
					{
						id: ROW,
						label,
						enableHiding: false,
						enableSorting: false,
						render: ItemCell,
					},
					...fields,
				] }
				defaultLayouts={ Object.fromEntries(
					layouts.map( ( type ) => [ type, {} ] )
				) }
				actions={ actions }
				paginationInfo={ paginationInfo }
				empty={ empty }
				selection={ selection }
				onChangeSelection={ onChangeSelection }
				{ ...rest }
			>
				{ header }
				{ selectLabel && <DataViews.BulkActionToolbar /> }
				<DataViews.Layout
					className={
						selectLabel
							? 'newspack-intelligence__run-only'
							: undefined
					}
				/>
				<ItemPager
					view={ view }
					onChangeView={ onChangeView }
					totalPages={ paginationInfo.totalPages }
				/>
			</DataViews>
		</RowContext.Provider>
	);
}
