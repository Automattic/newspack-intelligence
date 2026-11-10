/* eslint-env jest */
/**
 * Every Intelligence block is a View, with its Controls where it has
 * settings, registered in the browser against the schemas the server
 * localizes; the aside holds its children in a column of their own; and a
 * block's write controls go inert while its layout is edited.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { getBlockTypes, unregisterBlockType } from '@wordpress/blocks';
import { Core } from '@newspack-nodes/runtime';
import CompositionView from '@newspack-nodes/shared/composition/CompositionView';
import { registerCompositionBlocks } from '@newspack-nodes/shared/composition/registerCompositionBlocks';
import { intelWire } from '../../__tests__/intelWire';
import { useIntelWrite } from '../../hooks/useIntelCommand';
import { IntelPageContext, useWriteControl } from '../../layout/PageContext';
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
	'newspack-intelligence/aside': {
		title: 'Aside',
		attributes: {},
		container: true,
		multiple: false,
	},
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

/**
 * A block whose View names itself.
 *
 * @param {Object} props
 * @param {Object} props.attributes       Its attributes.
 * @param {string} props.attributes.label What it names.
 * @return {import('react').ReactElement} The block.
 */
const Named = ( { attributes: { label } } ) => <p>{ label }</p>;

/** A block that writes: one Save, as a screen's block holds one. */
function Writer() {
	const { run } = useIntelWrite( 'mark_seen', { scope: 'probe:mark_seen' } );
	return (
		<button
			type="button"
			{ ...useWriteControl( () => run( { key: '9f3a51c07be2d468' } ) ) }
		>
			Mark seen
		</button>
	);
}

const VIEWS = {
	...BLOCK_VIEWS,
	'newspack-intelligence/probe-named': { View: Named },
	'newspack-intelligence/probe-writer': { View: Writer },
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
			'newspack-intelligence/aside',
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

		const aside = getBlockTypes().find(
			( { name } ) => 'newspack-intelligence/aside' === name
		);
		expect( aside.title ).toBe( 'Aside' );
		expect( aside.supports.multiple ).toBe( false );
	} );

	it( "renders the aside's children in the aside region, apart from the main column", () => {
		render(
			<CompositionView
				views={ VIEWS }
				renderError={ ( error ) => <p>{ error.message }</p> }
				blocks={ [
					{
						name: 'newspack-intelligence/probe-named',
						attributes: { label: 'Kestrel pulse' },
						inner: [],
					},
					{
						name: 'newspack-intelligence/aside',
						attributes: {},
						inner: [
							{
								name: 'newspack-intelligence/probe-named',
								attributes: { label: 'Related to Kestrel' },
								inner: [],
							},
						],
					},
				] }
			/>
		);

		const aside = screen.getByRole( 'complementary' );
		expect( aside ).toHaveClass( 'newspack-intelligence__aside' );
		expect( aside ).toHaveTextContent( 'Related to Kestrel' );
		expect( aside ).not.toHaveTextContent( 'Kestrel pulse' );
	} );
} );

describe( 'write controls while the layout is edited', () => {
	const WATCHES = BLOCK_VIEWS[ 'newspack-intelligence/watches' ];
	const ASK = BLOCK_VIEWS[ 'newspack-intelligence/ask' ];

	beforeEach( () => {
		window.NewspackNodesData = {
			restUrl: '/wp-json/',
			nonce: 'NONCE',
			userId: '41',
		};
		window.localStorage.clear();
	} );

	/**
	 * A block's View as the substrate hands it its `editing` prop, under a
	 * page whose context says nothing of editing.
	 *
	 * @param {Object}   view       The block's view.
	 * @param {Function} view.View  Its View.
	 * @param {Object}   attributes Its attributes.
	 * @param {boolean}  editing    What the substrate hands the View.
	 * @param {Object}   [actions]  The page's actions.
	 */
	const renderView = ( { View }, attributes, editing, actions = {} ) =>
		render(
			<IntelPageContext.Provider
				value={ {
					page: 'briefing',
					lens_key: null,
					entity_key: null,
					since: 1792000000,
					actions,
				} }
			>
				<View attributes={ attributes } editing={ editing } />
			</IntelPageContext.Provider>
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

describe( 'useWriteControl', () => {
	it( 'writes under a page context, which holds no editing flag of its own', async () => {
		const wire = intelWire();
		wire.replies.mark_seen = { ok: true, count: 1 };
		render(
			<IntelPageContext.Provider
				value={
					/** @type {Object} */ ( {
						page: 'briefing',
						lens_key: null,
						entity_key: null,
						since: 1792000000,
						editing: true,
					} )
				}
			>
				<Writer />
			</IntelPageContext.Provider>
		);

		const button = screen.getByRole( 'button', { name: 'Mark seen' } );
		expect( button ).not.toHaveAttribute( 'aria-disabled' );
		fireEvent.click( button );

		await waitFor( () =>
			expect( wire.sent( 'mark_seen' ) ).toEqual( [
				[ '--key=9f3a51c07be2d468' ],
			] )
		);
	} );
} );
