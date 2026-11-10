/**
 * The `item-list` block: the items `search` reads under the page it stands
 * on, through the team view on a team view's page and about the entity on a
 * profile, narrowed by the lists and tiers it names, grouped by theme or in
 * the layout it names. On a team view's page each list it names is
 * intersected with the view's own list of that name, so the block narrows
 * the view and never widens it; an intersection left empty reads nothing.
 */

import {
	FormTokenField,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useContext, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import GroupPage from '../components/GroupPage';
import ItemList from '../components/ItemList';
import Section from '../components/Section';
import Skeleton from '../components/Skeleton';
import { words } from '../components/words';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import { IntelPageContext, useIntelActions } from '../layout/PageContext';

/** The skeleton rows that stand in for a list still owed. */
const SKELETON_ROWS = 5;

/**
 * @typedef {Object} ItemListAttributes
 * @property {string}                    heading The heading; '' for none.
 * @property {string[]}                  kinds   The kinds read; none for every one.
 * @property {string[]}                  topics  The topics read.
 * @property {string[]}                  roles   The roles read.
 * @property {string[]}                  sources The sources read.
 * @property {string}                    lens    A team view read through; '' for the view the page shows.
 * @property {''|'theme'}                group   `theme`, or '' for items.
 * @property {'importance'|'time'}       sort    `importance` or `time`.
 * @property {'list'|'activity'|'table'} layout  The layout the items draw in.
 * @property {number}                    limit   The items or groups a page holds.
 * @property {string[]}                  tiers   The tiers read, within those the reader holds.
 * @property {string}                    empty   What an empty list says.
 */

/**
 * The lens's list filters, `{ field, option, values }`, from the page data.
 *
 * @return {Array<{field: string, option: string}>} The lists.
 */
const lensLists = () =>
	/** @type {Array<{field: string, option: string}>} */ (
		/** @type {unknown} */ ( words( 'lensLists' ) )
	);

/**
 * Each list the block names as the option `search` takes, intersected with
 * the page's team view's list of that name when both name one; null when an
 * intersection is empty, so nothing is read.
 *
 * @param {ItemListAttributes} attributes The block's attributes.
 * @param {?Object}            lens       The page's team view, or null.
 * @return {?Object<string, string[]>} The options, or null.
 */
function listsOf( attributes, lens ) {
	/** @type {Object<string, string[]>} */
	const options = {};
	for ( const { field, option } of lensLists() ) {
		const own = attributes[ field ];
		const theirs = lens?.filters[ field ] ?? [];
		const kept =
			0 < own.length && 0 < theirs.length
				? own.filter( ( one ) => theirs.includes( one ) )
				: own;
		if ( 0 < own.length && 0 === kept.length ) {
			return null;
		}
		options[ option ] = kept;
	}
	return options;
}

/**
 * The `search` options the block reads, or null for none.
 *
 * @param {ItemListAttributes}                        attributes The block's attributes.
 * @param {import('../layout/PageContext').IntelPage} page       The page.
 * @return {?Object} The options.
 */
function searchOf( attributes, page ) {
	const lensKey =
		attributes.lens || ( 'lens' === page.page ? page.lens_key : '' );
	const lists = listsOf(
		attributes,
		lensKey === page.lens_key ? page.lens : null
	);
	if ( null === lists ) {
		return null;
	}
	return {
		lens: lensKey ?? '',
		entity: 'entity' === page.page ? page.entity_key : '',
		...lists,
		tier: attributes.tiers,
		...( attributes.group
			? { group: attributes.group }
			: { sort: attributes.sort } ),
		limit: attributes.limit,
	};
}

/**
 * The block's read, a page at a time, drawn as theme groups or items.
 *
 * @param {Object}             props
 * @param {Object}             props.options    The `search` options.
 * @param {ItemListAttributes} props.attributes The block's attributes.
 * @return {import('react').ReactElement} The list.
 */
function Read( { options, attributes } ) {
	const { onOpenEntity, onSave, canTune } = useIntelActions();
	const [ starts, setStarts ] = useState( [ 0 ] );
	const [ page, setPage ] = useState( 1 );
	const { group, layout, limit, empty } = attributes;
	const offset = group ? starts[ starts.length - 1 ] : ( page - 1 ) * limit;
	const read = useIntelRead(
		`item-list:${ JSON.stringify( options ) }`,
		'search',
		commandArgs( { ...options, ...( 0 < offset ? { offset } : {} ) } )
	);
	return (
		<AnswerBody
			read={ read }
			rows={ group ? 'groups' : 'items' }
			empty={ empty }
			skeleton={ <Skeleton count={ SKELETON_ROWS } /> }
		>
			{ ( answer ) =>
				group ? (
					<GroupPage
						answer={ answer }
						starts={ starts }
						onStarts={ setStarts }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
				) : (
					<ItemList
						items={ answer.items }
						view={ { type: layout, page, perPage: limit } }
						onChangeView={ ( view ) => setPage( view.page ?? 1 ) }
						layout={ layout }
						empty={ empty }
						paginationInfo={ {
							totalItems: answer.total,
							totalPages: Math.ceil( answer.total / limit ),
						} }
						rowProps={ { onOpenEntity, onSave, canTune } }
					/>
				)
			}
		</AnswerBody>
	);
}

/**
 * @param {Object}             props
 * @param {ItemListAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const options = searchOf( attributes, useContext( IntelPageContext ) );
	const body = options ? (
		<Read
			key={ JSON.stringify( options ) }
			options={ options }
			attributes={ attributes }
		/>
	) : (
		<p className="newspack-nodes-empty-state is-quiet">
			{ attributes.empty }
		</p>
	);
	return attributes.heading ? (
		<Section heading={ attributes.heading }>{ body }</Section>
	) : (
		<div className="newspack-intelligence__section">{ body }</div>
	);
}

/**
 * @param {ItemListAttributes} attributes Its attributes.
 * @return {string[]} The heading a profile's jump links name.
 */
const headings = ( attributes ) =>
	attributes.heading ? [ attributes.heading ] : [];

/**
 * A token field over a closed list, by its words.
 *
 * @param {Object}                   props
 * @param {string}                   props.label    The field's label.
 * @param {string}                   props.map      The vocabulary map wording the list.
 * @param {string[]}                 props.value    The keys picked.
 * @param {(keys: string[]) => void} props.onChange Takes the keys picked.
 * @return {import('react').ReactElement} The field.
 */
function Picker( { label, map, value, onChange } ) {
	const shown = words( map );
	const keyOf = Object.fromEntries(
		Object.entries( shown ).map( ( [ key, said ] ) => [ said, key ] )
	);
	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ value.map( ( key ) => shown[ key ] ?? key ) }
			suggestions={ Object.values( shown ) }
			__experimentalValidateInput={ ( said ) => said in keyOf }
			__experimentalExpandOnFocus
			onChange={ ( picked ) =>
				onChange(
					picked
						.map( ( one ) =>
							'string' === typeof one ? one : one.value
						)
						.map( ( said ) => keyOf[ said ] ?? said )
				)
			}
		/>
	);
}

