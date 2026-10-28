/* eslint-env jest */
/**
 * Review reads the two queues — proposed links and proposed entities — each
 * row with the items citing it, and offers a curator the judgments each row
 * takes, re-reading its queue once a judgment lands. A link queue row is one
 * item, holding a judgment for each entity proposed for it.
 */

import {
	render,
	screen,
	fireEvent,
	act,
	waitFor,
	within,
} from '@testing-library/react';
import {
	Core,
	newMessage,
	TYPE,
	TO,
	VALUE,
	TM_ERROR,
} from '@newspack-nodes/runtime';
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

const SIBLING = {
	...LINK,
	entity_key: 'organization:valley-ledger',
	name: 'Valley Ledger',
	role: 'subject',
	method: 'name',
	confidence: 1,
};

// Words distinct from the page's own, so a description proves it read these.
const VOCABULARY = {
	linkRoleWords: { mention: 'Named in passing', subject: 'Headlined' },
	methodWords: { ner_fuzzy: 'a lookalike', name: 'its exact name' },
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
			? { links: [ LINK, SIBLING ], available: true }
			: { entities: [ PROPOSED ], available: true };
} );

function renderReview( props = {} ) {
	return render(
		<Review
			canTune={ false }
			vocabulary={ VOCABULARY }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
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
		const links = queue( 'Proposed links' );
		expect(
			within( links ).getAllByRole( 'link', {
				name: 'Paywall bug reported by Acme',
			} )
		).toHaveLength( 1 );
		expect(
			within( links ).getByRole( 'button', { name: 'Acme Gazette' } )
		).toHaveAccessibleDescription(
			'Named in passing, found by a lookalike (61%)'
		);
		expect(
			within( links ).getByRole( 'button', { name: 'Valley Ledger' } )
		).toHaveAccessibleDescription(
			'Headlined, found by its exact name (100%)'
		);
		expect( wire.sent( 'review' ) ).toEqual(
			expect.arrayContaining( [ [ 'links' ], [ 'entities' ] ] )
		);
	} );

	it( 'reads a role or method outside the vocabulary as given', async () => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ { ...LINK, role: 'cameo', method: 'hunch' } ],
						available: true,
				  }
				: { entities: [], available: true };
		renderReview();
		expect(
			await screen.findByRole( 'button', { name: 'Acme Gazette' } )
		).toHaveAccessibleDescription( 'cameo, found by hunch (61%)' );
	} );

	it( "shows a proposed entity's key, which a merge names", async () => {
		renderReview();
		expect(
			await within( queue( 'Proposed entities' ) ).findByText(
				/organization:valley-times/
			)
		).toBeInTheDocument();
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
		expect(
			screen.queryAllByRole( 'button', {
				name: /^(Confirm|Reject|Accept|Merge)/,
			} )
		).toEqual( [] );
	} );
} );

