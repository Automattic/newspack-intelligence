/**
 * The `week` block: the week's items, most important first. Its top items
 * stand open as one activity list; the rest of each category, of
 * `otherwise` and of the clients folds into a row whose whole row opens
 * it, the clients' rest grouped by client. "Show" narrows the week to one category, or to
 * `otherwise`, each offered with the server's count.
 */

import {
	Icon,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { chevronDown } from '@wordpress/icons';
import { AnswerBody } from '../components/Answer';
import ItemList from '../components/ItemList';
import LensTokens from '../components/LensTokens';
import Section, { Invitation } from '../components/Section';
import Skeleton from '../components/Skeleton';
import { pageFormat } from '../components/format';
import { useIntelActions } from '../layout/PageContext';
import { useChanges } from './changes';

/** The most categories a week files under, as its row bounds them. */
const CATEGORY_MAX = 8;

/** "Show"'s value for every item. */
const ALL = '';

/** "Show"'s value for the items whose categories are empty. */
const OTHERWISE = ':otherwise';

/**
 * @typedef {Object} WeekAttributes
 * @property {string}   heading    The heading.
 * @property {number}   top        The items standing open.
 * @property {string[]} categories The category lenses, in order.
 * @property {string}   otherwise  The name of the items outside every category.
 * @property {boolean}  by_client  Whether the long tail groups by client.
 */

/**
 * A row whose whole row opens and closes what it folds.
 *
 * @param {Object}                    props
 * @param {string}                    props.label    The row's words.
 * @param {import('react').ReactNode} props.children What it folds.
 * @return {import('react').ReactElement} The fold.
 */
function Fold( { label, children } ) {
	const [ open, setOpen ] = useState( false );
	const id = useId();
	return (
		<div className="newspack-intelligence__fold">
			<button
				type="button"
				className="newspack-intelligence__fold-toggle"
				aria-expanded={ open }
				aria-controls={ id }
				onClick={ () => setOpen( ! open ) }
			>
				<span>{ label }</span>
				<Icon icon={ chevronDown } size={ 20 } />
			</button>
			<div id={ id } hidden={ ! open }>
				{ open && children }
			</div>
		</div>
	);
}

/**
 * Items as one still activity list of ItemRows.
 *
 * @param {Object}   props
 * @param {Object[]} props.items    The items.
 * @param {Object}   [props.client] Each item's client name by key, to group by.
 * @return {import('react').ReactElement} The list.
 */
function Items( { items, client } ) {
	const { onOpenEntity, onSave, canTune } = useIntelActions();
	const groupBy = client ? { field: 'client', showLabel: false } : undefined;
	return (
		<ItemList
			items={ items }
			view={ {
				type: 'activity',
				page: 1,
				perPage: items.length,
				groupBy,
			} }
			onChangeView={ () => {} }
			layout="activity"
			empty={ null }
			paginationInfo={ { totalItems: items.length, totalPages: 1 } }
			rowProps={ { onOpenEntity, onSave, canTune } }
			fields={
				client
					? [
							{
								id: 'client',
								label: __( 'Client', 'newspack-intelligence' ),
								enableHiding: false,
								enableSorting: false,
								getValue: ( { item } ) =>
									client[ item.item_key ],
								render: ( { item } ) => client[ item.item_key ],
							},
					  ]
					: []
			}
		/>
	);
}

/**
 * The week unnarrowed: the top items open, then a fold per category and for
 * `otherwise` holding what the top left, then the clients' long tail.
 *
 * @param {Object}         props
 * @param {Object}         props.answer     The `changes` answer.
 * @param {WeekAttributes} props.attributes The block's attributes.
 * @return {import('react').ReactElement} The week.
 */
function Week( { answer, attributes } ) {
	const byKey = Object.fromEntries(
		answer.items.map( ( item ) => [ item.item_key, item ] )
	);
	const open = answer.items.slice( 0, attributes.top );
	const opened = new Set( open.map( ( item ) => item.item_key ) );
	const rest = ( group ) =>
		group.items
			.filter( ( key ) => ! opened.has( key ) )
			.map( ( key ) => byKey[ key ] );
	const folds = answer.groups
		.filter( ( group ) => 'client' !== group.role )
		.map( ( group ) => ( {
			key: group.key ?? OTHERWISE,
			name: 'category' === group.role ? group.name : attributes.otherwise,
			items: rest( group ),
		} ) )
		.filter( ( fold ) => 0 < fold.items.length );
	const clients = answer.groups
		.filter( ( group ) => 'client' === group.role )
		.map( ( group ) => ( { ...group, rest: rest( group ) } ) )
		.filter( ( group ) => 0 < group.rest.length );
	const tail = clients.flatMap( ( group ) => group.rest );
	const client = Object.fromEntries(
		clients.flatMap( ( group ) =>
			group.rest.map( ( item ) => [ item.item_key, group.name ] )
		)
	);
	return (
		<>
			{ 0 < open.length && <Items items={ open } /> }
			{ folds.map( ( fold ) => (
				<Fold
					key={ fold.key }
					label={ sprintf(
						/* translators: 1: the group's name, 2: the items it holds. */
						__( '%1$s (%2$d)', 'newspack-intelligence' ),
						fold.name,
						fold.items.length
					) }
				>
					<Items items={ fold.items } />
				</Fold>
			) ) }
			{ 0 < tail.length && (
				<Fold
					label={ sprintf(
						/* translators: 1: clients, 2: their items. */
						_n(
							'%1$d more client, %2$d items',
							'%1$d more clients, %2$d items',
							clients.length,
							'newspack-intelligence'
						),
						clients.length,
						tail.length
					) }
				>
					<Items
						items={ tail }
						client={ attributes.by_client ? client : undefined }
					/>
				</Fold>
			) }
		</>
	);
}

/**
 * "Show": every item, each category or `otherwise`, each with its count.
 *
 * @param {Object}                  props
 * @param {Object}                  props.answer    The `changes` answer.
 * @param {string}                  props.otherwise What `otherwise` is called.
 * @param {string}                  props.value     The choice.
 * @param {(value: string) => void} props.onChange  Takes another.
 * @return {import('react').ReactElement} The control.
 */
function Show( { answer, otherwise, value, onChange } ) {
	const counted = ( name, count ) =>
		sprintf(
			/* translators: 1: the category's name, 2: how many items it admits. */
			__( '%1$s %2$d', 'newspack-intelligence' ),
			name,
			count
		);
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			labelPosition="side"
			label={ __( 'Show', 'newspack-intelligence' ) }
			value={ value }
			options={ [
				{ label: __( 'All', 'newspack-intelligence' ), value: ALL },
				...answer.categories.map( ( category ) => ( {
					label: counted( category.name, category.count ),
					value: category.lens_key,
				} ) ),
				{
					label: counted( otherwise, answer.otherwise ),
					value: OTHERWISE,
				},
			] }
			onChange={ onChange }
		/>
	);
}

