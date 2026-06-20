/* eslint-env jest */
import { render, screen } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';

// Page-hidden so the mounted graph's poll interval never starts in this smoke
// test; the one immediate mount-poll uses a default CommandClient whose fetch
// rejects in jsdom and is swallowed (rate-limited) — no network, no crash.
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => false,
} ) );

// Stub the substrate debug overlay so the page test asserts it's mounted with a
// per-dashboard storage key, without exercising the overlay's own internals.
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
