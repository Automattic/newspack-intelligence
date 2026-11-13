/* eslint-env jest */
/**
 * Collections is the Folders tab: the Folders board at the `folder-board`
 * block's defaults, under the tab's header, opening on the items not yet
 * used in every folder, and for a curator the items excluded from every
 * view, a section of the page, the one place they are listed and included
 * again.
 */

import { screen, fireEvent, waitFor, within } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import Collections from '../Collections';
import { pageData } from './fixtures';

const LIST = {
	collections: [
		{
			collection_key: 'harbor-ledger-notes',
			name: 'Harbor Ledger notes',
			created_ts: 1790035200,
			items: 2,
			recent_ts: 1792497600,
		},
		{
			collection_key: 'prairie-picks',
			name: 'Prairie picks',
			created_ts: 1790121600,
			items: 0,
			recent_ts: null,
		},
	],
	available: true,
};

const UNUSED = {
	item_key: 'feed:inkfold-review-17',
	url: 'https://inkfold-review.example/acme',
	title: 'Lakeside Works wins regional prize',
	actor: 'user:editor',
	saved_ts: 1792411200,
	marks: [],
};

const USED = {
	item_key: 'linear:quillsheet-pilot',
	url: 'https://tracker.example/quillsheet-pilot',
	title: 'Quillsheet pilot goes live',
	actor: 'user:desk',
	saved_ts: 1792497600,
	marks: [ { mark: 'site', day: '2026-10-21' } ],
};

const WIRE_COPY = {
	item_key: 'feed:tern-wire-copy-31',
	title: 'Tern wire copy of the prize story',
	url: 'https://tern-wire.example/31',
	actor: 'user:tern-desk',
	excluded_ts: 1792411200,
	why: 'The wire copy of a story we hold.',
};

const UNSAID = {
	item_key: 'linear:heron-stray-8',
	title: 'Heron stray ticket',
	url: 'https://tracker.example/heron-stray-8',
	actor: 'agent:tern-desk:Claude',
	excluded_ts: 1792497600,
	why: '',
};

let wire;

const Harness = ( props ) => <Collections { ...props } />;

afterEach( () => {
	jest.restoreAllMocks();
} );

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.collections = ( args ) =>
		0 === args.length
			? LIST
			: {
					collection_key: args[ 0 ],
					name: args[ 0 ],
					created_ts: 1790035200,
					items:
						'harbor-ledger-notes' === args[ 0 ]
							? [ UNUSED, USED ]
							: [],
					available: true,
			  };
	wire.replies.excluded = { items: [ UNSAID, WIRE_COPY ], available: true };
} );

