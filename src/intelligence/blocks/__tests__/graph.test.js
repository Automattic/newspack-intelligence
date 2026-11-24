/* eslint-env jest */
/**
 * The `graph` block reads `graph` once and draws every entity on the shared
 * force graph: its slider filters the lines it already holds, Topics asks
 * again, a selection lights what it reaches, and the list beside the canvas
 * selects and opens what a press on it does. The canvas takes every wheel over
 * it.
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
	truncated: false,
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
	document.documentElement.lang = '';
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
			truncated: false,
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

	it( 'says how many pairs a cut answer holds, in the page’s language', async () => {
		document.documentElement.lang = 'de';
		wire.replies.graph = {
			...ANSWER,
			pairs: Array.from( { length: 1237 }, () => [ 1, 2, 3 ] ),
			truncated: true,
		};
		await open();
		expect(
			screen.getByText(
				'Only the 1.237 pairs sharing the most are read.'
			)
		).toBeInTheDocument();
	} );

	it( 'says nothing of an answer that was not cut', async () => {
		await open();
		expect( screen.queryByText( /pairs sharing the most/ ) ).toBeNull();
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

describe( 'what it draws', () => {
	it( 'draws every entity, and the pairs at or above its attribute', async () => {
		await open( 9 );
		expect( slider() ).toHaveValue( 9 );
		expect( canvas().querySelectorAll( NODE ) ).toHaveLength( 4 );
		expect( endsOf( drawn() ) ).toEqual( [
			[ IDA, HARBOR ],
			[ HARBOR, PRAIRIE ],
		] );
		expect( drawn().groups.map( ( { label } ) => label ) ).toEqual( [
			'Rival',
			'Human',
			'Lapsed member',
			'Happening',
		] );
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

	it( 'is never live and keys no layout', async () => {
		await open();
		expect( drawn().live ).toBeUndefined();
		expect( drawn().layoutKey ).toBeUndefined();
	} );

	it( 'hands over the same nodes, lines and hulls on a render that changes nothing', async () => {
		const { rerender } = await open();
		const { nodes, links, groups } = drawn();
		const renders = mockRenders.length;
		rerender( block() );
		expect( mockRenders.length ).toBeGreaterThan( renders );
		expect( drawn().nodes ).toBe( nodes );
		expect( drawn().links ).toBe( links );
		expect( drawn().groups ).toBe( groups );
	} );
} );

describe( 'Topics', () => {
	const TOPIC = 'topic:tides';
	const WITH_TOPICS = {
		...ANSWER,
		entities: [
			...ANSWER.entities.slice( 0, 3 ),
			{
				key: TOPIC,
				name: 'Tides',
				type: 'topic',
				roles: [],
				items: 1,
			},
		],
	};

	beforeEach( () => {
		wire.replies.graph = ( args ) => ( args.length ? WITH_TOPICS : ANSWER );
	} );

	const toggle = () =>
		fireEvent.click( screen.getByRole( 'checkbox', { name: 'Topics' } ) );

	it( 'is off, and on reads again with topic links', async () => {
		await open();
		expect(
			screen.getByRole( 'checkbox', { name: 'Topics' } )
		).not.toBeChecked();
		toggle();
		await waitFor( () => expect( node( TOPIC ) ).not.toBeNull() );
		expect( wire.sent( 'graph' ) ).toEqual( [ [], [ '--topics' ] ] );
		expect( node( GULL ) ).toBeNull();
	} );

	it( 'keeps the slider, and the selection while the answer still holds it', async () => {
		await open( 9 );
		slide( 23 );
		fireEvent.click( chip( 'Ida Marsh' ) );
		toggle();
		await waitFor( () => expect( node( TOPIC ) ).not.toBeNull() );
		expect( slider() ).toHaveValue( 23 );
		expect( drawn().selectedId ).toBe( IDA );
	} );

	it( 'keeps the search text', async () => {
		await open();
		fireEvent.change( search(), { target: { value: 'sig' } } );
		toggle();
		await waitFor( () => expect( node( TOPIC ) ).not.toBeNull() );
		expect( search() ).toHaveValue( 'sig' );
		expect( chips() ).toEqual( [ 'Prairie Signal' ] );
	} );

	it( 'keeps the rest of the list once asked for', async () => {
		wire.replies.graph = ( args ) => ( {
			...ANSWER,
			entities: [ ...ANSWER.entities, ...coves( args.length ? 37 : 29 ) ],
		} );
		await open();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show 3 more' } )
		);
		toggle();
		await waitFor( () => expect( chips() ).toHaveLength( 41 ) );
	} );

	it( 'lays its graph out afresh, the first read and the remembered one alike', async () => {
		await open();
		const first = canvas();
		toggle();
		await waitFor( () => expect( node( TOPIC ) ).not.toBeNull() );
		const second = canvas();
		expect( second ).not.toBe( first );
		toggle();
		await waitFor( () => expect( node( GULL ) ).not.toBeNull() );
		expect( canvas() ).not.toBe( second );
	} );

	it( 'holds the canvas’s place while its answer is owed', async () => {
		wire.replies.graph = ( args ) =>
			args.length ? new Promise( () => {} ) : ANSWER;
		await open();
		const place = canvas().closest( '.newspack-intelligence__graph' );
		expect( place ).not.toBeNull();
		toggle();
		const owed = screen.getByRole( 'status', { name: 'Loading' } );
		expect( owed ).toHaveClass( 'newspack-intelligence__graph' );
		expect( owed.firstChild ).toHaveClass( 'newspack-nodes-skeleton' );
	} );

	it( 'selects none once the answer no longer holds the selected entity', async () => {
		await open();
		fireEvent.click( chip( 'Gull Summit' ) );
		expect( drawn().selectedId ).toBe( GULL );
		toggle();
		await waitFor( () => expect( node( TOPIC ) ).not.toBeNull() );
		expect( drawn().selectedId ).toBeNull();
		expect( node( HARBOR ) ).not.toHaveClass( 'is-dimmed' );
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
