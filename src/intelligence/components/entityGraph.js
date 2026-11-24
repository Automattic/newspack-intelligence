/**
 * The entity graph as the shared force graph draws it: what the `graph`
 * command answered as nodes, lines and hulls, and the classes a selection
 * lights and dims them by.
 */

import { entityName, itemsPhrase, metaLine } from './format';
import { word, words } from './words';

/** @typedef {import('@newspack-nodes/shared/forceGraph/ForceGraph').GraphNode} GraphNode */
/** @typedef {import('@newspack-nodes/shared/forceGraph/ForceGraph').GraphLink} GraphLink */
/** @typedef {import('@newspack-nodes/shared/forceGraph/ForceGraph').GraphGroup} GraphGroup */

/**
 * An entity as `graph` answers it.
 *
 * @typedef {Object} GraphEntity
 * @property {string}   key   Its key.
 * @property {?string}  name  Its name, null for none.
 * @property {string}   type  Its type.
 * @property {string[]} roles Its open roles, in the order the server ranks them.
 * @property {number}   items How many items name it.
 */

/**
 * @typedef {Object} GraphAnswer
 * @property {GraphEntity[]}              entities  The entities, the most named first.
 * @property {[number, number, number][]} pairs     Two places in `entities` and how many items they share, the most shared first.
 * @property {boolean}                    truncated Whether pairs were left out.
 */

/**
 * @typedef {Object} EntityGraph
 * @property {GraphNode[]}  nodes  One for each entity.
 * @property {GraphLink[]}  links  One for each pair drawn.
 * @property {GraphGroup[]} groups One for each hull.
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
 * The hull an entity stands in: its first open role, else its type.
 *
 * @param {GraphEntity} entity The entity.
 * @return {GraphGroup} The hull.
 */
const hullOf = ( { roles: [ role ], type } ) =>
	role
		? { id: `role:${ role }`, label: word( 'roleWords', role ) }
		: { id: `type:${ type }`, label: word( 'typeWords', type ) };

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
 * The answer as the force graph draws it: every entity, colored by its
 * type's place in the vocabulary, and a line for each pair sharing at least
 * `minShared` items.
 *
 * @param {GraphAnswer} answer            What `graph` answered.
 * @param {Object}      options
 * @param {number}      options.minShared The fewest items a line stands for.
 * @return {EntityGraph} The graph.
 */
export function toEntityGraph( { entities, pairs }, { minShared } ) {
	const types = Object.keys( words( 'typeWords' ) );
	/** @type {Map<string, GraphGroup>} */
	const hulls = new Map();
	const nodes = entities.map( ( entity ) => {
		const hull = hullOf( entity );
		hulls.set( hull.id, hull );
		return {
			id: entity.key,
			label: labelOf( entity ),
			group: hull.id,
			className: `is-type-${ types.indexOf( entity.type ) + 1 }`,
			title: titleOf( entity ),
		};
	} );
	const links = pairs
		.filter( ( [ , , shared ] ) => shared >= minShared )
		.map( ( [ a, b, shared ] ) => {
			const [ source, target ] = [ entities[ a ].key, entities[ b ].key ];
			return {
				id: `${ source } ${ target }`,
				source,
				target,
				weight: shared,
			};
		} );
	return { nodes, links, groups: [ ...hulls.values() ] };
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
		groups: graph.groups,
	};
}