/**
 * @param {Object}                                      props
 * @param {ItemListAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<ItemListAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	const shared = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};
	const set = ( name ) => ( value ) => setAttributes( { [ name ]: value } );
	return (
		<>
			<TextControl
				{ ...shared }
				label={ __( 'Heading', 'newspack-intelligence' ) }
				value={ attributes.heading }
				onChange={ set( 'heading' ) }
			/>
			<Picker
				label={ __( 'Kinds of news', 'newspack-intelligence' ) }
				map="kindWords"
				value={ attributes.kinds }
				onChange={ set( 'kinds' ) }
			/>
			<Picker
				label={ __( 'About', 'newspack-intelligence' ) }
				map="roleWords"
				value={ attributes.roles }
				onChange={ set( 'roles' ) }
			/>
			<Picker
				label={ __( 'Sources', 'newspack-intelligence' ) }
				map="sourceWords"
				value={ attributes.sources }
				onChange={ set( 'sources' ) }
			/>
			<Picker
				label={ __(
					'Include items that are',
					'newspack-intelligence'
				) }
				map="tierWords"
				value={ attributes.tiers }
				onChange={ set( 'tiers' ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Show', 'newspack-intelligence' ) }
				options={ [
					{
						label: __( 'Items', 'newspack-intelligence' ),
						value: '',
					},
					{
						label: __(
							'Stories by theme',
							'newspack-intelligence'
						),
						value: 'theme',
					},
				] }
				value={ attributes.group }
				onChange={ set( 'group' ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Order', 'newspack-intelligence' ) }
				options={ [
					{
						label: __(
							'Most important first',
							'newspack-intelligence'
						),
						value: 'importance',
					},
					{
						label: __( 'Newest first', 'newspack-intelligence' ),
						value: 'time',
					},
				] }
				value={ attributes.sort }
				onChange={ set( 'sort' ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Layout', 'newspack-intelligence' ) }
				options={ [
					{
						label: __( 'List', 'newspack-intelligence' ),
						value: 'list',
					},
					{
						label: __( 'Activity', 'newspack-intelligence' ),
						value: 'activity',
					},
					{
						label: __( 'Table', 'newspack-intelligence' ),
						value: 'table',
					},
				] }
				value={ attributes.layout }
				onChange={ set( 'layout' ) }
			/>
			<RangeControl
				{ ...shared }
				label={ __( 'Items shown', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 50 }
				value={ attributes.limit }
				onChange={ set( 'limit' ) }
			/>
			<TextControl
				{ ...shared }
				label={ __( 'When empty, say', 'newspack-intelligence' ) }
				value={ attributes.empty }
				onChange={ set( 'empty' ) }
			/>
		</>
	);
}

export default { View, Controls, headings };
