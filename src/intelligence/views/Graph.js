import { DropdownMenu } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { moreHorizontal } from '@wordpress/icons';
import { SectionHeader } from '@newspack-nodes/shared/components/Section';
import { editLayoutControl } from '@newspack-nodes/shared/composition/page';
import { PAGES } from '../layout/editMode';
import IntelligenceLayout from '../layout/IntelligenceLayout';

/**
 * The Graph tab: the page data's graph layout under the shared header, whose
 * ⋯ menu offers a curator **Edit layout**.
 *
 * @param {Object}                                                props
 * @param {Object}                                                props.layout     What the page data answered for the graph's context.
 * @param {boolean}                                               props.canTune    Whether the reader may curate, which offers Edit layout.
 * @param {Partial<import('../layout/PageContext').IntelActions>} props.actions    What the blocks open.
 * @param {(layout: Object) => void}                              props.onRestored Takes the default a curator restored.
 * @return {import('react').ReactElement} The view.
 */
export default function Graph( { layout, canTune, actions, onRestored } ) {
	return (
		<div className="newspack-intelligence__view">
			<SectionHeader
				title={ __( 'Graph', 'newspack-intelligence' ) }
				description={ __(
					'Everyone and everything the collected items are about, each joined to what its profile lists as related.',
					'newspack-intelligence'
				) }
				actions={
					canTune && (
						<DropdownMenu
							icon={ moreHorizontal }
							label={ __(
								'Graph actions',
								'newspack-intelligence'
							) }
							toggleProps={ { size: 'small' } }
							controls={ [
								editLayoutControl( { page: 'graph' }, PAGES ),
							] }
						/>
					)
				}
			/>
			<IntelligenceLayout
				layout={ layout }
				onRestored={ onRestored }
				page={ {
					page: 'graph',
					lens_key: null,
					entity_key: null,
					since: null,
					actions,
				} }
			/>
		</div>
	);
}
