/* eslint-env jest */
/**
 * SaveToCollection is the one dialog any result saves to a folder through:
 * it offers the reader's recent folders first, then the rest, sends
 * `collect` with the chosen one and the item, and creates a folder in place
 * through `create_collection` before saving into it, straight away when
 * there is no folder to pick.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import SaveToCollection from '../SaveToCollection';

// This plugin's "Close" translates; the substrate's would not, in this bundle.
jest.mock( '@wordpress/i18n', () => ( {
	...jest.requireActual( '@wordpress/i18n' ),
	__: ( text, domain ) =>
		'Close' === text && 'newspack-intelligence' === domain
			? 'Fermer'
			: text,
} ) );

const ITEM = {
	item_key: 'feed:inkfold-review-17',
	title: 'Acme Quillsheet wins regional prize',
};

const LIST = {
	collections: [
		{
			collection_key: 'q4-client-wins',
			name: 'Q4 client wins',
			items: 2,
			recent_ts: 1792497600,
		},
		{
			collection_key: 'paywall-complaints',
			name: 'Paywall complaints',
			items: 0,
			recent_ts: null,
		},
		{
			collection_key: 'awards-shortlist',
			name: 'Awards shortlist',
			items: 1,
			recent_ts: null,
		},
	],
	available: true,
};

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.collections = LIST;
} );

describe( 'SaveToCollection', () => {
	it( 'saves the item into the chosen folder and says so', async () => {
		wire.replies.collect = { ok: true, event_id: 'ev-s' };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const select = await screen.findByLabelText( 'Folder' );
		fireEvent.change( select, { target: { value: 'paywall-complaints' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( wire.sent( 'collect' ) ).toEqual( [
				[
					'--collection=paywall-complaints',
					'--item=feed:inkfold-review-17',
				],
			] )
		);
		expect(
			await screen.findByText( 'Saved to Paywall complaints.' )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toEqual( [ [] ] );
	} );

	it( "offers the reader's recent folders first, the latest chosen", async () => {
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const select = await screen.findByLabelText( 'Folder' );
		expect(
			[ ...select.querySelectorAll( 'optgroup' ) ].map( ( group ) => [
				group.label,
				[ ...group.querySelectorAll( 'option' ) ].map(
					( option ) => option.value
				),
			] )
		).toEqual( [
			[ 'Recent', [ 'q4-client-wins' ] ],
			[
				'Every other folder',
				[ 'paywall-complaints', 'awards-shortlist' ],
			],
		] );
		expect( select ).toHaveValue( 'q4-client-wins' );
	} );

	it( 'lists the folders alone while the reader has saved into none', async () => {
		wire.replies.collections = {
			...LIST,
			collections: LIST.collections.map( ( one ) => ( {
				...one,
				recent_ts: null,
			} ) ),
		};
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const select = await screen.findByLabelText( 'Folder' );
		expect( select.querySelectorAll( 'optgroup' ) ).toHaveLength( 0 );
		expect(
			[ ...select.querySelectorAll( 'option' ) ].map(
				( option ) => option.value
			)
		).toEqual( [
			'q4-client-wins',
			'paywall-complaints',
			'awards-shortlist',
		] );
	} );

	it( 'names the item it saves, as text', async () => {
		render(
			<SaveToCollection
				item={ { ...ITEM, title: '<img src=x onerror=alert(1)>' } }
				onClose={ jest.fn() }
			/>
		);
		expect(
			screen.getByText( '<img src=x onerror=alert(1)>' )
		).toBeInTheDocument();
		expect( document.querySelector( 'img' ) ).toBeNull();
		await screen.findByLabelText( 'Folder' );
	} );

	it( 'shows a refused save as the refusal', async () => {
		wire.replies.collect = () =>
			new Error( 'no item: feed:inkfold-review-17' );
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		await screen.findByLabelText( 'Folder' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect(
			await screen.findByText( 'no item: feed:inkfold-review-17' )
		).toBeInTheDocument();
	} );

	it( 'opens straight to naming the first folder when there is none', async () => {
		wire.replies.collections = { collections: [], available: true };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			await screen.findByText(
				'Make your first folder to save this item.'
			)
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Folder name' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Create and save' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText(
				'No folder yet. Create one under Collections first.'
			)
		).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Save' } ) ).toBeNull();
		await waitFor( () =>
			expect( screen.getByLabelText( 'Folder name' ) ).toHaveFocus()
		);
	} );

	it( 'opens the folder name in place from New folder', async () => {
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		await screen.findByLabelText( 'Folder' );
		expect( screen.queryByLabelText( 'Folder name' ) ).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'New folder' } ) );
		expect( screen.getByLabelText( 'Folder name' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Create and save' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Make your first folder to save this item.' )
		).toBeNull();
	} );

	it( 'creates the folder, then saves into it once the create lands', async () => {
		let land;
		wire.replies.create_collection = () =>
			new Promise( ( resolve ) => {
				land = resolve;
			} );
		wire.replies.collect = { ok: true, event_id: 'ev-c' };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		await screen.findByLabelText( 'Folder' );
		fireEvent.click( screen.getByRole( 'button', { name: 'New folder' } ) );
		fireEvent.change( screen.getByLabelText( 'Folder name' ), {
			target: { value: 'Quarry notes' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create and save' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'create_collection' ) ).toEqual( [
				[ '--name=Quarry notes' ],
			] )
		);
		await waitFor( () => expect( land ).toBeDefined() );
		expect( wire.sent( 'collect' ) ).toEqual( [] );
		land( { ok: true, event_id: 'ev-n', collection_key: 'quarry-notes' } );
		await waitFor( () =>
			expect( wire.sent( 'collect' ) ).toEqual( [
				[
					'--collection=quarry-notes',
					'--item=feed:inkfold-review-17',
				],
			] )
		);
		expect(
			await screen.findByText( 'Saved to Quarry notes.' )
		).toBeInTheDocument();
	} );

	it( 'leaves the name field once the create lands, on the new folder', async () => {
		let land;
		wire.replies.create_collection = () =>
			new Promise( ( resolve ) => {
				land = resolve;
			} );
		wire.replies.collect = { ok: true, event_id: 'ev-d' };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		await screen.findByLabelText( 'Folder' );
		fireEvent.click( screen.getByRole( 'button', { name: 'New folder' } ) );
		const field = screen.getByLabelText( 'Folder name' );
		fireEvent.change( field, { target: { value: 'Quarry notes' } } );
		fireEvent.submit( field );
		await waitFor( () => expect( land ).toBeDefined() );
		wire.replies.collections = {
			...LIST,
			collections: [
				...LIST.collections,
				{
					collection_key: 'quarry-notes',
					name: 'Quarry notes',
					items: 1,
					recent_ts: 1792501200,
				},
			],
		};
		land( { ok: true, event_id: 'ev-m', collection_key: 'quarry-notes' } );
		expect(
			await screen.findByText( 'Saved to Quarry notes.' )
		).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Folder name' ) ).toBeNull();
		const select = screen.getByLabelText( 'Folder' );
		await waitFor( () => expect( select ).toHaveValue( 'quarry-notes' ) );
		// Enter on a focused select submits nothing; only a field's Enter does.
		await waitFor( () => expect( select ).toHaveFocus() );
		expect( wire.sent( 'create_collection' ) ).toEqual( [
			[ '--name=Quarry notes' ],
		] );
		fireEvent.click( screen.getByRole( 'button', { name: 'New folder' } ) );
		expect( screen.getByLabelText( 'Folder name' ) ).toHaveValue( '' );
	} );

	it( 'leaves the name field once the first folder lands, while the list reads empty', async () => {
		let land;
		wire.replies.collections = { collections: [], available: true };
		wire.replies.create_collection = () =>
			new Promise( ( resolve ) => {
				land = resolve;
			} );
		wire.replies.collect = { ok: true, event_id: 'ev-f' };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const field = await screen.findByLabelText( 'Folder name' );
		fireEvent.change( field, { target: { value: 'Tidewater leads' } } );
		fireEvent.submit( field );
		await waitFor( () => expect( land ).toBeDefined() );
		// The second read lands before the projector flushes, empty again.
		land( {
			ok: true,
			event_id: 'ev-t',
			collection_key: 'tidewater-leads',
		} );
		expect(
			await screen.findByText( 'Saved to Tidewater leads.' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'collections' ) ).toEqual( [ [], [] ] )
		);
		expect( screen.queryByLabelText( 'Folder name' ) ).toBeNull();
		expect(
			screen.queryByText( 'Make your first folder to save this item.' )
		).toBeNull();
		expect( wire.sent( 'create_collection' ) ).toEqual( [
			[ '--name=Tidewater leads' ],
		] );
	} );

	it( 'shows a refused create as the refusal and saves nothing', async () => {
		wire.replies.collections = { collections: [], available: true };
		wire.replies.create_collection = () =>
			new Error( 'collection quarry-notes is taken' );
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		fireEvent.change( await screen.findByLabelText( 'Folder name' ), {
			target: { value: 'Quarry notes' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create and save' } )
		);
		expect(
			await screen.findByText( 'collection quarry-notes is taken' )
		).toBeInTheDocument();
		expect( wire.sent( 'collect' ) ).toEqual( [] );
	} );

	it( 'heads the dialog with its title beside a labelled icon close', async () => {
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const title = screen.getByText( 'Save to folder' );
		const close = screen.getByRole( 'button', { name: 'Fermer' } );
		expect( close.parentElement ).toBe( title.parentElement );
		expect( title.parentElement.tagName ).toBe( 'HEADER' );
		expect( close.textContent ).toBe( '' );
		expect( close.querySelector( 'svg' ) ).not.toBeNull();
		const dialog = screen.getByRole( 'dialog', { name: 'Save to folder' } );
		expect( dialog.hasAttribute( 'aria-label' ) ).toBe( false );
		expect( dialog.getAttribute( 'aria-labelledby' ) ).toBe( title.id );
		await screen.findByLabelText( 'Folder' );
	} );

	it( 'says the collections could not be read', async () => {
		wire.replies.collections = { collections: [], available: false };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
	} );

	it( 'focuses its close button, then the folder once the list loads', async () => {
		let answer;
		wire.replies.collections = () =>
			new Promise( ( resolve ) => {
				answer = resolve;
			} );
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			screen.getByRole( 'button', { name: 'Fermer' } )
		).toHaveFocus();
		await waitFor( () => expect( answer ).toBeDefined() );
		answer( LIST );
		const select = await screen.findByLabelText( 'Folder' );
		await waitFor( () => expect( select ).toHaveFocus() );
	} );

	it( 'closes from its close button', async () => {
		const onClose = jest.fn();
		render( <SaveToCollection item={ ITEM } onClose={ onClose } /> );
		await screen.findByLabelText( 'Folder' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Fermer' } ) );
		expect( onClose ).toHaveBeenCalled();
	} );
} );
