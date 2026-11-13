/**
 * The `timeline` block: the items about the profile's entity, newest first,
 * a page of `limit` at a time, then what its own site published, under a
 * disclosure, in place of the rest, or not at all, as `published` says.
 */

import {
	Icon,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronDown } from '@wordpress/icons';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { AnswerBody } from '../components/Answer';
import ItemList from '../components/ItemList';
import Section from '@newspack-nodes/shared/components/Section';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions, useIntelPage } from '../layout/PageContext';
import { entityName } from '../components/format';
import { aboutEntity, useEntityRecord } from './record';

/**
 * @typedef {Object} TimelineAttributes
 * @property {string}                     heading   The heading.
 * @property {'disclosure'|'none'|'only'} published Where what it published stands.
 * @property {'activity'|'list'}          layout    The list's layout.
 * @property {number}                     limit     The items a page holds.
 */

/**
 * One timeline read as an item list, a page of `limit` at a time.
 *
 * @param {Object}                    props
 * @param {string}                    props.entityKey The entity.
 * @param {boolean}                   props.published Whether it lists what the entity published.
 * @param {string}                    props.layout    The list's layout.
 * @param {number}                    props.limit     The items a page holds.
 * @param {import('react').ReactNode} props.empty     What an empty list says.
 * @return {import('react').ReactElement} The list.
 */
function Items( { entityKey, published, layout, limit, empty } ) {
	const { onOpenEntity, onSave, canTune } = useIntelActions();
	const [ page, setPage ] = useState( 1 );
	const offset = ( page - 1 ) * limit;
	const read = aboutEntity(
		entityKey,
		useIntelRead(
			published ? 'block:published' : 'block:timeline',
			'timeline',
			entityKey
				? formatCommandArgs( [ entityKey ], {
						limit,
						...( 0 < offset ? { offset } : {} ),
						...( published ? { published: true } : {} ),
				  } )
				: null
		)
	);
	return (
		<AnswerBody read={ read } rows="items" empty={ empty }>
			{ ( answer ) => (
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
			) }
		</AnswerBody>
	);
}

/**
 * What the entity's own site published, closed until opened and read only
 * then.
 *
 * @param {Object} props
 * @param {string} props.entityKey The entity.
 * @param {string} props.name      Its name.
 * @param {number} props.count     How many items it published.
 * @param {string} props.layout    The list's layout.
 * @param {number} props.limit     The items a page holds.
 * @return {import('react').ReactElement} The disclosure.
 */
function Published( { entityKey, name, count, layout, limit } ) {
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
				<span>
					{ sprintf(
						/* translators: 1: the entity's name, 2: items its own site published. */
						__(
							'Published by %1$s (%2$d)',
							'newspack-intelligence'
						),
						name,
						count
					) }
				</span>
				<Icon icon={ chevronDown } size={ 20 } />
			</button>
			<div id={ id } hidden={ ! open }>
				{ open && (
					<Items
						entityKey={ entityKey }
						published
						layout={ layout }
						limit={ limit }
						empty={ null }
					/>
				) }
			</div>
		</div>
	);
}

/**
 * @param {Object}             props
 * @param {TimelineAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { entity_key: key } = useIntelPage();
	const record = useEntityRecord().answer;
	const { heading, published, layout, limit } = attributes;
	const name = record?.available ? entityName( record ) : key;
	return (
		<Section heading={ heading }>
			<Items
				key={ 'only' === published ? 'published' : 'timeline' }
				entityKey={ key }
				published={ 'only' === published }
				layout={ layout }
				limit={ limit }
				empty={ sprintf(
					/* translators: %s: the entity's name. */
					__( 'Nothing about %s yet.', 'newspack-intelligence' ),
					name
				) }
			/>
			{ 'disclosure' === published &&
				record?.available &&
				0 < record.published.count && (
					<Published
						entityKey={ key }
						name={ name }
						count={ record.published.count }
						layout={ layout }
						limit={ limit }
					/>
				) }
		</Section>
	);
}

/**
 * @param {Object}                                      props
 * @param {TimelineAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<TimelineAttributes>) => void} props.setAttributes Writes them.
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
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'What it published', 'newspack-intelligence' ) }
				value={ attributes.published }
				options={ [
					{
						value: 'disclosure',
						label: __(
							'Under a disclosure',
							'newspack-intelligence'
						),
					},
					{
						value: 'none',
						label: __( 'Left out', 'newspack-intelligence' ),
					},
					{
						value: 'only',
						label: __( 'Shown alone', 'newspack-intelligence' ),
					},
				] }
				onChange={ ( value ) => setAttributes( { published: value } ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Layout', 'newspack-intelligence' ) }
				value={ attributes.layout }
				options={ [
					{
						value: 'activity',
						label: __( 'Activity', 'newspack-intelligence' ),
					},
					{
						value: 'list',
						label: __( 'List', 'newspack-intelligence' ),
					},
				] }
				onChange={ ( value ) => setAttributes( { layout: value } ) }
			/>
			<RangeControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Items a page', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 50 }
				value={ attributes.limit }
				onChange={ ( limit ) => setAttributes( { limit } ) }
			/>
		</>
	);
}

export default { View, Controls };