/**
 * @param {Object}         props
 * @param {WeekAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { onSearch } = useIntelActions();
	const read = useChanges( 'briefing:week', attributes.categories );
	const [ shown, setShown ] = useState( ALL );
	const answer = read.answer;
	const narrowed = ( item ) =>
		OTHERWISE === shown
			? item.otherwise
			: item.categories.includes( shown );
	const number = pageFormat( Intl.NumberFormat );
	return (
		<Section
			heading={ attributes.heading }
			actions={
				0 < attributes.categories.length &&
				answer?.categories && (
					<Show
						answer={ answer }
						otherwise={ attributes.otherwise }
						value={ shown }
						onChange={ setShown }
					/>
				)
			}
		>
			<AnswerBody
				read={ read }
				rows="items"
				skeleton={ <Skeleton count={ 10 } /> }
				empty={
					<Invitation
						text={ __(
							'Nothing arrived this week.',
							'newspack-intelligence'
						) }
						label={ __(
							'Search everything',
							'newspack-intelligence'
						) }
						onClick={ onSearch }
					/>
				}
			>
				{ ( week ) => (
					<>
						{ ALL === shown ? (
							<Week answer={ week } attributes={ attributes } />
						) : (
							<Items items={ week.items.filter( narrowed ) } />
						) }
						<p className="newspack-nodes-status is-muted">
							{ sprintf(
								/* translators: 1: items shown, 2: items this week. */
								__(
									'%1$s of %2$s items this week.',
									'newspack-intelligence'
								),
								number.format( week.items.length ),
								number.format( week.total )
							) }{ ' ' }
							<button
								type="button"
								className="button-link"
								onClick={ onSearch }
							>
								{ __(
									'Search them all',
									'newspack-intelligence'
								) }
							</button>
						</p>
					</>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object}                                  props
 * @param {WeekAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<WeekAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Heading', 'newspack-intelligence' ) }
				value={ attributes.heading }
				onChange={ ( heading ) => setAttributes( { heading } ) }
			/>
			<RangeControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Top items', 'newspack-intelligence' ) }
				min={ 0 }
				max={ 50 }
				value={ attributes.top }
				onChange={ ( top ) => setAttributes( { top } ) }
			/>
			<LensTokens
				label={ __( 'Categories', 'newspack-intelligence' ) }
				value={ attributes.categories }
				max={ CATEGORY_MAX }
				onChange={ ( categories ) => setAttributes( { categories } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Everything else', 'newspack-intelligence' ) }
				value={ attributes.otherwise }
				onChange={ ( otherwise ) => setAttributes( { otherwise } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Group the rest by client',
					'newspack-intelligence'
				) }
				checked={ attributes.by_client }
				onChange={ ( byClient ) =>
					setAttributes( { by_client: byClient } )
				}
			/>
		</>
	);
}

export default { View, Controls };
