/* eslint-env jest */
/**
 * Collections is a board of folders: it lists every folder, opens one onto
 * its saved items as read, unused first, each use mark a badge with its day,
 * filters them by mark, offers a curator the form creating a folder, and
 * marks and moves an item, holding each write until a read reflects it.
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
import { useState } from '@wordpress/element';
import Collections from '../Collections';
import { pageData } from './fixtures';

const VOCABULARY = {
	markWords: { newsletter: 'Newsletter', linkedin: 'LinkedIn', site: 'Site' },
};

const LIST = {
	collections: [
		{
			collection_key: 'q4-client-wins',
			name: 'Q4 client wins',
			created_ts: 1790035200,
			items: 2,
			recent_ts: 1792497600,
		},
		{
			collection_key: 'paywall-complaints',
			name: 'Paywall complaints',
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
	title: 'Acme Quillsheet wins regional prize',
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
	marks: [
		{ mark: 'newsletter', day: '2026-10-19' },
		{ mark: 'site', day: '2026-10-21' },
	],
};

const ONE = {
	collection_key: 'q4-client-wins',
	name: 'Q4 client wins',
	created_ts: 1790035200,
	items: [ UNUSED, USED ],
	available: true,
};

let wire;

function Harness( props ) {
	const [ opened, setOpened ] = useState( null );
	return (
		<Collections
			opened={ opened }
			onOpen={ setOpened }
			vocabulary={ VOCABULARY }
			{ ...props }
		/>
	);
}

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

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.collections = ( args ) => ( 0 === args.length ? LIST : ONE );
	wire.replies.excluded = { items: [ UNSAID, WIRE_COPY ], available: true };
} );

describe( 'Collections', () => {
	it( 'lists every collection with its count', async () => {
		render( <Harness canTune={ false } /> );
		const open = await screen.findByRole( 'button', {
			name: 'Q4 client wins',
		} );
		expect( open.closest( 'ul' ) ).toHaveClass(
			'newspack-intelligence__board'
		);
		expect(
			screen.getByText( /2 items · 2026-09-22/ )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toEqual( [ [] ] );
	} );

	it( 'opens a collection onto its saved items, each linking its URL', async () => {
		render( <Harness canTune={ false } /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		const items = await screen.findByRole( 'region', {
			name: 'Q4 client wins',
		} );
		expect(
			await within( items ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect(
			within( items ).getByText( /user:editor · 2026-10-19/ )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toContainEqual( [
			'q4-client-wins',
		] );
	} );

	it( 'says there are no collections, or that the graph is unavailable', async () => {
		wire.replies.collections = { collections: [], available: true };
		const { unmount } = render( <Harness canTune={ false } /> );
		expect(
			await screen.findByText( 'No folder yet.' )
		).toBeInTheDocument();
		unmount();
		Core.reset();
		wire = intelWire();
		wire.replies.collections = { collections: [], available: false };
		render( <Harness canTune={ false } /> );
		expect(
			await screen.findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
	} );

	it( 'says an open collection holds nothing readable', async () => {
		wire.replies.collections = ( args ) =>
			0 === args.length ? LIST : { ...ONE, items: [] };
		render( <Harness canTune={ false } /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		expect(
			await screen.findByText( 'This folder holds no item you can read.' )
		).toBeInTheDocument();
	} );

	it( 'offers a reader no create form', async () => {
		render( <Harness canTune={ false } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		expect(
			screen.queryByRole( 'button', { name: 'Create folder' } )
		).toBeNull();
	} );

	it( 'creates a collection by name and lists again', async () => {
		wire.replies.create_collection = {
			ok: true,
			event_id: 'ev-c',
			collection_key: 'clipped-praise',
		};
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Clipped praise' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create folder' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'create_collection' ) ).toEqual( [
				[ '--name=Clipped praise' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'collections' ) ).toHaveLength( 2 )
		);
		expect( screen.getByText( 'Clipped praise' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Saving…' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Clipped praise' } )
		).toBeNull();
	} );

	it( 'lists a created collection as the read answers it once it does', async () => {
		wire.replies.create_collection = {
			ok: true,
			event_id: 'ev-d',
			collection_key: 'clipped-praise',
		};
		let created = false;
		wire.replies.collections = ( args ) =>
			0 < args.length
				? ONE
				: {
						...LIST,
						collections: created
							? [
									...LIST.collections,
									{
										collection_key: 'clipped-praise',
										name: 'Clipped praise',
										created_ts: 1790121600,
										items: 0,
									},
							  ]
							: LIST.collections,
				  };
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		created = true;
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Clipped praise' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create folder' } )
		);
		expect(
			await screen.findByRole( 'button', { name: 'Clipped praise' } )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Saving…' ) ).toBeNull();
	} );

	it( 'drops a refused creation from the list', async () => {
		wire.replies.create_collection = () =>
			new Error( 'name must not be blank' );
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Clipped praise' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create folder' } )
		);
		expect(
			await screen.findByText( 'name must not be blank' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Clipped praise' ) ).toBeNull();
	} );

	it( 'shows a refused creation as the refusal', async () => {
		wire.replies.create_collection = () =>
			new Error( 'collection q4-client-wins is taken' );
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Q4 client wins' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Create folder' } )
		);
		expect(
			await screen.findByText( 'collection q4-client-wins is taken' )
		).toBeInTheDocument();
	} );

	/**
	 * Open the listed folder and wait for its items.
	 *
	 * @return {Promise<HTMLElement>} The folder's region.
	 */
	async function openFolder() {
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Q4 client wins' } )
		);
		const folder = await screen.findByRole( 'region', {
			name: 'Q4 client wins',
		} );
		await within( folder ).findByRole( 'link', {
			name: 'Quillsheet pilot goes live',
		} );
		return folder;
	}

	/**
	 * The titles a folder shows, in order.
	 *
	 * @param {HTMLElement} folder The folder's region.
	 * @return {string[]} The titles.
	 */
	const titles = ( folder ) =>
		within( folder )
			.queryAllByRole( 'link' )
			.map( ( link ) => link.textContent );

	it( 'lists a folder unused first, as read, each mark a badge with its day', async () => {
		render( <Harness canTune={ false } /> );
		const folder = await openFolder();
		expect( titles( folder ) ).toEqual( [
			'Acme Quillsheet wins regional prize',
			'Quillsheet pilot goes live',
		] );
		const used = within( folder )
			.getByRole( 'link', { name: 'Quillsheet pilot goes live' } )
			.closest( 'li' );
		const badges = [
			...used.querySelectorAll( '.newspack-nodes-status-badge' ),
		];
		expect( badges.map( ( badge ) => badge.textContent ) ).toEqual( [
			'Newsletter · 2026-10-19',
			'Site · 2026-10-21',
		] );
		const unused = within( folder )
			.getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
			.closest( 'li' );
		expect(
			within( unused ).getByText( 'Not yet used' )
		).toBeInTheDocument();
	} );

	it( 'filters a folder by mark, or to the items not yet used', async () => {
		render( <Harness canTune={ false } /> );
		const folder = await openFolder();
		const show = ( name ) =>
			fireEvent.click( within( folder ).getByRole( 'button', { name } ) );

		show( 'Site' );
		expect( titles( folder ) ).toEqual( [ 'Quillsheet pilot goes live' ] );
		expect(
			within( folder ).getByRole( 'button', { name: 'Site' } )
		).toHaveAttribute( 'aria-pressed', 'true' );
		show( 'LinkedIn' );
		expect( titles( folder ) ).toEqual( [] );
		expect(
			within( folder ).getByText( 'No item here carries this mark.' )
		).toBeInTheDocument();
		show( 'Not yet used' );
		expect( titles( folder ) ).toEqual( [
			'Acme Quillsheet wins regional prize',
		] );
		show( 'All' );
		expect( titles( folder ) ).toHaveLength( 2 );
	} );

	it( 'marks an item used on a day and shows the badge before a read does', async () => {
		wire.replies.mark_used = { ok: true, event_id: 'ev-m' };
		render( <Harness canTune={ true } /> );
		const folder = await openFolder();
		const row = within( folder )
			.getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
			.closest( 'li' );
		fireEvent.change( within( row ).getByLabelText( 'Used in' ), {
			target: { value: 'linkedin' },
		} );
		fireEvent.change( within( row ).getByLabelText( 'On' ), {
			target: { value: '2026-10-23' },
		} );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Mark used' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'mark_used' ) ).toEqual( [
				[
					'--collection=q4-client-wins',
					'--item=feed:inkfold-review-17',
					'--mark=linkedin',
					'--day=2026-10-23',
				],
			] )
		);
		expect(
			within( row ).getByText( 'LinkedIn · 2026-10-23' )
		).toBeInTheDocument();
	} );

	it( 'offers today as the day a mark is made on', async () => {
		render( <Harness canTune={ true } /> );
		const folder = await openFolder();
		const now = new Date();
		const today = [
			now.getFullYear(),
			String( now.getMonth() + 1 ).padStart( 2, '0' ),
			String( now.getDate() ).padStart( 2, '0' ),
		].join( '-' );
		expect( within( folder ).getAllByLabelText( 'On' )[ 0 ] ).toHaveValue(
			today
		);
	} );

	it( 'moves an item into another folder and takes it out of this one', async () => {
		wire.replies.move_item = { ok: true, event_id: 'ev-v' };
		render( <Harness canTune={ true } /> );
		const folder = await openFolder();
		const row = within( folder )
			.getByRole( 'link', { name: 'Quillsheet pilot goes live' } )
			.closest( 'li' );
		const into = within( row ).getByLabelText( 'Move to' );
		expect(
			within( into )
				.getAllByRole( 'option' )
				.map( ( option ) => option.value )
		).toEqual( [ 'paywall-complaints' ] );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Move' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'move_item' ) ).toEqual( [
				[
					'--collection=q4-client-wins',
					'--item=linear:quillsheet-pilot',
					'--into=paywall-complaints',
				],
			] )
		);
		await waitFor( () =>
			expect( titles( folder ) ).toEqual( [
				'Acme Quillsheet wins regional prize',
			] )
		);
	} );

	it( 'shows a refused move as the refusal and keeps the item', async () => {
		wire.replies.move_item = () =>
			new Error(
				'linear:quillsheet-pilot is not saved in q4-client-wins'
			);
		render( <Harness canTune={ true } /> );
		const folder = await openFolder();
		const row = within( folder )
			.getByRole( 'link', { name: 'Quillsheet pilot goes live' } )
			.closest( 'li' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Move' } )
		);
		expect(
			await screen.findByText(
				'linear:quillsheet-pilot is not saved in q4-client-wins'
			)
		).toBeInTheDocument();
		expect( titles( folder ) ).toHaveLength( 2 );
	} );

	it( 'offers a reader the badges and no control marking or moving', async () => {
		render( <Harness canTune={ false } /> );
		const folder = await openFolder();
		expect(
			within( folder ).queryByRole( 'button', { name: 'Mark used' } )
		).toBeNull();
		expect(
			within( folder ).queryByRole( 'button', { name: 'Move' } )
		).toBeNull();
	} );
} );

