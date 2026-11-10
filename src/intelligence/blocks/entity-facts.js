/**
 * The `entity-facts` block: what a profile holds, in the parts it shows. Its
 * counts, each only when not zero, and the day of its last item; its facts,
 * each claim in words citing its item; and its details, the names,
 * identifiers, roles and topics, with the ID under a disclosure for a curator.
 */

import { CheckboxControl, RangeControl } from '@wordpress/components';
import { useId } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { AnswerBody } from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import Section from '../components/Section';
import Skeleton from '../components/Skeleton';
import { roleText, shownDay, utcDay } from '../components/format';
import { word } from '../components/words';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions } from '../layout/PageContext';
import { useEntityRecord } from './record';

/** The parts the block may show, in the order it shows them. */
const PARTS = [ 'counts', 'claims', 'details' ];

/**
 * @typedef {Object} FactsAttributes
 * @property {string[]} show  The parts shown.
 * @property {number}   limit The most claims shown.
 */

/**
 * The status kinds a profile counts, each with its words.
 *
 * @type {Array<[string, (n: number) => string]>}
 */
const COUNTED = [
	[
		'feature_request',
		( n ) =>
			/* translators: %d: feature requests about the client. */
			_n(
				'%d feature request',
				'%d feature requests',
				n,
				'newspack-intelligence'
			),
	],
	[
		'complaint',
		( n ) =>
			/* translators: %d: complaints about the client. */
			_n( '%d complaint', '%d complaints', n, 'newspack-intelligence' ),
	],
	[
		'churn_signal',
		( n ) =>
			/* translators: %d: churn risks about the client. */
			_n( '%d churn risk', '%d churn risks', n, 'newspack-intelligence' ),
	],
];

/**
 * Each count that is not zero, as words: a client's status kinds and the
 * day of its last item, else the items about it.
 *
 * @param {Object} entity The record.
 * @return {string[]} The counts.
 */
function countsOf( entity ) {
	const { status } = entity;
	if ( ! status ) {
		return 0 < entity.counts.items
			? [
					sprintf(
						/* translators: %d: readable items about the entity. */
						_n(
							'%d item',
							'%d items',
							entity.counts.items,
							'newspack-intelligence'
						),
						entity.counts.items
					),
			  ]
			: [];
	}
	const counts = COUNTED.filter(
		( [ kind ] ) => 0 < status.kinds[ kind ]
	).map( ( [ kind, words ] ) =>
		sprintf( words( status.kinds[ kind ] ), status.kinds[ kind ] )
	);
	if ( null !== status.last_item_ts ) {
		counts.push(
			sprintf(
				/* translators: %s: the day of the newest item about it. */
				__( 'Last item %s', 'newspack-intelligence' ),
				shownDay( utcDay( status.last_item_ts ) )
			)
		);
	}
	return counts;
}

/**
 * A role row that held time; a closed row ending where it starts is an
 * import's same-day correction, not a period the entity held the role.
 *
 * @param {Object} role The role row.
 * @return {boolean} Whether it held time.
 */
const heldTime = ( role ) => 0 === role.to_ts || role.from_ts !== role.to_ts;

/**
 * @param {Object} props
 * @param {Object} props.entity The record.
 * @return {?import('react').ReactElement} The counts.
 */
function Counts( { entity } ) {
	const counts = countsOf( entity );
	if ( 0 === counts.length ) {
		return null;
	}
	return (
		<p className="newspack-intelligence__counts">
			{ counts.map( ( count ) => (
				<span key={ count }>{ count }</span>
			) ) }
		</p>
	);
}

/**
 * The claims naming the entity, each in words: its subject and object as
 * chips, but the entity itself, which the page already names.
 *
 * @param {Object} props
 * @param {Object} props.entity The record.
 * @param {number} props.limit  The most claims shown.
 * @return {import('react').ReactElement} The facts.
 */
function Claims( { entity, limit } ) {
	const { onOpenEntity } = useIntelActions();
	const read = useIntelRead(
		`block:claims:${ useId() }`,
		'claims',
		formatCommandArgs( [ entity.entity_key ], { limit } )
	);
	const chip = ( key, name ) =>
		key === entity.entity_key ? null : (
			<EntityChip
				entityKey={ key }
				name={ name }
				onOpen={ onOpenEntity }
			/>
		);
	return (
		<Section heading={ __( 'Facts', 'newspack-intelligence' ) }>
			<AnswerBody
				read={ read }
				rows="claims"
				skeleton={ <Skeleton shape="line" count={ 3 } /> }
				empty={ __(
					'No fact names this entity.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.claims.map( ( claim, i ) => (
							<li
								key={ `${ claim.item_key } ${ i }` }
								className="newspack-intelligence__actions"
							>
								{ chip( claim.subject, claim.subject_name ) }
								<span>
									{ word(
										'predicateWords',
										claim.predicate
									) }
								</span>
								{ null === claim.object_name ? (
									<span>{ claim.object }</span>
								) : (
									chip( claim.object, claim.object_name )
								) }
								<Cite url={ claim.url } title={ claim.title } />
							</li>
						) ) }
					</ul>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object} props
 * @param {Object} props.entity The record.
 * @return {import('react').ReactElement} The details.
 */
