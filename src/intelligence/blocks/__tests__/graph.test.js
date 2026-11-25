/* eslint-env jest */
/**
 * The `graph` block reads `graph` once and draws every entity on the shared
 * force graph: its slider and its types filter what it holds, a
 * selection lights what it reaches, and the list beside the canvas selects and
 * opens what a press on it does. The canvas takes every wheel over it.
 *
 * The force graph is the real one in a box jsdom lacks, wrapped to show what
 * it was handed, and each chip is wrapped to count what it draws.
 */

let mockRenders = [];

let mockChips = 0;

jest.mock( '@newspack-nodes/shared/forceGraph', () => ( {
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

/**
 * Render the block and wait for its first layout's paint.
 *
 * @param {number} [shared] Its attribute.
 * @return {Promise<Object>} What `renderOnPage()` answers.
 */
const open = async ( shared ) => {
	const view = renderOnPage( block( shared ) );
	await screen.findByRole( 'application', { name: 'Entity graph' } );
	await waitFor( () => expect( node( HARBOR ) ).not.toBeNull() );
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

	it( 'are each a checkbox, in words, all on but topics and products', async () => {
		await open();
		expect(
			screen
				.getAllByRole( 'checkbox' )
				.map( ( { checked, labels } ) => [
					labels[ 0 ].textContent,
					checked,
				] )
		).toEqual( [
			[ 'Outfit', true ],
			[ 'Human', true ],
			[ 'Locale', true ],
			[ 'Offering', false ],
			[ 'Subject area', false ],
			[ 'Happening', true ],
		] );
		expect( shown() ).toEqual( [ HARBOR, IDA, PRAIRIE, GULL ] );
	} );

	it( 'take their entities, lines and chips in and out without a second read', async () => {
		await open( 9 );
		fireEvent.click( check( 'Subject area' ) );
		expect( shown() ).toEqual( [ HARBOR, IDA, PRAIRIE, GULL, TIDES ] );
		expect( endsOf( drawn() ) ).toContainEqual( [ HARBOR, TIDES ] );
		fireEvent.click( check( 'Human' ) );
		expect( shown() ).toEqual( [ HARBOR, PRAIRIE, GULL, TIDES ] );
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

	it( 'drop the selection with its type', async () => {
		await open();
		fireEvent.click( chip( 'Ida Marsh' ) );
		expect( drawn().selectedId ).toBe( IDA );
		fireEvent.click( check( 'Human' ) );
		expect( drawn().selectedId ).toBeNull();
	} );
} );

describe( 'what it draws', () => {
	it( 'draws every entity, and the pairs at or above its attribute', async () => {
		await open( 9 );
		expect( slider() ).toHaveValue( 9 );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 4 );
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
		slide( 8 );
		expect( endsOf( drawn() ) ).toHaveLength( 3 );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 4 );
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
		expect( node( GULL ) ).toHaveClass( 'is-dimmed' );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();

		press( canvas() );
		expect( node( PRAIRIE ) ).not.toHaveClass( 'is-selected' );
		expect( node( GULL ) ).not.toHaveClass( 'is-dimmed' );
	} );

	it( 'opens the entity a double press lands on, and nothing off one', async () => {
		await open();
		press( node( GULL ) );
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

	it( 'selects the entity picked on the canvas, and opens it picked again', async () => {
		await open();
		fireEvent.click( chip( 'Ida Marsh' ) );
		expect( node( IDA ) ).toHaveClass( 'is-selected' );
		expect( chip( 'Ida Marsh' ) ).toHaveAccessibleDescription(
			'Selected. Press again to open its profile.'
		);
		expect( chip( 'Ida Marsh' ) ).toHaveAttribute( 'aria-current', 'true' );
		expect( chip( 'Ida Marsh' ) ).not.toHaveAttribute( 'aria-pressed' );
		expect( chip( 'Gull Summit' ) ).not.toHaveAccessibleDescription();
		expect( chip( 'Gull Summit' ) ).not.toHaveAttribute( 'aria-current' );
		expect( actions.onOpenEntity ).not.toHaveBeenCalled();
		fireEvent.click( chip( 'Ida Marsh' ) );
		expect( actions.onOpenEntity.mock.calls ).toEqual( [ [ IDA ] ] );
	} );

	it( 'shows a selection made on the canvas, and its end', async () => {
		await open();
		press( node( PRAIRIE ) );
		expect( chip( 'Prairie Signal' ) ).toHaveAttribute(
			'aria-current',
			'true'
		);
		press( canvas() );
		expect( chip( 'Prairie Signal' ) ).not.toHaveAttribute(
			'aria-current'
		);
	} );

	it( 'shows a selection past the 30 it lists, in its place', async () => {
		wire.replies.graph = {
			...ANSWER,
			entities: [ ...ANSWER.entities, ...coves( 29 ) ],
		};
		await open();
		press( node( 'place:cove-28' ) );
		expect( chips() ).toHaveLength( 31 );
		expect( chips()[ 30 ] ).toBe( 'Cove 28' );
		expect( chip( 'Cove 28' ) ).toHaveAttribute( 'aria-current', 'true' );
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
		fireEvent.change( search(), { target: { value: 'gul' } } );
		fireEvent.keyDown( search(), { key: 'l' } );
		expect( drawn().selectedId ).toBe( HARBOR );
		fireEvent.keyDown( search(), { key: 'Enter' } );
		expect( drawn().selectedId ).toBe( GULL );
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
