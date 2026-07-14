/* eslint-env jest */
/**
 * SourceCounts — the "By source" card. Reads ONLY the source-counts:view slice
 * ({ sources:{name:count} }) and renders one proportion bar per source plus the
 * Sources KPI. A slice error surfaces as a notice; no sources yet shows a hint.
 */

import { render, screen } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { SourceCountsViewNode } from '../../nodes/source-counts-view-node';
import { SourceCounts } from '../SourceCounts';

beforeEach( () => Core.reset() );

// Mount the view node and publish a slice as the graph reply path does.
function mountSlice( slice ) {
	const node = new SourceCountsViewNode();
	node.name = 'source-counts:view';
	node.setState( 'view', slice );
	return node;
}

describe( 'SourceCounts', () => {
	it( 'renders a proportion bar per source with an inline width and the Sources KPI', () => {
		mountSlice( { sources: { github: 2, linear: 1 } } );
		const { container } = render( <SourceCounts /> );
		const names = [
			...container.querySelectorAll( '.eai-insights__source-name' ),
		].map( ( el ) => el.textContent );
		expect( names ).toEqual( [ 'github', 'linear' ] );
		const bars = container.querySelectorAll( '.eai-insights__bar-fill' );
		expect( bars.length ).toBe( 2 );
		// github is 2/3 of the total.
		expect( bars[ 0 ].style.width ).toContain( '66.6' );
		expect( screen.getByText( /^sources$/i ) ).toBeInTheDocument();
	} );

	it( 'shows an empty hint when there are no sources yet', () => {
		mountSlice( { sources: {} } );
		render( <SourceCounts /> );
		expect( screen.getByText( /no sources yet/i ) ).toBeInTheDocument();
	} );

	it( 'surfaces a slice error as a notice', () => {
		mountSlice( { sources: {}, error: 'counts read failed' } );
		render( <SourceCounts /> );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			/counts read failed/
		);
	} );
} );
