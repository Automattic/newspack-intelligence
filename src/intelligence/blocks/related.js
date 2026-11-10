/**
 * The `related` block: the entities sharing the most items with the
 * profile's entity, each opening its own profile with how many it shares, at most
 * `limit` until the rest are asked for.
 */

import { RangeControl, TextControl } from '@wordpress/components';
import { useContext, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import { EntityChip } from '../components/Cite';
import Section from '../components/Section';
import Skeleton from '../components/Skeleton';
import { useIntelRead } from '../hooks/useIntelCommand';
import { IntelPageContext, useIntelActions } from '../layout/PageContext';
import { entityName } from '../components/format';
import { aboutEntity, useEntityRecord } from './record';

/**
 * @typedef {Object} RelatedAttributes
 * @property {string} heading The heading.
 * @property {number} limit   The rows shown before "Show all %d".
 */

/**
 * @param {Object}   props
 * @param {Object[]} props.entities The related entities.
 * @param {number}   props.limit    The rows shown before the rest are asked for.
 * @return {import('react').ReactElement} The rows.
 */
function Rows( { entities, limit } ) {
	const { onOpenEntity } = useIntelActions();
	const [ all, setAll ] = useState( false );
	const shown = all ? entities : entities.slice( 0, limit );
	return (
		<>
			<ul className="newspack-intelligence__facts">
				{ shown.map( ( other ) => (
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
				) ) }
			</ul>
			{ shown.length < entities.length && (
				<button
					type="button"
					className="button-link"
					onClick={ () => setAll( true ) }
				>
					{ sprintf(
						/* translators: %d: every related entity. */
						__( 'Show all %d', 'newspack-intelligence' ),
						entities.length
					) }
				</button>
			) }
		</>
	);
}

/**
 * @param {Object}            props
 * @param {RelatedAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { entity_key: key } = useContext( IntelPageContext );
	const record = useEntityRecord();
	const read = aboutEntity(
		key,
		useIntelRead( 'block:related', 'related', key ? [ key ] : null )
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
 * @param {RelatedAttributes} attributes Its attributes.
 * @return {string[]} The heading a profile's jump links name.
 */
const headings = ( attributes ) => [ attributes.heading ];

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

export default { View, Controls, headings };
