import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import DataViewsList from '@newspack-nodes/shared/components/DataViewsList';
import { EntityChip } from './Cite';
import ItemRow, { ItemRows } from './ItemRow';
import { dayLabel, utcDay } from './format';
import { word } from './words';

/**
 * One theme group of a lens: its theme as the heading, its count, kind and
 * newest day as cells, the day by `dayLabel()`, a chip per client it is about, and the button disclosing its
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
	const newest = utcDay( group.newest_ts );
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
			<p className="newspack-nodes-status is-muted newspack-intelligence__meta">
				<span>
					{ sprintf(
						/* translators: %d: the items the group holds. */
						_n(
							'%d item',
							'%d items',
							group.count,
							'newspack-intelligence'
						),
						group.count
					) }
				</span>
				{ group.kind && (
					<span>{ word( 'kindWords', group.kind ) }</span>
				) }
				{ newest && (
					<time dateTime={ newest }>
						{ dayLabel(
							newest,
							utcDay( Math.floor( Core.now() ) )
						) }
					</time>
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
 * A group's row key, unique across both row types: its theme and its first
 * item's key as one JSON pair, since a story can stand in two groups.
 *
 * @param {Object} group The group.
 * @return {string} The key.
 */
const rowKey = ( group ) =>
	JSON.stringify( [ group.theme, group.items?.[ 0 ]?.item_key ?? null ] );

/**
 * One theme group's row: a group counting one item whose `items` hold it as
 * that item's `ItemRow`, any other a `GroupRow`.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item         The group.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The row.
 */
function ThemeRow( { item: group, onOpenEntity, onSave, canTune } ) {
	return 1 === group.count && 1 === group.items?.length ? (
		<ItemRow
			item={ group.items[ 0 ] }
			onOpenEntity={ onOpenEntity }
			onSave={ onSave }
			canTune={ canTune }
		/>
	) : (
		<GroupRow
			group={ group }
			onOpenEntity={ onOpenEntity }
			onSave={ onSave }
			canTune={ canTune }
		/>
	);
}

/**
 * One page of the theme groups a lens's items read answered, in the order
 * it ranked them, which it names: `DataViewsList` over the groups, each a
 * `ThemeRow` keyed by `rowKey()`, every row's writes held for the page by
 * `ItemRows`, paged by the view the caller holds.
 *
 * @param {Object}                                   props
 * @param {{groups: Object[]}}                       props.answer         The read's answer.
 * @param {Object}                                   props.view           The DataViews view: `type`, `page`, `perPage`.
 * @param {(view: Object) => void}                   props.onChangeView   Takes the view moved.
 * @param {{totalItems: number, totalPages: number}} props.paginationInfo The groups matching, and the pages they make.
 * @param {string}                                   props.empty          What an empty page says.
 * @param {(key: string) => void}                    props.onOpenEntity   Opens an entity.
 * @param {(item: Object) => void}                   [props.onSave]       Saves an item; absent for a reader.
 * @param {boolean}                                  [props.canTune]      Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The page.
 */
export default function GroupPage( {
	answer,
	view,
	onChangeView,
	paginationInfo,
	empty,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const items = useMemo(
		() => answer.groups.flatMap( ( group ) => group.items ?? [] ),
		[ answer ]
	);
	return (
		<ItemRows items={ items }>
			<DataViewsList
				items={ answer.groups }
				view={ view }
				onChangeView={ onChangeView }
				paginationInfo={ paginationInfo }
				empty={ empty }
				render={ ThemeRow }
				getItemId={ rowKey }
				rowProps={ { onOpenEntity, onSave, canTune } }
				header={
					<p className="newspack-nodes-status is-muted">
						{ __(
							'Ranked by severity, importance, clients affected, then recency',
							'newspack-intelligence'
						) }
					</p>
				}
			/>
		</ItemRows>
	);
}
