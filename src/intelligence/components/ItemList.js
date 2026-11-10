/**
 * The substrate's DataViews list, drawing each item as its ItemRow and
 * keying it by its `item_key`.
 */

import DataViewsList from '@newspack-nodes/shared/components/DataViewsList';
import ItemRow from './ItemRow';

/**
 * An item's key, which DataViews tells rows and selections apart by.
 *
 * @param {Object} item The item.
 * @return {string} Its key.
 */
const itemKey = ( item ) => item.item_key;

/**
 * `<ItemList … />`: `DataViewsList` over the items, each drawn as `render`,
 * ItemRow unless named, and keyed by `getItemId`, the item's `item_key`
 * unless named. Every other prop is `DataViewsList`'s, and passes through.
 *
 * @typedef {import('@newspack-nodes/shared/components/DataViewsList').DataViewsListProps} DataViewsListProps
 * @typedef {Omit<DataViewsListProps, 'render'> & Partial<Pick<DataViewsListProps, 'render'>>} ItemListProps
 *
 * @param {ItemListProps & Object<string, any>} props `DataViewsList`'s props, `render` optional.
 * @return {import('react').ReactElement} The list.
 */
export default function ItemList( {
	render = ItemRow,
	getItemId = itemKey,
	...rest
} ) {
	return (
		<DataViewsList render={ render } getItemId={ getItemId } { ...rest } />
	);
}
