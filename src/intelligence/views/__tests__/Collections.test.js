/* eslint-env jest */
/**
 * Collections lists every collection, opens one onto its saved items, and
 * offers a curator the form creating one, re-listing once it lands.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { useState } from '@wordpress/element';
import Collections from '../Collections';

const LIST = {
	collections: [
		{
			collection_key: 'q4-client-wins',
			name: 'Q4 client wins',
			created_ts: 1790035200,
			items: 2,
		},
	],
	available: true,
};

const ONE = {
	collection_key: 'q4-client-wins',
	name: 'Q4 client wins',
	created_ts: 1790035200,
	items: [
		{
			item_key: 'feed:press-gazette-17',
			url: 'https://pressgazette.example/acme',
			title: 'Acme Gazette wins regional prize',
			actor: 'user:editor',
		},
	],
	available: true,
};

let wire;

function Harness( props ) {
	const [ opened, setOpened ] = useState( null );
	return <Collections opened={ opened } onOpen={ setOpened } { ...props } />;
}

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.collections = ( args ) => ( 0 === args.length ? LIST : ONE );
} );

describe( 'Collections', () => {
	it( 'lists every collection with its count', async () => {
		render( <Harness canTune={ false } /> );
		expect(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /2 items · 2026-09-22/ )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toEqual( [ [] ] );
	} );

	it( 'opens a collection onto its saved items, each linking its URL', async () => {
		render( <Harness canTune={ false } /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		const items = await screen.findByRole( 'region', {
			name: 'Q4 client wins',
		} );
		expect(
			await within( items ).findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://pressgazette.example/acme' );
		expect(
			within( items ).getByText( /user:editor/ )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toContainEqual( [
			'q4-client-wins',
		] );
	} );

	it( 'says there are no collections, or that the graph is unavailable', async () => {
		wire.replies.collections = { collections: [], available: true };
		const { unmount } = render( <Harness canTune={ false } /> );
		expect(
			await screen.findByText( 'No collection yet.' )
		).toBeInTheDocument();
		unmount();
		Core.reset();
		wire = intelWire();
		wire.replies.collections = { collections: [], available: false };
		render( <Harness canTune={ false } /> );
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
	} );

	it( 'says an open collection holds nothing readable', async () => {
		wire.replies.collections = ( args ) =>
			0 === args.length ? LIST : { ...ONE, items: [] };
		render( <Harness canTune={ false } /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		expect(
			await screen.findByText(
				'This collection holds no item you can read.'
			)
		).toBeInTheDocument();
	} );

	it( 'offers a reader no create form', async () => {
		render( <Harness canTune={ false } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		expect(
			screen.queryByRole( 'button', { name: 'Create collection' } )
		).toBeNull();
	} );

	it( 'creates a collection by name and lists again', async () => {
		wire.replies.create_collection = {
			ok: true,
			event_id: 'ev-c',
			collection_key: 'paywall-complaints',
		};
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Paywall complaints' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create collection' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'create_collection' ) ).toEqual( [
				[ '--name=Paywall complaints' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'collections' ) ).toHaveLength( 2 )
		);
	} );

	it( 'shows a refused creation as the refusal', async () => {
		wire.replies.create_collection = () =>
			new Error( 'collection q4-client-wins is taken' );
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Q4 client wins' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create collection' } )
		);
		expect(
			await screen.findByText( 'collection q4-client-wins is taken' )
		).toBeInTheDocument();
	} );
} );
