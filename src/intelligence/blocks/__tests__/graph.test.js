/* eslint-env jest */
/**
 * The `graph` block reads `graph` once and draws every entity on the shared
 * force graph: its slider and its types filter what it holds, organizations
 * alone at first, a selection lights what it reaches, and the list beside the
 * canvas selects what a press on it does. While an entity is selected an
 * inspector stands in the list's place, built from that one answer. The
 * canvas takes every wheel over it.
 *
 * The force graph is the real one in a box jsdom lacks, wrapped to show what
 * it was handed, and each chip is wrapped to count what it draws.
 */

let mockRenders = [];

let mockChips = 0;

jest.mock( '@newspack-nodes/shared/forceGraph', () => ( {
	...jest.requireActual( '@newspack-nodes/shared/forceGraph' ),
	ForceGraph: ( props ) => {
		mockRenders.push( props );
		const { ForceGraph } = jest.requireActual(
			'@newspack-nodes/shared/forceGraph'
		);
		return <ForceGraph { ...props } />;
	},
} ) );

jest.mock( '../../components/Cite', () => ( {
	EntityChip: ( props ) => {
		mockChips++;
		return jest
			.requireActual( '../../components/Cite' )
			.EntityChip( props );
	},
} ) );

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { installSvgBox } from '@newspack-nodes/shared/test-utils/svgBox';
import { renderOnPage } from '../../__tests__/pageReads';
import { intelWire } from '../../__tests__/intelWire';
import { pageData } from '../../views/__tests__/fixtures';
import { ITEM_READS, useIntelWrite } from '../../hooks/useIntelCommand';
import graph from '../graph';

const HARBOR = 'organization:harbor-ledger';
const IDA = 'person:ida-marsh';
const PRAIRIE = 'organization:prairie-signal';
const GULL = 'event:gull-summit';

const ANSWER = {
	entities: [
		{
			key: HARBOR,
			name: 'Harbor Ledger',
			type: 'organization',
			roles: [ 'competitor' ],
			items: 31,
		},
		{
			key: IDA,
			name: 'Ida Marsh',
			type: 'person',
			roles: [],
			items: 12,
		},
		{
			key: PRAIRIE,
			name: 'Prairie Signal',
			type: 'organization',
			roles: [ 'former_client' ],
			items: 7,
		},
		{
			key: GULL,
			name: 'Gull Summit',
			type: 'event',
			roles: [],
			items: 2,
		},
	],
	pairs: [
		[ 1, 0, 23 ],
		[ 0, 2, 9 ],
		[ 1, 2, 8 ],
	],
	available: true,
};

/**
 * Entities no pair joins.
 *
 * @param {number} length How many.
 * @return {Object[]} The entities.
 */
const coves = ( length ) =>
	Array.from( { length }, ( _, n ) => ( {
		key: `place:cove-${ n }`,
		name: `Cove ${ n }`,
		type: 'place',
		roles: [],
		items: 1,
	} ) );

/**
 * The answer with that many coves more, each sharing 9 items with Harbor
 * Ledger, so each is drawn.
 *
 * @param {number} length How many.
 * @return {Object} The answer.
 */
const withCoves = ( length ) => ( {
	...ANSWER,
	entities: [ ...ANSWER.entities, ...coves( length ) ],
	pairs: [
		...ANSWER.pairs,
		...Array.from( { length }, ( _, n ) => [
			0,
			ANSWER.entities.length + n,
			9,
		] ),
	],
} );

const NODE = '.newspack-nodes-force-graph__node';

let wire;
let box;
let actions;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	box = installSvgBox( 913, 617 );
	mockRenders = [];
	mockChips = 0;
	wire = intelWire();
	wire.replies.graph = ANSWER;
	actions = { onOpenEntity: jest.fn() };
} );

afterEach( () => {
	box.restore();
} );

/**
 * The block on its page.
 *
 * @param {number} shared Its attribute.
 * @return {import('react').ReactElement} The page.
 */
