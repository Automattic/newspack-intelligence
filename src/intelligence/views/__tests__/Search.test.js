/* eslint-env jest */
/**
 * Search asks `search` on mount and on every submit, with each filter given
 * as a named option, and renders the items it answers; its two kinds of
 * nothing say which they are.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, VOCABULARY } from './fixtures';
import { useState } from '@wordpress/element';
import Search, { NEW_SEARCH } from '../Search';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
} );

function Harness( props ) {
	const [ query, setQuery ] = useState( NEW_SEARCH );
	return (
		<Search
			query={ query }
			onQuery={ setQuery }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
}

function renderSearch( props = {} ) {
	return render( <Harness { ...props } /> );
}

describe( 'Search', () => {
	it( 'asks for the newest items on mount and renders them', async () => {
		wire.replies.search = { items: [ ITEM ], total: 9, available: true };
		renderSearch();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toBeInTheDocument();
		expect( screen.getByText( /1 of 9 items/ ) ).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toEqual( [ [] ] );
	} );

	it( 'sends every filter given as a named option', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		fireEvent.change( screen.getByLabelText( 'Words' ), {
			target: { value: 'regional prize' },
		} );
		fireEvent.change( screen.getByLabelText( 'Kind' ), {
			target: { value: 'funding' },
		} );
		fireEvent.change( screen.getByLabelText( 'Topic' ), {
			target: { value: 'topic:local-news' },
		} );
		fireEvent.change( screen.getByLabelText( 'Source' ), {
			target: { value: 'github' },
		} );
		fireEvent.change( screen.getByLabelText( 'Since' ), {
			target: { value: '2026-09-01' },
		} );
		fireEvent.change( screen.getByLabelText( 'Until' ), {
			target: { value: '2026-09-30' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--q=regional prize',
				'--kind=funding',
				'--topic=topic:local-news',
				'--source=github',
				'--since=2026-09-01',
				'--until=2026-09-30',
			] )
		);
	} );

	it( 'pages forward past the rows shown, and back', async () => {
		wire.replies.search = ( args ) => ( {
			items: [ 'a', 'b', 'c' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.join( ' ' ) || 'start' }`,
			} ) ),
			total: 120,
			available: true,
		} );
		renderSearch();
		await screen.findByText( 'a at start' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText( 'a at --offset=3' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect( await screen.findByText( 'a at start' ) ).toBeInTheDocument();
	} );

	it( 'opens an entity from a result chip', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		const onOpenEntity = jest.fn();
		renderSearch( { onOpenEntity } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Gazette' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-gazette'
		);
	} );

	it( 'offers save to collection only to a curator', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		const onSave = jest.fn();
		renderSearch( { onSave } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: /save to collection/i } )
		);
		expect( onSave ).toHaveBeenCalledWith( ITEM );
	} );

	it( 'offers no save control to a reader', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		expect(
			screen.queryByRole( 'button', { name: /save to collection/i } )
		).toBeNull();
	} );

	it( 'says no item matches when the graph answers none', async () => {
		wire.replies.search = { items: [], total: 0, available: true };
		renderSearch();
		expect(
			await screen.findByText( 'No readable item matches these filters.' )
		).toBeInTheDocument();
	} );

	it( 'says the graph is unavailable rather than that nothing matched', async () => {
		wire.replies.search = { items: [], total: 0, available: false };
		renderSearch();
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'No readable item matches these filters.' )
		).toBeNull();
	} );
} );
