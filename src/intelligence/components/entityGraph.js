/**
 * The entity graph as the shared force graph draws it: what the `graph`
 * command answered as nodes and lines, and the classes a selection lights and
 * dims them by.
 */

import { entityName, itemsPhrase, metaLine } from './format';
import { word, words } from './words';

/** @typedef {import('@newspack-nodes/shared/forceGraph/ForceGraph').GraphNode} GraphNode */
/** @typedef {import('@newspack-nodes/shared/forceGraph/ForceGraph').GraphLink} GraphLink */

/**
 * An entity as `graph` answers it.
 *
 * @typedef {Object} GraphEntity
 * @property {string}   key   Its key.
 * @property {?string}  name  Its name, null for none.
 * @property {string}   type  Its type.
 * @property {string[]} roles Its open roles, in the order the server ranks them.
 * @property {number}   items How many items are about it.
 */

/**
 * @typedef {Object} GraphAnswer
 * @property {GraphEntity[]}              entities The entities, those the most items are about first.
 * @property {[number, number, number][]} pairs    Two places in `entities`, one related to the other, and how many items they share, the most shared first.
 */

/**
 * @typedef {Object} EntityGraph
 * @property {GraphNode[]} nodes One for each entity.
 * @property {GraphLink[]} links One for each pair drawn.
 */

/**
 * What an entity is shown as: its name, else its key.
 *
 * @param {GraphEntity} entity The entity.
 * @return {string} The label.
 */
export const labelOf = ( { name, key } ) =>
	entityName( { name, entity_key: key } );

/**
 * An entity's tooltip: its label, then its type, roles and items.
 *
 * @param {GraphEntity} entity The entity.
 * @return {string} The tooltip.
 */
const titleOf = ( entity ) => {
	const line = metaLine(
		word( 'typeWords', entity.type ),
		...entity.roles.map( ( role ) => word( 'roleWords', role ) ),
		itemsPhrase( entity.items )
	);
	return `${ labelOf( entity ) }\n${ line }`;
};

/**
 * The part of the answer naming entities of some types, and the pairs among
 * them.
 *
 * @param {GraphAnswer} answer What `graph` answered.
 * @param {string[]}    types  The types.
 * @return {GraphAnswer} That part, its places its own.
 */
export function ofTypes( { entities, pairs }, types ) {
	const places = new Map();
	const kept = entities.filter( ( { type }, place ) => {
		const holds = types.includes( type );
		if ( holds ) {
			places.set( place, places.size );
		}
		return holds;
	} );
	return {
		entities: kept,
		pairs: pairs.flatMap( ( [ a, b, shared ] ) =>
			places.has( a ) && places.has( b )
				? [ [ places.get( a ), places.get( b ), shared ] ]
				: []
		),
	};
}

/** Pixels a line pulls its ends to. */
const DISTANCE = 600;

/** What a joined entity carries, so the lines it holds do not pile names up. */
const JOINED_CHARGE = -6000;

/**
 * The answer as the force graph draws it: every entity, colored by its
 * type's place in the vocabulary, and a line for each pair sharing at least
 * `minShared` items, pulling less the more lines its less joined end has.
 *
 * @param {GraphAnswer} answer            What `graph` answered.
 * @param {Object}      options
 * @param {number}      options.minShared The fewest items a line stands for.
 * @return {EntityGraph} The graph.
 */
export function toEntityGraph( { entities, pairs }, { minShared } ) {
	const types = Object.keys( words( 'typeWords' ) );
	const drawn = pairs.filter( ( [ , , shared ] ) => shared >= minShared );
	const joins = new Map();
	for ( const place of drawn.flatMap( ( [ a, b ] ) => [ a, b ] ) ) {
		joins.set( place, ( joins.get( place ) ?? 0 ) + 1 );
	}
	const nodes = entities.map( ( entity, place ) => ( {
		id: entity.key,
		label: labelOf( entity ),
		className: `is-type-${ types.indexOf( entity.type ) + 1 }`,
		title: titleOf( entity ),
		charge: joins.has( place ) ? JOINED_CHARGE : undefined,
	} ) );
	const links = drawn.map( ( [ a, b, shared ] ) => {
		const [ source, target ] = [ entities[ a ].key, entities[ b ].key ];
		return {
			id: `${ source } ${ target }`,
			source,
			target,
			weight: shared,
			distance: DISTANCE,
			strength: 1 / Math.min( joins.get( a ), joins.get( b ) ),
		};
	} );
	return { nodes, links };
}

/**
 * The graph with an entity's lines and what they reach lit, the rest dimmed.
 *
 * @param {EntityGraph} graph    The graph.
 * @param {?string}     selected The entity's key, or null to light none.
 * @return {EntityGraph} The graph it was handed, for null.
 */
export function lightGraph( graph, selected ) {
	if ( null === selected ) {
		return graph;
	}
	const touches = ( { source, target } ) =>
		selected === source || selected === target;
	const lit = new Set( [
		selected,
		...graph.links
			.filter( touches )
			.flatMap( ( { source, target } ) => [ source, target ] ),
	] );
	const shade = ( on ) => ( on ? 'is-lit' : 'is-dimmed' );
	return {
		nodes: graph.nodes.map( ( node ) => ( {
			...node,
			className: `${ node.className } ${ shade( lit.has( node.id ) ) }`,
		} ) ),
		links: graph.links.map( ( link ) => ( {
			...link,
			className: shade( touches( link ) ),
		} ) ),
	};
}
