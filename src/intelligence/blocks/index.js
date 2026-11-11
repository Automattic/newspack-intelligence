/**
 * Every block Intelligence's layouts are composed of, by name: the View a
 * page renders and, where it has settings, the Controls the editor's
 * inspector holds. Each name is a row of `Intelligence_Blocks::BLOCKS`. Each
 * View provides the `editing` prop the substrate hands it to every write
 * control inside it (`useWriteControl`), and its jump scope to its headings.
 */

import { useContext } from '@wordpress/element';
import {
	Editing,
	IntelPageContext,
	JumpScope,
	jumpId,
} from '../layout/PageContext';
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

/**
 * A block's view whose View provides its `editing` prop and its jump scope
 * to what it holds.
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
	const Provided = ( props ) => {
		const scope =
			useContext( IntelPageContext ).jumps?.get( props.attributes ) ??
			null;
		return (
			<Editing.Provider value={ props.editing }>
				<JumpScope.Provider value={ scope }>
					<View { ...props } />
				</JumpScope.Provider>
			</Editing.Provider>
		);
	};
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

/**
 * A layout's jump scopes and headings, numbered depth first under the
 * layout's own prefix, so the scopes a page's blocks render under and the
 * links naming their headings agree, and two layouts on a page never share one.
 *
 * @param {import('@newspack-nodes/shared/composition/CompositionView').CompositionNode[]} blocks The layout's blocks.
 * @param {string}                                                                         prefix The layout's own, one `useId()`.
 * @return {{scopes: WeakMap<Object, string>, headings: Array<{text: string, id: string}>}}
 *   Each block's scope, by its attributes, and every heading in the order
 *   it stands, each a container's children in its place.
 */
export function jumpsOf( blocks, prefix ) {
	const scopes = new WeakMap();
	const headings = [];
	let count = 0;
	const walk = ( nodes ) =>
		nodes.forEach( ( node ) => {
			const scope = `${ prefix }jump-${ count++ }`;
			scopes.set( node.attributes, scope );
			( VIEWS[ node.name ]?.headings?.( node.attributes ) ?? [] ).forEach(
				( text ) => headings.push( { text, id: jumpId( scope, text ) } )
			);
			walk( node.inner );
		} );
	walk( blocks );
	return { scopes, headings };
}
