import {
	ITEM_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { AnswerBody } from '../components/Answer';
import Cite from '../components/Cite';
import FolderBoard from '../components/FolderBoard';
import Refusal from '../components/Refusal';
import Section from '../components/Section';
import { __, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import { actorNoun, dayLabel, itemKey, utcDay } from '../components/format';

/**
 * The items a curator excluded from every view, a section of the Folders
 * page like the board above it, the latest first, each with
 * its title citing its URL, then as cells who excluded it in words, its UTC
 * day (`dayLabel()`) and why, when it says, and the
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
	const today = utcDay( Math.floor( Core.now() ) );
	return (
		<Section heading={ __( 'Excluded', 'newspack-intelligence' ) }>
			<Refusal error={ include.refusal } />
			<AnswerBody
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
								<p className="newspack-nodes-status is-muted newspack-intelligence__meta">
									<span>
										{ sprintf(
											/* translators: %s: who excluded it, such as "a curator". */
											__(
												'Excluded by %s',
												'newspack-intelligence'
											),
											actorNoun( item.actor )
										) }
									</span>
									<time
										dateTime={ utcDay( item.excluded_ts ) }
									>
										{ dayLabel(
											utcDay( item.excluded_ts ),
											today
										) }
									</time>
									{ item.why && <span>{ item.why }</span> }
								</p>
							</li>
						) ) }
					</ul>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * The Folders tab: the Folders board at the `folder-board` block's defaults,
 * whose header heads the tab, every folder, opening on the items not yet
 * used; and for a curator the items excluded from every view (`Excluded`),
 * the one place they are listed and included again.
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
