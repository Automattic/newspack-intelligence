/* eslint-env jest */
/**
 * Search asks `search` on mount and on every submit, with each filter given
 * as a named option and each list value repeated, and renders the items it
 * answers; its two kinds of nothing say which they are. A lens picked shows
 * every filter it runs with, read-only, and sends only the lens, the words
 * and the page; "Edit as my own search" copies them into Search's fields.
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
import { ITEM, LENSES, VOCABULARY } from './fixtures';
import { useState } from '@wordpress/element';
import Search, { newSearch } from '../Search';
import { daysSince } from '../../components/lensDraft';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
} );

function Harness( props ) {
	const [ query, setQuery ] = useState( () =>
		newSearch( VOCABULARY.lensLists )
	);
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
		fireEvent.change( screen.getByLabelText( 'Kinds' ), {
			target: { value: 'funding, launch' },
		} );
		fireEvent.change( screen.getByLabelText( 'Topics' ), {
			target: { value: 'topic:local-news' },
		} );
		fireEvent.change( screen.getByLabelText( 'Roles' ), {
			target: { value: 'competitor' },
		} );
		fireEvent.change( screen.getByLabelText( 'Sources' ), {
			target: { value: 'github,' },
		} );
		fireEvent.change( screen.getByLabelText( 'Entity' ), {
			target: { value: 'organization:valley-ledger' },
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
				'--kind=launch',
				'--topic=topic:local-news',
				'--role=competitor',
				'--source=github',
				'--entity=organization:valley-ledger',
				'--since=2026-09-01',
				'--until=2026-09-30',
			] )
		);
	} );

	it( 'shows a lens picked read-only and searches only through it, with the words', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByRole( 'option', { name: 'Support desk' } );
		fireEvent.change( screen.getByLabelText( 'Words' ), {
			target: { value: 'paywall' },
		} );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: 'support-desk' },
		} );
		const runs = screen.getByRole( 'group', {
			name: 'Support desk searches with',
		} );
		for ( const shown of [
			'complaint, praise',
			'client',
			'organization:acme-gazette',
			'The last 21 days',
			'internal',
		] ) {
			expect( within( runs ).getByText( shown ) ).toBeInTheDocument();
		}
		expect( within( runs ).getAllByText( 'Any' ) ).toHaveLength( 2 );
		expect( screen.queryByLabelText( 'Kinds' ) ).toBeNull();
		expect( screen.queryByLabelText( 'Entity' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--q=paywall',
				'--lens=support-desk',
			] )
		);
		expect( wire.sent( 'lenses' ) ).toEqual( [ [] ] );
	} );

	it( 'copies a lens into its own fields to edit, dropping the lens and its tiers', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByRole( 'option', { name: 'Support desk' } );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: 'support-desk' },
		} );
		expect(
			screen.getByText(
				'A search reads every tier you may read, so editing drops this lens’s tiers.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit as my own search' } )
		);
		const since = daysSince( 21, Date.now() );
		expect( screen.getByLabelText( 'Lens' ) ).toHaveValue( '' );
		expect( screen.getByLabelText( 'Kinds' ) ).toHaveValue(
			'complaint, praise'
		);
		expect( screen.getByLabelText( 'Roles' ) ).toHaveValue( 'client' );
		expect( screen.getByLabelText( 'Entity' ) ).toHaveValue(
			'organization:acme-gazette'
		);
		expect( screen.getByLabelText( 'Since' ) ).toHaveValue( since );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--kind=complaint',
				'--kind=praise',
				'--role=client',
				'--entity=organization:acme-gazette',
				`--since=${ since }`,
			] )
		);
		fireEvent.change( screen.getByLabelText( 'Kinds' ), {
			target: { value: '' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--role=client',
				'--entity=organization:acme-gazette',
				`--since=${ since }`,
			] )
		);
	} );

	it( 'copies a lens setting no days or tiers without a since day or a tier note', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByRole( 'option', { name: 'Developer shipping' } );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: 'dev-shipping' },
		} );
		const runs = screen.getByRole( 'group', {
			name: 'Developer shipping searches with',
		} );
		expect( within( runs ).getAllByText( 'Any' ) ).toHaveLength( 4 );
		expect( screen.queryByText( /drops this lens’s tiers/ ) ).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit as my own search' } )
		);
		expect( screen.getByLabelText( 'Since' ) ).toHaveValue( '' );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--kind=release',
				'--topic=topic:product',
				'--source=github',
				'--source=linear',
			] )
		);
	} );

	it( 'returns to its own filters when no lens is picked', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByRole( 'option', { name: 'Support desk' } );
		fireEvent.change( screen.getByLabelText( 'Kinds' ), {
			target: { value: 'award' },
		} );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: 'support-desk' },
		} );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: '' },
		} );
		expect( screen.getByLabelText( 'Kinds' ) ).toHaveValue( 'award' );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [ '--kind=award' ] )
		);
	} );

	it( 'shows a refused lens list as the refusal', async () => {
		wire.replies.lenses = () => new Error( 'unknown command: lenses' );
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		expect(
			await screen.findByText( 'unknown command: lenses' )
		).toBeInTheDocument();
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
