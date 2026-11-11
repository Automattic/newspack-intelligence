import { DropdownMenu } from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { moreHorizontal } from '@wordpress/icons';
import { useIntelRead } from '../hooks/useIntelCommand';
import { ChangesRead, changesArgs } from '../blocks/changes';
import { editLayoutControl } from '../layout/LayoutMenu';
import PageLayout from '../layout/PageLayout';

/** The blocks that read the week. */
const READS_WEEK = [
	'newspack-intelligence/since',
	'newspack-intelligence/week',
];

/**
 * The category lenses the layout's `week` blocks file the week under, each
 * once, in order.
 *
 * @param {Object[]} blocks The layout's top-level blocks.
 * @return {string[]} The lens keys.
 */
const categoriesOf = ( blocks ) => [
	...new Set(
		blocks
			.filter( ( node ) => 'newspack-intelligence/week' === node.name )
			.flatMap( ( node ) => node.attributes.categories )
	),
];

/**
 * Briefing: the page data's Briefing layout on one surface, each block by
 * its view, under the Briefing's header, whose ⋯ menu offers a curator
 * **Edit layout**. The week is read once, since the reader's last look and
 * through the categories every `week` block names, and its `since` and
 * `week` blocks share the answer. It tells the page it is seen while
 * mounted, so the page knows when a leave marks a new look.
 *
 * @param {Object}                                       props
 * @param {Object}                                       props.layout      What the page data answered for the Briefing's context.
 * @param {?number}                                      props.since       The last look, UTC seconds, or null for none.
 * @param {boolean}                                      props.kept        Whether the browser keeps the last look.
 * @param {(visible: boolean) => void}                   props.noteVisible Tells the page whether it is seen.
 * @param {boolean}                                      props.canTune     Whether the reader may curate, which offers Edit layout.
 * @param {import('../layout/PageContext').IntelActions} props.actions     What the blocks' rows open.
 * @param {(layout: Object) => void}                     props.onRestored  Takes the default a curator restored.
 * @return {import('react').ReactElement} The view.
 */
export default function Briefing( {
	layout,
	since,
	kept,
	noteVisible,
	canTune,
	actions,
	onRestored,
} ) {
	useEffect( () => {
		noteVisible( true );
		return () => noteVisible( false );
	}, [ noteVisible ] );
	const blocks = layout.blocks ?? [];
	const changes = useIntelRead(
		'briefing:changes',
		'briefing',
		blocks.some( ( node ) => READS_WEEK.includes( node.name ) )
			? changesArgs( since, categoriesOf( blocks ) )
			: null
	);
	return (
		<div className="newspack-intelligence__view">
			{ canTune && (
				<div className="newspack-intelligence__page-actions">
					<DropdownMenu
						icon={ moreHorizontal }
						label={ __(
							'Briefing actions',
							'newspack-intelligence'
						) }
						toggleProps={ { size: 'small' } }
						controls={ [
							editLayoutControl( { page: 'briefing' } ),
						] }
					/>
				</div>
			) }
			<div className="newspack-intelligence__surface newspack-nodes-card">
				<ChangesRead.Provider value={ changes }>
					<PageLayout
						layout={ layout }
						onRestored={ onRestored }
						page={ {
							page: 'briefing',
							lens_key: null,
							entity_key: null,
							since,
							kept,
							actions,
						} }
					/>
				</ChangesRead.Provider>
			</div>
		</div>
	);
}
