/* eslint-env jest */
/**
 * IntelligencePage switches among its four views in place, opens an entity
 * from any chip, saves an item through one dialog only for a curator, and
 * carries the substrate debug overlay under its own storage key.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';

jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: ( props ) => {
		global.__debugOverlayProps = props;
		return <div data-testid="debug-overlay" />;
	},
} ) );

import { intelWire } from './intelWire';
import { ENTITY, ITEM, VOCABULARY } from '../views/__tests__/fixtures';
import IntelligencePage from '../IntelligencePage';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.search = { items: [ ITEM ], total: 1, available: true };
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [], total: 0, available: true };
	wire.replies.related = { entities: [], available: true };
	wire.replies.claims = { claims: [], available: true };
	wire.replies.review = ( [ what ] ) => ( { [ what ]: [], available: true } );
	wire.replies.collections = { collections: [], available: true };
} );

function renderPage( canTune = false ) {
	return render(
		<IntelligencePage canTune={ canTune } vocabulary={ VOCABULARY } />
	);
}

const nav = ( name ) =>
	within( screen.getByRole( 'tablist', { name: 'Views' } ) ).getByRole(
		'tab',
		{ name }
	);

describe( 'IntelligencePage', () => {
	it( 'opens on Search', async () => {
		renderPage();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toBeInTheDocument();
		expect( nav( 'Search' ) ).toHaveAttribute( 'aria-selected', 'true' );
	} );

	it( 'switches to Review and Collections in place', async () => {
		renderPage();
		fireEvent.click( nav( 'Review' ) );
		expect(
			await screen.findByText( 'No proposed link awaits review.' )
		).toBeInTheDocument();
		expect( nav( 'Review' ) ).toHaveAttribute( 'aria-selected', 'true' );
		fireEvent.click( nav( 'Collections' ) );
		expect(
			await screen.findByText( 'No collection yet.' )
		).toBeInTheDocument();
	} );

	it( 'opens an entity from a result chip and returns to it from the nav', async () => {
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Gazette' } )
		);
		expect(
			await screen.findByRole( 'heading', { name: 'Acme Gazette' } )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toEqual( [
				[ 'organization:acme-gazette' ],
			] )
		);
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Entity: organization:acme-gazette' ) );
		expect(
			await screen.findByRole( 'heading', { name: 'Acme Gazette' } )
		).toBeInTheDocument();
	} );

	it( 'restores the search filters and page after visiting an entity', async () => {
		wire.replies.search = ( args ) => ( {
			items: [
				{
					...ITEM,
					title: `found by ${ args.join( ' ' ) || 'nothing' }`,
				},
			],
			total: 40,
			available: true,
		} );
		renderPage();
		await screen.findByText( 'found by nothing' );
		const form = screen.getByRole( 'search', { name: 'Filters' } );
		fireEvent.change( within( form ).getByLabelText( 'Words' ), {
			target: { value: 'regional prize' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Search' } )
		);
		await screen.findByText( 'found by --q=regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await screen.findByText( 'found by --q=regional prize --offset=1' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Acme Gazette' } )
		);
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		fireEvent.click( nav( 'Search' ) );
		expect(
			await screen.findByText( 'found by --q=regional prize --offset=1' )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Words' ) ).toHaveValue(
			'regional prize'
		);
	} );

	it( 'reopens the collection it left', async () => {
		wire.replies.collections = ( args ) =>
			0 === args.length
				? {
						collections: [
							{
								collection_key: 'q4-client-wins',
								name: 'Q4 client wins',
								created_ts: 1790035200,
								items: 1,
							},
						],
						available: true,
				  }
				: {
						collection_key: 'q4-client-wins',
						name: 'Q4 client wins',
						created_ts: 1790035200,
						items: [],
						available: true,
				  };
		renderPage();
		fireEvent.click( nav( 'Collections' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		await screen.findByRole( 'region', { name: 'Q4 client wins' } );
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Collections' ) );
		expect(
			await screen.findByRole( 'region', { name: 'Q4 client wins' } )
		).toBeInTheDocument();
	} );

	it( 'offers a reader no save control and no curation', async () => {
		renderPage( false );
		await screen.findByRole( 'link', {
			name: 'Acme Gazette wins regional prize',
		} );
		expect(
			screen.queryByRole( 'button', { name: /save to collection/i } )
		).toBeNull();
	} );

	it( 'saves a result through the dialog for a curator', async () => {
		renderPage( true );
		fireEvent.click(
			await screen.findByRole( 'button', { name: /save to collection/i } )
		);
		expect(
			await screen.findByRole( 'dialog', { name: 'Save to collection' } )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'renders inside the shared dashboard shell, headed by its subtitle', async () => {
		const { container } = renderPage();
		await screen.findByRole( 'link', {
			name: 'Acme Gazette wins regional prize',
		} );
		expect( container.firstChild ).toHaveClass(
			'newspack-nodes-theme',
			'newspack-nodes-skin-root',
			'newspack-nodes-ui'
		);
		expect(
			container.querySelector( '.newspack-nodes-page-surface' )
		).not.toBeNull();
		expect(
			container.querySelector( '.topology-brand' ).textContent
		).toContain( 'NEWSPACK' );
		expect(
			container.querySelector( '.topology-subtitle' ).textContent
		).toContain( 'Intelligence' );
		expect( screen.queryByRole( 'heading', { level: 1 } ) ).toBeNull();
		expect(
			container.querySelector(
				'.newspack-nodes-page-content .newspack-intelligence'
			)
		).not.toBeNull();
	} );

	it( 'mounts one debug overlay under its own storage key', async () => {
		renderPage();
		await screen.findByRole( 'link', {
			name: 'Acme Gazette wins regional prize',
		} );
		expect( screen.getByTestId( 'debug-overlay' ) ).toBeInTheDocument();
		expect( global.__debugOverlayProps.storageKey ).toBe(
			'newspack-nodes:debug:intelligence'
		);
	} );
} );
