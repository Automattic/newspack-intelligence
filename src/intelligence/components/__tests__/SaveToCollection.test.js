/* eslint-env jest */
/**
 * SaveToCollection is the one dialog any result saves through: it lists the
 * collections, sends `collect` with the chosen one and the item, and says
 * which kind of nothing it holds when there is no collection to pick.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import SaveToCollection from '../SaveToCollection';

const ITEM = {
	item_key: 'feed:press-gazette-17',
	title: 'Acme Gazette wins regional prize',
};

const LIST = {
	collections: [
		{ collection_key: 'q4-client-wins', name: 'Q4 client wins', items: 2 },
		{
			collection_key: 'paywall-complaints',
			name: 'Paywall complaints',
			items: 0,
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
	it( 'saves the item into the chosen collection and says so', async () => {
		wire.replies.collect = { ok: true, event_id: 'ev-s' };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		const select = await screen.findByLabelText( 'Collection' );
		fireEvent.change( select, { target: { value: 'paywall-complaints' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		await waitFor( () =>
			expect( wire.sent( 'collect' ) ).toEqual( [
				[
					'--collection=paywall-complaints',
					'--item=feed:press-gazette-17',
				],
			] )
		);
		expect(
			await screen.findByText( 'Saved to Paywall complaints.' )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toEqual( [ [] ] );
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
		await screen.findByLabelText( 'Collection' );
	} );

	it( 'shows a refused save as the refusal', async () => {
		wire.replies.collect = () =>
			new Error( 'no item: feed:press-gazette-17' );
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		await screen.findByLabelText( 'Collection' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect(
			await screen.findByText( 'no item: feed:press-gazette-17' )
		).toBeInTheDocument();
	} );

	it( 'says there is no collection to save into', async () => {
		wire.replies.collections = { collections: [], available: true };
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			await screen.findByText(
				'No collection yet. Create one under Collections first.'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Save' } ) ).toBeNull();
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

	it( 'focuses its close button, then the collection once the list loads', async () => {
		let answer;
		wire.replies.collections = () =>
			new Promise( ( resolve ) => {
				answer = resolve;
			} );
		render( <SaveToCollection item={ ITEM } onClose={ jest.fn() } /> );
		expect( screen.getByRole( 'button', { name: 'Close' } ) ).toHaveFocus();
		await waitFor( () => expect( answer ).toBeDefined() );
		answer( LIST );
		const select = await screen.findByLabelText( 'Collection' );
		await waitFor( () => expect( select ).toHaveFocus() );
	} );

	it( 'closes from its close button', async () => {
		const onClose = jest.fn();
		render( <SaveToCollection item={ ITEM } onClose={ onClose } /> );
		await screen.findByLabelText( 'Collection' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( onClose ).toHaveBeenCalled();
	} );
} );
