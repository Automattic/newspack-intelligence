/* eslint-env jest */
/**
 * Review reads the two queues — proposed links and proposed entities — each
 * row with the items citing it, and offers a curator the judgments each row
 * takes, re-reading its queue once a judgment lands.
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
import Review from '../Review';

const LINK = {
	item_key: 'github:automattic/newspack#912',
	entity_key: 'organization:acme-gazette',
	name: 'Acme Gazette',
	type: 'organization',
	role: 'mention',
	method: 'ner_fuzzy',
	confidence: 0.61,
	title: 'Paywall bug reported by Acme',
	url: 'https://github.example/automattic/newspack/912',
};

const PROPOSED = {
	entity_key: 'organization:valley-times',
	type: 'organization',
	name: 'Valley Times',
	items: [
		{
			item_key: 'feed:valley-17',
			url: 'https://valley.example/17',
			title: 'Valley Times joins the network',
		},
	],
};

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.review = ( [ what ] ) =>
		'links' === what
			? { links: [ LINK ], available: true }
			: { entities: [ PROPOSED ], available: true };
} );

function renderReview( props = {} ) {
	return render(
		<Review canTune={ false } onOpenEntity={ jest.fn() } { ...props } />
	);
}

const queue = ( name ) => screen.getByRole( 'region', { name } );

describe( 'Review — reads', () => {
	it( 'reads both queues and renders each row with its citing item', async () => {
		renderReview();
		expect(
			await screen.findByRole( 'link', {
				name: 'Paywall bug reported by Acme',
			} )
		).toHaveAttribute( 'href', LINK.url );
		expect(
			await screen.findByRole( 'link', {
				name: 'Valley Times joins the network',
			} )
		).toHaveAttribute( 'href', 'https://valley.example/17' );
		expect(
			within( queue( 'Proposed links' ) ).getByText(
				/mention · ner_fuzzy · 0\.61/
			)
		).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toEqual(
			expect.arrayContaining( [ [ 'links' ], [ 'entities' ] ] )
		);
	} );

	it( 'opens a row entity', async () => {
		const onOpenEntity = jest.fn();
		renderReview( { onOpenEntity } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Valley Times' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:valley-times'
		);
	} );

	it( 'says each queue is empty, or that the graph is unavailable', async () => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [], available: true }
				: { entities: [], available: false };
		renderReview();
		expect(
			await screen.findByText( 'No proposed link awaits review.' )
		).toBeInTheDocument();
		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so this was not read.'
			)
		).toBeInTheDocument();
	} );

	it( 'offers a reader no judgment', async () => {
		renderReview();
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		for ( const name of [ 'Confirm', 'Reject', 'Accept', 'Merge' ] ) {
			expect( screen.queryByRole( 'button', { name } ) ).toBeNull();
		}
	} );
} );

describe( 'Review — judgments', () => {
	it( 'confirms and rejects a link by its item and entity, then re-reads', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', { name: 'Confirm' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
		await waitFor( () =>
			expect(
				wire.sent( 'review' ).filter( ( [ w ] ) => 'links' === w )
			).toHaveLength( 2 )
		);
		const reject = await within( queue( 'Proposed links' ) ).findByRole(
			'button',
			{ name: 'Reject' }
		);
		await waitFor( () => expect( reject ).toBeEnabled() );
		fireEvent.click( reject );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
	} );

	it( 'holds a row judgment while the last one waits', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', { name: 'Confirm' } )
		);
		expect(
			within( links ).getByRole( 'button', { name: 'Reject' } )
		).toBeDisabled();
	} );

	it( 'judges a link whose item key is a whole feed URL, holding its row', async () => {
		const itemKey = `feed:https://news.example.org/2026/10/${ 'slug-'.repeat(
			30
		) }`;
		expect( encodeURIComponent( itemKey ).length ).toBeGreaterThan( 128 );
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [ { ...LINK, item_key: itemKey } ], available: true }
				: { entities: [], available: true };
		wire.replies.confirm_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', { name: 'Confirm' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[ `--item=${ itemKey }`, `--entity=${ LINK.entity_key }` ],
			] )
		);
		expect(
			within( links ).getByRole( 'button', { name: 'Reject' } )
		).toBeDisabled();
	} );

	it( 'accepts, rejects and merges a proposed entity', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-a' };
		wire.replies.reject_entity = { ok: true, event_id: 'ev-x' };
		wire.replies.merge_entities = { ok: true, event_id: 'ev-m' };
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const entities = () => queue( 'Proposed entities' );
		const settled = async ( name ) => {
			const button = await within( entities() ).findByRole( 'button', {
				name,
			} );
			await waitFor( () => expect( button ).toBeEnabled() );
			return button;
		};
		fireEvent.click( await settled( 'Accept' ) );
		await waitFor( () =>
			expect( wire.sent( 'accept_entity' ) ).toEqual( [
				[ `--key=${ PROPOSED.entity_key }` ],
			] )
		);
		fireEvent.click( await settled( 'Reject' ) );
		await waitFor( () =>
			expect( wire.sent( 'reject_entity' ) ).toEqual( [
				[ `--key=${ PROPOSED.entity_key }` ],
			] )
		);
		fireEvent.change( within( entities() ).getByLabelText( 'Merge into' ), {
			target: { value: 'organization:the-valley-times' },
		} );
		fireEvent.click( await settled( 'Merge' ) );
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toEqual( [
				[
					`--from=${ PROPOSED.entity_key }`,
					'--into=organization:the-valley-times',
				],
			] )
		);
	} );

	it( 'holds the entity rows while a merge from that queue waits', async () => {
		wire.replies.merge_entities = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const entities = queue( 'Proposed entities' );
		fireEvent.change( within( entities ).getByLabelText( 'Merge into' ), {
			target: { value: 'organization:the-valley-times' },
		} );
		fireEvent.click(
			within( entities ).getByRole( 'button', { name: 'Merge' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toHaveLength( 1 )
		);
		expect(
			within( entities ).getByRole( 'button', { name: 'Accept' } )
		).toBeDisabled();
		expect(
			within( entities ).getByRole( 'button', { name: 'Merge' } )
		).toBeDisabled();
	} );

	it( 'shows a refused judgment as the refusal', async () => {
		wire.replies.merge_entities = () =>
			new Error(
				'cannot merge organization:valley-times (organization) into person:jo (person)'
			);
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const entities = queue( 'Proposed entities' );
		fireEvent.change( within( entities ).getByLabelText( 'Merge into' ), {
			target: { value: 'person:jo' },
		} );
		fireEvent.click(
			within( entities ).getByRole( 'button', { name: 'Merge' } )
		);
		expect(
			await screen.findByText( /cannot merge organization:valley-times/ )
		).toBeInTheDocument();
	} );
} );