const block = ( shared = 9 ) => (
	<ComposedPage.Provider
		value={ {
			page: 'graph',
			lens_key: null,
			entity_key: null,
			since: null,
			actions,
		} }
	>
		<graph.View attributes={ { shared } } />
	</ComposedPage.Provider>
);

/**
 * What the force graph was last handed.
 *
 * @return {Object} Its props.
 */
const drawn = () => mockRenders[ mockRenders.length - 1 ];

const canvas = () =>
	screen.getByRole( 'application', { name: 'Entity graph' } );

/**
 * An entity's node on the canvas.
 *
 * @param {string} key The entity.
 * @return {Element} The node.
 */
const node = ( key ) =>
	canvas().querySelector( `${ NODE }[data-id="${ key }"]` );

/** The types checked past the one checked at first, so every fixture shows. */
const WIDE = [ 'Human', 'Locale', 'Happening' ];

/**
 * Render the block, check some types, and wait for its layout's paint.
 *
 * @param {number}   [shared] Its attribute.
 * @param {string[]} [checks] The type checkboxes clicked once it draws.
 * @return {Promise<Object>} What `renderOnPage()` answers.
 */
const open = async ( shared, checks = WIDE ) => {
	const view = renderOnPage( block( shared ) );
	await screen.findByRole( 'application', { name: 'Entity graph' } );
	// A graph of many joined entities untangles before it paints.
	const painted = { timeout: 10000 };
	await waitFor( () => expect( node( HARBOR ) ).not.toBeNull(), painted );
	for ( const name of checks ) {
		fireEvent.click( screen.getByRole( 'checkbox', { name } ) );
	}
	await waitFor( () => expect( node( HARBOR ) ).not.toBeNull(), painted );
	return view;
};

const mouse = ( type, target ) =>
	act( () => {
		target.dispatchEvent(
			new window.MouseEvent( type, {
				view: window,
				bubbles: true,
				cancelable: true,
				clientX: 301,
				clientY: 207,
			} )
		);
	} );

/**
 * Press a part of the canvas.
 *
 * @param {Element} el The part.
 */
const press = ( el ) => {
	mouse( 'mousedown', el );
	mouse( 'mouseup', window );
	mouse( 'click', el );
};

/**
 * Turn a wheel over the canvas.
 *
 * @return {boolean} Whether the canvas took it from the page.
 */
const wheel = () => {
	const turned = new window.WheelEvent( 'wheel', {
		bubbles: true,
		cancelable: true,
		deltaY: 100,
	} );
	act( () => {
		canvas().dispatchEvent( turned );
	} );
	return turned.defaultPrevented;
};

const slider = () =>
	screen.getByRole( 'spinbutton', { name: 'Items shared, at least' } );

const slide = ( value ) =>
	fireEvent.change( slider(), { target: { value: String( value ) } } );

const search = () => screen.getByLabelText( 'Find a name' );

const chips = () =>
	within( screen.getByRole( 'list' ) )
		.getAllByRole( 'button' )
		.map( ( chip ) => chip.textContent );

const chip = ( name ) => screen.getByRole( 'button', { name } );

const endsOf = ( { links } ) =>
	links.map( ( { source, target } ) => [ source, target ] );

/**
 * The inspector, named by the entity it shows.
 *
 * @param {string} name The entity's name.
 * @return {Element} The region.
 */
const inspector = ( name ) => screen.getByRole( 'region', { name } );

const closeInspector = () =>
	fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );

/**
 * The related entities the inspector lists, each with what it shares.
 *
 * @param {string} name The entity shown.
 * @return {string[][]} Each row's name and count.
 */
const relatedOf = ( name ) =>
	within( inspector( name ) )
		.queryAllByRole( 'listitem' )
		.map( ( row ) => [
			within( row ).getByRole( 'button' ).textContent,
			row.querySelector( '.newspack-intelligence__count' ).textContent,
		] );

