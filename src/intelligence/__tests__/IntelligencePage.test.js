/* eslint-env jest */
/**
 * IntelligencePage switches among its six views in place, opens an entity
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
import { ENTITY, ITEM, LENSES, VOCABULARY } from '../views/__tests__/fixtures';
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
	wire.replies.lenses = LENSES;
	wire.replies.trends = { rows: [], available: true };
	wire.replies.signals = { signals: [], available: true };
	wire.replies.watches = { watches: [], available: true };
} );

afterEach( () => {
	window.history.replaceState( {}, '', '/' );
} );

const LINKED = {
	watches: [
		{
			watch_key: '9f3a51c07be2d468',
			name: 'Gazette group',
			target: { kind: 'entity', key: 'organization:acme-gazette' },
			digest: true,
			instant: false,
			inbox: true,
			unseen: 5,
		},
	],
	available: true,
};

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

	it( 'labels its view panel by the selected tab, and moves with the keys', async () => {
		renderPage();
		const search = nav( 'Search' );
		const panel = screen.getByRole( 'tabpanel' );
		expect( search ).toHaveAttribute( 'aria-controls', panel.id );
		expect( panel ).toHaveAttribute( 'aria-labelledby', search.id );

		fireEvent.keyDown( search, { key: 'End' } );
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect( nav( 'Watches' ) ).toHaveFocus();
		fireEvent.keyDown( nav( 'Watches' ), { key: 'ArrowRight' } );
		expect( nav( 'Search' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
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

	it( 'reopens the lens it left, on the page it left', async () => {
		wire.replies.search = ( args ) => ( {
			items: [
				{
					...ITEM,
					title: `seen by ${ args.join( ' ' ) || 'nothing' }`,
				},
			],
			total: 30,
			available: true,
		} );
		renderPage();
		fireEvent.click( nav( 'Lenses' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk' } )
		);
		await screen.findByText( 'seen by --lens=support-desk' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await screen.findByText( 'seen by --lens=support-desk --offset=1' );
		fireEvent.click( nav( 'Review' ) );
		fireEvent.click( nav( 'Lenses' ) );
		expect(
			await screen.findByText( 'seen by --lens=support-desk --offset=1' )
		).toBeInTheDocument();
	} );

	it( 'switches to Watches for every reader', async () => {
		renderPage( false );
		fireEvent.click( nav( 'Watches' ) );
		expect(
			await screen.findByText( 'No watch yet.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'New watch' } )
		).toBeInTheDocument();
	} );

	it( 'opens the watch form on a lens from its Watch this', async () => {
		renderPage( false );
		fireEvent.click( nav( 'Lenses' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Developer shipping' } )
		);
		await screen.findByText( 'No signal is seen through this lens.' );
		await screen.findByText( 'No trend is seen through this lens.' );
		await screen.findByText( 'Acme Gazette wins regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Watch this' } ) );
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const form = screen.getByRole( 'form', { name: 'Save watch' } );
		expect( within( form ).getByLabelText( 'Name' ) ).toHaveValue(
			'Developer shipping'
		);
		expect( within( form ).getByLabelText( 'Follows' ) ).toHaveValue(
			'lens'
		);
		await within( form ).findByRole( 'option', {
			name: 'Developer shipping',
		} );
		expect( within( form ).getByLabelText( 'Lens' ) ).toHaveValue(
			'dev-shipping'
		);
		await screen.findByText( 'No watch yet.' );
	} );

	it( 'opens the watch a link names, on the Watches view', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&watch=9f3a51c07be2d468'
		);
		wire.replies.watches = LINKED;
		wire.replies.watch_matches = {
			items: [ ITEM ],
			total: 1,
			available: true,
		};
		renderPage();
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		const region = await screen.findByRole( 'region', {
			name: 'Gazette group',
		} );
		expect(
			await within( region ).findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [
			[ '--key=9f3a51c07be2d468' ],
		] );
		expect( wire.sent( 'search' ) ).toEqual( [] );
	} );

	it( 'shows the list for a linked watch the read does not list', async () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&watch=0d1e2f3a4b5c6d7e'
		);
		wire.replies.watches = LINKED;
		renderPage();
		expect( nav( 'Watches' ) ).toHaveAttribute( 'aria-selected', 'true' );
		expect(
			await screen.findByRole( 'button', { name: 'Gazette group' } )
		).not.toHaveClass( 'is-active' );
		expect(
			screen.queryByRole( 'region', { name: 'Gazette group' } )
		).toBeNull();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [] );
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
