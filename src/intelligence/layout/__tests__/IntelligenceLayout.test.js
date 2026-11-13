/* eslint-env jest */
/**
 * An Intelligence page's layout on the substrate's frame: each block by its
 * view, the aside apart; a block that cannot render shows the shared read
 * error in its own place, the blocks beside it still drawn, its reason for
 * an operator alone, and its Try again reads the page again.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { CanManage } from '../../components/Answer';
import { renderOnPage } from '../../__tests__/pageReads';
import IntelligenceLayout from '../IntelligenceLayout';

const PAGE = {
	page: 'briefing',
	lens_key: null,
	entity_key: null,
	since: null,
};

const LAYOUT = {
	blocks: [
		{
			name: 'newspack-intelligence/heron-gone',
			attributes: {},
			inner: [],
		},
		{ name: 'newspack-nodes/aside', attributes: {}, inner: [] },
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
			<IntelligenceLayout layout={ layout } page={ PAGE } />
		</CanManage.Provider>
	);

describe( 'IntelligenceLayout', () => {
	it( 'gives each layout on a page headings of its own, a hidden one beside a profile', () => {
		const followed = {
			blocks: [
				{
					name: 'newspack-intelligence/watches',
					attributes: { heading: 'Harbor Ledger watches', max: 3 },
					inner: [],
				},
			],
		};
		renderOnPage(
			<>
				<div hidden>
					<IntelligenceLayout layout={ followed } page={ PAGE } />
				</div>
				<IntelligenceLayout
					layout={ followed }
					page={ { ...PAGE, page: 'entity' } }
				/>
			</>
		);
		const ids = screen
			.getAllByRole( 'heading', {
				name: 'Harbor Ledger watches',
				hidden: true,
			} )
			.map( ( heading ) => heading.id );
		expect( ids ).toHaveLength( 2 );
		expect( new Set( ids ).size ).toBe( 2 );
	} );

	it( 'shows a block that cannot render as the read error in its place, its neighbours drawn', () => {
		const { container } = renderLayout( LAYOUT );
		const main = container.querySelector(
			'.newspack-nodes-composed-layout-main'
		);
		expect(
			within( main ).getByText(
				'This could not be read just now. Try again.'
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

	it( 'draws nothing for a layout holding no block', () => {
		const { container } = renderLayout( { blocks: [] } );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
