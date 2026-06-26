/* eslint-env jest */
/**
 * TopTable — the "Top items by source" card. Reads ONLY the top-table:view slice
 * ({ top:{ source:[{title,score}] } }) and renders a ranked table per source with
 * inline score bars (sized against the global top score), plus the Top score KPI.
 */

import { render, screen } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { TopTableViewNode } from '../../nodes/top-table-view-node';
import { TopTable } from '../TopTable';

beforeEach( () => Core.reset() );

function mountSlice( slice ) {
	const node = new TopTableViewNode();
	node.name = 'top-table:view';
	node.setState( 'view', slice );
	return node;
}

const TOP = {
	github: [ { title: 'Big release', score: 9.5 } ],
	linear: [ { title: 'Hot thread', score: 4 } ],
};

describe( 'TopTable', () => {
	it( 'renders a ranked table per source, each item under its source', () => {
		mountSlice( { top: TOP } );
		render( <TopTable /> );
		expect( screen.getByText( 'Big release' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Hot thread' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'table' ) ).toHaveLength( 2 );
		expect(
			screen.getByRole( 'heading', { name: 'github' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'linear' } )
		).toBeInTheDocument();
	} );

	it( 'renders each item with a rank and a score bar sized by the global top score', () => {
		mountSlice( { top: TOP } );
		const { container } = render( <TopTable /> );
		// Each source's table ranks from #1 (one item per source in the fixture).
		expect( screen.getAllByText( '#1' ) ).toHaveLength( 2 );
		const bars = container.querySelectorAll( '.eai-insights__score-bar' );
		expect( bars.length ).toBe( 2 );
		// Bars relative to the global top score (9.5), so the top item is full-width.
		expect( bars[ 0 ].style.width ).toBe( '100%' );
	} );

	it( 'shows the Top score KPI', () => {
		mountSlice( { top: TOP } );
		const { container } = render( <TopTable /> );
		expect( screen.getByText( /top score/i ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.eai-insights__stat-num' ).textContent
		).toBe( '9.5' );
	} );

	it( 'shows an empty state (no table) until there are items', () => {
		mountSlice( { top: {} } );
		render( <TopTable /> );
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( /no scored items yet/i )
		).toBeInTheDocument();
	} );

	it( 'surfaces a slice error as a notice', () => {
		mountSlice( { top: {}, error: 'top read failed' } );
		render( <TopTable /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			/top read failed/
		);
	} );
} );