describe( 'Review — judgments', () => {
	const linksRead = () =>
		wire.sent( 'review' ).filter( ( [ w ] ) => 'links' === w );

	it( 'confirms and rejects a link by its item and entity, then re-reads', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
	} );

	it( 'hides a judged link at once, though the re-read still lists it', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		expect(
			within( links ).queryByRole( 'button', { name: 'Acme Gazette' } )
		).toBeNull();
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		expect(
			within( queue( 'Proposed links' ) ).queryByRole( 'button', {
				name: 'Acme Gazette',
			} )
		).toBeNull();
		expect(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Valley Ledger',
			} )
		).toBeInTheDocument();
	} );

	it( 'takes the next judgment while the last one waits', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		wire.replies.reject_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
		expect(
			within( links ).getByText( 'No proposed link awaits review.' )
		).toBeInTheDocument();
	} );

	it( 'brings a refused judgment back with its refusal', async () => {
		wire.replies.confirm_link = () =>
			new Error( 'no link: github:automattic/newspack#912' );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		expect(
			await screen.findByText( /no link: github:automattic/ )
		).toBeInTheDocument();
		expect(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		).toBeInTheDocument();
	} );

	it( 'brings every judged link back on a refusal naming no send', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		wire.replies.reject_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 )
		);
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'intelligence:confirm_link:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		expect(
			await within( queue( 'Proposed links' ) ).findByRole( 'button', {
				name: 'Acme Gazette',
			} )
		).toBeInTheDocument();
		expect(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Valley Ledger',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows a judged link again once a read has dropped it and a later one lists it', async () => {
		let listed = [ LINK, SIBLING ];
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: listed, available: true }
				: { entities: [], available: true };
		wire.replies.confirm_link = () => {
			listed = [ SIBLING ];
			return { ok: true, event_id: 'ev-c' };
		};
		wire.replies.reject_link = () => {
			listed = [ LINK ];
			return { ok: true, event_id: 'ev-r' };
		};
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		fireEvent.click(
			await within( queue( 'Proposed links' ) ).findByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		expect(
			await within( queue( 'Proposed links' ) ).findByRole( 'button', {
				name: 'Acme Gazette',
			} )
		).toBeInTheDocument();
	} );

	it( 'judges a link whose item key is a whole feed URL, hiding it', async () => {
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
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Gazette',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[ `--item=${ itemKey }`, `--entity=${ LINK.entity_key }` ],
			] )
		);
		expect(
			within( links ).queryByRole( 'button', { name: 'Acme Gazette' } )
		).toBeNull();
	} );

	it.each( [
		[
			'accept_entity',
			'Accept Valley Times',
			[ `--key=${ PROPOSED.entity_key }` ],
		],
		[
			'reject_entity',
			'Reject Valley Times',
			[ `--key=${ PROPOSED.entity_key }` ],
		],
	] )(
		'sends %s for a proposed entity and hides it',
		async ( verb, name, tokens ) => {
			wire.replies[ verb ] = { ok: true, event_id: 'ev-e' };
			renderReview( { canTune: true } );
			await screen.findByRole( 'button', { name: 'Valley Times' } );
			fireEvent.click(
				within( queue( 'Proposed entities' ) ).getByRole( 'button', {
					name,
				} )
			);
			await waitFor( () =>
				expect( wire.sent( verb ) ).toEqual( [ tokens ] )
			);
			expect(
				within( queue( 'Proposed entities' ) ).getByText(
					'No proposed entity awaits review.'
				)
			).toBeInTheDocument();
		}
	);

	it( 'merges a proposed entity into the one named, hiding it', async () => {
		wire.replies.merge_entities = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const entities = queue( 'Proposed entities' );
		expect(
			within( entities ).queryByLabelText( 'Merge into' )
		).toBeNull();
		fireEvent.click(
			within( entities ).getByRole( 'button', {
				name: 'Merge Valley Times…',
			} )
		);
		fireEvent.change( within( entities ).getByLabelText( 'Merge into' ), {
			target: { value: 'organization:the-valley-times' },
		} );
		fireEvent.click(
			within( entities ).getByRole( 'button', { name: 'Merge' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toEqual( [
				[
					`--from=${ PROPOSED.entity_key }`,
					'--into=organization:the-valley-times',
				],
			] )
		);
		expect(
			within( entities ).queryByRole( 'button', { name: 'Valley Times' } )
		).toBeNull();
	} );

	it( 'shows a refused merge as the refusal, the entity back in its queue', async () => {
		wire.replies.merge_entities = () =>
			new Error(
				'cannot merge organization:valley-times (organization) into person:jo (person)'
			);
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const entities = queue( 'Proposed entities' );
		fireEvent.click(
			within( entities ).getByRole( 'button', {
				name: 'Merge Valley Times…',
			} )
		);
		fireEvent.change( within( entities ).getByLabelText( 'Merge into' ), {
			target: { value: 'person:jo' },
		} );
		fireEvent.click(
			within( entities ).getByRole( 'button', { name: 'Merge' } )
		);
		expect(
			await screen.findByText( /cannot merge organization:valley-times/ )
		).toBeInTheDocument();
		expect(
			within( queue( 'Proposed entities' ) ).getByRole( 'button', {
				name: 'Accept Valley Times',
			} )
		).toBeInTheDocument();
	} );
} );