function Details( { entity } ) {
	const { canTune, onOpenEntity } = useIntelActions();
	return (
		<Section heading={ __( 'Details', 'newspack-intelligence' ) }>
			<ul className="newspack-intelligence__facts">
				{ entity.names.map( ( name ) => (
					<li key={ `name ${ name.kind } ${ name.value }` }>
						{ `${ word( 'nameKindWords', name.kind ) }: ${
							name.value
						}` }
					</li>
				) ) }
				{ entity.identifiers.map( ( id ) => (
					<li key={ `id ${ id.kind } ${ id.value }` }>
						{ `${ word( 'identifierWords', id.kind ) }: ${
							id.value
						}` }
					</li>
				) ) }
				{ entity.roles.filter( heldTime ).map( ( role ) => (
					<li key={ `role ${ role.role } ${ role.from_ts }` }>
						{ roleText( role ) }
					</li>
				) ) }
			</ul>
			{ 0 < entity.topics.length && (
				<ul className="newspack-intelligence__chips">
					{ entity.topics.map( ( topic ) => (
						<li key={ topic.topic_key }>
							<EntityChip
								entityKey={ topic.topic_key }
								name={ topic.name }
								onOpen={ onOpenEntity }
							/>
						</li>
					) ) }
				</ul>
			) }
			{ canTune && (
				<details>
					<summary>
						{ __( 'Show the ID', 'newspack-intelligence' ) }
					</summary>
					<p>
						{ sprintf(
							/* translators: %s: the entity's ID. */
							__( 'ID: %s', 'newspack-intelligence' ),
							entity.entity_key
						) }
					</p>
					{ 0 < entity.merged_from.length && (
						<p>
							{ sprintf(
								/* translators: %s: the IDs merged into this entity. */
								__( 'Merged from %s', 'newspack-intelligence' ),
								entity.merged_from.join( ', ' )
							) }
						</p>
					) }
				</details>
			) }
		</Section>
	);
}

/**
 * @param {Object}          props
 * @param {FactsAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const record = useEntityRecord();
	const { show, limit } = attributes;
	return (
		<AnswerBody
			read={ record }
			skeleton={ <Skeleton shape="line" count={ 2 } /> }
		>
			{ ( entity ) => (
				<>
					{ show.includes( 'counts' ) && (
						<Counts entity={ entity } />
					) }
					{ show.includes( 'claims' ) && (
						<Claims entity={ entity } limit={ limit } />
					) }
					{ show.includes( 'details' ) && (
						<Details entity={ entity } />
					) }
				</>
			) }
		</AnswerBody>
	);
}

/**
 * The headings the block shows, which a profile's jump links name.
 *
 * @param {FactsAttributes} attributes Its attributes.
 * @return {string[]} The headings.
 */
const headings = ( attributes ) =>
	[
		attributes.show.includes( 'claims' ) &&
			__( 'Facts', 'newspack-intelligence' ),
		attributes.show.includes( 'details' ) &&
			__( 'Details', 'newspack-intelligence' ),
	].filter( Boolean );

/**
 * @param {Object}                                   props
 * @param {FactsAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<FactsAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	const words = {
		counts: __( 'Counts', 'newspack-intelligence' ),
		claims: __( 'Facts', 'newspack-intelligence' ),
		details: __( 'Details', 'newspack-intelligence' ),
	};
	return (
		<>
			{ PARTS.map( ( part ) => (
				<CheckboxControl
					key={ part }
					__nextHasNoMarginBottom
					label={ words[ part ] }
					checked={ attributes.show.includes( part ) }
					onChange={ ( on ) =>
						setAttributes( {
							show: PARTS.filter( ( one ) =>
								one === part
									? on
									: attributes.show.includes( one )
							),
						} )
					}
				/>
			) ) }
			<RangeControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Facts shown', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 50 }
				value={ attributes.limit }
				onChange={ ( limit ) => setAttributes( { limit } ) }
			/>
		</>
	);
}

export default { View, Controls, headings };