describe( 'Collections', () => {
	it( 'draws the board on every folder, opening on the items not yet used', async () => {
		renderOnPage( <Harness canTune={ false } /> );
		const notes = await screen.findByRole( 'region', {
			name: 'Harbor Ledger notes',
		} );
		expect(
			await within( notes ).findByRole( 'link', { name: UNUSED.title } )
		).toBeInTheDocument();
		expect(
			within( notes ).queryByRole( 'link', { name: USED.title } )
		).toBeNull();
		expect(
			screen.getByRole( 'region', { name: 'Prairie picks' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Show' ) ).toHaveValue( 'unused' );
	} );

	it( 'offers a curator the card menu and a reader none', async () => {
		const { unmount } = renderOnPage( <Harness canTune={ true } /> );
		expect(
			await screen.findByRole( 'button', {
				name: `Actions for ${ UNUSED.title }`,
			} )
		).toBeInTheDocument();
		unmount();
		renderOnPage( <Harness canTune={ false } /> );
		await screen.findByRole( 'link', { name: UNUSED.title } );
		expect(
			screen.queryByRole( 'button', {
				name: `Actions for ${ UNUSED.title }`,
			} )
		).toBeNull();
	} );
} );

describe( 'Collections — excluded items', () => {
	const excluded = () => screen.findByRole( 'region', { name: 'Excluded' } );

	it( 'stands the excluded items in a section of the page under the shared header, no card around them', async () => {
		renderOnPage( <Harness canTune={ true } /> );
		const section = await excluded();
		expect(
			within( section )
				.getByRole( 'heading', { level: 2, name: 'Excluded' } )
				.closest( '.newspack-nodes-composed-section-head' )
		).not.toBeNull();
		expect(
			screen
				.getByRole( 'heading', { level: 2, name: 'Folders' } )
				.closest( '.newspack-nodes-composed-section-head' )
		).not.toBeNull();
		expect( section.closest( '.components-card' ) ).toBeNull();
	} );

	it( 'lists a curator every excluded item, who excluded it, when and why', async () => {
		jest.spyOn( Core, 'now' ).mockReturnValue(
			Date.UTC( 2026, 10, 10, 12 ) / 1000
		);
		renderOnPage( <Harness canTune={ true } /> );
		const section = await excluded();

		expect(
			await within( section ).findByRole( 'link', {
				name: WIRE_COPY.title,
			} )
		).toHaveAttribute( 'href', WIRE_COPY.url );
		const meta = [
			...section.querySelectorAll( '.newspack-intelligence__meta' ),
		].map( ( line ) =>
			[ ...line.children ].map( ( cell ) => cell.textContent )
		);
		expect( meta ).toEqual( [
			[ 'Excluded by an agent', '20 Oct' ],
			[
				'Excluded by a curator',
				'19 Oct',
				'The wire copy of a story we hold.',
			],
		] );
		expect(
			within( section ).getByText( '19 Oct' ).closest( 'time' )
		).toHaveAttribute( 'dateTime', '2026-10-19' );
		expect( section ).not.toHaveTextContent( /tern-desk|·/ );
		expect( wire.sent( 'excluded' ) ).toEqual( [ [] ] );
	} );

	it( 'says when nothing is excluded', async () => {
		wire.replies.excluded = { items: [], available: true };
		renderOnPage( <Harness canTune={ true } /> );
		expect(
			await within( await excluded() ).findByText(
				'No item is excluded from every view.'
			)
		).toBeInTheDocument();
	} );

	it( 'includes an item again, takes it off the list and reads it again', async () => {
		wire.replies.include_item = { ok: true, event_id: 'ev-i' };
		renderOnPage( <Harness canTune={ true } /> );
		const section = await excluded();
		const row = (
			await within( section ).findByRole( 'link', {
				name: WIRE_COPY.title,
			} )
		).closest( 'li' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Include again' } )
		);

		expect(
			within( section ).queryByRole( 'link', { name: WIRE_COPY.title } )
		).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'include_item' ) ).toEqual( [
				[ `--item=${ WIRE_COPY.item_key }` ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'excluded' ) ).toHaveLength( 2 )
		);
		expect(
			within( section ).queryByRole( 'link', { name: WIRE_COPY.title } )
		).toBeNull();
	} );

	it( 'puts a refused inclusion back with the refusal', async () => {
		wire.replies.include_item = () =>
			new Error( `${ WIRE_COPY.item_key } is not excluded` );
		renderOnPage( <Harness canTune={ true } /> );
		const section = await excluded();
		const row = (
			await within( section ).findByRole( 'link', {
				name: WIRE_COPY.title,
			} )
		).closest( 'li' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Include again' } )
		);

		expect(
			await screen.findByText( `${ WIRE_COPY.item_key } is not excluded` )
		).toBeInTheDocument();
		expect(
			await within( section ).findByRole( 'link', {
				name: WIRE_COPY.title,
			} )
		).toBeInTheDocument();
	} );

	it( 'shows a reader no excluded list and asks for none', async () => {
		renderOnPage( <Harness canTune={ false } /> );
		await screen.findByRole( 'region', { name: 'Harbor Ledger notes' } );
		expect(
			screen.queryByRole( 'region', { name: 'Excluded' } )
		).toBeNull();
		expect( wire.sent( 'excluded' ) ).toEqual( [] );
	} );
} );
