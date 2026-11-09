/* eslint-env jest */
/**
 * Search asks `search` on mount and on every submit, with each filter given
 * as a named option and each list value repeated, and renders the items it
 * answers; its two kinds of nothing say which they are. Kinds, roles and
 * sources are multi-selects, and the entity suggests the entities whose names
 * it leads, through `names`. A lens picked shows
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
	const [ asked, setAsked ] = useState( null );
	return (
		<Search
			query={ query }
			onQuery={ setQuery }
			operator={ {
				name: 'Quillmere Cooperative',
				heading: 'Quillmere Cooperative',
			} }
			asked={ asked }
			onAsked={ setAsked }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
}

function renderSearch( props = {} ) {
	return render( <Harness { ...props } /> );
}

/**
 * The multi-select a label names.
 *
 * @param {string} label Its label.
 * @return {HTMLElement} The field.
 */
const tokenField = ( label ) =>
	screen.getByLabelText( label ).closest( '.components-form-token-field' );

/**
 * The tokens a multi-select holds, in order.
 *
 * @param {string} label Its label.
 * @return {string[]} The tokens.
 */
const tokens = ( label ) =>
	[
		...tokenField( label ).querySelectorAll(
			'.components-form-token-field__token-text [aria-hidden="true"]'
		),
	].map( ( one ) => one.textContent );

/**
 * Type each value into a multi-select and enter it.
 *
 * @param {string}    label  Its label.
 * @param {...string} values The values.
 */
const pick = ( label, ...values ) => {
	const box = screen.getByLabelText( label );
	for ( const value of values ) {
		fireEvent.change( box, { target: { value } } );
		fireEvent.keyDown( box, { key: 'Enter' } );
	}
};

/**
 * What an input's suggestion list offers, each its value and its label.
 *
 * @param {HTMLElement} input The input.
 * @return {string[][]} Each offer.
 */
const offered = ( input ) =>
	[
		...( document
			.getElementById( input.getAttribute( 'list' ) )
			?.querySelectorAll( 'option' ) ?? [] ),
	].map( ( one ) => [ one.value, one.label ] );

/**
 * Remove every token a multi-select holds.
 *
 * @param {string} label Its label.
 */
const clearTokens = ( label ) => {
	for ( const remove of within( tokenField( label ) ).getAllByRole(
		'button',
		{ name: 'Remove item' }
	) ) {
		fireEvent.click( remove );
	}
};

