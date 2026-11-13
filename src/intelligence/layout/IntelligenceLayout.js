/**
 * A composed Intelligence page's layout, laid out by the substrate's frame:
 * Intelligence's blocks under the page they stand on, sent for its command
 * group, the page naming an operator (`CanManage`), who alone reads why a
 * block could not render or a stored layout no longer reads.
 *
 * The reader half of Intelligence's layouts: it imports no block library.
 */

import { useContext } from '@wordpress/element';
import { ComposedLayout } from '@newspack-nodes/shared/composition/page';
import { CanManage } from '../components/Answer';
import { BLOCK_VIEWS } from '../blocks';
import { GROUP } from '../hooks/useIntelCommand';

/**
 * @param {Object}                                                                             props
 * @param {Object}                                                                             props.layout     What the page data or a save answered for the page's context.
 * @param {Omit<import('./PageContext').IntelPage, 'group'|'canTune'|'onRestored'|'operator'>} props.page       The page the blocks stand on.
 * @param {(layout: Object) => void}                                                           props.onRestored Takes the default a curator's restore answers.
 * @return {?import('react').ReactElement} The layout, or nothing for an empty one.
 */
export default function IntelligenceLayout( { layout, page, onRestored } ) {
	const operator = useContext( CanManage );
	return (
		<ComposedLayout
			layout={ layout }
			views={ BLOCK_VIEWS }
			page={ {
				...page,
				group: GROUP,
				canTune: Boolean( page.actions?.canTune ),
				onRestored,
				operator,
			} }
		/>
	);
}
