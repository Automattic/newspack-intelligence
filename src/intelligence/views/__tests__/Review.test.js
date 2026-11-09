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
import { speak } from '@wordpress/a11y';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import Review from '../Review';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const LINK = {
	item_key: 'github:example/plugin#912',
	entity_key: 'organization:acme-quillsheet',
	name: 'Acme Quillsheet',
	type: 'organization',
	role: 'mention',
	method: 'ner_fuzzy',
	confidence: 0.61,
	evidence: 'Acme readers flagged the paywall bug twice.',
	title: 'Paywall bug reported by Acme',
	url: 'https://github.example/example/plugin/912',
};

const SIBLING = {
	...LINK,
	entity_key: 'organization:valley-ledger',
	name: 'Valley Ledger',
	role: 'subject',
	method: 'name',
	confidence: 1,
	evidence: 'Valley Ledger editors reproduced it.',
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
			evidence: 'Valley Times signed on this week.',
		},
	],
};

const PAIR = {
	themes: [
		{ theme: 'kiosk offboarding', stories: 4 },
		{ theme: 'kiosk offboarding from platform', stories: 2 },
	],
	words: [ 'kiosk', 'offboarding' ],
	shared: 3,
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
	wire.replies.theme_suggestions = { suggestions: [ PAIR ], available: true };
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

// Only the undo window's own timer is faked; the wire runs as it does.
const fakeUndoClock = () =>
	jest.useFakeTimers( {
		doNotFake: [
			'Date',
			'hrtime',
			'nextTick',
			'performance',
			'queueMicrotask',
			'requestAnimationFrame',
			'cancelAnimationFrame',
			'requestIdleCallback',
			'cancelIdleCallback',
			'setImmediate',
			'clearImmediate',
			'setInterval',
			'clearInterval',
		],
	} );
const elapse = ( ms ) =>
	act( async () => {
		jest.advanceTimersByTime( ms );
	} );
const settle = () => act( async () => {} );

afterEach( () => {
	jest.useRealTimers();
} );

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
			within( links ).getByRole( 'button', { name: 'Acme Quillsheet' } )
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
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
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

	it( "saves a proposed link's item to a folder when given a save handler", async () => {
		const onSave = jest.fn();
		renderReview( { canTune: true, onSave } );
		const links = await screen.findByRole( 'region', {
			name: 'Proposed links',
		} );
		fireEvent.click(
			await within( links ).findByRole( 'button', {
				name: 'Save to folder',
			} )
		);
		expect( onSave ).toHaveBeenCalledWith(
			expect.objectContaining( {
				item_key: 'github:example/plugin#912',
				title: 'Paywall bug reported by Acme',
			} )
		);
	} );

	it( "saves a proposed entity's cited item to a folder when given a save handler", async () => {
		const onSave = jest.fn();
		renderReview( { canTune: true, onSave } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		fireEvent.click(
			within( queue( 'Proposed entities' ) ).getByRole( 'button', {
				name: 'Save to folder',
			} )
		);
		expect( onSave ).toHaveBeenCalledWith(
			expect.objectContaining( {
				item_key: 'feed:valley-17',
				title: 'Valley Times joins the network',
			} )
		);
	} );

	it( 'offers a reader no judgment', async () => {
		renderReview();
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		expect(
			screen.queryAllByRole( 'button', {
				name: /^(Confirm|Reject|Accept|Merge|Save to folder)/,
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
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
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
		await elapse( UNDO_MS );
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
		fakeUndoClock();
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		expect(
			within( links ).queryByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeNull();
		await elapse( UNDO_MS );
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		expect(
			within( queue( 'Proposed links' ) ).queryByRole( 'button', {
				name: 'Acme Quillsheet',
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
		fakeUndoClock();
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
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
			new Error( 'no link: github:example/plugin#912' );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
		expect(
			await screen.findByText( /no link: github:example\/plugin/ )
		).toBeInTheDocument();
		expect(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		).toBeInTheDocument();
	} );

	it( 'brings every judged link back on a refusal that does not name its send', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		wire.replies.reject_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'link', {
			name: 'Paywall bug reported by Acme',
		} );
		fakeUndoClock();
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
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
				name: 'Acme Quillsheet',
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
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		fireEvent.click(
			await within( queue( 'Proposed links' ) ).findByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		expect(
			await within( queue( 'Proposed links' ) ).findByRole( 'button', {
				name: 'Acme Quillsheet',
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
		fakeUndoClock();
		const links = queue( 'Proposed links' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[ `--item=${ itemKey }`, `--entity=${ LINK.entity_key }` ],
			] )
		);
		expect(
			within( links ).queryByRole( 'button', { name: 'Acme Quillsheet' } )
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
			fakeUndoClock();
			fireEvent.click(
				within( queue( 'Proposed entities' ) ).getByRole( 'button', {
					name,
				} )
			);
			await elapse( UNDO_MS );
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

describe( 'Review — themes', () => {
	const MERGE_INTO_FIRST =
		'Merge kiosk offboarding from platform into kiosk offboarding';
	const MERGE_INTO_SECOND =
		'Merge kiosk offboarding into kiosk offboarding from platform';

	it( 'lists each suggested pair with its stories, shared words and entities, and offers a reader no merge', async () => {
		renderReview();
		const themes = queue( 'Themes' );
		expect(
			await within( themes ).findByText( 'kiosk offboarding' )
		).toBeInTheDocument();
		expect(
			within( themes ).getByText( 'kiosk offboarding from platform' )
		).toBeInTheDocument();
		expect(
			within( themes ).getByText(
				'4 stories and 2 stories, 3 entities in common, sharing kiosk, offboarding'
			)
		).toBeInTheDocument();
		expect( within( themes ).queryAllByRole( 'button' ) ).toEqual( [] );
		expect( wire.sent( 'theme_suggestions' ) ).toEqual( [ [] ] );
	} );

	it( 'says when no theme pair is suggested', async () => {
		wire.replies.theme_suggestions = { suggestions: [], available: true };
		renderReview();
		expect(
			await screen.findByText( 'No two themes look alike.' )
		).toBeInTheDocument();
	} );

	it( 'merges either theme into the other, hiding the pair, then re-reads', async () => {
		wire.replies.merge_theme = { ok: true, event_id: 'ev-t' };
		renderReview( { canTune: true } );
		const themes = queue( 'Themes' );
		const merge = await within( themes ).findByRole( 'button', {
			name: MERGE_INTO_FIRST,
		} );
		expect( merge ).toHaveTextContent( 'Merge into kiosk offboarding' );
		fakeUndoClock();
		fireEvent.click( merge );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'merge_theme' ) ).toEqual( [
				[
					'--from=kiosk offboarding from platform',
					'--into=kiosk offboarding',
				],
			] )
		);
		expect(
			within( queue( 'Themes' ) ).queryByRole( 'button', {
				name: MERGE_INTO_SECOND,
			} )
		).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'theme_suggestions' ) ).toHaveLength( 2 )
		);
	} );

	it( 'merges the first theme into the second', async () => {
		wire.replies.merge_theme = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		const merge = await within( queue( 'Themes' ) ).findByRole( 'button', {
			name: MERGE_INTO_SECOND,
		} );
		fakeUndoClock();
		fireEvent.click( merge );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'merge_theme' ) ).toEqual( [
				[
					'--from=kiosk offboarding',
					'--into=kiosk offboarding from platform',
				],
			] )
		);
	} );

	it( 'shows a refused merge as the refusal, the pair back in place', async () => {
		wire.replies.merge_theme = () =>
			new Error(
				'kiosk offboarding merges into kiosk exit; merge kiosk offboarding from platform into kiosk exit instead'
			);
		renderReview( { canTune: true } );
		const merge = await within( queue( 'Themes' ) ).findByRole( 'button', {
			name: MERGE_INTO_FIRST,
		} );
		fakeUndoClock();
		fireEvent.click( merge );
		await elapse( UNDO_MS );
		expect(
			await screen.findByText( /into kiosk exit instead/ )
		).toBeInTheDocument();
		expect(
			within( queue( 'Themes' ) ).getByRole( 'button', {
				name: MERGE_INTO_SECOND,
			} )
		).toBeInTheDocument();
	} );
} );

describe( 'Review — evidence', () => {
	it( 'quotes the sentence each proposal came from', async () => {
		renderReview();
		const links = queue( 'Proposed links' );
		expect(
			await within( links ).findByText(
				'Acme readers flagged the paywall bug twice.'
			)
		).toBeInTheDocument();
		expect(
			within( links ).getByText( 'Valley Ledger editors reproduced it.' )
		).toBeInTheDocument();
		expect(
			await within( queue( 'Proposed entities' ) ).findByText(
				'Valley Times signed on this week.'
			)
		).toBeInTheDocument();
	} );

	it( 'quotes nothing for a proposal that is not spelled in any sentence', async () => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [ { ...LINK, evidence: '' } ], available: true }
				: { entities: [], available: true };
		renderReview();
		const links = queue( 'Proposed links' );
		await within( links ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		expect( links.querySelectorAll( 'blockquote' ) ).toHaveLength( 0 );
	} );
} );

describe( 'Review — keyboard', () => {
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'list', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );
	// A judgment is focused: one row, carrying the tabindex the ring keys on.
	const focusedHolds = ( name ) => {
		expect( document.activeElement ).toHaveAttribute( 'tabindex', '-1' );
		expect( document.activeElement ).toContainElement(
			screen.getByRole( 'button', { name } )
		);
	};

	it( 'names each list and describes its keys to a screen reader, each key on its control', async () => {
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		for ( const name of [ 'Proposed links', 'Proposed entities' ] ) {
			expect( list( name ) ).toHaveAccessibleDescription(
				'Keys: J and K to move, Y yes, N no, M same as…, Z undo.'
			);
		}
		expect(
			screen.getByRole( 'button', { name: 'Confirm Acme Quillsheet' } )
		).toHaveAttribute( 'aria-keyshortcuts', 'y' );
		expect(
			screen.getByRole( 'button', { name: 'Reject Acme Quillsheet' } )
		).toHaveAttribute( 'aria-keyshortcuts', 'n' );
		expect(
			screen.getByRole( 'button', { name: 'Merge Valley Times…' } )
		).toHaveAttribute( 'aria-keyshortcuts', 'm' );
	} );

	it( 'moves through the judgments with j and k, each focusable', async () => {
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		focusedHolds( 'Acme Quillsheet' );
		key( document.activeElement, 'j' );
		focusedHolds( 'Valley Ledger' );
		key( document.activeElement, 'j' );
		focusedHolds( 'Valley Ledger' );
		key( document.activeElement, 'k' );
		focusedHolds( 'Acme Quillsheet' );
		expect( links ).toHaveAttribute( 'tabindex', '0' );
	} );

	it( 'confirms with y and rejects with n, focus moving to the next row', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		wire.replies.reject_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		fakeUndoClock();
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		await act( async () => key( document.activeElement, 'y' ) );
		focusedHolds( 'Valley Ledger' );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
		focusedHolds( 'Valley Ledger' );
		await act( async () => key( document.activeElement, 'n' ) );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
		expect(
			within( queue( 'Proposed links' ) ).getByText(
				'No proposed link awaits review.'
			)
		).toBeInTheDocument();
	} );

	it( 'focuses the row above once the last row is judged', async () => {
		wire.replies.reject_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Ledger' } );
		fakeUndoClock();
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'k' );
		key( document.activeElement, 'j' );
		await act( async () => key( document.activeElement, 'n' ) );
		focusedHolds( 'Acme Quillsheet' );
		await elapse( UNDO_MS );
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ SIBLING.item_key }`,
				`--entity=${ SIBLING.entity_key }`,
			],
		] );
		focusedHolds( 'Acme Quillsheet' );
	} );

	it( 'accepts an entity with y, and opens its merge with m', async () => {
		wire.replies.accept_entity = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		fakeUndoClock();
		const entities = list( 'Proposed entities' );
		entities.focus();
		key( entities, 'j' );
		focusedHolds( 'Valley Times' );
		await act( async () => key( document.activeElement, 'm' ) );
		const into = within( queue( 'Proposed entities' ) ).getByLabelText(
			'Merge into'
		);
		expect( document.activeElement ).toBe( into );
		key( into, 'y' );
		key( into, 'n' );
		key( into, 'k' );
		expect( document.activeElement ).toBe( into );
		expect( wire.sent( 'accept_entity' ) ).toEqual( [] );
		expect( wire.sent( 'reject_entity' ) ).toEqual( [] );
		into.closest( '[tabindex="-1"]' ).focus();
		await act( async () => key( document.activeElement, 'y' ) );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'accept_entity' ) ).toEqual( [
				[ `--key=${ PROPOSED.entity_key }` ],
			] )
		);
	} );

	it( 'ignores a key with a modifier, and every key outside the list', async () => {
		wire.replies.confirm_link = () => new Promise( () => {} );
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		fireEvent.keyDown( document.activeElement, {
			key: 'y',
			metaKey: true,
		} );
		key( document.body, 'y' );
		key( document.body, 'j' );
		focusedHolds( 'Acme Quillsheet' );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'offers a reader the moves and no judgment', async () => {
		renderReview();
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		key( document.activeElement, 'y' );
		focusedHolds( 'Acme Quillsheet' );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );
} );

describe( 'Review — undo', () => {
	const HARBOR = {
		...LINK,
		entity_key: 'organization:harbor-ledger',
		name: 'Harbor Ledger',
	};
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'list', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );
	const harborRow = () =>
		within( queue( 'Proposed links' ) )
			.queryByRole( 'button', { name: 'Harbor Ledger' } )
			?.closest( '[data-review-row]' ) ?? null;

	beforeEach( () => {
		speak.mockClear();
		window.localStorage.clear();
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [ HARBOR, SIBLING ], available: true }
				: { entities: [ PROPOSED ], available: true };
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		wire.replies.accept_entity = { ok: true, event_id: 'ev-a' };
		wire.replies.merge_theme = { ok: true, event_id: 'ev-t' };
	} );

	async function focusHarbor() {
		await screen.findByRole( 'button', { name: 'Harbor Ledger' } );
		fakeUndoClock();
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		expect( document.activeElement ).toBe( harborRow() );
	}

	it( 'hides a row judged with n, and z brings it and its focus back with nothing sent', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		expect( harborRow() ).toBeNull();
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await act( async () => key( document.activeElement, 'z' ) );
		expect( harborRow() ).not.toBeNull();
		expect( document.activeElement ).toBe( harborRow() );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'sends a judgment once, after the undo window', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await elapse( UNDO_MS - 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await elapse( 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ HARBOR.item_key }`,
				`--entity=${ HARBOR.entity_key }`,
			],
		] );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( harborRow() ).toBeNull();
	} );

	it( 'offers the undo in a snackbar and announces it politely', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		const snackbar = screen.getByText( 'Rejected Harbor Ledger.' );
		expect( snackbar.closest( '.components-snackbar' ) ).toHaveTextContent(
			'Rejected Harbor Ledger.Undo'
		);
		expect( speak ).toHaveBeenCalledWith(
			'Rejected Harbor Ledger.',
			'polite'
		);
		expect( speak ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'undoes the judgment from the snackbar, the row focused again', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'y' ) );
		expect(
			screen.getByText( 'Confirmed Harbor Ledger.' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( document.activeElement ).toBe( harborRow() );
		expect( screen.queryByText( 'Confirmed Harbor Ledger.' ) ).toBeNull();
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'undoes only the most recent judgment still pending', async () => {
		const prairie = {
			...SIBLING,
			entity_key: 'organization:prairie-signal',
			name: 'Prairie Signal',
		};
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [ HARBOR, SIBLING, prairie ], available: true }
				: { entities: [], available: true };
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await act( async () => key( document.activeElement, 'y' ) );
		expect(
			screen.getByText( 'Confirmed Valley Ledger.' )
		).toBeInTheDocument();
		await act( async () => key( document.activeElement, 'z' ) );
		expect(
			within( queue( 'Proposed links' ) ).getByRole( 'button', {
				name: 'Valley Ledger',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Rejected Harbor Ledger.' )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'sends a pending judgment once on a tab switch, and withdraws its undo', async () => {
		const { unmount } = renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		unmount();
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ HARBOR.item_key }`,
				`--entity=${ HARBOR.entity_key }`,
			],
		] );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
	} );

	it( 'sends every pending judgment once when the page is left', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await act( async () => key( document.activeElement, 'y' ) );
		await act( async () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toHaveLength( 1 );
	} );

	it( 'holds an accepted entity and a theme merge for the undo window too', async () => {
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const merge = await within( queue( 'Themes' ) ).findByRole( 'button', {
			name: 'Merge kiosk offboarding from platform into kiosk offboarding',
		} );
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Proposed entities' ) ).getByRole( 'button', {
				name: 'Accept Valley Times',
			} )
		);
		expect(
			screen.getByText( 'Accepted Valley Times.' )
		).toBeInTheDocument();
		fireEvent.click( merge );
		expect(
			screen.getByText(
				'Merged kiosk offboarding from platform into kiosk offboarding.'
			)
		).toBeInTheDocument();
		expect(
			within( queue( 'Themes' ) ).getByText( 'No two themes look alike.' )
		).toBeInTheDocument();
		await elapse( UNDO_MS - 1 );
		await settle();
		expect( wire.sent( 'accept_entity' ) ).toEqual( [] );
		expect( wire.sent( 'merge_theme' ) ).toEqual( [] );
		await elapse( 1 );
		await settle();
		expect( wire.sent( 'accept_entity' ) ).toEqual( [
			[ `--key=${ PROPOSED.entity_key }` ],
		] );
		expect( wire.sent( 'merge_theme' ) ).toEqual( [
			[
				'--from=kiosk offboarding from platform',
				'--into=kiosk offboarding',
			],
		] );
	} );

	it( 'brings a theme pair back with its focus on an undo', async () => {
		renderReview( { canTune: true } );
		const merge = await within( queue( 'Themes' ) ).findByRole( 'button', {
			name: 'Merge kiosk offboarding from platform into kiosk offboarding',
		} );
		fakeUndoClock();
		fireEvent.click( merge );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( document.activeElement ).toHaveTextContent(
			'kiosk offboardingkiosk offboarding from platform'
		);
		expect( document.activeElement.tagName ).toBe( 'LI' );
	} );
} );

describe( 'Review — the keys legend and switch', () => {
	const LEGEND = 'Keys: J and K to move, Y yes, N no, M same as…, Z undo.';
	const SWITCH = 'Turn off single-key shortcuts';
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'list', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );

	beforeEach( () => {
		window.localStorage.clear();
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
	} );

	it( 'shows the legend as visible text describing each list', async () => {
		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		const legend = screen.getByText( LEGEND );
		expect( legend.closest( '.components-visually-hidden' ) ).toBeNull();
		for ( const name of [ 'Proposed links', 'Proposed entities' ] ) {
			expect( list( name ) ).toHaveAccessibleDescription( LEGEND );
		}
	} );

	it( 'takes no single key while shortcuts are turned off, remembered per browser', async () => {
		const { unmount } = renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		const off = screen.getByRole( 'checkbox', { name: SWITCH } );
		expect( off ).not.toBeChecked();
		fireEvent.click( off );
		expect( off ).toBeChecked();
		expect( screen.queryByText( LEGEND ) ).toBeNull();
		unmount();

		renderReview( { canTune: true } );
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		expect(
			screen.getByRole( 'checkbox', { name: SWITCH } )
		).toBeChecked();
		const links = list( 'Proposed links' );
		links.focus();
		key( links, 'j' );
		expect( document.activeElement ).toBe( links );
		const row = screen
			.getByRole( 'button', { name: 'Acme Quillsheet' } )
			.closest( '[data-review-row]' );
		row.focus();
		await act( async () => key( row, 'y' ) );
		expect(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		await act( async () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );
} );
