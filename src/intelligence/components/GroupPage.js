import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { EntityChip } from './Cite';
import ItemRow from './ItemRow';
import Pager from './Pager';
import { metaLine, utcDay } from './format';
import { word } from './words';

/**
 * One theme group of a lens: its theme as the heading, its count, kind and
 * newest day, a chip per client it is about, and the button disclosing its
 * items, each an `ItemRow`; a group answering no `clients` or `items` shows
 * none.
 *
 * @param {Object}                 props
 * @param {Object}                 props.group        The group as `search --lens --group=theme` answers it.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The row.
 */
function GroupRow( { group, onOpenEntity, onSave, canTune } ) {
	const [ open, setOpen ] = useState( false );
	const clients = group.clients ?? [];
	const items = group.items ?? [];
	return (
		<li className="newspack-intelligence__item">
			<div className="newspack-intelligence__item-head">
				<h4>{ group.theme }</h4>
				<button
					type="button"
					className="button button-small"
					aria-expanded={ open }
					aria-label={
						open
							? sprintf(
									/* translators: %s: the group's theme. */
									__(
										'Hide items: %s',
										'newspack-intelligence'
									),
									group.theme
							  )
							: sprintf(
									/* translators: %s: the group's theme. */
									__(
										'Show items: %s',
										'newspack-intelligence'
									),
									group.theme
							  )
					}
					onClick={ () => setOpen( ! open ) }
				>
					{ open
						? __( 'Hide items', 'newspack-intelligence' )
						: __( 'Show items', 'newspack-intelligence' ) }
				</button>
			</div>
			<p className="newspack-nodes-status is-muted">
				{ metaLine(
					sprintf(
						/* translators: %d: the items the group holds. */
						_n(
							'%d item',
							'%d items',
							group.count,
							'newspack-intelligence'
						),
						group.count
					),
					group.kind && word( 'kindWords', group.kind ),
					utcDay( group.newest_ts )
				) }
			</p>
			{ 0 < clients.length && (
				<ul className="newspack-intelligence__chips">
					{ clients.map( ( client ) => (
						<li key={ client.entity_key }>
							<EntityChip
								entityKey={ client.entity_key }
								name={ client.name }
								onOpen={ onOpenEntity }
							/>
						</li>
					) ) }
				</ul>
			) }
			{ open && (
				<ul className="newspack-intelligence__items">
					{ items.map( ( item ) => (
						<ItemRow
							key={ item.item_key }
							item={ item }
							onOpenEntity={ onOpenEntity }
							onSave={ onSave }
							canTune={ canTune }
						/>
					) ) }
				</ul>
			) }
		</li>
	);
}

/**
 * The pager's words for a page of groups, in the form the count matching
 * takes.
 *
 * @param {number} total The groups matching.
 * @return {string} The format, the groups shown first and the groups matching second.
 */
const groupForms = ( total ) =>
	/* translators: 1: groups shown, 2: groups matching. */
	_n(
		'%1$d of %2$d group',
		'%1$d of %2$d groups',
		total,
		'newspack-intelligence'
	);

/**
 * A group's row key, unique across both row types: its theme and its first
 * item's key as one JSON pair, since a story can stand in two groups.
 *
 * @param {Object} group The group.
 * @return {string} The key.
 */
const rowKey = ( group ) =>
	JSON.stringify( [ group.theme, group.items?.[ 0 ]?.item_key ?? null ] );

/**
 * One page of the theme groups a lens's items read answered, in the order
 * it ranked them, which it names: a group counting one item whose `items`
 * hold it as that item's `ItemRow`, any other a `GroupRow`; then the pager over the page starts
 * its view holds, counting groups.
 *
 * @param {Object}                            props
 * @param {{groups: Object[], total: number}} props.answer       The read's answer.
 * @param {number[]}                          props.starts       Each page's first group, the current last.
 * @param {(starts: number[]) => void}        props.onStarts     Replaces the stack.
 * @param {(key: string) => void}             props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}            [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                           [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The page.
 */
export default function GroupPage( {
	answer,
	starts,
	onStarts,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	return (
		<>
			<p className="newspack-nodes-status is-muted">
				{ __(
					'Ranked by severity, importance, clients affected, then recency',
					'newspack-intelligence'
				) }
			</p>
			<ul className="newspack-intelligence__items">
				{ answer.groups.map( ( group ) =>
					1 === group.count && 1 === group.items?.length ? (
						<ItemRow
							key={ rowKey( group ) }
							item={ group.items[ 0 ] }
							onOpenEntity={ onOpenEntity }
							onSave={ onSave }
							canTune={ canTune }
						/>
					) : (
						<GroupRow
							key={ rowKey( group ) }
							group={ group }
							onOpenEntity={ onOpenEntity }
							onSave={ onSave }
							canTune={ canTune }
						/>
					)
				) }
			</ul>
			<Pager
				starts={ starts }
				onStarts={ onStarts }
				shown={ answer.groups.length }
				total={ answer.total }
				forms={ groupForms }
			/>
		</>
	);
}
