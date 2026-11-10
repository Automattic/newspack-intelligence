/**
 * The substrate's DataViews list over items, each drawn as its ItemRow, the
 * rows sharing their list's writes.
 */

import DataViewsList from '@newspack-nodes/shared/components/DataViewsList';
import ItemRow, { ItemRows } from './ItemRow';
import { itemKey } from './format';

/**
 * `<ItemList … />`: `DataViewsList` over the items inside `ItemRows`, each
 * drawn as `render`, ItemRow unless named, and keyed by its `item_key`.
 * `deferred` is the undo window a list taking `z` holds, which its rows'
 * writes join. Every other prop is `DataViewsList`'s, and passes through.
 *
 * @typedef {import('@newspack-nodes/shared/components/DataViewsList').DataViewsListProps} DataViewsListProps
 * @typedef {Omit<DataViewsListProps, 'render' | 'getItemId'> & Partial<Pick<DataViewsListProps, 'render'>> & {deferred?: Object}} ItemListProps
 *
 * @param {ItemListProps & Object<string, any>} props `DataViewsList`'s props, `render` optional.
 * @return {import('react').ReactElement} The list.
 */
export default function ItemList( { render = ItemRow, deferred, ...rest } ) {
	return (
		<ItemRows items={ rest.items } deferred={ deferred }>
			<DataViewsList
				render={ render }
				getItemId={ itemKey }
				{ ...rest }
			/>
		</ItemRows>
	);
}
