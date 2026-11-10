/**
 * Every block Intelligence's layouts are composed of, by name: the View a
 * page renders and, where it has settings, the Controls the editor's
 * inspector holds. Each name is a row of `Intelligence_Blocks::BLOCKS`.
 */

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
 * A block's view, and the headings it shows for a profile's jump links.
 *
 * @typedef {import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView & {headings?: (attributes: Object) => string[]}} IntelBlockView
 */

/** @type {Object<string,IntelBlockView>} */
export const BLOCK_VIEWS = {
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

/**
 * The headings a layout's blocks show, in the order they stand, each a
 * container's children in its place: what a profile's jump links name.
 *
 * @param {import('@newspack-nodes/shared/composition/CompositionView').CompositionNode[]} blocks The layout's blocks.
 * @return {string[]} The headings.
 */
export const headingsOf = ( blocks ) =>
	blocks.flatMap( ( node ) => [
		...( BLOCK_VIEWS[ node.name ]?.headings?.( node.attributes ) ?? [] ),
		...headingsOf( node.inner ),
	] );
