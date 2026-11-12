/**
 * Every block Intelligence's layouts are composed of, by name: the View a
 * page renders and, where it has settings, the Controls the editor's
 * inspector holds. Each name is a row of `Intelligence_Blocks::BLOCKS`. Each
 * View provides the `editing` prop the substrate hands it to every write
 * control inside it (`useWriteControl`).
 */

import { Editing } from '../layout/PageContext';
import aside from './aside';
import health from './health';
import ask from './ask';
import since from './since';
import needsAttention from './needs-attention';
import watches from './watches';
import week from './week';
import pulse from './pulse';
import entityFacts from './entity-facts';
import related from './related';
import timeline from './timeline';
import trendChart from './trend-chart';
import itemList from './item-list';
import folderBoard from './folder-board';

/**
 * A block's view.
 *
 * @typedef {import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView} IntelBlockView
 */

/**
 * A block's view whose View provides its `editing` prop to what it holds.
 *
 * @param {IntelBlockView} view The block's view.
 * @return {IntelBlockView} The view.
 */
function provided( view ) {
	const { View } = view;
	/**
	 * @param {{attributes: Object, editing: boolean, children?: import('react').ReactNode}} props The View's props.
	 * @return {import('react').ReactNode} The block.
	 */
	const Provided = ( props ) => (
		<Editing.Provider value={ props.editing }>
			<View { ...props } />
		</Editing.Provider>
	);
	return { ...view, View: Provided };
}

/** @type {Object<string,IntelBlockView>} */
const VIEWS = {
	'newspack-intelligence/aside': aside,
	'newspack-intelligence/health': health,
	'newspack-intelligence/ask': ask,
	'newspack-intelligence/since': since,
	'newspack-intelligence/needs-attention': needsAttention,
	'newspack-intelligence/watches': watches,
	'newspack-intelligence/week': week,
	'newspack-intelligence/pulse': pulse,
	'newspack-intelligence/entity-facts': entityFacts,
	'newspack-intelligence/related': related,
	'newspack-intelligence/timeline': timeline,
	'newspack-intelligence/trend-chart': trendChart,
	'newspack-intelligence/item-list': itemList,
	'newspack-intelligence/folder-board': folderBoard,
};

/** @type {Object<string,IntelBlockView>} */
export const BLOCK_VIEWS = Object.fromEntries(
	Object.entries( VIEWS ).map( ( [ name, view ] ) => [
		name,
		provided( view ),
	] )
);