describe( 'Collections — excluded items', () => {
	const excluded = () => screen.findByRole( 'region', { name: 'Excluded' } );

	it( 'lists a curator every excluded item, who excluded it, when and why', async () => {
		render( <Harness canTune={ true } /> );
		const section = await excluded();

		expect(
			await within( section ).findByRole( 'link', {
				name: WIRE_COPY.title,
			} )
		).toHaveAttribute( 'href', WIRE_COPY.url );
		expect(
			within( section ).getByText(
				'user:tern-desk · 2026-10-19 · The wire copy of a story we hold.'
			)
		).toBeInTheDocument();
		expect(
			within( section ).getByText( 'agent:tern-desk:Claude · 2026-10-20' )
		).toBeInTheDocument();
		expect( wire.sent( 'excluded' ) ).toEqual( [ [] ] );
	} );

	it( 'says when nothing is excluded', async () => {
		wire.replies.excluded = { items: [], available: true };
		render( <Harness canTune={ true } /> );
		expect(
			await within( await excluded() ).findByText(
				'No item is excluded from every view.'
			)
		).toBeInTheDocument();
	} );

	it( 'includes an item again, takes it off the list and reads it again', async () => {
		wire.replies.include_item = { ok: true, event_id: 'ev-i' };
		render( <Harness canTune={ true } /> );
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
		render( <Harness canTune={ true } /> );
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
		render( <Harness canTune={ false } /> );
		await screen.findByRole( 'button', { name: 'Q4 client wins' } );
		expect(
			screen.queryByRole( 'region', { name: 'Excluded' } )
		).toBeNull();
		expect( wire.sent( 'excluded' ) ).toEqual( [] );
	} );
} );