describe( 'what it reads', () => {
	it( 'shows a skeleton while the answer is owed, and no canvas', async () => {
		wire.replies.graph = () => new Promise( () => {} );
		renderOnPage( block() );
		expect(
			screen.getByRole( 'status', { name: 'Loading' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'application' ) ).toBeNull();
		await waitFor( () => expect( wire.sent( 'graph' ) ).toEqual( [ [] ] ) );
	} );

	it( 'shows the shared read error for a refusal', async () => {
		wire.replies.graph = new Error( 'graph refused 4471' );
		renderOnPage( block() );
		expect(
			await screen.findByText(
				'This could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( mockRenders ).toEqual( [] );
	} );

	it( 'says the store did not open', async () => {
		wire.replies.graph = {
			entities: [],
			pairs: [],
			available: false,
		};
		renderOnPage( block() );
		expect(
			await screen.findByText( /^The intelligence store did not open/ )
		).toBeInTheDocument();
	} );

	it( 'says no item names anyone yet', async () => {
		wire.replies.graph = { ...ANSWER, entities: [], pairs: [] };
		renderOnPage( block() );
		expect(
			await screen.findByText( 'No collected item names anyone yet.' )
		).toBeInTheDocument();
		expect( mockRenders ).toEqual( [] );
	} );
} );

/**
 * A control whose write changes which items show.
 *
 * @return {import('react').ReactElement} The control.
 */
function Exclude() {
	const write = useIntelWrite( 'exclude_item', { dirties: ITEM_READS } );
	return (
		<button onClick={ () => write.run( { item: 'feed:cove-9' } ) }>
			Exclude
		</button>
	);
}

it( 'reads again once a write changes which items show', async () => {
	wire.replies.exclude_item = { ok: true };
	renderOnPage(
		<>
			{ block() }
			<Exclude />
		</>
	);
	await screen.findByRole( 'application' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Exclude' } ) );
	await waitFor( () => expect( wire.sent( 'graph' ) ).toEqual( [ [], [] ] ) );
} );

describe( 'its types', () => {
	const TIDES = 'topic:tides';
	const PRESS = 'product:gull-press';

	beforeEach( () => {
		const typed = ( key, type ) => ( { ...coves( 1 )[ 0 ], key, type } );
		wire.replies.graph = {
			...ANSWER,
			entities: [
				...ANSWER.entities,
				typed( TIDES, 'topic' ),
				typed( PRESS, 'product' ),
			],
			pairs: [ ...ANSWER.pairs, [ 0, 4, 31 ], [ 0, 5, 29 ] ],
		};
	} );

	const check = ( name ) => screen.getByRole( 'checkbox', { name } );
	const shown = () => drawn().nodes.map( ( { id } ) => id );

	it( 'are each a checkbox, in words, organizations alone on at first', async () => {
		await open( 9, [] );
		expect(
			screen
				.getAllByRole( 'checkbox' )
				.map( ( { checked, labels } ) => [
					labels[ 0 ].textContent,
					checked,
				] )
		).toEqual( [
			[ 'Outfit', true ],
			[ 'Human', false ],
			[ 'Locale', false ],
			[ 'Offering', false ],
			[ 'Subject area', false ],
			[ 'Happening', false ],
		] );
		expect( shown() ).toEqual( [ HARBOR, PRAIRIE ] );
		expect( endsOf( drawn() ) ).toEqual( [ [ HARBOR, PRAIRIE ] ] );
		expect( chips() ).toEqual( [ 'Harbor Ledger', 'Prairie Signal' ] );
	} );

	it( 'take their entities, lines and chips in and out without a second read', async () => {
		await open( 9 );
		fireEvent.click( check( 'Subject area' ) );
		expect( shown() ).toEqual( [ HARBOR, IDA, PRAIRIE, TIDES ] );
		expect( endsOf( drawn() ) ).toContainEqual( [ HARBOR, TIDES ] );
		fireEvent.click( check( 'Human' ) );
		expect( shown() ).toEqual( [ HARBOR, PRAIRIE, TIDES ] );
		expect( endsOf( drawn() ) ).toEqual( [
			[ HARBOR, PRAIRIE ],
			[ HARBOR, TIDES ],
		] );
		expect( chips() ).not.toContain( 'Ida Marsh' );
		await act( () => new Promise( ( done ) => setTimeout( done, 50 ) ) );
		expect( wire.sent( 'graph' ) ).toEqual( [ [] ] );
	} );

	it( 'lay the graph out afresh, on a canvas of its own', async () => {
		await open();
		const first = canvas();
		fireEvent.click( check( 'Offering' ) );
		expect( canvas() ).not.toBe( first );
		const second = canvas();
		fireEvent.click( check( 'Offering' ) );
		expect( canvas() ).not.toBe( second );
	} );

	it( 'leave the slider, and the selection they still hold', async () => {
		await open();
		slide( 23 );
		fireEvent.click( chip( 'Ida Marsh' ) );
		fireEvent.click( check( 'Subject area' ) );
		expect( slider() ).toHaveValue( 23 );
		expect( drawn().selectedId ).toBe( IDA );
		expect( inspector( 'Ida Marsh' ) ).toBeInTheDocument();
	} );

	it( 'leave the search text', async () => {
		await open();
		fireEvent.change( search(), { target: { value: 'sig' } } );
		fireEvent.click( check( 'Subject area' ) );
		expect( search() ).toHaveValue( 'sig' );
		expect( chips() ).toEqual( [ 'Prairie Signal' ] );
	} );

	it( 'leave the rest of the list once asked for', async () => {
		wire.replies.graph = {
			...ANSWER,
			entities: [ ...ANSWER.entities, ...coves( 29 ) ],
		};
		await open();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 3 more' } )
		);
		fireEvent.click( check( 'Human' ) );
		expect( chips() ).toHaveLength( 32 );
	} );

	it( 'leave nothing to list with none checked, and say so', async () => {
		await open();
		for ( const name of [ 'Outfit', 'Human', 'Locale', 'Happening' ] ) {
			fireEvent.click( check( name ) );
		}
		expect( shown() ).toEqual( [] );
		expect( screen.getByText( 'No names to list.' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No name here matches.' ) ).toBeNull();
	} );

	it( 'drop the selection with its type, and bring the list back', async () => {
		await open();
		fireEvent.click( chip( 'Ida Marsh' ) );
		expect( drawn().selectedId ).toBe( IDA );
		fireEvent.click( check( 'Human' ) );
		expect( drawn().selectedId ).toBeNull();
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( chips() ).toEqual( [
			'Harbor Ledger',
			'Prairie Signal',
			'Gull Summit',
		] );
		fireEvent.click( check( 'Human' ) );
		expect( drawn().selectedId ).toBeNull();
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( chips() ).toContain( 'Ida Marsh' );
	} );

	it( 'keep a selection whose type stays checked', async () => {
		await open();
		fireEvent.click( chip( 'Ida Marsh' ) );
		fireEvent.click( check( 'Happening' ) );
		fireEvent.click( check( 'Happening' ) );
		expect( drawn().selectedId ).toBe( IDA );
		expect( inspector( 'Ida Marsh' ) ).toBeInTheDocument();
	} );
} );

describe( 'what it draws', () => {
	it( 'draws every entity a pair at or above its attribute joins, and those pairs', async () => {
		await open( 9 );
		expect( slider() ).toHaveValue( 9 );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 3 );
		expect( endsOf( drawn() ) ).toEqual( [
			[ IDA, HARBOR ],
			[ HARBOR, PRAIRIE ],
		] );
		expect( drawn().groups ).toBeUndefined();
	} );

	it( 'filters its lines by the slider without a second read', async () => {
		await open( 9 );
		slide( 23 );
		expect( endsOf( drawn() ) ).toEqual( [ [ IDA, HARBOR ] ] );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 2 );
		slide( 8 );
		expect( endsOf( drawn() ) ).toHaveLength( 3 );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 3 );
		await act( () => new Promise( ( done ) => setTimeout( done, 50 ) ) );
		expect( wire.sent( 'graph' ) ).toEqual( [ [] ] );
	} );

	it( 'takes its attribute’s count when that changes', async () => {
		const { rerender } = await open( 9 );
		slide( 8 );
		rerender( block( 9 ) );
		expect( slider() ).toHaveValue( 8 );
		rerender( block( 23 ) );
		expect( slider() ).toHaveValue( 23 );
		expect( endsOf( drawn() ) ).toEqual( [ [ IDA, HARBOR ] ] );
	} );

	it( 'offers the slider from 1 to 50', async () => {
		await open();
		expect( slider() ).toHaveAttribute( 'min', '1' );
		expect( slider() ).toHaveAttribute( 'max', '50' );
	} );

	it( 'is never live', async () => {
		await open();
		expect( drawn().live ).toBeUndefined();
	} );

	it( 'hands over the same nodes and lines on a render that changes nothing', async () => {
		const { rerender } = await open();
		const { nodes, links } = drawn();
		const renders = mockRenders.length;
		rerender( block() );
		expect( mockRenders.length ).toBeGreaterThan( renders );
		expect( drawn().nodes ).toBe( nodes );
		expect( drawn().links ).toBe( links );
	} );
} );

describe( 'the canvas', () => {
	it( 'selects and lights the entity pressed, and a press of the canvas lets it go', async () => {
		await open( 9 );
		press( node( PRAIRIE ) );
		expect( node( PRAIRIE ) ).toHaveClass( 'is-selected', 'is-lit' );
		expect( node( HARBOR ) ).toHaveClass( 'is-lit' );
		expect( node( IDA ) ).toHaveClass( 'is-dimmed' );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();

		press( canvas() );
		expect( node( PRAIRIE ) ).not.toHaveClass( 'is-selected' );
		expect( node( IDA ) ).not.toHaveClass( 'is-dimmed' );
	} );

	it( 'lights nothing on the canvas for an entity picked from the list it does not draw', async () => {
		await open( 9 );
		expect( node( GULL ) ).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Gull Summit' } )
		);
		expect(
			screen.getByRole( 'heading', { name: 'Gull Summit' } )
		).not.toBeNull();
		expect( drawn().selectedId ).toBeNull();
		expect( node( PRAIRIE ) ).not.toHaveClass( 'is-dimmed' );
	} );

	it( 'opens the entity a double press lands on, and nothing off one', async () => {
		await open();
		press( node( PRAIRIE ) );
		mouse( 'dblclick', canvas() );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
		mouse( 'dblclick', node( IDA ).firstChild );
		expect( actions.onOpenEntity.mock.calls ).toEqual( [ [ IDA ] ] );
	} );

	it( 'opens the selected entity on Enter, and nothing with none selected', async () => {
		await open();
		fireEvent.keyDown( canvas(), { key: 'Enter' } );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
		press( node( PRAIRIE ) );
		fireEvent.keyDown( canvas(), { key: 'x' } );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
		fireEvent.keyDown( canvas(), { key: 'Enter' } );
		expect( actions.onOpenEntity.mock.calls ).toEqual( [ [ PRAIRIE ] ] );
	} );

	it( 'takes a wheel over it without focus', async () => {
		await open();
		expect( canvas() ).not.toHaveFocus();
		expect( wheel() ).toBe( true );
	} );
} );

describe( 'the list', () => {
	it( 'offers each entity as a chip, in the answer’s order', async () => {
		await open();
		expect( chips() ).toEqual( [
			'Harbor Ledger',
			'Ida Marsh',
			'Prairie Signal',
			'Gull Summit',
		] );
	} );

	it( 'selects the entity picked, and shows the inspector in its place', async () => {
		await open();
		fireEvent.click( chip( 'Ida Marsh' ) );
		expect( node( IDA ) ).toHaveClass( 'is-selected' );
		expect( inspector( 'Ida Marsh' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Find a name' ) ).toBeNull();
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
	} );

	it( 'gives way to the inspector for a selection made on the canvas, and comes back at its end', async () => {
		await open();
		press( node( PRAIRIE ) );
		expect( inspector( 'Prairie Signal' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'list', { name: 'Names' } ) ).toBeNull();
		press( canvas() );
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( chips() ).toHaveLength( 4 );
	} );

	it( 'keeps its search text and the rest it was asked for through a selection', async () => {
		wire.replies.graph = withCoves( 29 );
		await open();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 3 more' } )
		);
		press( node( 'place:cove-28' ) );
		press( canvas() );
		expect( chips() ).toHaveLength( 33 );
		fireEvent.change( search(), { target: { value: 'cove 2' } } );
		press( node( PRAIRIE ) );
		fireEvent.keyDown( inspector( 'Prairie Signal' ), {
			key: 'Escape',
		} );
		expect( search() ).toHaveValue( 'cove 2' );
		expect( chips() ).toEqual( [
			'Cove 2',
			...Array.from( { length: 9 }, ( _, n ) => `Cove ${ 20 + n }` ),
		] );
	} );

	it( 'lists the names holding the text searched for, whatever its case', async () => {
		await open();
		fireEvent.change( search(), { target: { value: ' SIGNAL ' } } );
		expect( chips() ).toEqual( [ 'Prairie Signal' ] );
		fireEvent.change( search(), { target: { value: 'mar' } } );
		expect( chips() ).toEqual( [ 'Ida Marsh' ] );
	} );

	it( 'selects the first it lists on Enter in the search', async () => {
		await open();
		fireEvent.change( search(), { target: { value: 'a' } } );
		fireEvent.keyDown( search(), { key: 'Enter' } );
		expect( drawn().selectedId ).toBe( HARBOR );
		closeInspector();
		fireEvent.change( search(), { target: { value: 'gul' } } );
		fireEvent.keyDown( search(), { key: 'l' } );
		expect( drawn().selectedId ).toBeNull();
		fireEvent.keyDown( search(), { key: 'Enter' } );
		expect( inspector( 'Gull Summit' ) ).not.toBeNull();
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
	} );

	it.each( [ [ '' ], [ '   ' ] ] )(
		'selects nothing on Enter in a search holding "%s"',
		async ( text ) => {
			await open();
			fireEvent.change( search(), { target: { value: `${ text }x` } } );
			fireEvent.change( search(), { target: { value: text } } );
			fireEvent.keyDown( search(), { key: 'Enter' } );
			expect( drawn().selectedId ).toBeNull();
		}
	);

	it( 'says no name holds the text, and Enter then selects nothing', async () => {
		await open();
		fireEvent.change( search(), { target: { value: 'walrus' } } );
		expect(
			screen.getByText( 'No name here matches.' )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'list' ) ).toBeNull();
		fireEvent.keyDown( search(), { key: 'Enter' } );
		expect( drawn().selectedId ).toBeNull();
	} );

	it( 'draws no chip again as the slider moves', async () => {
		await open( 9 );
		const chipsDrawn = mockChips;
		slide( 23 );
		expect( endsOf( drawn() ) ).toHaveLength( 1 );
		expect( mockChips ).toBe( chipsDrawn );
	} );

	it( 'lists 30 and the rest once asked', async () => {
		wire.replies.graph = {
			...ANSWER,
			entities: [ ...ANSWER.entities, ...coves( 29 ) ],
		};
		await open();
		expect( chips() ).toHaveLength( 30 );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 3 more' } )
		);
		expect( chips() ).toHaveLength( 33 );
	} );
} );

