/* eslint-env jest */
/**
 * Every Intelligence block is a View, with its Controls where it has
 * settings, registered in the browser against the schemas the server
 * localizes; and a block's write controls go inert while its layout is
 * edited, as the substrate's frame hands each View its `editing` prop.
 */

import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { getBlockTypes, unregisterBlockType } from '@wordpress/blocks';
import { Core } from '@newspack-nodes/runtime';
import { registerCompositionBlocks } from '@newspack-nodes/shared/composition/registerCompositionBlocks';
import { intelWire } from '../../__tests__/intelWire';
import {
	ComposedPage,
	providedViews,
} from '@newspack-nodes/shared/composition/page';
import { BLOCK_VIEWS } from '..';

// The editor's half, which a View never reaches, needs WordPress's one
// private-APIs copy; what registers a block reads none of it.
jest.mock( '@wordpress/block-editor', () => ( {
	InnerBlocks: () => null,
	InspectorControls: ( { children } ) => children,
	useBlockProps: () => ( {} ),
} ) );

/** The schemas `Composition::schemas( 'newspack-intelligence/entity' )` localizes. */
const ENTITY_SCHEMAS = {
	'newspack-intelligence/health': {
		title: 'Collection status',
		attributes: {
			detail: {
				type: 'string',
				enum: [ 'summary', 'sources' ],
				default: 'summary',
			},
		},
		container: false,
		multiple: false,
	},
};

beforeEach( () => {
	Core.reset();
} );

afterEach( () => {
	getBlockTypes()
		.filter( ( { name } ) => name.startsWith( 'newspack-intelligence/' ) )
		.forEach( ( { name } ) => unregisterBlockType( name ) );
} );

describe( 'BLOCK_VIEWS', () => {
	it( 'gives every block a View', () => {
		expect( Object.keys( BLOCK_VIEWS ) ).toEqual( [
			'newspack-intelligence/health',
			'newspack-intelligence/ask',
			'newspack-intelligence/since',
			'newspack-intelligence/needs-attention',
			'newspack-intelligence/watches',
			'newspack-intelligence/week',
			'newspack-intelligence/pulse',
			'newspack-intelligence/entity-facts',
			'newspack-intelligence/related',
			'newspack-intelligence/timeline',
			'newspack-intelligence/trend-chart',
			'newspack-intelligence/item-list',
			'newspack-intelligence/folder-board',
		] );
		Object.values( BLOCK_VIEWS ).forEach( ( { View } ) =>
			expect( typeof View ).toBe( 'function' )
		);
	} );

	it( 'registers against the localized schemas without a throw', () => {
		const entityViews = Object.fromEntries(
			Object.entries( BLOCK_VIEWS ).filter(
				( [ name ] ) => name in ENTITY_SCHEMAS
			)
		);
		expect( () =>
			registerCompositionBlocks( ENTITY_SCHEMAS, entityViews )
		).not.toThrow();

		const health = getBlockTypes().find(
			( { name } ) => 'newspack-intelligence/health' === name
		);
		expect( health.title ).toBe( 'Collection status' );
		expect( health.supports.multiple ).toBe( false );
	} );
} );

describe( 'write controls while the layout is edited', () => {
	const PROVIDED = providedViews( BLOCK_VIEWS );
	const WATCHES = PROVIDED[ 'newspack-intelligence/watches' ];
	const ASK = PROVIDED[ 'newspack-intelligence/ask' ];

	beforeEach( () => {
		window.NewspackNodesData = {
			restUrl: '/wp-json/',
			nonce: 'NONCE',
			userId: '41',
		};
		window.localStorage.clear();
	} );

	/**
	 * A block's View, provided as the frame provides it, handed its
	 * `editing` prop, under a page whose context says nothing of editing.
	 *
	 * @param {Object}   view       The block's view.
	 * @param {Function} view.View  Its View.
	 * @param {Object}   attributes Its attributes.
	 * @param {boolean}  editing    What the substrate hands the View.
	 * @param {Object}   [actions]  The page's actions.
	 */
	const renderView = ( { View }, attributes, editing, actions = {} ) =>
		renderOnPage(
			<ComposedPage.Provider
				value={ {
					page: 'briefing',
					lens_key: null,
					entity_key: null,
					since: 1792000000,
					actions,
				} }
			>
				<View attributes={ attributes } editing={ editing } />
			</ComposedPage.Provider>
		);

	it( 'leaves a block’s write control inert when its View is handed editing, opening nothing', async () => {
		intelWire().replies.watches = { watches: [], available: true };
		const onNewWatch = jest.fn();
		renderView( WATCHES, { heading: 'Kept close', max: 3 }, true, {
			onNewWatch,
		} );

		await screen.findByText( /to see its new items here/ );
		const buttons = screen.getAllByRole( 'button', { name: 'New watch' } );
		expect( buttons ).toHaveLength( 2 );
		buttons.forEach( ( button ) => {
			expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
			fireEvent.click( button );
		} );

		expect( onNewWatch ).not.toHaveBeenCalled();
	} );

	it( 'acts when its View is read', async () => {
		intelWire().replies.watches = { watches: [], available: true };
		const onNewWatch = jest.fn();
		renderView( WATCHES, { heading: 'Kept close', max: 3 }, false, {
			onNewWatch,
		} );

		await screen.findByText( /to see its new items here/ );
		const button = screen.getAllByRole( 'button', {
			name: 'New watch',
		} )[ 0 ];
		expect( button ).not.toHaveAttribute( 'aria-disabled' );
		fireEvent.click( button );

		expect( onNewWatch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends no ask and marks no first ask from an Ask box while the layout is edited', async () => {
		const wire = intelWire();
		wire.replies.ask = { ok: true, ask_key: '5d1e9a7c3b2f4086' };
		const onAsked = jest.fn();
		renderView(
			ASK,
			{
				label: 'Put it to the desk',
				placeholder: 'Ask the desk…',
				examples: [ 'Who launched?' ],
			},
			true,
			{ onAsked }
		);

		fireEvent.change( screen.getByLabelText( 'Put it to the desk' ), {
			target: { value: 'Which members wobbled?' },
		} );
		const button = screen.getByRole( 'button', { name: 'Ask' } );
		expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
		fireEvent.click( button );
		fireEvent.submit( button.closest( 'form' ) );
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
		);

		expect( wire.sent( 'ask' ) ).toEqual( [] );
		expect( onAsked ).not.toHaveBeenCalled();
		expect( window.localStorage.length ).toBe( 0 );
		expect( screen.getByText( 'Try: Who launched?' ) ).toBeInTheDocument();
	} );

	it( 'asks from an Ask box when its layout is read', async () => {
		const wire = intelWire();
		wire.replies.ask = { ok: true, ask_key: '5d1e9a7c3b2f4086' };
		renderView(
			ASK,
			{
				label: 'Put it to the desk',
				placeholder: 'Ask the desk…',
				examples: [],
			},
			false
		);

		fireEvent.change( screen.getByLabelText( 'Put it to the desk' ), {
			target: { value: 'Which members wobbled?' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );

		await waitFor( () =>
			expect( wire.sent( 'ask' ) ).toEqual( [
				[ '--question=Which members wobbled?' ],
			] )
		);
	} );
} );
