/**
 * A composed page's layout as a reader reads it: each block by its view
 * under the page it stands on, the aside apart so it can stand in a column
 * of its own, and a stored layout that no longer reads as the shared read
 * error in its place, with no Try again, since a read refuses it again; a
 * curator is told why and offered **Restore default**, which asks first.
 *
 * The reader half of Intelligence's layouts: it imports no block library.
 */

import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import CompositionView from '@newspack-nodes/shared/composition/CompositionView';
import { Unread } from '../components/Answer';
import Refusal from '../components/Refusal';
import { BLOCK_VIEWS, jumpsOf } from '../blocks';
import { useIntelWrite } from '../hooks/useIntelCommand';
import { Confirm } from '@newspack-nodes/shared/components/Modal';
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
 * The way back from a stored layout that no longer reads: the default
 * restored once the curator confirms, as the layout editor restores it.
 *
 * @param {Object}                   props
 * @param {string}                   props.context    The context refused.
 * @param {(layout: Object) => void} props.onRestored Takes the default the server answers.
 * @return {import('react').ReactElement} The line and its control.
 */
function Restore( { context, onRestored } ) {
	const [ asking, setAsking ] = useState( false );
	const restore = useIntelWrite( 'restore', {
		ci: 'compositions',
		scope: 'layout:restore',
		refresh: onRestored,
	} );
	return (
		<>
			<p>
				{ __(
					'This layout names a block or a setting this version does not have.',
					'newspack-intelligence'
				) }{ ' ' }
				<button
					type="button"
					className="button"
					disabled={ restore.pending }
					onClick={ () => setAsking( true ) }
				>
					{ __( 'Restore default', 'newspack-intelligence' ) }
				</button>
			</p>
			<Refusal error={ restore.refusal } />
			{ asking && (
				<Confirm
					title={ __(
						'Restore the default layout?',
						'newspack-intelligence'
					) }
					body={ __(
						'Everyone who opens this page will see the default.',
						'newspack-intelligence'
					) }
					yes={ __( 'Restore default', 'newspack-intelligence' ) }
					no={ __( 'Cancel', 'newspack-intelligence' ) }
					onYes={ () => {
						setAsking( false );
						restore.run( { context } );
					} }
					onNo={ () => setAsking( false ) }
				/>
			) }
		</>
	);
}

/**
 * @param {Object}                            props
 * @param {Object}                            props.layout       What the page data or a save answered for the page's context.
 * @param {import('./PageContext').IntelPage} props.page         The page the blocks stand on.
 * @param {(layout: Object) => void}          props.onRestored   Takes the default a curator's restore answers.
 * @param {string}                            [props.jumpPrefix] The prefix its jump scopes lead with, where the page links to its headings; else one of its own.
 * @return {?import('react').ReactElement} The layout, or nothing for an empty one.
 */
export default function PageLayout( { layout, page, onRestored, jumpPrefix } ) {
	const own = useId();
	if ( layout.error ) {
		return (
			<>
				<Unread error={ layout.error } />
				{ page.actions?.canTune && (
					<Restore
						context={ layout.context }
						onRestored={ onRestored }
					/>
				) }
			</>
		);
	}
	if ( 0 === layout.blocks.length ) {
		return null;
	}
	const main = layout.blocks.filter( ( node ) => ASIDE !== node.name );
	const aside = layout.blocks.filter( ( node ) => ASIDE === node.name );
	return (
		<IntelPageContext.Provider
			value={ {
				...page,
				jumps: jumpsOf( layout.blocks, jumpPrefix ?? own ).scopes,
			} }
		>
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
