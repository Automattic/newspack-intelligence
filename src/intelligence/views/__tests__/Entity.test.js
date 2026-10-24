/* eslint-env jest */
/**
 * Entity reads one entity through four verbs — its record, its timeline, the
 * entities sharing its items and the claims naming it — and offers a curator
 * the four edits a record takes, each re-reading the record once it lands.
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
import { ENTITY, ITEM, VOCABULARY } from './fixtures';
import Entity from '../Entity';

const KEY = 'organization:acme-gazette';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [ ITEM ], total: 1, available: true };
	wire.replies.related = {
		entities: [
			{
				entity_key: 'person:jo-reporter',
				name: 'Jo Reporter',
				type: 'person',
				shared_items: 4,
				item_keys: [ 'feed:press-gazette-17' ],
			},
		],
		available: true,
	};
	wire.replies.claims = {
		claims: [
			{
				subject: KEY,
				predicate: 'won',
				object: 'Regional Press Award',
				confidence: 0.9,
				item_key: 'feed:press-gazette-17',
				url: 'https://pressgazette.example/acme',
			},
		],
		available: true,
	};
} );

function renderEntity( props = {} ) {
	return render(
		<Entity
			entityKey={ KEY }
			canTune={ false }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);
}

const recordCard = () =>
	screen
		.getByRole( 'heading', { name: 'Acme Gazette' } )
		.closest( 'section' );

describe( 'Entity — reads', () => {
	it( 'asks each read verb about the entity', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		await waitFor( () => {
			for ( const verb of [
				'entity',
				'timeline',
				'related',
				'claims',
			] ) {
				expect( wire.sent( verb ) ).toEqual( [ [ KEY ] ] );
			}
		} );
	} );

	it( 'renders the record: names, identifiers, roles, topics and counts', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const record = recordCard();
		expect(
			within( record ).getByText( /alias: The Gazette/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /domain: acmegazette\.example/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /client since 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /prospect 2026-05-28 – 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /organization:acme-gazette-inc/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /12 items, 3 proposed links/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByRole( 'button', { name: 'Local news' } )
		).toBeInTheDocument();
	} );

	it( 'renders the timeline, the related entities and the cited claims', async () => {
		const onOpenEntity = jest.fn();
		renderEntity( { onOpenEntity } );
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toBeInTheDocument();
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Jo Reporter' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith( 'person:jo-reporter' );
		expect( screen.getByText( /4 shared items/ ) ).toBeInTheDocument();
		const claims = await screen.findByRole( 'region', { name: 'Claims' } );
		expect(
			within( claims ).getByText( /won Regional Press Award/ )
		).toBeInTheDocument();
		expect(
			within( claims ).getByRole( 'link', {
				name: 'feed:press-gazette-17',
			} )
		).toHaveAttribute( 'href', 'https://pressgazette.example/acme' );
	} );

	it( 'counts one of a thing in the singular', async () => {
		wire.replies.entity = {
			...ENTITY,
			counts: { items: 1, proposed_links: 1 },
		};
		wire.replies.related = {
			entities: [
				{
					entity_key: 'person:jo-reporter',
					name: 'Jo Reporter',
					type: 'person',
					shared_items: 1,
					item_keys: [ 'feed:press-gazette-17' ],
				},
			],
			available: true,
		};
		renderEntity();
		expect(
			await screen.findByText( '1 item, 1 proposed link' )
		).toBeInTheDocument();
		expect(
			await screen.findByText( /person · 1 shared item$/ )
		).toBeInTheDocument();
	} );

	it( 'pages the timeline past the items shown, with its total', async () => {
		wire.replies.timeline = ( args ) => ( {
			items: [ 'a', 'b', 'c' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.slice( 1 ).join( ' ' ) || 'start' }`,
			} ) ),
			total: 7,
			available: true,
		} );
		renderEntity();
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		await within( timeline ).findByText( 'a at start' );
		expect(
			within( timeline ).getByText( '3 of 7 items' )
		).toBeInTheDocument();
		fireEvent.click(
			within( timeline ).getByRole( 'button', { name: 'Next' } )
		);
		expect(
			await within( timeline ).findByText( 'a at --offset=3' )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toContainEqual( [
			KEY,
			'--offset=3',
		] );
	} );

	it( 'says which kind of nothing each section holds', async () => {
		wire.replies.timeline = { items: [], total: 0, available: true };
		wire.replies.related = { entities: [], available: true };
		wire.replies.claims = { claims: [], available: false };
		renderEntity();
		expect(
			await screen.findByText( 'No readable item links this entity.' )
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'No other entity shares an item with this one.'
			)
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
	} );

	it( 'says the record could not be read when the graph is unavailable', async () => {
		wire.replies.entity = { ...ENTITY, name: null, available: false };
		renderEntity();
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows a refused read as the refusal', async () => {
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity();
		expect(
			await screen.findByText( `no entity: ${ KEY }` )
		).toBeInTheDocument();
	} );
} );

describe( 'Entity — curation', () => {
	it( 'offers a reader no curation control', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		for ( const name of [
			'Add name',
			'Add identifier',
			'Assert role',
			'End client',
		] ) {
			expect( screen.queryByRole( 'button', { name } ) ).toBeNull();
		}
	} );

	it( 'offers no edit until the record answers', async () => {
		wire.replies.entity = () => new Error( `no entity: ${ KEY }` );
		renderEntity( { canTune: true } );
		await screen.findByText( `no entity: ${ KEY }` );
		expect(
			screen.queryByRole( 'group', { name: 'Add name' } )
		).toBeNull();
		expect(
			screen.queryByRole( 'group', { name: 'Assert role' } )
		).toBeNull();
	} );

	it( 'adds a name of the chosen kind and re-reads the record', async () => {
		wire.replies.add_name = { ok: true, event_id: 'ev-name' };
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const form = screen.getByRole( 'group', { name: 'Add name' } );
		fireEvent.change( within( form ).getByLabelText( 'Kind' ), {
			target: { value: 'acronym' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'AG' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add name' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_name' ) ).toEqual( [
				[ `--key=${ KEY }`, '--kind=acronym', '--value=AG' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toHaveLength( 2 )
		);
	} );

	it( 'sends a value that reads like an option as the value', async () => {
		wire.replies.add_name = { ok: true, event_id: 'ev-dash' };
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const form = screen.getByRole( 'group', { name: 'Add name' } );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: '--kind=acronym' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add name' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_name' ) ).toEqual( [
				[
					`--key=${ KEY }`,
					'--kind=canonical',
					'--value=--kind=acronym',
				],
			] )
		);
	} );

	it( 'adds an identifier of the chosen kind', async () => {
		wire.replies.add_identifier = { ok: true, event_id: 'ev-id' };
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const form = screen.getByRole( 'group', { name: 'Add identifier' } );
		fireEvent.change( within( form ).getByLabelText( 'Kind' ), {
			target: { value: 'github_org' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'acme-gazette' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add identifier' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_identifier' ) ).toEqual( [
				[
					`--key=${ KEY }`,
					'--kind=github_org',
					'--value=acme-gazette',
				],
			] )
		);
	} );

	it( 'asserts the chosen role and ends an open one', async () => {
		wire.replies.assert_role = { ok: true, event_id: 'ev-role' };
		wire.replies.end_role = { ok: true, event_id: 'ev-end' };
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const form = screen.getByRole( 'group', { name: 'Assert role' } );
		fireEvent.change( within( form ).getByLabelText( 'Role' ), {
			target: { value: 'competitor' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Assert role' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'End client' } ) );
		await waitFor( () => {
			expect( wire.sent( 'assert_role' ) ).toEqual( [
				[ `--key=${ KEY }`, '--role=competitor' ],
			] );
			expect( wire.sent( 'end_role' ) ).toEqual( [
				[ `--key=${ KEY }`, '--role=client' ],
			] );
		} );
		expect(
			screen.queryByRole( 'button', { name: 'End prospect' } )
		).toBeNull();
	} );

	it( 'shows a refused edit as the refusal', async () => {
		wire.replies.assert_role = () =>
			new Error( `${ KEY } already holds client` );
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Gazette' } );
		const form = screen.getByRole( 'group', { name: 'Assert role' } );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Assert role' } )
		);
		expect(
			await screen.findByText( `${ KEY } already holds client` )
		).toBeInTheDocument();
	} );
} );
