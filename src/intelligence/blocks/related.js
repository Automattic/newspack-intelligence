/**
 * The `related` block: the entities sharing the most items with the
 * profile's entity, each opening its own profile with how many it shares, at most
 * `limit` until the rest are asked for.
 */

import { RangeControl, TextControl } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import CappedList from '../components/CappedList';
import { EntityChip } from '../components/Cite';
import Section from '@newspack-nodes/shared/components/Section';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions, useIntelPage } from '../layout/PageContext';
import { entityName } from '../components/format';
import { aboutEntity, useEntityRecord } from './record';

/**
 * @typedef {Object} RelatedAttributes
 * @property {string} heading The heading.
 * @property {number} limit   The rows shown before "Show %d more".
 */

/**
 * @param {Object}   props
 * @param {Object[]} props.entities The related entities.
 * @param {number}   props.limit    The rows shown before the rest are asked for.
 * @return {import('react').ReactElement} The rows.
 */
function Rows( { entities, limit } ) {
	const { onOpenEntity } = useIntelActions();
	return (
		<CappedList rows={ entities } limit={ limit }>
			{ ( other ) => (
				<li
					key={ other.entity_key }
					className="newspack-intelligence__actions"
				>
					<EntityChip
						entityKey={ other.entity_key }
						name={ other.name }
						onOpen={ onOpenEntity }
					/>
					<span className="newspack-intelligence__count">
						{ sprintf(
							/* translators: %d: items both entities link. */
							__( '%d shared', 'newspack-intelligence' ),
							other.shared_items
						) }
					</span>
				</li>
			) }
		</CappedList>
	);
}

/**
 * @param {Object}            props
 * @param {RelatedAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { entity_key: key } = useIntelPage();
	const record = useEntityRecord();
	const read = aboutEntity(
		key,
		useIntelRead(
			`block:related:${ useId() }`,
			'related',
			key ? [ key ] : null
		)
	);
	const name =
		record.answer && ! record.error ? entityName( record.answer ) : key;
	return (
		<Section heading={ attributes.heading }>
			<AnswerBody
				read={ read }
				rows="entities"
				skeleton={ <Skeleton shape="line" count={ 3 } /> }
				empty={ sprintf(
					/* translators: %s: the entity's name. */
					__(
						'No other client or company shares items with %s yet.',
						'newspack-intelligence'
					),
					name
				) }
			>
				{ ( answer ) => (
					<Rows
						entities={ answer.entities }
						limit={ attributes.limit }
					/>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object}                                     props
 * @param {RelatedAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<RelatedAttributes>) => void} props.setAttributes Writes them.
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
				label={ __( 'Rows shown', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 20 }
				value={ attributes.limit }
				onChange={ ( limit ) => setAttributes( { limit } ) }
			/>
		</>
	);
}

export default { View, Controls };
