/* eslint-env jest */
/**
 * Watches lists the caller's watches as a table, or a list when narrow;
 * opens one onto its new matches a page at a time, marking one or a page
 * of them seen behind an undo window, its matches walked with j and k; and
 * saves, edits and stops a watch through a form naming exactly one target
 * and its deliveries, stopping one only once a dialog confirms it.
 */

import {
	act,
	cleanup,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { ITEM, LENSES, pageData } from './fixtures';
import Watches, { CLOSED_WATCH, newWatch } from '../Watches';

const WATCHES = {
	watches: [
		{
			watch_key: 'acme-complaints',
			name: 'Acme complaints',
			target: { kind: 'entity', key: 'organization:acme-quillsheet' },
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

/**
 * `count` matches, each its own key and title.
 *
 * @param {number} count How many.
 * @return {Object[]} The matches.
 */
const harborNotes = ( count ) =>
	Array.from( { length: count }, ( _, n ) => ( {
		...ITEM,
		item_key: `feed:harbor-ledger-${ n + 1 }`,
		title: `Harbor Ledger note ${ n + 1 }`,
		matched_ts: 1790121600 + n,
	} ) );

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

/**
 * The page's watch state, as IntelligencePage holds it.
 *
 * @param {Object}  props
 * @param {?Object} [props.initialDraft] The form's draft to open on.
 * @param {boolean} [props.canTune]      Whether the reader curates.
 * @return {import('react').ReactElement} The view.
 */
function Harness( { initialDraft = null, canTune = false } ) {
	const [ opened, setOpened ] = useState( CLOSED_WATCH );
	const [ draft, setDraft ] = useState( initialDraft );
	return (
		<Watches
			opened={ opened }
			onOpen={ setOpened }
			draft={ draft }
			onDraft={ setDraft }
			onOpenEntity={ jest.fn() }
			onSave={ jest.fn() }
			canTune={ canTune }
		/>
	);
}

let wire;
let viewport;

// jsdom lays nothing out, so it has no scrollIntoView for the pickers.
const { scrollIntoView } = window.Element.prototype;

beforeEach( () => {
	window.Element.prototype.scrollIntoView = jest.fn();
	Core.reset();
	window.localStorage.clear();
	window.NewspackNodesData = pageData();
	viewport = installViewport( false );
	wire = intelWire();
	wire.replies.watches = WATCHES;
	wire.replies.watch_matches = MATCHES;
	wire.replies.lenses = LENSES;
	wire.replies.collections = COLLECTIONS;
	wire.replies.names = { entities: [], available: true };
} );

afterEach( () => {
	cleanup();
	jest.useRealTimers();
	viewport.restore();
	window.Element.prototype.scrollIntoView = scrollIntoView;
} );

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

const openAcme = async () =>
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Acme complaints' } )
	);

const opened = ( name = 'Acme complaints' ) =>
	screen.findByRole( 'region', { name } );

const form = () => screen.getByRole( 'dialog' );

/**
 * The match row whose title reads `title`.
 *
 * @param {string} title The title.
 * @return {Promise<HTMLElement>} Its row.
 */
const matchRow = async ( title ) =>
	( await screen.findByText( title ) ).closest( 'li' );

/**
 * The table row naming `name` as the page holds it now.
 *
 * @param {string} name The watch's name.
 * @return {HTMLElement} Its row.
 */
const watchRowNow = ( name ) =>
	screen.getByRole( 'button', { name } ).closest( 'tr' );

/**
 * The table row naming `name`, or the list row when narrow.
 *
 * @param {string} name The watch's name.
 * @return {Promise<HTMLElement>} Its row.
 */
const watchRow = async ( name ) =>
	( await screen.findByRole( 'button', { name } ) ).closest(
		'tr, [role="row"]'
	);

const type = ( label, value ) =>
	fireEvent.change( within( form() ).getByLabelText( label ), {
		target: { value },
	} );

/**
 * Pick `option` in the combobox labelled `label`, typing `text` first.
 *
 * @param {string} label  The field's label.
 * @param {string} text   What is typed.
 * @param {string} option The option's name.
 */
async function pick( label, text, option ) {
	const box = within( form() ).getByRole( 'combobox', { name: label } );
	act( () => box.focus() );
	fireEvent.change( box, { target: { value: text } } );
	fireEvent.click(
		await within( form() ).findByRole( 'option', { name: option } )
	);
}

describe( 'Watches', () => {
	it( 'lists the caller’s watches as a table: what each watches, how it delivers and how many are new', async () => {
		render( <Harness /> );
		const acme = await watchRow( 'Acme complaints' );
		expect( acme ).toHaveTextContent( 'Client or company' );
		expect( acme ).toHaveTextContent( 'Here, daily email' );
		expect( within( acme ).getByText( '7' ) ).toBeInTheDocument();
		const support = await watchRow( 'Support spikes' );
		expect( support ).toHaveTextContent( 'Team view' );
		expect( support ).toHaveTextContent( 'Each new item' );
		expect( support ).toHaveTextContent( 'Not listed here; email only' );
		const headers = screen
			.getAllByRole( 'columnheader' )
			.map( ( one ) => one.textContent );
		expect( headers ).toEqual(
			expect.arrayContaining( [ 'Name', 'Watching', 'Delivery', 'New' ] )
		);
		expect( headers ).not.toContain( 'Item' );
		expect( wire.sent( 'watches' ) ).toEqual( [ [] ] );
	} );

	it( 'lists each watch with its new count when narrow', async () => {
		viewport.setNarrow( true );
		render( <Harness /> );
		await screen.findByRole( 'button', { name: /^Acme complaints/ } );
		expect( screen.queryByRole( 'table' ) ).toBeNull();
		expect( screen.getByText( '7 new' ) ).toBeInTheDocument();
	} );

	it( 'invites a first watch when there is none', async () => {
		wire.replies.watches = { watches: [], available: true };
		render( <Harness /> );
		expect(
			await screen.findByText(
				'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.'
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: 'New watch' } ).length
		).toBeGreaterThan( 0 );
	} );

	it( 'stands three skeleton rows in while the watches are owed', () => {
		wire.replies.watches = () => new Promise( () => {} );
		render( <Harness /> );
		expect(
			within( screen.getByRole( 'region', { name: 'Watches' } ) )
				.getByRole( 'status', { name: 'Loading' } )
				.querySelectorAll( 'li' )
		).toHaveLength( 3 );
	} );

	it( 'says a refused read of watches was not read, its words kept from a reader', async () => {
		wire.replies.watches = () => new Error( 'not signed in' );
		render( <Harness /> );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'not signed in' ) ).toBeNull();
	} );

	it( 'opens a watch onto its new matches, linking only a safe URL', async () => {
		render( <Harness /> );
		await openAcme();
		const region = await opened();
		expect(
			await within( region ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect(
			within( region ).getByText( 'javascript:alert(1)' ).closest( 'a' )
		).toBeNull();
		expect(
			within( region ).getByText(
				'7 new items since you last marked them seen.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'watch_matches' ) ).toEqual( [
			[ '--key=acme-complaints', '--limit=50' ],
		] );
	} );

	it( "offers a curator the exclusion on a watch's matches", async () => {
		render( <Harness canTune /> );
		await openAcme();
		const region = await opened();
		expect(
			(
				await within( region ).findAllByRole( 'button', {
					name: 'Exclude from every view',
				} )
			).length
		).toBeGreaterThan( 0 );
	} );

	it( "offers a reader no exclusion on a watch's matches", async () => {
		render( <Harness /> );
		await openAcme();
		const region = await opened();
		await within( region ).findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect(
			within( region ).queryByRole( 'button', {
				name: 'Exclude from every view',
			} )
		).toBeNull();
	} );

	it( 'says a watch whose inbox is off is not listed here', async () => {
		wire.replies.watch_matches = { items: [], total: 0, available: true };
		render( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support spikes' } )
		);
		const region = await opened( 'Support spikes' );
		expect(
			await within( region ).findByText( 'Not listed here; email only' )
		).toBeInTheDocument();
		expect( screen.queryByText( /^Nothing new/ ) ).toBeNull();
	} );

	it( 'says a watch has nothing new', async () => {
		wire.replies.watch_matches = { items: [], total: 0, available: true };
		render( <Harness /> );
		await openAcme();
		expect(
			await screen.findByText(
				'Nothing new. Items appear here as they arrive.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: /^Mark \d+ as seen$/ } )
		).toBeNull();
	} );

	it( 'pages a watch’s matches forward and back', async () => {
		wire.replies.watch_matches = ( args ) => ( {
			items: [ 'a', 'b' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.join( ' ' ) }`,
				matched_ts: 1790121600,
			} ) ),
			total: 120,
			available: true,
		} );
		render( <Harness /> );
		await openAcme();
		await screen.findByText( 'a at --key=acme-complaints --limit=50' );
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText(
				'a at --key=acme-complaints --limit=50 --offset=50'
			)
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect(
			await screen.findByText( 'a at --key=acme-complaints --limit=50' )
		).toBeInTheDocument();
	} );

	it( 'marks 50 as seen: hides the 50 rows, sends after the undo window and says so with Undo', async () => {
		const notes = harborNotes( 50 );
		wire.replies.watch_matches = {
			items: notes,
			total: 134,
			available: true,
		};
		wire.replies.mark_seen = { ok: true, count: 50 };
		render( <Harness /> );
		await openAcme();
		await screen.findByText( 'Harbor Ledger note 50' );
		fakeUndoClock();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Mark 50 as seen' } )
		);
		expect( screen.queryByText( /^Harbor Ledger note/ ) ).toBeNull();
		const snackbar = document.querySelector( '.components-snackbar' );
		expect( snackbar ).toHaveTextContent( 'Marked 50 as seen.' );
		expect(
			within( snackbar ).getByRole( 'button', { name: 'Undo' } )
		).toBeInTheDocument();
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'mark_seen' ) ).toEqual( [] );
		await elapse( 1 );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'mark_seen' ) ).toEqual( [
				[
					'--key=acme-complaints',
					...notes.map( ( one ) => `--item=${ one.item_key }` ),
				],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watch_matches' ) ).toHaveLength( 2 )
		);
		expect( screen.queryByText( /^Harbor Ledger note/ ) ).toBeNull();
	} );

	it( 'puts the rows back on Undo and sends nothing', async () => {
		wire.replies.mark_seen = { ok: true, count: 2 };
		render( <Harness /> );
		await openAcme();
		await matchRow( 'Acme Quillsheet wins regional prize' );
		fakeUndoClock();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Mark 2 as seen' } )
		);
		expect(
			screen.queryByText( 'Acme Quillsheet wins regional prize' )
		).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect(
			screen.getByText( 'Acme Quillsheet wins regional prize' )
		).toBeInTheDocument();
		await elapse( UNDO_MS * 2 );
		expect( wire.sent( 'mark_seen' ) ).toEqual( [] );
	} );

	it( 'marks one match seen after the undo window, keeping it hidden until a re-read stops listing it', async () => {
		wire.replies.mark_seen = { ok: true, count: 1 };
		render( <Harness /> );
		await openAcme();
		const row = await matchRow( 'Acme Quillsheet wins regional prize' );
		fakeUndoClock();
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Mark seen' } )
		);
		expect(
			screen.queryByText( 'Acme Quillsheet wins regional prize' )
		).toBeNull();
		expect( wire.sent( 'mark_seen' ) ).toEqual( [] );
		await elapse( UNDO_MS );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'mark_seen' ) ).toEqual( [
				[ '--key=acme-complaints', '--item=feed:inkfold-review-17' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'watches' ) ).toHaveLength( 2 )
		);
		expect(
			screen.queryByText( 'Acme Quillsheet wins regional prize' )
		).toBeNull();
		expect( screen.getByText( 'javascript:alert(1)' ) ).toBeInTheDocument();
	} );

	it( 'returns to the first page once a mark lands, so a match is never skipped', async () => {
		wire.replies.mark_seen = { ok: true, count: 1 };
		wire.replies.watch_matches = ( args ) => ( {
			items: [ 'a', 'b' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }-${ args.length }`,
				title: `${ n } at ${ args.join( ' ' ) }`,
				matched_ts: 1790121600,
			} ) ),
			total: 120,
			available: true,
		} );
		render( <Harness /> );
		await openAcme();
		await screen.findByText( 'a at --key=acme-complaints --limit=50' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		const row = await matchRow(
			'a at --key=acme-complaints --limit=50 --offset=50'
		);
		fakeUndoClock();
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Mark seen' } )
		);
		await elapse( UNDO_MS );
		jest.useRealTimers();
		expect(
			await screen.findByText( 'b at --key=acme-complaints --limit=50' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Previous' } )
		).toBeNull();
	} );

	it( 'puts every match back when marking them seen is refused', async () => {
		wire.replies.mark_seen = () => new Error( 'no watch: acme-complaints' );
		render( <Harness /> );
		await openAcme();
		await matchRow( 'Acme Quillsheet wins regional prize' );
		fakeUndoClock();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Mark 2 as seen' } )
		);
		await elapse( UNDO_MS );
		jest.useRealTimers();
		expect(
			await screen.findByText( 'no watch: acme-complaints' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Acme Quillsheet wins regional prize' )
		).toBeInTheDocument();
		expect( screen.getByText( 'javascript:alert(1)' ) ).toBeInTheDocument();
	} );

	describe( 'single-key shortcuts in a watch’s matches', () => {
		const items = () => [
			...document.querySelectorAll( '.dataviews-view-list__item' ),
		];

		beforeEach( () => {
			wire.replies.watch_matches = {
				items: harborNotes( 3 ),
				total: 3,
				available: true,
			};
		} );

		it( 'moves focus with j and k only while the list has focus, and shows the legend', async () => {
			render( <Harness /> );
			await openAcme();
			await screen.findByText( 'Harbor Ledger note 3' );
			expect(
				screen.getByText( 'Keys: J and K to move, Z undo.' )
			).toBeInTheDocument();
			const [ first, second ] = items();
			act( () => first.focus() );
			fireEvent.keyDown( first, { key: 'j' } );
			expect( document.activeElement ).toBe( second );
			fireEvent.keyDown( second, { key: 'k' } );
			expect( document.activeElement ).toBe( first );
			const outside = screen.getByRole( 'button', { name: 'New watch' } );
			act( () => outside.focus() );
			fireEvent.keyDown( outside, { key: 'j' } );
			expect( document.activeElement ).toBe( outside );
		} );

		it( 'takes no key once the reader turns them off', async () => {
			render( <Harness /> );
			await openAcme();
			await screen.findByText( 'Harbor Ledger note 3' );
			fireEvent.click(
				screen.getByRole( 'checkbox', {
					name: 'Turn off single-key shortcuts',
				} )
			);
			expect(
				screen.queryByText( 'Keys: J and K to move, Z undo.' )
			).toBeNull();
			const [ first ] = items();
			act( () => first.focus() );
			fireEvent.keyDown( first, { key: 'j' } );
			expect( document.activeElement ).toBe( first );
			expect(
				window.localStorage.getItem(
					'newspack-intelligence:single-keys-off'
				)
			).toBe( '1' );
		} );

		it( 'undoes a chip removal in a match with z, on one Undo', async () => {
			wire.replies.watch_matches = {
				items: harborNotes( 3 ).map( ( item, n ) => ( {
					...item,
					entities: [
						{
							entity_key: `organization:kestrel-${ n }`,
							name: `Kestrel Desk ${ n }`,
							state: 'asserted',
							role: 'subject',
							client: false,
						},
					],
				} ) ),
				total: 3,
				available: true,
			};
			render( <Harness canTune /> );
			await openAcme();
			await screen.findByText( 'Harbor Ledger note 3' );
			fakeUndoClock();
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Remove Kestrel Desk 1 from this item',
				} )
			);
			expect(
				screen.queryByRole( 'button', { name: 'Kestrel Desk 1' } )
			).toBeNull();
			const [ first ] = items();
			act( () => first.focus() );
			fireEvent.keyDown( first, { key: 'z' } );
			expect(
				screen.getByRole( 'button', { name: 'Kestrel Desk 1' } )
			).toBeInTheDocument();
			await elapse( UNDO_MS * 2 );
			expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		} );

		it( 'hands focus to the next match on Mark seen, and to the watch once none is left', async () => {
			render( <Harness /> );
			await openAcme();
			await screen.findByText( 'Harbor Ledger note 3' );
			fakeUndoClock();
			await act( async () =>
				fireEvent.click(
					within(
						await matchRow( 'Harbor Ledger note 2' )
					).getByRole( 'button', { name: 'Mark seen' } )
				)
			);
			expect( document.activeElement ).toBe( items()[ 1 ] );
			expect( items()[ 1 ] ).toHaveAccessibleName(
				/^Harbor Ledger note 3/
			);
			for ( const title of [
				'Harbor Ledger note 3',
				'Harbor Ledger note 1',
			] ) {
				await act( async () =>
					fireEvent.click(
						within( await matchRow( title ) ).getByRole( 'button', {
							name: 'Mark seen',
						} )
					)
				);
			}
			expect( document.activeElement ).toBe(
				screen.getByRole( 'heading', { name: 'Acme complaints' } )
			);
		} );

		it( 'undoes the latest mark with z', async () => {
			render( <Harness /> );
			await openAcme();
			const row = await matchRow( 'Harbor Ledger note 2' );
			fakeUndoClock();
			fireEvent.click(
				within( row ).getByRole( 'button', { name: 'Mark seen' } )
			);
			expect( screen.queryByText( 'Harbor Ledger note 2' ) ).toBeNull();
			const [ first ] = items();
			act( () => first.focus() );
			fireEvent.keyDown( first, { key: 'z' } );
			expect(
				screen.getByText( 'Harbor Ledger note 2' )
			).toBeInTheDocument();
			await elapse( UNDO_MS * 2 );
			expect( wire.sent( 'mark_seen' ) ).toEqual( [] );
		} );
	} );

	it( 'saves a new watch with exactly one target and its deliveries, then says so', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w1',
			watch_key: 'paywall-watch',
		};
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		expect( form() ).toHaveAccessibleName( 'New watch' );
		type( 'Name', 'Paywall watch' );
		fireEvent.click(
			within( form() ).getByRole( 'radio', {
				name: 'A client or company',
			} )
		);
		fireEvent.click(
			within( form() ).getByRole( 'radio', { name: 'A folder' } )
		);
		await pick( 'Folder', 'Pay', 'Paywall complaints' );
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		);
		fireEvent.click(
			within( form() ).getByLabelText( 'Email me each new item' )
		);
		fireEvent.click(
			within( form() ).getByLabelText( 'List new items here' )
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
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( screen.getByText( 'Paywall watch' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Saving…' ) ).toBeInTheDocument();
		expect(
			document.querySelector( '.components-snackbar' )
		).toHaveTextContent( 'Paywall watch saved.' );
	} );

	it( 'follows a client or company picked by name', async () => {
		wire.replies.save_watch = {
			ok: true,
			event_id: 'ev-w7',
			watch_key: 'quill-watch',
		};
		wire.replies.names = {
			entities: [
				{
					entity_key: 'organization:prairie-signal',
					name: 'Prairie Signal',
				},
			],
			available: true,
		};
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		type( 'Name', 'Prairie watch' );
		fireEvent.click(
			within( form() ).getByRole( 'radio', {
				name: 'A client or company',
			} )
		);
		await pick( 'Client or company', 'Prairie', 'Prairie Signal' );
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_watch' ) ).toEqual( [
				[
					'--name=Prairie watch',
					'--entity=organization:prairie-signal',
					'--digest=false',
					'--instant=false',
					'--inbox=true',
				],
			] )
		);
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
		await waitFor( () =>
			expect(
				within( watchRowNow( 'Shipping watch' ) ).getByText( '4' )
			).toBeInTheDocument()
		);
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
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		expect( form() ).toHaveAccessibleName( 'Edit Acme complaints' );
		type( 'Name', 'Acme watch' );
		saved = true;
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		await waitFor( () =>
			expect(
				within( watchRowNow( 'Acme watch' ) ).getByText( '3' )
			).toBeInTheDocument()
		);
		expect( wire.sent( 'save_watch' ) ).toEqual( [
			[
				'--key=acme-complaints',
				'--name=Acme watch',
				'--entity=organization:acme-quillsheet',
				'--digest=true',
				'--instant=false',
				'--inbox=true',
			],
		] );
	} );

	it( 'offers every team view to follow, by name, and the deliveries a new watch starts with', async () => {
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		expect(
			within( form() ).getByRole( 'radio', { name: 'A team view' } )
		).toBeChecked();
		await pick( 'Team view', 'Dev', 'Developer shipping' );
		expect(
			within( form() ).getByLabelText( 'List new items here' )
		).toBeChecked();
		expect(
			within( form() ).getByLabelText( 'Email me a daily digest' )
		).not.toBeChecked();
	} );

	it( 'says a refused team view or folder list in the form was not read', async () => {
		wire.replies.lenses = () =>
			new Error( 'lenses refused for Harbor Ledger' );
		wire.replies.collections = () =>
			new Error( 'folders refused for Harbor Ledger' );
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		expect(
			await within( form() ).findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			within( form() ).getByRole( 'radio', { name: 'A folder' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'collections' ) ).toHaveLength( 1 )
		);
		expect(
			await within( form() ).findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'lenses refused for Harbor Ledger' )
		).toBeNull();
		expect(
			screen.queryByText( 'folders refused for Harbor Ledger' )
		).toBeNull();
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
		expect(
			within( form() ).getByRole( 'radio', { name: 'A team view' } )
		).toBeChecked();
		await waitFor( () =>
			expect(
				within( form() ).getByRole( 'combobox', { name: 'Team view' } )
			).toHaveValue( 'Developer shipping' )
		);
	} );

	it( 'keeps the form and shows a refused save', async () => {
		wire.replies.save_watch = () =>
			new Error(
				'a watch follows exactly one of lens, entity or collection'
			);
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		type( 'Name', 'Nothing followed' );
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Save watch' } )
		);
		expect(
			await screen.findByText(
				'a watch follows exactly one of lens, entity or collection'
			)
		).toBeInTheDocument();
		expect( form() ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Nothing followed' } )
		).toBeNull();
		expect( screen.queryByText( 'Nothing followed saved.' ) ).toBeNull();
	} );

	it( 'moves focus to the Name field as the form opens', async () => {
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		expect( document.activeElement ).toBe(
			within( form() ).getByLabelText( 'Name' )
		);
		// The team views it offers land after; let them.
		await act( () => new Promise( ( done ) => setTimeout( done, 50 ) ) );
	} );

	it( 'cancels the form unsent', async () => {
		render( <Harness /> );
		await screen.findByRole( 'button', { name: 'Acme complaints' } );
		fireEvent.click(
			screen.getAllByRole( 'button', { name: 'New watch' } )[ 0 ]
		);
		fireEvent.click(
			within( form() ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( wire.sent( 'save_watch' ) ).toEqual( [] );
	} );

	describe( 'stopping a watch', () => {
		const askToStop = async () => {
			await openAcme();
			await opened();
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'More actions for Acme complaints',
				} )
			);
			fireEvent.click(
				await screen.findByRole( 'menuitem', {
					name: 'Stop watching…',
				} )
			);
		};

		it( 'sends nothing until the dialog confirms it', async () => {
			wire.replies.remove_watch = { ok: true, event_id: 'ev-w3' };
			render( <Harness /> );
			await askToStop();
			const dialog = screen.getByRole( 'dialog', {
				name: 'Stop watching Acme complaints?',
			} );
			expect( dialog ).toHaveTextContent(
				'Its list of new items goes with it.'
			);
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
			);
			expect( wire.sent( 'remove_watch' ) ).toEqual( [] );
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Cancel' } )
			);
			expect( screen.queryByRole( 'dialog' ) ).toBeNull();
			expect( wire.sent( 'remove_watch' ) ).toEqual( [] );
			expect(
				screen.getByRole( 'button', { name: 'Acme complaints' } )
			).toBeInTheDocument();
		} );

		it( 'stops the watch once confirmed, closes it and lists again', async () => {
			wire.replies.remove_watch = { ok: true, event_id: 'ev-w3' };
			render( <Harness /> );
			await askToStop();
			fireEvent.click(
				within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
					name: 'Stop watching',
				} )
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

		it( 'puts a watch back when stopping it is refused', async () => {
			wire.replies.remove_watch = () =>
				new Error( 'no watch: acme-complaints' );
			render( <Harness /> );
			await askToStop();
			fireEvent.click(
				within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
					name: 'Stop watching',
				} )
			);
			expect(
				await screen.findByText( 'no watch: acme-complaints' )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Acme complaints' } )
			).toBeInTheDocument();
		} );
	} );
} );