describe( 'the inspector', () => {
	it( 'names the entity, its type and open roles, and its items', async () => {
		await open();
		press( node( HARBOR ) );
		const region = inspector( 'Harbor Ledger' );
		expect(
			within( region ).getByRole( 'heading', { level: 2 } )
		).toHaveTextContent( 'Harbor Ledger' );
		expect( region ).toHaveTextContent( 'Outfit, Rival' );
		expect( region ).toHaveTextContent( '31 items about it' );
		press( node( IDA ) );
		expect( inspector( 'Ida Marsh' ) ).toHaveTextContent( 'Human' );
		expect( inspector( 'Ida Marsh' ) ).not.toHaveTextContent( ',' );
		expect( inspector( 'Ida Marsh' ) ).toHaveTextContent(
			'12 items about it'
		);
	} );

	it( 'lists the entities its drawn lines reach, the most shared first', async () => {
		wire.replies.graph = {
			...ANSWER,
			pairs: [
				[ 0, 2, 9 ],
				[ 3, 0, 14 ],
				[ 1, 0, 23 ],
				[ 1, 2, 8 ],
			],
		};
		await open( 9 );
		press( node( HARBOR ) );
		expect( relatedOf( 'Harbor Ledger' ) ).toEqual( [
			[ 'Ida Marsh', '23 shared' ],
			[ 'Gull Summit', '14 shared' ],
			[ 'Prairie Signal', '9 shared' ],
		] );
		slide( 14 );
		expect( relatedOf( 'Harbor Ledger' ) ).toEqual( [
			[ 'Ida Marsh', '23 shared' ],
			[ 'Gull Summit', '14 shared' ],
		] );
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Human' } ) );
		expect( relatedOf( 'Harbor Ledger' ) ).toEqual( [
			[ 'Gull Summit', '14 shared' ],
		] );
	} );

	it( 'says no drawn line reaches it', async () => {
		await open( 9 );
		fireEvent.click( chip( 'Gull Summit' ) );
		expect( relatedOf( 'Gull Summit' ) ).toEqual( [] );
		expect( inspector( 'Gull Summit' ) ).toHaveTextContent(
			'No related entity is drawn.'
		);
	} );

	it( 'selects a related entity it lists, opening nothing', async () => {
		await open( 9 );
		press( node( HARBOR ) );
		fireEvent.click(
			within( inspector( 'Harbor Ledger' ) ).getByRole( 'button', {
				name: 'Prairie Signal',
			} )
		);
		expect( drawn().selectedId ).toBe( PRAIRIE );
		expect( node( PRAIRIE ) ).toHaveClass( 'is-selected' );
		expect( relatedOf( 'Prairie Signal' ) ).toEqual( [
			[ 'Harbor Ledger', '9 shared' ],
		] );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
	} );

	it( 'opens the profile as Enter does', async () => {
		await open();
		press( node( PRAIRIE ) );
		fireEvent.click(
			within( inspector( 'Prairie Signal' ) ).getByRole( 'button', {
				name: 'Open profile',
			} )
		);
		expect( actions.onOpenEntity.mock.calls ).toEqual( [ [ PRAIRIE ] ] );
		expect( drawn().selectedId ).toBe( PRAIRIE );
	} );

	it.each( [
		[ 'its close button', () => closeInspector() ],
		[
			'Escape inside it',
			() =>
				fireEvent.keyDown(
					within( inspector( 'Ida Marsh' ) ).getByRole( 'button', {
						name: 'Open profile',
					} ),
					{ key: 'Escape' }
				),
		],
	] )( 'clears the selection on %s', async ( _, clear ) => {
		await open();
		press( node( IDA ) );
		clear();
		expect( drawn().selectedId ).toBeNull();
		expect( node( IDA ) ).not.toHaveClass( 'is-selected' );
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( chips() ).toHaveLength( 4 );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
	} );

	it( 'leaves every key but Escape alone', async () => {
		await open();
		press( node( IDA ) );
		fireEvent.keyDown( inspector( 'Ida Marsh' ), { key: 'q' } );
		expect( drawn().selectedId ).toBe( IDA );
	} );

	it( 'takes focus from a chip it replaces, and leaves the canvas focused', async () => {
		await open();
		const picked = chip( 'Ida Marsh' );
		picked.focus();
		fireEvent.click( picked );
		expect(
			within( inspector( 'Ida Marsh' ) ).getByRole( 'heading', {
				level: 2,
			} )
		).toHaveFocus();
		fireEvent.click(
			within( inspector( 'Ida Marsh' ) ).getByRole( 'button', {
				name: 'Harbor Ledger',
			} )
		);
		expect(
			within( inspector( 'Harbor Ledger' ) ).getByRole( 'heading', {
				level: 2,
			} )
		).toHaveFocus();
		closeInspector();
		canvas().focus();
		press( node( PRAIRIE ) );
		expect( canvas() ).toHaveFocus();
	} );

	it( 'takes no focus on its first load', async () => {
		await open( 9, [] );
		expect( document.body ).toHaveFocus();
	} );

	it.each( [
		[
			'its close button',
			() => {
				const close = screen.getByRole( 'button', { name: 'Close' } );
				close.focus();
				fireEvent.click( close );
			},
		],
		[
			'Escape inside it',
			() => {
				const button = screen.getByRole( 'button', {
					name: 'Open profile',
				} );
				button.focus();
				fireEvent.keyDown( button, { key: 'Escape' } );
			},
		],
	] )(
		'hands focus to the chip of the entity it closed on %s',
		async ( _, clear ) => {
			await open();
			fireEvent.click( chip( 'Prairie Signal' ) );
			clear();
			expect( screen.queryByRole( 'region' ) ).toBeNull();
			expect( chip( 'Prairie Signal' ) ).toHaveFocus();
		}
	);

	it.each( [
		[ 'a search that leaves it out', 'sig', HARBOR, 'Harbor Ledger' ],
		[ 'a cap it stands past', '', 'place:cove-28', 'Cove 28' ],
	] )( 'hands focus to the search where %s', async ( _, text, key, name ) => {
		wire.replies.graph = withCoves( 29 );
		await open();
		fireEvent.change( search(), { target: { value: text } } );
		press( node( key ) );
		const close = within( inspector( name ) ).getByRole( 'button', {
			name: 'Close',
		} );
		close.focus();
		fireEvent.click( close );
		expect( search() ).toHaveFocus();
		expect( search() ).toHaveValue( text );
	} );

	it( 'leaves the focus on the canvas when the canvas clears it', async () => {
		await open();
		canvas().focus();
		press( node( IDA ) );
		press( canvas() );
		expect( screen.queryByRole( 'region' ) ).toBeNull();
		expect( canvas() ).toHaveFocus();
		press( node( IDA ) );
		fireEvent.keyDown( canvas(), { key: 'Escape' } );
		expect( drawn().selectedId ).toBeNull();
		expect( canvas() ).toHaveFocus();
	} );

	it( 'reaches each related entity and Open profile by Tab', async () => {
		await open( 9 );
		press( node( HARBOR ) );
		const region = inspector( 'Harbor Ledger' );
		const buttons = within( region ).getAllByRole( 'button' );
		expect( buttons.map( ( one ) => one.textContent ) ).toEqual( [
			'Close',
			'Open profile',
			'Ida Marsh',
			'Prairie Signal',
		] );
		buttons.forEach( ( one ) => {
			expect( one.tagName ).toBe( 'BUTTON' );
			expect( one ).not.toHaveAttribute( 'tabindex' );
		} );
	} );

	it( 'asks the server for nothing', async () => {
		wire.replies.exclude_item = { ok: true };
		renderOnPage(
			<>
				{ block( 9 ) }
				<Exclude />
			</>
		);
		await waitFor( () => expect( node( HARBOR ) ).not.toBeNull() );
		await waitFor( () =>
			expect( wire.sent( 'health' ) ).not.toEqual( [] )
		);
		const before = wire.verbs().length;
		press( node( HARBOR ) );
		fireEvent.click(
			within( inspector( 'Harbor Ledger' ) ).getByRole( 'button', {
				name: 'Prairie Signal',
			} )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Exclude' } ) );
		await waitFor( () => expect( wire.sent( 'graph' ) ).toHaveLength( 2 ) );
		closeInspector();
		expect( wire.verbs().slice( before ) ).toEqual( [
			'exclude_item',
			'graph',
		] );
	} );
} );

describe( 'its controls', () => {
	it( 'write the least shared it opens at', () => {
		const setAttributes = jest.fn();
		render(
			<graph.Controls
				attributes={ { shared: 11 } }
				setAttributes={ setAttributes }
			/>
		);
		expect( slider() ).toHaveValue( 11 );
		expect( slider() ).toHaveAttribute( 'min', '1' );
		expect( slider() ).toHaveAttribute( 'max', '50' );
		slide( 17 );
		expect( setAttributes ).toHaveBeenLastCalledWith( { shared: 17 } );
	} );
} );
