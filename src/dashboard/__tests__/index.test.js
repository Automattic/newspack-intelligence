/* eslint-env jest */

describe( 'dashboard index', () => {
	function loadIndex() {
		jest.resetModules();
		const render = jest.fn();
		const createRoot = jest.fn( () => ( { render } ) );
		jest.doMock( '@wordpress/element', () => ( { createRoot } ) );
		jest.doMock( '../PublisherInsightsPage', () => ( {
			__esModule: true,
			default: function PublisherInsightsPage() {
				return null;
			},
		} ) );
		require( '../index' );
		return { createRoot, render };
	}

	beforeEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'mounts the insights page on DOMContentLoaded when the mount exists', () => {
		document.body.innerHTML =
			'<div id="newspack-ai-newsletter-insights"></div>';
		const { createRoot, render } = loadIndex();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expect( createRoot ).toHaveBeenCalledWith(
			document.getElementById( 'newspack-ai-newsletter-insights' )
		);
		expect( render ).toHaveBeenCalledTimes( 1 );
		expect( render.mock.calls[ 0 ][ 0 ].type.name ).toBe(
			'PublisherInsightsPage'
		);
	} );

	it( 'does nothing when the mount is absent', () => {
		const { createRoot, render } = loadIndex();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expect( createRoot ).not.toHaveBeenCalled();
		expect( render ).not.toHaveBeenCalled();
	} );
} );
