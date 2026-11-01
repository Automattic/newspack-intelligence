/* eslint-env jest */
/**
 * The bundle entry mounts IntelligencePage on its admin page's mount point,
 * handing it the curation and operator flags and the vocabulary PHP
 * localized. `wp_localize_script()` casts a top-level scalar to a string, so
 * each flag arrives as `'1'` for a reader holding its role and `''` for
 * anyone else.
 */

describe( 'intelligence index', () => {
	function loadIndex() {
		jest.resetModules();
		const render = jest.fn();
		const createRoot = jest.fn( () => ( { render } ) );
		jest.doMock( '@wordpress/element', () => ( { createRoot } ) );
		jest.doMock( '../IntelligencePage', () => ( {
			__esModule: true,
			default: function IntelligencePage() {
				return null;
			},
		} ) );
		require( '../index' );
		return { createRoot, render };
	}

	beforeEach( () => {
		document.body.innerHTML = '';
		window.NewspackNodesData = {
			restUrl: '/wp-json/',
			nonce: 'NONCE',
			canTune: '1',
			canManage: '1',
			vocabulary: { roles: [ 'funder' ] },
		};
	} );

	it( 'mounts the page with the localized flag and vocabulary', () => {
		document.body.innerHTML = '<div id="newspack-intelligence-app"></div>';
		const { createRoot, render } = loadIndex();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expect( createRoot ).toHaveBeenCalledWith(
			document.getElementById( 'newspack-intelligence-app' )
		);
		const element = render.mock.calls[ 0 ][ 0 ];
		expect( element.type.name ).toBe( 'IntelligencePage' );
		expect( element.props ).toEqual( {
			canTune: true,
			canManage: true,
			vocabulary: { roles: [ 'funder' ] },
		} );
	} );

	it.each( [ '', 'yes', true ] )(
		'hides curation unless the flag is exactly the string 1, not %p',
		( flag ) => {
			window.NewspackNodesData.canTune = flag;
			document.body.innerHTML =
				'<div id="newspack-intelligence-app"></div>';
			const { render } = loadIndex();

			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

			expect( render.mock.calls[ 0 ][ 0 ].props.canTune ).toBe( false );
		}
	);

	it.each( [ '', 'yes', true ] )(
		'withholds the operator fixes unless the flag is exactly the string 1, not %p',
		( flag ) => {
			window.NewspackNodesData.canManage = flag;
			document.body.innerHTML =
				'<div id="newspack-intelligence-app"></div>';
			const { render } = loadIndex();

			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

			expect( render.mock.calls[ 0 ][ 0 ].props.canManage ).toBe( false );
		}
	);

	it( 'does nothing when the mount is absent', () => {
		const { createRoot } = loadIndex();

		document.dispatchEvent( new Event( 'DOMContentLoaded' ) );

		expect( createRoot ).not.toHaveBeenCalled();
	} );
} );
