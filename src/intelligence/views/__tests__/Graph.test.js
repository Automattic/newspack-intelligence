/* eslint-env jest */
/**
 * The Graph tab draws the page data's graph layout under the shared header,
 * whose ⋯ menu offers a curator **Edit layout**; a layout that no longer
 * reads shows the shared read error.
 */

import { fireEvent, screen } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { COMPOSITIONS, pageData } from './fixtures';
import Graph from '../Graph';

/** Each address Edit layout would load, in place of the navigation jsdom refuses. */
const mockLoads = [];
jest.mock( '@newspack-nodes/shared/composition/page', () => {
	const actual = jest.requireActual(
		'@newspack-nodes/shared/composition/page'
	);
	return {
		...actual,
		editLayoutControl: ( target, pages ) => ( {
			...actual.editLayoutControl( target, pages ),
			onClick: () =>
				mockLoads.push(
					actual.editSearch(
						globalThis.location.search,
						target,
						pages
					)
				),
		} ),
	};
} );

const LAYOUT = COMPOSITIONS[ 'newspack-intelligence/graph' ];

let wire;
let actions;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.graph = {
		entities: [
			{
				key: 'organization:harbor-ledger',
				name: 'Harbor Ledger',
				type: 'organization',
				roles: [ 'competitor' ],
				items: 31,
			},
		],
		pairs: [],
		available: true,
	};
	actions = { onOpenEntity: jest.fn() };
} );

/**
 * Render the view.
 *
 * @param {Object} [props] Props over those of a reader.
 * @return {Object} What `renderOnPage()` answers.
 */
const renderGraph = ( props = {} ) =>
	renderOnPage(
		<Graph
			layout={ LAYOUT }
			canTune={ false }
			actions={ actions }
			onRestored={ jest.fn() }
			{ ...props }
		/>
	);

const menu = () => screen.queryByRole( 'button', { name: 'Graph actions' } );

describe( 'Graph', () => {
	it( 'lays out its blocks under the header, handing them the page’s actions', async () => {
		renderGraph();
		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Graph' } )
		).toBeInTheDocument();
		const chip = await screen.findByRole( 'button', {
			name: 'Harbor Ledger',
		} );
		fireEvent.click( chip );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Open profile' } )
		);
		expect( actions.onOpenEntity.mock.calls ).toEqual( [
			[ 'organization:harbor-ledger' ],
		] );
	} );

	it( 'opens at the least shared its layout names', async () => {
		renderGraph( {
			layout: {
				...LAYOUT,
				blocks: [
					{ ...LAYOUT.blocks[ 0 ], attributes: { shared: 13 } },
				],
			},
		} );
		expect(
			screen.getByRole( 'spinbutton', { name: 'Items shared, at least' } )
		).toHaveValue( 13 );
		await screen.findByRole( 'button', { name: 'Harbor Ledger' } );
	} );

	it( 'offers a curator Edit layout in its ⋯ menu, and a reader no menu', async () => {
		const { unmount } = renderGraph();
		await screen.findByRole( 'button', { name: 'Harbor Ledger' } );
		expect( menu() ).toBeNull();
		unmount();
		renderGraph( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Harbor Ledger' } );
		expect(
			menu().closest( '.newspack-nodes-composed-section-head' )
		).toHaveTextContent( 'Graph' );
		fireEvent.click( menu() );
		fireEvent.click(
			await screen.findByRole( 'menuitem', { name: 'Edit layout' } )
		);
		expect( mockLoads ).toEqual( [ '?view=graph&edit=graph' ] );
	} );

	it( 'shows the shared read error for a layout that no longer reads', () => {
		renderGraph( {
			layout: {
				context: 'newspack-intelligence/graph',
				error: 'this layout could not be read',
			},
		} );
		expect(
			screen.getByText( 'This could not be read just now.' )
		).toBeInTheDocument();
		expect( wire.sent( 'graph' ) ).toEqual( [] );
	} );
} );
