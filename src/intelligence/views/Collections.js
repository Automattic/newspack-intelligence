import { __ } from '@wordpress/i18n';
import {
	ITEM_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import Cite from '../components/Cite';
import FolderBoard from '../components/FolderBoard';
import Refusal from '../components/Refusal';
import { metaLine, utcDay } from '../components/format';

/**
 * An excluded item's key.
 *
 * @param {Object} item The item.
 * @return {string} Its key.
 */
const itemKey = ( item ) => item.item_key;

/**
 * The items a curator excluded from every view, the latest first, each with
 * its title citing its URL, who excluded it, its UTC day and why, and the
 * control including it again, which takes it off the list from the moment
 * it is sent until a read no longer lists it; a refusal puts it back.
 *
 * @return {import('react').ReactElement} The list.
 */
function Excluded() {
	const read = useIntelRead( 'collections:excluded', 'excluded', [] );
	const listed = useHeld( read, 'items', itemKey );
	const include = useIntelWrite( 'include_item', {
		dirties: ITEM_READS,
		onRefused: listed.refused,
	} );
	return (
		<>
			<Refusal error={ include.refusal } />
			<Answer
				title={ __( 'Excluded', 'newspack-intelligence' ) }
				read={ listed.read }
				rows="items"
				empty={ __(
					'No item is excluded from every view.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__items">
						{ answer.items.map( ( item ) => (
							<li
								key={ item.item_key }
								className="newspack-intelligence__item"
							>
								<div className="newspack-intelligence__item-head">
									<strong>
										<Cite
											url={ item.url }
											title={ item.title }
										/>
									</strong>
									<button
										type="button"
										className="button button-small"
										onClick={ () =>
											listed.hold(
												item.item_key,
												null,
												() =>
													include.run( {
														item: item.item_key,
													} )
											)
										}
									>
										{ __(
											'Include again',
											'newspack-intelligence'
										) }
									</button>
								</div>
								<p className="newspack-nodes-status is-muted">
									{ metaLine(
										item.actor,
										utcDay( item.excluded_ts ),
										item.why
									) }
								</p>
							</li>
						) ) }
					</ul>
				) }
			</Answer>
		</>
	);
}

/**
 * The Folders tab: the Folders board at the `folder-board` block's defaults,
 * every folder, opening on the items not yet used; and for a curator the
 * items excluded from every view (`Excluded`), the one place they are listed
 * and included again.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune Whether to offer the writes.
 * @return {import('react').ReactElement} The view.
 */
export default function Collections( { canTune } ) {
	return (
		<div className="newspack-intelligence__view">
			<FolderBoard show="unused" folders={ [] } canTune={ canTune } />
			{ canTune && <Excluded /> }
		</div>
	);
}
