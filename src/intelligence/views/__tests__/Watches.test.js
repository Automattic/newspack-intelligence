/* eslint-env jest */
/**
 * Watches lists the caller's watches with their unseen counts, opens one
 * onto its unseen matches a page at a time, marks one or all of them seen,
 * and saves, edits and removes a watch through one form naming exactly one
 * target and all three deliveries.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, LENSES } from './fixtures';
import Watches, { CLOSED_WATCH, newWatch } from '../Watches';

const WATCHES = {
	watches: [
		{
			watch_key: 'acme-complaints',
			name: 'Acme complaints',
			target: { kind: 'entity', key: 'organization:acme-gazette' },
			digest: true,
			instant: false,
			inbox: true,
			unseen: 7,
		},
		{
			watch_key: 'support-spikes',
			name: 'Support spikes',
			target: { kind: 'lens', key: 'support-desk' },
			digest: false,
			instant: true,
			inbox: false,
			unseen: 0,
		},
	],
	available: true,
};

const UNSAFE = {
	...ITEM,
	item_key: 'feed:untitled-9',
	title: '',
	url: 'javascript:alert(1)',
	matched_ts: 1790121600,
};

const MATCHES = {
	items: [ { ...ITEM, matched_ts: 1790121600 }, UNSAFE ],
	total: 7,
	available: true,
};

const COLLECTIONS = {
	collections: [
		{
			collection_key: 'q4-client-wins',
			name: 'Q4 client wins',
			created_ts: 1790035200,
			items: 2,
		},
		{
			collection_key: 'paywall-complaints',
			name: 'Paywall complaints',
			created_ts: 1790121600,
			items: 5,
		},
	],
	available: true,
};

let wire;

function Harness( { initialDraft = null } ) {
	const [ opened, setOpened ] = useState( CLOSED_WATCH );
	const [ draft, setDraft ] = useState( initialDraft );
	return (
		<Watches
			opened={ opened }
			onOpen={ setOpened }
			draft={ draft }
			onDraft={ setDraft }
			onOpenEntity={ jest.fn() }
		/>
	);
}

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.watches = WATCHES;
	wire.replies.watch_matches = MATCHES;
	wire.replies.lenses = LENSES;
	wire.replies.collections = COLLECTIONS;
} );

const openAcme = async () =>
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Acme complaints' } )
	);

const matchRow = async ( name ) =>
	( await screen.findByText( name ) ).closest( 'li' );

const form = () => screen.getByRole( 'form', { name: 'Save watch' } );

const type = ( label, value ) =>
	fireEvent.change( within( form() ).getByLabelText( label ), {
		target: { value },
	} );

describe( 'Watches', () => {
	it( 'lists the caller’s watches with their targets, unseen counts and an inbox off', async () => {
		render( <Harness /> );
		const acme = (
			await screen.findByRole( 'button', {
				name: 'Acme complaints',
			} )
		).closest( 'li' );
		expect(
			within( acme ).getByText(
				'Entity · organization:acme-gazette · 7 unseen'
			)
		).toBeInTheDocument();
		const support = screen
			.getByRole( 'button', { name: 'Support spikes' } )
			.closest( 'li' );
		expect(
			within( support ).getByText( 'Lens · support-desk · Inbox off' )
		).toBeInTheDocument();
		expect( wire.sent( 'watches' ) ).toEqual( [ [] ] );
	} );

	it( 'says there is no watch yet', async () => {
		wire.replies.watches = { watches: [], available: true };
		render( <Harness /> );
		expect(
			await screen.findByText( 'No watch yet.' )
		).toBeInTheDocument();
	} );

	it( 'shows a refused read of watches as the refusal', async () => {
		wire.replies.watches = () => new Error( 'not signed in' );
		render( <Harness /> );
		expect(
			await screen.findByText( 'not signed in' )
		).toBeInTheDocument();
	} );

	it( 'opens a watch onto its unseen matches, linking only a safe URL', async () => {
		render( <Harness /> );
		await openAcme();
		const region = await screen.findByRole( 'region', {
			name: 'Acme complaints',
		} );
		expect(
			await within( region ).findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://pressgazette.example/acme' );
		expect(
			within( region ).getByText( 'javascript:alert(1)' ).closest( 'a' )
		).toBeNull();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [
			[ '--key=acme-complaints' ],
		] );
	} );

	it( 'says the inbox is off for a watch that does not list its matches', async () => {
		wire.replies.watch_matches = { items: [], total: 0, available: true };
		render( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support spikes' } )
		);
		const region = await screen.findByRole( 'region', {
			name: 'Support spikes',
		} );
		expect(
			await within( region ).findByText(
				'The inbox is off for this watch, so its matches are not listed here.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'No unseen match.' ) ).toBeNull();
	} );

	it( 'says a watch has no unseen match', async () => {
		wire.replies.watch_matches = { items: [], total: 0, available: true };
		render( <Harness /> );
		await openAcme();
		expect(
			await screen.findByText( 'No unseen match.' )
		).toBeInTheDocument();
	} );

	it( 'pages a watch’s matches forward and back', async () => {
		wire.replies.watch_matches = ( args ) => ( {
			items: [ 'a', 'b' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.join( ' ' ) }`,
				matched_ts: 1790121600,
			} ) ),
			total: 7,
			available: true,
		} );
		render( <Harness /> );
		await openAcme();
		await screen.findByText( 'a at --key=acme-complaints' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText( 'a at --key=acme-complaints --offset=2' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect(
			await screen.findByText( 'a at --key=acme-complaints' )
		).toBeInTheDocument();
	} );

	it( 'marks one match seen, hiding it before a re-read stops listing it', async () => {
		wire.replies.mark_seen = { ok: true, count: 1 };
		render( <Harness /> );
		await openAcme();
		const row = await matchRow( 'Acme Gazette wins regional prize' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Mark seen' } )
		);
		expect(
			screen.queryByText( 'Acme Gazette wins regional prize' )
		).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'mark_seen' ) ).toEqual( [
				[ '--key=acme-complaints', '--item=feed:press-gazette-17' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watch_matches' ) ).toHaveLength( 2 )
		);
		await waitFor( () =>
			expect( wire.sent( 'watches' ) ).toHaveLength( 2 )
		);
		expect(
			screen.queryByText( 'Acme Gazette wins regional prize' )
		).toBeNull();
		expect( screen.getByText( 'javascript:alert(1)' ) ).toBeInTheDocument();
	} );

	it( 'returns to the first page once a mark lands, so no match is skipped', async () => {
		wire.replies.mark_seen = { ok: true, count: 1 };
		wire.replies.watch_matches = ( args ) => ( {
			items: [ 'a', 'b' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }-${ args.length }`,
				title: `${ n } at ${ args.join( ' ' ) }`,
				matched_ts: 1790121600,
			} ) ),
			total: 7,
			available: true,
		} );
		render( <Harness /> );
		await openAcme();
		await screen.findByText( 'a at --key=acme-complaints' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		const row = await matchRow( 'a at --key=acme-complaints --offset=2' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Mark seen' } )
		);
		expect(
			await screen.findByText( 'b at --key=acme-complaints' )
		).toBeInTheDocument();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [
			[ '--key=acme-complaints' ],
			[ '--key=acme-complaints', '--offset=2' ],
			[ '--key=acme-complaints' ],
		] );
		expect(
			screen.queryByRole( 'button', { name: 'Previous' } )
		).toBeNull();
	} );

	it( 'marks the matches shown seen in one send naming each, hiding them all', async () => {
		wire.replies.mark_seen = { ok: true, count: 7 };
		render( <Harness /> );
		await openAcme();
		await matchRow( 'Acme Gazette wins regional prize' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Mark these seen' } )
		);
		expect(
			screen.queryByText( 'Acme Gazette wins regional prize' )
		).toBeNull();
		expect( screen.queryByText( 'javascript:alert(1)' ) ).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'mark_seen' ) ).toEqual( [
				[
					'--key=acme-complaints',
					'--item=feed:press-gazette-17',
					'--item=feed:untitled-9',
				],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watch_matches' ) ).toHaveLength( 2 )
		);
		expect( screen.queryByText( 'javascript:alert(1)' ) ).toBeNull();
	} );

	it( 'puts every match back when marking them seen is refused', async () => {
		wire.replies.mark_seen = () => new Error( 'no watch: acme-complaints' );
		render( <Harness /> );
		await openAcme();
		await matchRow( 'Acme Gazette wins regional prize' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Mark these seen' } )
		);
		expect(
			await screen.findByText( 'no watch: acme-complaints' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Acme Gazette wins regional prize' )
		).toBeInTheDocument();
		expect( screen.getByText( 'javascript:alert(1)' ) ).toBeInTheDocument();
	} );

	it( 'saves a new watch with exactly one target and all three deliveries', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w1',
			watch_key: 'paywall-watch',
		};
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New watch' } ) );
		type( 'Name', 'Paywall watch' );
		type( 'Follows', 'entity' );
		type( 'Entity', 'organization:other-gazette' );
		type( 'Follows', 'collection' );
		await within( form() ).findByRole( 'option', {
			name: 'Paywall complaints',
		} );
		type( 'Collection', 'paywall-complaints' );
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		);
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me each new match' )
		);
		fireEvent.click(
			within( form() ).getByLabelText( 'List new matches on this page' )
		);
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_watch' ) ).toEqual( [
				[
					'--name=Paywall watch',
					'--collection=paywall-complaints',
					'--digest=true',
					'--instant=true',
					'--inbox=false',
				],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watches' ) ).toHaveLength( 2 )
		);
		expect(
			screen.queryByRole( 'form', { name: 'Save watch' } )
		).toBeNull();
		expect( screen.getByText( 'Paywall watch' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Saving…' ) ).toBeInTheDocument();
	} );

	it( 'lists a saved watch as the read answers it once it does', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w4',
			watch_key: 'shipping-watch',
		};
		let saved = false;
		wire.replies.watches = () => ( {
			...WATCHES,
			watches: saved
				? [
						...WATCHES.watches,
						{
							watch_key: 'shipping-watch',
							name: 'Shipping watch',
							target: { kind: 'lens', key: 'dev-shipping' },
							digest: false,
							instant: false,
							inbox: true,
							unseen: 4,
						},
				  ]
				: WATCHES.watches,
		} );
		render(
			<Harness
				initialDraft={ newWatch(
					{ kind: 'lens', key: 'dev-shipping' },
					'Shipping watch'
				) }
			/>
		);
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		saved = true;
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		const row = (
			await screen.findByRole( 'button', { name: 'Shipping watch' } )
		).closest( 'li' );
		expect(
			within( row ).getByText( 'Lens · dev-shipping · 4 unseen' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Saving…' ) ).toBeNull();
	} );

	it( 'lists an edit the server stored on the entity’s survivor once a read answers it', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w5',
			watch_key: 'acme-complaints',
		};
		let saved = false;
		wire.replies.watches = () =>
			saved
				? {
						...WATCHES,
						watches: [
							{
								...WATCHES.watches[ 0 ],
								name: 'Acme watch',
								unseen: 3,
							},
							WATCHES.watches[ 1 ],
						],
				  }
				: WATCHES;
		render( <Harness /> );
		await openAcme();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit Acme complaints' } )
		);
		type( 'Name', 'Acme watch' );
		type( 'Entity', 'organization:acme-gazette-inc' );
		saved = true;
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		const row = (
			await screen.findByRole( 'button', { name: 'Acme watch' } )
		).closest( 'li' );
		expect(
			await within( row ).findByText(
				'Entity · organization:acme-gazette · 3 unseen'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'save_watch' ) ).toEqual( [
			[
				'--key=acme-complaints',
				'--name=Acme watch',
				'--entity=organization:acme-gazette-inc',
				'--digest=true',
				'--instant=false',
				'--inbox=true',
			],
		] );
	} );

	it( 'lists a new watch the server stored on the entity’s survivor once a read answers it', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w6',
			watch_key: '9f3a51c07be2d468',
		};
		let saved = false;
		wire.replies.watches = () => ( {
			...WATCHES,
			watches: saved
				? [
						...WATCHES.watches,
						{
							watch_key: '9f3a51c07be2d468',
							name: 'Gazette group',
							target: {
								kind: 'entity',
								key: 'organization:acme-gazette',
							},
							digest: false,
							instant: true,
							inbox: true,
							unseen: 2,
						},
				  ]
				: WATCHES.watches,
		} );
		render(
			<Harness
				initialDraft={ newWatch(
					{ kind: 'entity', key: 'organization:acme-gazette-inc' },
					'Gazette group'
				) }
			/>
		);
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me each new match' )
		);
		saved = true;
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		const row = (
			await screen.findByRole( 'button', { name: 'Gazette group' } )
		).closest( 'li' );
		expect(
			within( row ).getByText(
				'Entity · organization:acme-gazette · 2 unseen'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'Saving…' ) ).toBeNull();
	} );

	it( 'offers every lens to follow, by name', async () => {
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New watch' } ) );
		expect(
			await within( form() ).findByRole( 'option', {
				name: 'Developer shipping',
			} )
		).toHaveValue( 'dev-shipping' );
		expect( within( form() ).getByLabelText( 'Lens' ) ).toHaveValue( '' );
		expect(
			within( form() ).getByLabelText( 'List new matches on this page' )
		).toBeChecked();
		expect(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		).not.toBeChecked();
	} );

	it( 'opens the form on a target it is handed', async () => {
		render(
			<Harness
				initialDraft={ newWatch(
					{ kind: 'lens', key: 'dev-shipping' },
					'Developer shipping'
				) }
			/>
		);
		expect( within( form() ).getByLabelText( 'Name' ) ).toHaveValue(
			'Developer shipping'
		);
		expect( within( form() ).getByLabelText( 'Follows' ) ).toHaveValue(
			'lens'
		);
		await within( form() ).findByRole( 'option', {
			name: 'Developer shipping',
		} );
		expect( within( form() ).getByLabelText( 'Lens' ) ).toHaveValue(
			'dev-shipping'
		);
	} );

	it( 'edits the watch opened, carrying its key', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w2',
			watch_key: 'acme-complaints',
		};
		render( <Harness /> );
		await openAcme();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit Acme complaints' } )
		);
		expect( within( form() ).getByLabelText( 'Entity' ) ).toHaveValue(
			'organization:acme-gazette'
		);
		expect(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		).toBeChecked();
		type( 'Name', 'Acme watch' );
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		);
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_watch' ) ).toEqual( [
				[
					'--key=acme-complaints',
					'--name=Acme watch',
					'--entity=organization:acme-gazette',
					'--digest=false',
					'--instant=false',
					'--inbox=true',
				],
			] )
		);
		expect(
			await screen.findByRole( 'button', { name: 'Acme watch' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Acme complaints' } )
		).toBeNull();
	} );

	it( 'keeps the form and shows a refused save', async () => {
		wire.replies.save_watch = () =>
			new Error( 'name exactly one of lens, entity or collection' );
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New watch' } ) );
		type( 'Name', 'Nothing followed' );
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		expect(
			await screen.findByText(
				'name exactly one of lens, entity or collection'
			)
		).toBeInTheDocument();
		expect( form() ).toBeInTheDocument();
		expect( screen.queryByText( 'Nothing followed' ) ).toBeNull();
	} );

	it( 'cancels the form unsent', async () => {
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New watch' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect(
			screen.queryByRole( 'form', { name: 'Save watch' } )
		).toBeNull();
		expect( wire.sent( 'save_watch' ) ).toEqual( [] );
	} );

	it( 'removes the watch opened, closes it and lists again', async () => {
		wire.replies.remove_watch = { ok: true, event_id: 'ev-w3' };
		render( <Harness /> );
		await openAcme();
		await screen.findByRole( 'region', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Acme complaints' } )
		);
		expect(
			screen.queryByRole( 'button', { name: 'Acme complaints' } )
		).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'remove_watch' ) ).toEqual( [
				[ '--key=acme-complaints' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watches' ) ).toHaveLength( 2 )
		);
		expect(
			screen.queryByRole( 'region', { name: 'Acme complaints' } )
		).toBeNull();
	} );

	it( 'puts a watch back when its removal is refused', async () => {
		wire.replies.remove_watch = () =>
			new Error( 'no watch: acme-complaints' );
		render( <Harness /> );
		await openAcme();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Acme complaints' } )
		);
		expect(
			await screen.findByText( 'no watch: acme-complaints' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Acme complaints' } )
		).toBeInTheDocument();
	} );
} );
