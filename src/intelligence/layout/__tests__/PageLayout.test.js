/* eslint-env jest */
/**
 * A composed page's layout as a reader reads it: each block by its view,
 * the aside apart; a block that cannot render shows the shared read error in
 * its own place, the blocks beside it still drawn, its reason for an
 * operator alone, and its Try again reads the page again. A stored layout
 * that no longer reads shows the read error with no Try again, since reading
 * it again refuses it again, and offers a curator Restore default, which
 * asks first and hands the page the default the server answers.
 */

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { pageData } from '../../views/__tests__/fixtures';
import { CanManage } from '../../components/Answer';
import PageLayout from '../PageLayout';

const PAGE = {
	page: 'briefing',
	lens_key: null,
	entity_key: null,
	since: null,
};

/** What the page data answers for a stored layout a later version refuses. */
const REFUSED = {
	context: 'newspack-intelligence/briefing',
	error: 'composition newspack-intelligence/briefing: newspack-intelligence/heron-gone is not a declared block',
};

/** The default `compositions restore` answers. */
const RESTORED = {
	context: 'newspack-intelligence/briefing',
	source: 'default',
	blocks: [],
	saved_ts: 1912345678.5,
	actor: 'lakeside-editor',
};

const LAYOUT = {
	blocks: [
		{
			name: 'newspack-intelligence/heron-gone',
			attributes: {},
			inner: [],
		},
		{ name: 'newspack-intelligence/aside', attributes: {}, inner: [] },
	],
};

/**
 * The layout, for a reader or an operator.
 *
 * @param {Object}  layout    The layout.
 * @param {boolean} canManage Whether the reader is an operator.
 * @return {Object} The render.
 */
const renderLayout = ( layout, canManage = false ) =>
	render(
		<CanManage.Provider value={ canManage }>
			<PageLayout layout={ layout } page={ PAGE } />
		</CanManage.Provider>
	);

describe( 'PageLayout', () => {
	it( 'shows a block that cannot render as the read error in its place, its neighbours drawn', () => {
		const { container } = renderLayout( LAYOUT );
		const main = container.querySelector(
			'.newspack-intelligence__layout-main'
		);
		expect(
			within( main ).getByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( screen.getByRole( 'complementary' ) ).toBeInTheDocument();
		expect(
			screen.queryByText( 'newspack-intelligence/heron-gone has no view' )
		).toBeNull();
	} );

	it( 'names the reason to an operator', () => {
		renderLayout( LAYOUT, true );
		expect(
			screen.getByText( 'newspack-intelligence/heron-gone has no view' )
		).toBeInTheDocument();
	} );

	it( 'reads the page again on Try again', () => {
		const error = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		try {
			renderLayout( LAYOUT );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Try again' } )
			);
			// jsdom refuses the reload with a logged "Not implemented".
			expect(
				error.mock.calls.some( ( [ e ] ) =>
					/navigation/.test( String( e ) )
				)
			).toBe( true );
		} finally {
			error.mockRestore();
		}
	} );

	describe( 'a stored layout that no longer reads', () => {
		let wire;

		beforeEach( () => {
			Core.reset();
			window.NewspackNodesData = pageData();
			wire = intelWire();
			wire.replies.restore = RESTORED;
		} );

		/**
		 * The refused layout, for a reader or a curator.
		 *
		 * @param {boolean}  canTune    Whether the reader may curate.
		 * @param {Function} onRestored Takes the layout a restore answers.
		 */
		const renderRefused = ( canTune, onRestored = jest.fn() ) =>
			render(
				<PageLayout
					layout={ REFUSED }
					page={ { ...PAGE, actions: { canTune } } }
					onRestored={ onRestored }
				/>
			);

		it( 'shows the read error with no Try again, and offers a reader nothing to do', () => {
			renderRefused( false );
			expect(
				screen.getByText( 'Intelligence could not be read just now.' )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Try again' } )
			).toBeNull();
			expect(
				screen.queryByRole( 'button', { name: 'Restore default' } )
			).toBeNull();
			expect(
				screen.queryByText(
					'This layout names a block or a setting this version does not have.'
				)
			).toBeNull();
		} );

		it( 'tells a curator why, and restores the default once asked and confirmed', async () => {
			const onRestored = jest.fn();
			renderRefused( true, onRestored );
			expect(
				screen.getByText(
					'This layout names a block or a setting this version does not have.'
				)
			).toBeInTheDocument();

			fireEvent.click(
				screen.getByRole( 'button', { name: 'Restore default' } )
			);
			const dialog = screen.getByRole( 'dialog', {
				name: 'Restore the default layout?',
			} );
			expect( dialog ).toHaveTextContent(
				'Everyone who opens this page will see the default.'
			);
			expect(
				within( dialog ).getByRole( 'button', { name: 'Cancel' } )
			).toHaveFocus();
			fireEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Restore default',
				} )
			);

			await waitFor( () =>
				expect( onRestored ).toHaveBeenCalledWith( RESTORED )
			);
			expect( wire.sent( 'restore' ) ).toEqual( [
				[ '--context=newspack-intelligence/briefing' ],
			] );
			expect( wire.sentTo( 'restore' ) ).toEqual( [ 'compositions' ] );
		} );

		it( 'restores nothing when the curator cancels', async () => {
			renderRefused( true );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Restore default' } )
			);
			fireEvent.click(
				within(
					screen.getByRole( 'dialog', {
						name: 'Restore the default layout?',
					} )
				).getByRole( 'button', { name: 'Cancel' } )
			);
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
			expect( wire.sent( 'restore' ) ).toEqual( [] );
		} );
	} );

	it( 'draws nothing for a layout holding no block', () => {
		const { container } = renderLayout( { blocks: [] } );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
