/**
 * A composed page's layout as a reader reads it: each block by its view
 * under the page it stands on, the aside apart so it can stand in a column
 * of its own, and a layout that no longer reads as the shared read error in
 * its place.
 *
 * The reader half of Intelligence's layouts: it imports no block library.
 */

import CompositionView from '@newspack-nodes/shared/composition/CompositionView';
import { Unread } from '../components/Answer';
import { BLOCK_VIEWS } from '../blocks';
import { IntelPageContext } from './PageContext';

/** The one container, which stands beside the main column. */
const ASIDE = 'newspack-intelligence/aside';

/** Read the page again, the one way to read its layouts again. */
const reload = () => window.location.reload();

/**
 * A block that could not render, in its own place.
 *
 * @param {Error} error Why.
 * @return {import('react').ReactElement} The read error.
 */
const renderError = ( error ) => (
	<Unread error={ error.message } refresh={ reload } />
);

/**
 * @param {Object}                            props
 * @param {Object}                            props.layout What the page data or a save answered for the page's context.
 * @param {import('./PageContext').IntelPage} props.page   The page the blocks stand on.
 * @return {?import('react').ReactElement} The layout, or nothing for an empty one.
 */
export default function PageLayout( { layout, page } ) {
	if ( layout.error ) {
		return <Unread error={ layout.error } refresh={ reload } />;
	}
	if ( 0 === layout.blocks.length ) {
		return null;
	}
	const main = layout.blocks.filter( ( node ) => ASIDE !== node.name );
	const aside = layout.blocks.filter( ( node ) => ASIDE === node.name );
	return (
		<IntelPageContext.Provider value={ page }>
			<div className="newspack-intelligence__layout">
				<div className="newspack-intelligence__layout-main">
					<CompositionView
						blocks={ main }
						views={ BLOCK_VIEWS }
						renderError={ renderError }
					/>
				</div>
				<CompositionView
					blocks={ aside }
					views={ BLOCK_VIEWS }
					renderError={ renderError }
				/>
			</div>
		</IntelPageContext.Provider>
	);
}
