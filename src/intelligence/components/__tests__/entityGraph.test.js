/* eslint-env jest */
/**
 * The `graph` answer as the force graph draws it: a node for every entity
 * whatever the least shared, a line for each pair sharing at least that many,
 * a hull for each first open role, else for each type, and what a selection
 * lights.
 */

import { pageData, VOCABULARY } from '../../views/__tests__/fixtures';
import { lightGraph, toEntityGraph } from '../entityGraph';

/** Four entities, one holding no name and no role, and the pairs they share. */
const ANSWER = {
	entities: [
		{
			key: 'organization:harbor-ledger',
			name: 'Harbor Ledger',
			type: 'organization',
			roles: [ 'competitor', 'former_client' ],
			items: 31,
		},
		{
			key: 'person:ida-marsh',
			name: null,
			type: 'person',
			roles: [],
			items: 12,
		},
		{
			key: 'organization:prairie-signal',
			name: 'Prairie Signal',
			type: 'organization',
			roles: [ 'former_client' ],
			items: 7,
		},
		{
			key: 'event:gull-summit',
			name: 'Gull Summit',
			type: 'event',
			roles: [],
			items: 1,
		},
	],
	pairs: [
		[ 1, 0, 23 ],
		[ 0, 2, 9 ],
		[ 1, 2, 8 ],
	],
	truncated: false,
	available: true,
};

/** Each type's place in the vocabulary, which colors it. */
const TYPE = Object.fromEntries(
	Object.keys( VOCABULARY.typeWords ).map( ( type, place ) => [
		type,
		`is-type-${ place + 1 }`,
	] )
);

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

describe( 'toEntityGraph', () => {
	it( 'draws every entity, in the answer’s order, colored by its type and titled by type, roles and items', () => {
		expect( toEntityGraph( ANSWER, { minShared: 9 } ).nodes ).toEqual( [
			{
				id: 'organization:harbor-ledger',
				label: 'Harbor Ledger',
				group: 'role:competitor',
				className: TYPE.organization,
				title: 'Harbor Ledger\nOutfit, Rival, Lapsed member, 31 items',
			},
			{
				id: 'person:ida-marsh',
				label: 'person:ida-marsh',
				group: 'type:person',
				className: TYPE.person,
				title: 'person:ida-marsh\nHuman, 12 items',
			},
			{
				id: 'organization:prairie-signal',
				label: 'Prairie Signal',
				group: 'role:former_client',
				className: TYPE.organization,
				title: 'Prairie Signal\nOutfit, Lapsed member, 7 items',
			},
			{
				id: 'event:gull-summit',
				label: 'Gull Summit',
				group: 'type:event',
				className: TYPE.event,
				title: 'Gull Summit\nHappening, 1 item',
			},
		] );
	} );

	it( 'gives each type a color of its own', () => {
		expect( new Set( Object.values( TYPE ) ).size ).toBe( 6 );
		expect( TYPE.organization ).toBe( 'is-type-1' );
		expect( TYPE.event ).toBe( 'is-type-6' );
	} );

	it( 'draws a line for each pair at or above the least shared, as wide as they share', () => {
		const { links } = toEntityGraph( ANSWER, { minShared: 9 } );
		expect( links.map( ( { id, ...link } ) => link ) ).toEqual( [
			{
				source: 'person:ida-marsh',
				target: 'organization:harbor-ledger',
				weight: 23,
			},
			{
				source: 'organization:harbor-ledger',
				target: 'organization:prairie-signal',
				weight: 9,
			},
		] );
		expect( new Set( links.map( ( { id } ) => id ) ).size ).toBe( 2 );
	} );

	it( 'names a line by its ends, so it keeps its place when the answer reorders', () => {
		const [ first, second, ...rest ] = ANSWER.entities;
		const moved = {
			...ANSWER,
			entities: [ second, first, ...rest ],
			pairs: [ [ 0, 1, 24 ] ],
		};
		expect( toEntityGraph( moved, { minShared: 1 } ).links[ 0 ].id ).toBe(
			toEntityGraph( ANSWER, { minShared: 1 } ).links[ 0 ].id
		);
	} );

	it( 'draws every entity and no line above the most any pair shares', () => {
		const { nodes, links } = toEntityGraph( ANSWER, { minShared: 24 } );
		expect( nodes ).toHaveLength( 4 );
		expect( links ).toEqual( [] );
	} );

	it( 'hulls each first open role, else each type, in words, each once', () => {
		const doubled = {
			...ANSWER,
			entities: [
				...ANSWER.entities,
				{
					...ANSWER.entities[ 2 ],
					key: 'organization:fen-courier',
				},
			],
		};
		expect( toEntityGraph( doubled, { minShared: 9 } ).groups ).toEqual( [
			{ id: 'role:competitor', label: 'Rival' },
			{ id: 'type:person', label: 'Human' },
			{ id: 'role:former_client', label: 'Lapsed member' },
			{ id: 'type:event', label: 'Happening' },
		] );
	} );

	it( 'refuses a role the vocabulary does not word', () => {
		const odd = {
			...ANSWER,
			entities: [ { ...ANSWER.entities[ 0 ], roles: [ 'landlord' ] } ],
			pairs: [],
		};
		expect( () => toEntityGraph( odd, { minShared: 9 } ) ).toThrow(
			'no roleWords word for "landlord"'
		);
	} );

	it( 'refuses a type the vocabulary does not word', () => {
		const odd = {
			...ANSWER,
			entities: [ { ...ANSWER.entities[ 3 ], type: 'vessel' } ],
			pairs: [],
		};
		expect( () => toEntityGraph( odd, { minShared: 9 } ) ).toThrow(
			'no typeWords word for "vessel"'
		);
	} );
} );

