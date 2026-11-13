/* eslint-env jest */
/**
 * The `folder-board` block draws the Folders board on a composed page: the
 * folders its `folders` names, or every one, opening on its `show`, the
 * card menus a curator's alone and inert while its layout is edited.
 */

import { fireEvent, screen, within } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { pageData } from '../../views/__tests__/fixtures';
import { ComposedPage } from '@newspack-nodes/shared/composition/page';
import { BLOCK_VIEWS } from '..';

const { View, Controls } = BLOCK_VIEWS[ 'newspack-intelligence/folder-board' ];

const YEAR = new Date().getUTCFullYear();

const USED = {
	item_key: 'feed:prairie-signal-finalist',
	url: 'https://prairie-signal.example/finalist',
	title: 'Prairie Signal named a finalist',
	actor: 'user:editor',
	saved_ts: Date.UTC( YEAR, 0, 8 ) / 1000,
	marks: [ { mark: 'site', day: `${ YEAR }-11-04` } ],
};

const UNUSED = {
	...USED,
	item_key: 'feed:lakeside-works-launch',
	url: 'https://lakeside-works.example/launch',
	title: 'Lakeside Works launches its site',
	marks: [],
};

const LIST = {
	collections: [
		{
			collection_key: 'coastline-picks',
			name: 'Coastline picks',
			created_ts: 1790035200,
			items: 1,
			recent_ts: null,
		},
		{
			collection_key: 'awards-page',
			name: 'Awards page',
			created_ts: 1790121600,
			items: 2,
			recent_ts: null,
		},
	],
	available: true,
};

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.collections = ( args ) =>
		0 === args.length
			? LIST
			: {
					collection_key: args[ 0 ],
					name: args[ 0 ],
					created_ts: 1790035200,
					items: 'awards-page' === args[ 0 ] ? [ UNUSED, USED ] : [],
					available: true,
			  };
} );

/**
 * The block on a page.
 *
 * @param {Object}  attributes Its attributes.
 * @param {boolean} editing    Whether its layout is being edited.
 */
const renderBlock = ( attributes, editing = false ) =>
	renderOnPage(
		<ComposedPage.Provider
			value={ {
				page: 'briefing',
				lens_key: null,
				entity_key: null,
				since: null,
				actions: { canTune: true },
			} }
		>
			<View attributes={ attributes } editing={ editing } />
		</ComposedPage.Provider>
	);

describe( 'the folder-board block', () => {
	it( 'shows the one folder it names, its used cards included under "all"', async () => {
		renderBlock( { show: 'all', folders: [ 'awards-page' ] } );
		const awards = await screen.findByRole( 'region', {
			name: 'Awards page',
		} );
		expect(
			await within( awards ).findByRole( 'article', { name: USED.title } )
		).toBeInTheDocument();
		expect(
			within( awards ).getByRole( 'article', { name: UNUSED.title } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Homepage, 4 Nov' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'region', { name: 'Coastline picks' } )
		).toBeNull();
		expect( wire.sent( 'collections' ) ).not.toContainEqual( [
			'coastline-picks',
		] );
	} );

	it( 'offers a curator the card menus, and none while the layout is edited', async () => {
		const { unmount } = renderBlock( { show: 'all', folders: [] } );
		expect(
			await screen.findByRole( 'button', {
				name: `Actions for ${ USED.title }`,
			} )
		).toBeInTheDocument();
		unmount();
		renderBlock( { show: 'all', folders: [] }, true );
		await screen.findByRole( 'article', { name: USED.title } );
		expect(
			screen.queryByRole( 'button', {
				name: `Actions for ${ USED.title }`,
			} )
		).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'New folder' } )
		).toBeNull();
	} );

	it( 'picks its folders by name, holding their keys', async () => {
		const setAttributes = jest.fn();
		renderOnPage(
			<Controls
				attributes={ { show: 'all', folders: [ 'coastline-picks' ] } }
				setAttributes={ setAttributes }
			/>
		);
		expect(
			await screen.findByText( 'Coastline picks' )
		).toBeInTheDocument();
		const field = screen.getByLabelText( 'Folders (none shows every one)' );
		fireEvent.change( field, { target: { value: 'Awards page' } } );
		fireEvent.keyDown( field, { key: 'Enter' } );
		expect( setAttributes ).toHaveBeenCalledWith( {
			folders: [ 'coastline-picks', 'awards-page' ],
		} );
	} );

	it( 'sets what the board opens on from its controls', async () => {
		const setAttributes = jest.fn();
		renderOnPage(
			<Controls
				attributes={ { show: 'unused', folders: [ 'awards-page' ] } }
				setAttributes={ setAttributes }
			/>
		);
		fireEvent.change( screen.getByLabelText( 'Opens on' ), {
			target: { value: 'linkedin' },
		} );
		expect( setAttributes ).toHaveBeenCalledWith( { show: 'linkedin' } );
		expect(
			[ ...screen.getByLabelText( 'Opens on' ).options ].map(
				( one ) => one.textContent
			)
		).toEqual( [
			'All',
			'Not yet used',
			'Used in Digest',
			'Used in LinkedIn post',
			'Used in Homepage',
		] );
		expect( await screen.findByText( 'Awards page' ) ).toBeInTheDocument();
	} );
} );