describe( 'Search', () => {
	it( 'opens with the Ask box above the filters', async () => {
		renderSearch();
		const box = await screen.findByPlaceholderText(
			'Ask about clients, the industry or Quillmere Cooperative…'
		);
		expect( document.querySelectorAll( 'section' )[ 0 ] ).toContainElement(
			box
		);
		expect( wire.sent( 'ask' ) ).toEqual( [] );
	} );

	it( 'asks for the newest items on mount and renders them', async () => {
		wire.replies.search = { items: [ ITEM ], total: 9, available: true };
		renderSearch();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
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
		pick( 'Kinds', 'funding', 'launch' );
		fireEvent.change( screen.getByLabelText( 'Topics' ), {
			target: { value: 'topic:local-news' },
		} );
		pick( 'Roles', 'competitor' );
		pick( 'Sources', 'github' );
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

	it( 'sends the kinds multi-select as repeated --kind= args, refusing a kind outside the vocabulary', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		pick( 'Kinds', 'funding', 'gossip', 'award' );
		expect( tokens( 'Kinds' ) ).toEqual( [ 'funding', 'award' ] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--kind=funding',
				'--kind=award',
			] )
		);
	} );

	it( 'refuses a kind or a role outside its vocabulary typed with a comma', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		fireEvent.change( screen.getByLabelText( 'Kinds' ), {
			target: { value: 'gossip,launch,' },
		} );
		fireEvent.change( screen.getByLabelText( 'Roles' ), {
			target: { value: 'rival,' },
		} );
		expect( tokens( 'Kinds' ) ).toEqual( [ 'launch' ] );
		expect( tokens( 'Roles' ) ).toEqual( [] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--kind=launch',
			] )
		);
		expect( wire.sent( 'search' ).flat() ).not.toContain( '--kind=gossip' );
	} );

	it( 'takes a role from its vocabulary alone and a source as typed', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		pick( 'Roles', 'former_client', 'rival' );
		pick( 'Sources', 'linear', 'unthread' );
		expect( tokens( 'Roles' ) ).toEqual( [ 'Lapsed member' ] );
		expect( tokens( 'Sources' ) ).toEqual( [ 'linear', 'unthread' ] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--role=former_client',
				'--source=linear',
				'--source=unthread',
			] )
		);
	} );

	it( 'suggests the entities whose names the entity typed leads, and searches the one picked', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		wire.replies.names = ( args ) => ( {
			entities:
				'--prefix=Valley' === args[ 0 ]
					? [
							{
								entity_key: 'organization:valley-ledger',
								name: 'Valley Ledger',
							},
							{
								entity_key: 'organization:valley-times',
								name: 'Valley Times',
							},
							{
								entity_key: 'person:valley-times',
								name: 'Valley Times',
							},
					  ]
					: [],
			available: true,
		} );
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		const entity = screen.getByLabelText( 'Entity' );
		fireEvent.change( entity, { target: { value: 'V' } } );
		expect( wire.sent( 'names' ) ).toEqual( [] );
		fireEvent.change( entity, { target: { value: 'Valley' } } );
		await waitFor( () =>
			expect( offered( entity ) ).toEqual( [
				[ 'Valley Ledger', 'organization:valley-ledger' ],
				[ 'organization:valley-times', 'Valley Times' ],
				[ 'person:valley-times', 'Valley Times' ],
			] )
		);
		fireEvent.change( entity, { target: { value: 'Valley Ledger' } } );
		expect( entity ).toHaveValue( 'Valley Ledger' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--entity=organization:valley-ledger',
			] )
		);
		expect( entity ).toHaveValue( 'organization:valley-ledger' );
		expect( wire.sent( 'names' ) ).toEqual( [ [ '--prefix=Valley' ] ] );
	} );

	it( 'lets a name that leads another be typed past, searching the longer one', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		wire.replies.names = ( args ) => ( {
			entities: args[ 0 ].startsWith( '--prefix=Quiet H' )
				? [
						{
							entity_key: 'organization:quiet-harbor',
							name: 'Quiet Harbor',
						},
						{
							entity_key: 'organization:quiet-harbor-weekly',
							name: 'Quiet Harbor Weekly',
						},
				  ]
				: [],
			available: true,
		} );
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		const entity = screen.getByLabelText( 'Entity' );
		fireEvent.change( entity, { target: { value: 'Quiet H' } } );
		await waitFor( () => expect( offered( entity ) ).toHaveLength( 2 ) );
		fireEvent.change( entity, { target: { value: 'Quiet Harbor' } } );
		expect( entity ).toHaveValue( 'Quiet Harbor' );
		fireEvent.change( entity, {
			target: { value: 'Quiet Harbor Weekly' },
		} );
		expect( entity ).toHaveValue( 'Quiet Harbor Weekly' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--entity=organization:quiet-harbor-weekly',
			] )
		);
	} );

	it( 'asks names only past two letters or digits, and says a refusal was not read', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		wire.replies.names = () =>
			new Error( 'names could not be read for Harb' );
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		const entity = screen.getByLabelText( 'Entity' );
		fireEvent.change( entity, { target: { value: 'h.' } } );
		fireEvent.change( entity, { target: { value: ' -h- ' } } );
		expect( wire.sent( 'names' ) ).toEqual( [] );
		fireEvent.change( entity, { target: { value: 'Harb' } } );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'names could not be read for Harb' )
		).toBeNull();
		expect( wire.sent( 'names' ) ).toEqual( [ [ '--prefix=Harb' ] ] );
	} );

	it( 'asks nothing for an entity box emptied', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		wire.replies.names = {
			entities: [
				{ entity_key: 'topic:harbor-news', name: 'Harbor News' },
			],
			available: true,
		};
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		const entity = screen.getByLabelText( 'Entity' );
		fireEvent.change( entity, { target: { value: 'Harb' } } );
		await waitFor( () =>
			expect( offered( entity ) ).toEqual( [
				[ 'Harbor News', 'topic:harbor-news' ],
			] )
		);
		fireEvent.change( entity, { target: { value: ' ' } } );
		await waitFor( () => expect( offered( entity ) ).toEqual( [] ) );
		expect( wire.sent( 'names' ) ).toEqual( [ [ '--prefix=Harb' ] ] );
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
			'organization:acme-quillsheet',
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
		expect( tokens( 'Kinds' ) ).toEqual( [ 'complaint', 'praise' ] );
		expect( tokens( 'Roles' ) ).toEqual( [ 'Paying member' ] );
		expect( screen.getByLabelText( 'Entity' ) ).toHaveValue(
			'organization:acme-quillsheet'
		);
		expect( screen.getByLabelText( 'Since' ) ).toHaveValue( since );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--kind=complaint',
				'--kind=praise',
				'--role=client',
				'--entity=organization:acme-quillsheet',
				`--since=${ since }`,
			] )
		);
		clearTokens( 'Kinds' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Search' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [
				'--role=client',
				'--entity=organization:acme-quillsheet',
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

	it( 'returns to its own filters when the lens is cleared', async () => {
		wire.replies.lenses = LENSES;
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByRole( 'option', { name: 'Support desk' } );
		pick( 'Kinds', 'award' );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: 'support-desk' },
		} );
		fireEvent.change( screen.getByLabelText( 'Lens' ), {
			target: { value: '' },
		} );
		expect( tokens( 'Kinds' ) ).toEqual( [ 'award' ] );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toContainEqual( [ '--kind=award' ] )
		);
	} );

	it( 'says a refused lens list was not read, its words kept from a reader', async () => {
		wire.replies.lenses = () => new Error( 'unknown command: lenses' );
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'unknown command: lenses' ) ).toBeNull();
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
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
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-quillsheet'
		);
	} );

	it( 'offers save to folder only to a curator', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		const onSave = jest.fn();
		renderSearch( { onSave } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: /save to folder/i } )
		);
		expect( onSave ).toHaveBeenCalledWith( ITEM );
	} );

	it( 'offers no save control to a reader', async () => {
		wire.replies.search = { items: [ ITEM ], total: 1, available: true };
		renderSearch();
		await screen.findByText( /^1 of 1 item$/ );
		expect(
			screen.queryByRole( 'button', { name: /save to folder/i } )
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
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'No readable item matches these filters.' )
		).toBeNull();
	} );

	it( 'switches the order and asks again from the first page', async () => {
		wire.replies.search = ( args ) => ( {
			items: [ ITEM ],
			total: 70,
			available: true,
			sort: args.includes( '--sort=time' ) ? 'time' : 'importance',
		} );
		renderSearch();
		expect(
			await screen.findByText( 'Ranked by importance' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [ '--offset=1' ] )
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Show newest first' } )
		);
		expect( await screen.findByText( 'Newest first' ) ).toBeInTheDocument();
		expect( wire.sent( 'search' ).at( -1 ) ).toEqual( [ '--sort=time' ] );
	} );
} );