describe( 'lightGraph', () => {
	const classesOf = ( list ) =>
		Object.fromEntries(
			list.map( ( { id, className } ) => [ id, className ] )
		);

	it( 'leaves the graph as it is with nothing selected', () => {
		const graph = toEntityGraph( ANSWER, { minShared: 9 } );
		expect( lightGraph( graph, null ) ).toBe( graph );
	} );

	it( 'lights the entity, its lines and what they reach, and dims the rest', () => {
		const graph = toEntityGraph( ANSWER, { minShared: 9 } );
		const lit = lightGraph( graph, 'organization:prairie-signal' );
		expect( classesOf( lit.nodes ) ).toEqual( {
			'organization:harbor-ledger': `${ TYPE.organization } is-lit`,
			'person:ida-marsh': `${ TYPE.person } is-dimmed`,
			'organization:prairie-signal': `${ TYPE.organization } is-lit`,
			'event:gull-summit': `${ TYPE.event } is-dimmed`,
		} );
		expect( lit.links.map( ( { className } ) => className ) ).toEqual( [
			'is-dimmed',
			'is-lit',
		] );
		expect( lit.groups ).toBe( graph.groups );
	} );

	it( 'lights a line from either of its ends', () => {
		const { links } = lightGraph(
			toEntityGraph( ANSWER, { minShared: 8 } ),
			'organization:harbor-ledger'
		);
		expect( links.map( ( { className } ) => className ) ).toEqual( [
			'is-lit',
			'is-lit',
			'is-dimmed',
		] );
	} );

	it( 'lights an entity no line reaches alone', () => {
		const lit = lightGraph(
			toEntityGraph( ANSWER, { minShared: 9 } ),
			'event:gull-summit'
		);
		expect(
			lit.nodes
				.filter( ( { className } ) => / is-lit$/.test( className ) )
				.map( ( { id } ) => id )
		).toEqual( [ 'event:gull-summit' ] );
		expect(
			lit.links.every( ( { className } ) => 'is-dimmed' === className )
		).toBe( true );
	} );
} );
