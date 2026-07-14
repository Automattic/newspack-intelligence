/* eslint-env jest */
/**
 * PublisherInsightsPage — mounts the genuine node graph (via PublisherInsights)
 * and rides the substrate debug overlay alongside (debug-gated, its own storage
 * key) so the live browser graph is inspectable like every other dashboard.
 */

import { render, screen } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';

// Page-hidden: no poll runs; mount-poll's fetch rejects in jsdom, swallowed.
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => false,
} ) );

// Stub the debug overlay: assert it's mounted with a per-dashboard storage key.
jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: ( props ) => {
		global.__debugOverlayProps = props;
		return <div data-testid="debug-overlay" />;
	},
} ) );

import PublisherInsightsPage from '../PublisherInsightsPage';

beforeEach( () => Core.reset() );

describe( 'PublisherInsightsPage', () => {
	it( 'renders the Publisher Insights heading', () => {
		render( <PublisherInsightsPage /> );
		expect(
			screen.getByRole( 'heading', { name: 'Publisher Insights' } )
		).toBeInTheDocument();
	} );

	it( 'shows the empty state (no table) until the poll fills it', () => {
		render( <PublisherInsightsPage /> );
		expect(
			screen.getByText( /no scored items yet/i )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'mounts the substrate debug overlay with a per-dashboard storage key', () => {
		render( <PublisherInsightsPage /> );
		expect( screen.getByTestId( 'debug-overlay' ) ).toBeInTheDocument();
		expect( global.__debugOverlayProps.storageKey ).toContain(
			'publisher-insights'
		);
	} );
} );
