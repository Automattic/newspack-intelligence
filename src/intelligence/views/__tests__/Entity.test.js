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

const KEY = 'organization:acme-quillsheet';

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
				items: [
					{
						item_key: 'feed:inkfold-review-17',
						url: 'https://inkfold-review.example/acme',
						title: 'Acme Quillsheet wins regional prize',
					},
				],
			},
		],
		available: true,
	};
	wire.replies.signals = { signals: [], available: true };
	wire.replies.claims = {
		claims: [
			{
				subject: KEY,
				predicate: 'won',
				object: 'Regional Press Award',
				confidence: 0.9,
				item_key: 'feed:inkfold-review-17',
				url: 'https://inkfold-review.example/acme',
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
		.getByRole( 'heading', { name: 'Acme Quillsheet' } )
		.closest( 'section' );

describe( 'Entity — reads', () => {
	it( 'asks each read verb about the entity', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
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
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		const record = recordCard();
		expect(
			within( record ).getByText( /alias: The Quillsheet/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /domain: acmequillsheet\.example/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /client since 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /prospect 2026-05-28 – 2026-09-09/ )
		).toBeInTheDocument();
		expect(
			within( record ).getByText( /organization:acme-quillsheet-inc/ )
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
				name: 'Acme Quillsheet wins regional prize',
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
				name: 'feed:inkfold-review-17',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
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
					items: [
						{
							item_key: 'feed:inkfold-review-17',
							url: 'https://inkfold-review.example/acme',
							title: 'Acme Quillsheet wins regional prize',
						},
					],
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
			await screen.findByText( 'No readable item is about this entity.' )
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

	it( 'shows its signals under its header, each marked by its axis', async () => {
		wire.replies.signals = {
			signals: [
				{
					axis: 'about',
					signal: 'spike',
					bucket: '2026-10-12',
					by: 'kind',
					value: 'complaint',
					count: 6,
					baseline: 1.125,
					items: [
						{
							item_key: 'linear:SUP-140',
							url: 'https://linear.example/SUP-140',
							title: 'Acme asks for a refund',
						},
					],
				},
				{
					axis: 'publisher',
					signal: 'drop',
					bucket: '2026-10-12',
					by: null,
					value: null,
					count: 2,
					baseline: 7.375,
					items: [
						{
							item_key: 'feed:acmequillsheet.example:city-hall',
							url: 'https://acmequillsheet.example/city-hall',
							title: 'City hall budget vote',
						},
					],
				},
			],
			available: true,
		};
		renderEntity();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const drop = await within( signals ).findByRole( 'row', {
			name: /publisher drop/,
		} );
		expect(
			within( drop ).getByText( '2 against a baseline of 7.4' )
		).toBeInTheDocument();
		expect(
			within( drop ).getByRole( 'link', {
				name: 'City hall budget vote',
			} )
		).toHaveAttribute( 'href', 'https://acmequillsheet.example/city-hall' );
		expect(
			within( signals ).getByRole( 'row', {
				name: /about spike: complaint/,
			} )
		).toBeInTheDocument();
		expect(
			recordCard().compareDocumentPosition( signals ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect(
			signals.compareDocumentPosition(
				screen.getByRole( 'region', { name: 'Timeline' } )
			) & window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( wire.sent( 'signals' ) ).toEqual( [ [ `--entity=${ KEY }` ] ] );
	} );

	it( 'says it shows no signal', async () => {
		renderEntity();
		expect(
			await screen.findByText( 'No signal is seen for this entity.' )
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

describe( 'Entity — status', () => {
	const heading = () =>
		screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );

	it( 'heads a client with its start, latest signal, asks and last item', async () => {
		wire.replies.signals = {
			signals: [
				{
					axis: 'publisher',
					signal: 'drop',
					bucket: '2026-10-12',
					by: null,
					value: null,
					count: 2,
					baseline: 4.5,
					items: [],
				},
			],
			available: true,
		};
		renderEntity();
		const status = ( await heading() ).nextElementSibling;
		await waitFor( () =>
			expect( status ).toHaveTextContent(
				'Client since 2026-09-09 · Latest signal: publisher drop, week of 2026-10-12 · 2 feature requests, 5 complaints · Last item 2026-09-22'
			)
		);
		expect( wire.sent( 'signals' ) ).toHaveLength( 1 );
	} );

	it( 'says a client has no signal and no item yet, and counts one in the singular', async () => {
		wire.replies.entity = {
			...ENTITY,
			status: {
				client_since: 1791504000,
				kinds: { feature_request: 1, complaint: 0 },
				last_item_ts: null,
			},
		};
		renderEntity();
		const status = ( await heading() ).nextElementSibling;
		await waitFor( () =>
			expect( status ).toHaveTextContent(
				'Client since 2026-10-09 · No signal · 1 feature request, 0 complaints · No item about it yet'
			)
		);
	} );

	it( 'renders no status line for an entity holding no client role', async () => {
		wire.replies.entity = {
			...ENTITY,
			roles: [ ENTITY.roles[ 1 ] ],
			status: null,
		};
		renderEntity();
		await heading();
		expect( screen.queryByText( /Client since/ ) ).not.toBeInTheDocument();
		expect( screen.queryByText( /Latest signal/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'Entity — published', () => {
	const published = () =>
		screen.getByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );

	beforeEach( () => {
		wire.replies.entity = { ...ENTITY, published: { count: 4 } };
		wire.replies.timeline = ( args ) =>
			args.includes( '--published' )
				? {
						items: [ 'crater', 'ferry' ].map( ( n ) => ( {
							...ITEM,
							item_key: `feed:acmequillsheet.example:${ n }`,
							title: `${ n } at ${
								args.slice( 2 ).join( ' ' ) || 'start'
							}`,
							entities: [],
						} ) ),
						total: 4,
						available: true,
				  }
				: { items: [ ITEM ], total: 1, available: true };
	} );

	it( 'lists the items about it first, then a collapsed section counting what it published', async () => {
		renderEntity();
		const timeline = await screen.findByRole( 'region', {
			name: 'Timeline',
		} );
		await screen.findByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );
		const section = published().closest( 'section' );
		expect(
			// eslint-disable-next-line no-bitwise
			timeline.compareDocumentPosition( section ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( published() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( within( section ).queryByRole( 'list' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'timeline' ) ).toEqual( [ [ KEY ] ] )
		);
	} );

	it( 'reads what it published once opened, a page at a time', async () => {
		renderEntity();
		await screen.findByRole( 'button', {
			name: 'Published by Acme Quillsheet (4)',
		} );
		fireEvent.click( published() );
		const section = published().closest( 'section' );
		expect( published() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect(
			await within( section ).findByText( 'crater at start' )
		).toBeInTheDocument();
		expect(
			within( section ).getByText( '2 of 4 items' )
		).toBeInTheDocument();
		fireEvent.click(
			within( section ).getByRole( 'button', { name: 'Next' } )
		);
		expect(
			await within( section ).findByText( 'crater at --offset=2' )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toEqual(
			expect.arrayContaining( [
				[ KEY, '--published' ],
				[ KEY, '--published', '--offset=2' ],
			] )
		);
	} );

	it( 'offers no section for an entity that published nothing', async () => {
		wire.replies.entity = { ...ENTITY, published: { count: 0 } };
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		expect(
			screen.queryByRole( 'button', { name: /^Published by/ } )
		).toBeNull();
	} );
} );

describe( 'Entity — curation', () => {
	it( 'offers a reader no curation control', async () => {
		renderEntity();
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
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
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
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
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
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
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		const form = screen.getByRole( 'group', { name: 'Add identifier' } );
		fireEvent.change( within( form ).getByLabelText( 'Kind' ), {
			target: { value: 'github_org' },
		} );
		fireEvent.change( within( form ).getByLabelText( 'Value' ), {
			target: { value: 'acme-quillsheet' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add identifier' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'add_identifier' ) ).toEqual( [
				[
					`--key=${ KEY }`,
					'--kind=github_org',
					'--value=acme-quillsheet',
				],
			] )
		);
	} );

	it( 'asserts the chosen role and ends an open one', async () => {
		wire.replies.assert_role = { ok: true, event_id: 'ev-role' };
		wire.replies.end_role = { ok: true, event_id: 'ev-end' };
		renderEntity( { canTune: true } );
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
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
		await screen.findByRole( 'heading', { name: 'Acme Quillsheet' } );
		const form = screen.getByRole( 'group', { name: 'Assert role' } );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Assert role' } )
		);
		expect(
			await screen.findByText( `${ KEY } already holds client` )
		).toBeInTheDocument();
	} );
} );
