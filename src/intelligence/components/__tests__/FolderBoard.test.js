/* eslint-env jest */
/**
 * FolderBoard lays each folder out as a column of its saved items' cards,
 * opening on those not yet used. A card drags into another column, or its
 * menu moves it in one action; either is announced, deferred with Undo, and
 * lands focus on the card in its new column. A mark and a removal defer the
 * same way, and a refused write returns the card with the refusal on it.
 */

import {
	act,
	cleanup,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { speak } from '@wordpress/a11y';
import { Core } from '@newspack-nodes/runtime';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { pageData } from '../../views/__tests__/fixtures';
import FolderBoard from '../FolderBoard';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const YEAR = new Date().getUTCFullYear();

const UNUSED = {
	item_key: 'feed:coastline-weekly-award',
	url: 'https://coastline-weekly.example/award',
	title: 'Coastline Weekly wins regional press award',
	actor: 'user:editor',
	saved_ts: Date.UTC( YEAR, 0, 9 ) / 1000,
	marks: [],
};

const USED = {
	item_key: 'feed:prairie-signal-finalist',
	url: 'https://prairie-signal.example/finalist',
	title: 'Prairie Signal named a finalist',
	actor: 'user:editor',
	saved_ts: Date.UTC( YEAR, 0, 8 ) / 1000,
	marks: [ { mark: 'newsletter', day: `${ YEAR }-11-05` } ],
};

const LIST = {
	collections: [
		{
			collection_key: 'coastline-picks',
			name: 'Coastline picks',
			created_ts: 1790035200,
			items: 2,
			recent_ts: 1792497600,
		},
		{
			collection_key: 'awards-page',
			name: 'Awards page',
			created_ts: 1790121600,
			items: 0,
			recent_ts: null,
		},
	],
	available: true,
};

/**
 * A folder's answer.
 *
 * @param {string}   key   Its key.
 * @param {Object[]} items Its items.
 * @return {Object} The answer.
 */
const folder = ( key, items ) => ( {
	collection_key: key,
	name: LIST.collections.find( ( one ) => one.collection_key === key ).name,
	created_ts: 1790035200,
	items,
	available: true,
} );

let wire;
let folders;
let viewport;

beforeEach( () => {
	Core.reset();
	speak.mockClear();
	window.NewspackNodesData = pageData();
	viewport = installViewport( false );
	wire = intelWire();
	folders = {
		'coastline-picks': [ UNUSED, USED ],
		'awards-page': [],
	};
	wire.replies.collections = ( args ) =>
		0 === args.length ? LIST : folder( args[ 0 ], folders[ args[ 0 ] ] );
} );

afterEach( () => {
	cleanup();
	jest.useRealTimers();
	viewport.restore();
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

const renderBoard = ( props = {} ) =>
	render( <FolderBoard show="unused" folders={ [] } canTune { ...props } /> );

const column = ( name ) => screen.findByRole( 'region', { name } );

/**
 * The card citing `title`, once it shows.
 *
 * @param {string} title The item's title.
 * @return {Promise<HTMLElement>} The card.
 */
const card = ( title ) => screen.findByRole( 'article', { name: title } );

/**
 * The titles a column shows, in order.
 *
 * @param {HTMLElement} region The column.
 * @return {string[]} The titles.
 */
const titles = ( region ) =>
	within( region )
		.queryAllByRole( 'article' )
		.map( ( one ) => within( one ).getByRole( 'link' ).textContent );

/**
 * Drag a card onto a column.
 *
 * @param {HTMLElement} dragged The card.
 * @param {HTMLElement} onto    The column.
 */
function drag( dragged, onto ) {
	fireEvent.dragStart( dragged, {
		dataTransfer: { setData: jest.fn(), effectAllowed: '' },
	} );
	fireEvent.dragEnter( onto );
	fireEvent.dragOver( onto );
	fireEvent.drop( onto );
	fireEvent.dragEnd( dragged );
}

/**
 * Open a card's menu and pick one of its items.
 *
 * @param {HTMLElement} one  The card.
 * @param {string}      name The menu item.
 */
async function pick( one, name ) {
	fireEvent.click(
		within( one ).getByRole( 'button', { name: /^Actions for / } )
	);
	fireEvent.click( await screen.findByRole( 'menuitem', { name } ) );
}

const MOVED = `Moved ${ UNUSED.title } from Coastline picks to Awards page.`;

describe( 'FolderBoard', () => {
	it( 'opens on the items not yet used, each folder a column with its count', async () => {
		renderBoard();
		const picks = await column( 'Coastline picks' );
		await card( UNUSED.title );
		expect( screen.getByLabelText( 'Show' ) ).toHaveValue( 'unused' );
		expect( titles( picks ) ).toEqual( [ UNUSED.title ] );
		expect(
			within( picks ).getByText( '2', {
				selector: '.newspack-intelligence__folder-count',
			} )
		).toBeInTheDocument();
		expect(
			within( await column( 'Awards page' ) ).getByText(
				'Drag items here, or use Save on any item.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toEqual(
			expect.arrayContaining( [
				[],
				[ 'coastline-picks' ],
				[ 'awards-page' ],
			] )
		);
	} );

	it( 'shows a used card with its badge once the filter takes in every item', async () => {
		renderBoard();
		await card( UNUSED.title );
		expect(
			[ ...screen.getByLabelText( 'Show' ).options ].map(
				( one ) => one.textContent
			)
		).toEqual( [
			'All',
			'Not yet used',
			'Used in Digest',
			'Used in LinkedIn post',
			'Used in Homepage',
		] );
		fireEvent.change( screen.getByLabelText( 'Show' ), {
			target: { value: 'all' },
		} );
		const used = await card( USED.title );
		expect( within( used ).getByText( 'Digest, 5 Nov' ) ).toHaveClass(
			'newspack-nodes-status-badge'
		);
		fireEvent.change( screen.getByLabelText( 'Show' ), {
			target: { value: 'newsletter' },
		} );
		expect( titles( await column( 'Coastline picks' ) ) ).toEqual( [
			USED.title,
		] );
	} );

	it( 'moves a dropped card, announcing it, focusing it in its new column, and sends after the undo window', async () => {
		wire.replies.move_item = { ok: true, event_id: 'ev-m' };
		renderBoard();
		const dragged = await card( UNUSED.title );
		const awards = await column( 'Awards page' );
		fakeUndoClock();
		drag( dragged, awards );
		expect( speak ).toHaveBeenCalledWith(
			`Picked up ${ UNUSED.title }.`,
			'polite'
		);
		expect( speak ).toHaveBeenCalledWith( 'Over Awards page.', 'polite' );
		expect( speak ).toHaveBeenCalledWith( MOVED, 'polite' );
		expect( titles( awards ) ).toEqual( [ UNUSED.title ] );
		expect( titles( await column( 'Coastline picks' ) ) ).toEqual( [] );
		expect(
			within( awards ).getByRole( 'article', { name: UNUSED.title } )
		).toHaveFocus();
		const snackbar = document.querySelector( '.components-snackbar' );
		expect( snackbar ).toHaveTextContent( 'Moved to Awards page.' );
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'move_item' ) ).toEqual( [] );
		await elapse( 1 );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'move_item' ) ).toEqual( [
				[
					'--collection=coastline-picks',
					`--item=${ UNUSED.item_key }`,
					'--into=awards-page',
				],
			] )
		);
		expect( titles( awards ) ).toEqual( [ UNUSED.title ] );
	} );

	it( 'moves a card from its menu in one action, as a drop does', async () => {
		wire.replies.move_item = { ok: true, event_id: 'ev-n' };
		renderBoard();
		const moved = await card( UNUSED.title );
		fakeUndoClock();
		await pick( moved, 'Awards page' );
		expect( speak ).toHaveBeenCalledWith( MOVED, 'polite' );
		const awards = await column( 'Awards page' );
		expect(
			within( awards ).getByRole( 'article', { name: UNUSED.title } )
		).toHaveFocus();
		await elapse( UNDO_MS );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'move_item' ) ).toEqual( [
				[
					'--collection=coastline-picks',
					`--item=${ UNUSED.item_key }`,
					'--into=awards-page',
				],
			] )
		);
	} );

	it( 'puts a moved card back on Undo and sends nothing', async () => {
		renderBoard();
		const moved = await card( UNUSED.title );
		fakeUndoClock();
		drag( moved, await column( 'Awards page' ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( titles( await column( 'Coastline picks' ) ) ).toEqual( [
			UNUSED.title,
		] );
		expect( titles( await column( 'Awards page' ) ) ).toEqual( [] );
		await elapse( UNDO_MS * 2 );
		expect( wire.sent( 'move_item' ) ).toEqual( [] );
	} );

	it( 'returns a refused move to its column with the refusal on it', async () => {
		wire.replies.move_item = () =>
			new Error( `${ UNUSED.item_key } is not saved in coastline-picks` );
		renderBoard();
		const moved = await card( UNUSED.title );
		fakeUndoClock();
		drag( moved, await column( 'Awards page' ) );
		await elapse( UNDO_MS );
		jest.useRealTimers();
		const back = await within(
			await column( 'Coastline picks' )
		).findByRole( 'article', { name: UNUSED.title } );
		expect( await within( back ).findByRole( 'alert' ) ).toHaveTextContent(
			`${ UNUSED.item_key } is not saved in coastline-picks`
		);
		expect( titles( await column( 'Awards page' ) ) ).toEqual( [] );
	} );

	it( 'returns two refused moves, each card with its own refusal', async () => {
		const GALA = {
			...UNUSED,
			item_key: 'feed:lakeside-works-gala',
			title: 'Lakeside Works hosts a gala',
		};
		folders[ 'coastline-picks' ] = [ UNUSED, GALA ];
		wire.replies.move_item = ( tokens ) =>
			new Error(
				tokens.includes( `--item=${ GALA.item_key }` )
					? 'awards-page is full'
					: `${ UNUSED.item_key } is not saved in coastline-picks`
			);
		renderBoard();
		const awards = await column( 'Awards page' );
		fakeUndoClock();
		drag( await card( UNUSED.title ), awards );
		drag( await card( GALA.title ), awards );
		await elapse( UNDO_MS );
		jest.useRealTimers();
		const picks = await column( 'Coastline picks' );
		const unused = await within( picks ).findByRole( 'article', {
			name: UNUSED.title,
		} );
		const gala = await within( picks ).findByRole( 'article', {
			name: GALA.title,
		} );
		expect( await within( gala ).findByRole( 'alert' ) ).toHaveTextContent(
			'awards-page is full'
		);
		expect(
			await within( unused ).findByRole( 'alert' )
		).toHaveTextContent(
			`${ UNUSED.item_key } is not saved in coastline-picks`
		);
	} );

	it( 'warns of a folder over twenty items with "20+" and still takes a drop', async () => {
		wire.replies.move_item = { ok: true, event_id: 'ev-w' };
		folders[ 'awards-page' ] = Array.from( { length: 21 }, ( _, n ) => ( {
			...UNUSED,
			item_key: `feed:lakeside-works-${ n }`,
			title: `Lakeside Works note ${ n }`,
		} ) );
		renderBoard();
		const awards = await column( 'Awards page' );
		await within( awards ).findByRole( 'article', {
			name: 'Lakeside Works note 20',
		} );
		expect( within( awards ).getByText( '20+' ) ).toHaveClass(
			'newspack-nodes-status',
			'is-warning'
		);
		fakeUndoClock();
		drag( await card( UNUSED.title ), awards );
		expect(
			within( awards ).getByRole( 'article', { name: UNUSED.title } )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'move_item' ) ).toHaveLength( 1 )
		);
	} );

	it( 'removes a card from its folder after the undo window', async () => {
		wire.replies.uncollect = { ok: true, event_id: 'ev-u' };
		renderBoard();
		const removed = await card( UNUSED.title );
		fakeUndoClock();
		await pick( removed, 'Remove from folder' );
		expect(
			screen.queryByRole( 'article', { name: UNUSED.title } )
		).toBeNull();
		expect(
			document.querySelector( '.components-snackbar' )
		).toHaveTextContent( 'Removed from Coastline picks.' );
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'uncollect' ) ).toEqual( [] );
		await elapse( 1 );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'uncollect' ) ).toEqual( [
				[
					'--collection=coastline-picks',
					`--item=${ UNUSED.item_key }`,
				],
			] )
		);
		expect(
			screen.queryByRole( 'article', { name: UNUSED.title } )
		).toBeNull();
	} );

	it( 'puts a removed card back on Undo and sends nothing', async () => {
		renderBoard();
		const removed = await card( UNUSED.title );
		fakeUndoClock();
		await pick( removed, 'Remove from folder' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( await card( UNUSED.title ) ).toBeInTheDocument();
		await elapse( UNDO_MS * 2 );
		expect( wire.sent( 'uncollect' ) ).toEqual( [] );
	} );

	it( 'marks a card used through the dialog, its badge shown at once and the mark sent after the undo window', async () => {
		wire.replies.mark_used = { ok: true, event_id: 'ev-k' };
		renderBoard( { show: 'all' } );
		const marked = await card( UNUSED.title );
		await pick( marked, 'LinkedIn post' );
		const dialog = screen.getByRole( 'dialog', { name: 'Mark as used' } );
		fireEvent.change( within( dialog ).getByLabelText( 'On' ), {
			target: { value: `${ YEAR }-10-23` },
		} );
		fakeUndoClock();
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Mark as used' } )
		);
		expect(
			within( await card( UNUSED.title ) ).getByText(
				'LinkedIn post, 23 Oct'
			)
		).toBeInTheDocument();
		expect(
			document.querySelector( '.components-snackbar' )
		).toHaveTextContent( 'Marked as used in LinkedIn post on 23 Oct.' );
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'mark_used' ) ).toEqual( [] );
		await elapse( 1 );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'mark_used' ) ).toEqual( [
				[
					'--collection=coastline-picks',
					`--item=${ UNUSED.item_key }`,
					'--mark=linkedin',
					`--day=${ YEAR }-10-23`,
				],
			] )
		);
	} );

	it( 'creates a folder from New folder, saving until a read lists it', async () => {
		wire.replies.create_collection = {
			ok: true,
			event_id: 'ev-c',
			collection_key: 'tidewater-leads',
		};
		renderBoard();
		await column( 'Coastline picks' );
		fireEvent.click( screen.getByRole( 'button', { name: 'New folder' } ) );
		fireEvent.change( screen.getByLabelText( 'Folder name' ), {
			target: { value: 'Tidewater leads' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create folder' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'create_collection' ) ).toEqual( [
				[ '--name=Tidewater leads' ],
			] )
		);
		expect(
			within( await column( 'Tidewater leads' ) ).getByText( 'Saving…' )
		).toBeInTheDocument();
	} );

	it( 'offers a reader neither a card menu nor New folder', async () => {
		renderBoard( { canTune: false } );
		const one = await card( UNUSED.title );
		expect(
			within( one ).queryByRole( 'button', { name: /^Actions for / } )
		).toBeNull();
		expect( one ).not.toHaveAttribute( 'draggable', 'true' );
		expect(
			screen.queryByRole( 'button', { name: 'New folder' } )
		).toBeNull();
	} );

	it( 'shows the folders named alone', async () => {
		renderBoard( { folders: [ 'awards-page' ] } );
		await column( 'Awards page' );
		expect(
			screen.queryByRole( 'region', { name: 'Coastline picks' } )
		).toBeNull();
		expect( wire.sent( 'collections' ) ).not.toContainEqual( [
			'coastline-picks',
		] );
	} );

	it( 'lays one folder out at a time when narrow, picked from a select', async () => {
		viewport.setNarrow( true );
		renderBoard();
		const pickFolder = await screen.findByLabelText( 'Folder' );
		expect(
			[ ...pickFolder.options ].map( ( one ) => one.textContent )
		).toEqual( [ 'Coastline picks (2)', 'Awards page (0)' ] );
		await column( 'Coastline picks' );
		expect(
			screen.queryByRole( 'region', { name: 'Awards page' } )
		).toBeNull();
		fireEvent.change( pickFolder, { target: { value: 'awards-page' } } );
		await column( 'Awards page' );
		expect(
			screen.queryByRole( 'region', { name: 'Coastline picks' } )
		).toBeNull();
	} );

	describe.each( [
		[ 390, true ],
		[ 1440, false ],
	] )( 'at %d px', ( width, narrow ) => {
		beforeEach( () => viewport.setNarrow( narrow ) );

		it( 'says there are no folders yet and offers New folder', async () => {
			wire.replies.collections = { collections: [], available: true };
			renderBoard();
			expect(
				await screen.findByText(
					'No folders yet. Create one, then use Save on any item to file it here.'
				)
			).toBeInTheDocument();
			expect(
				screen.getAllByRole( 'button', { name: 'New folder' } )
			).toHaveLength( 1 );
		} );

		it( 'stands in skeleton columns of three cards while loading', () => {
			wire.replies.collections = () => new Promise( () => {} );
			renderBoard();
			const loading = screen.getAllByRole( 'status', {
				name: 'Loading',
			} );
			expect( loading ).toHaveLength( narrow ? 1 : 3 );
			expect(
				loading[ 0 ].querySelectorAll( '.newspack-intelligence__item' )
			).toHaveLength( 3 );
		} );

		it( 'says the folders could not be read', async () => {
			wire.replies.collections = () => new Error( 'collections refused' );
			renderBoard();
			expect(
				await screen.findByText(
					'Intelligence could not be read just now. Try again.'
				)
			).toBeInTheDocument();
		} );
	} );
} );
