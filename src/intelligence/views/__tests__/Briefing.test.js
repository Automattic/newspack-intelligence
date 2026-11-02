/* eslint-env jest */
/**
 * Briefing reads the two `briefing` slices and the reader's watches, each
 * through its own read: the week's items grouped under the clients they are
 * about, every client's and lens's signals, and each watch with what it
 * follows and its unseen count. Each that answers nothing invites the
 * reader to act.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM } from './fixtures';
import Briefing from '../Briefing';

const CLIENT = 'organization:kestrel-quillwork';

const CHURN = {
	...ITEM,
	item_key: 'linear:kestrel-cancel-4',
	title: 'Kestrel Quillwork asks to cancel',
	url: 'https://linear.example/kestrel-cancel-4',
	kind: 'churn_signal',
	importance: 9,
	entities: [
		{ entity_key: CLIENT, name: 'Kestrel Quillwork', state: 'asserted' },
	],
};

const CHANGES = {
	groups: [
		{ entity_key: CLIENT, name: 'Kestrel Quillwork', items: [ CHURN ] },
		{ entity_key: null, name: null, items: [ ITEM ] },
	],
	total: 61,
	available: true,
};

const SIGNAL = {
	axis: 'publisher',
	signal: 'drop',
	bucket: '2026-10-12',
	by: null,
	value: null,
	count: 2,
	baseline: 4,
	items: [
		{
			item_key: 'feed:kestrel-1',
			url: 'https://kestrelquillwork.example/one',
			title: 'Kestrel story one',
		},
	],
};

const SIGNALS = {
	clients: [
		{ entity_key: CLIENT, name: 'Kestrel Quillwork', signals: [ SIGNAL ] },
	],
	lenses: [
		{
			lens_key: 'support-desk',
			name: 'Support desk',
			signals: [
				{
					...SIGNAL,
					axis: 'about',
					signal: 'spike',
					by: 'kind',
					value: 'complaint',
					count: 7,
					baseline: 1.5,
					items: [
						{
							item_key: 'unthread:kestrel-ticket-8',
							url: 'https://support.example/kestrel-ticket-8',
							title: 'Kestrel ticket eight',
						},
					],
				},
			],
		},
	],
	available: true,
};

const WATCHES = {
	watches: [
		{
			watch_key: '4c2e9a1f7b3d5e60',
			name: 'Kestrel account',
			target: { kind: 'entity', key: CLIENT },
			digest: false,
			instant: false,
			inbox: true,
			unseen: 3,
		},
	],
	available: true,
};

const NONE = {
	changes: { groups: [], total: 0, available: true },
	signals: { clients: [], lenses: [], available: true },
};

let wire;
let handlers;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	const full = { changes: CHANGES, signals: SIGNALS };
	wire.replies.briefing = ( [ what ] ) => full[ what ];
	wire.replies.watches = WATCHES;
	handlers = {
		onOpenEntity: jest.fn(),
		onOpenLens: jest.fn(),
		onOpenWatch: jest.fn(),
		onNewWatch: jest.fn(),
		onSearch: jest.fn(),
	};
} );

const renderBriefing = () => render( <Briefing { ...handlers } /> );

describe( 'Briefing', () => {
	it( 'asks each slice through its own read', async () => {
		renderBriefing();
		await screen.findByRole( 'button', { name: 'Kestrel account' } );
		expect( wire.sent( 'briefing' ).sort() ).toEqual( [
			[ 'changes' ],
			[ 'signals' ],
		] );
		expect( wire.sent( 'watches' ) ).toEqual( [ [] ] );
	} );

	it( 'groups the week under each client, the rest under Industry', async () => {
		renderBriefing();
		const changes = await screen.findByRole( 'region', {
			name: 'Last seven days',
		} );
		await within( changes ).findByRole( 'link', {
			name: 'Kestrel Quillwork asks to cancel',
		} );
		const groups = within( changes ).getAllByRole( 'heading', {
			level: 3,
		} );
		expect( groups.map( ( g ) => g.textContent ) ).toEqual( [
			'Kestrel Quillwork',
			'Industry',
		] );
		expect(
			within( changes ).getByText(
				'The 2 most important of 61 items about clients and the industry in the last seven days.'
			)
		).toBeInTheDocument();
		expect(
			within( changes ).getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		fireEvent.click(
			within( groups[ 0 ] ).getByRole( 'button', {
				name: 'Kestrel Quillwork',
			} )
		);
		expect( handlers.onOpenEntity ).toHaveBeenCalledWith( CLIENT );
	} );

	it( 'lists each client and lens with its signals', async () => {
		renderBriefing();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		await within( signals ).findByText( '2 against a baseline of 4' );
		expect(
			within( signals ).getByText( 'publisher drop' )
		).toBeInTheDocument();
		expect(
			within( signals ).getByText( 'about spike: complaint' )
		).toBeInTheDocument();
		expect(
			within( signals ).getByRole( 'link', { name: 'Kestrel story one' } )
		).toBeInTheDocument();
		fireEvent.click(
			within( signals ).getByRole( 'button', {
				name: 'Kestrel Quillwork',
			} )
		);
		expect( handlers.onOpenEntity ).toHaveBeenCalledWith( CLIENT );
		fireEvent.click(
			within( signals ).getByRole( 'button', { name: 'Support desk' } )
		);
		expect( handlers.onOpenLens ).toHaveBeenCalledWith( 'support-desk' );
	} );

	it( 'lists the reader’s watches with their unseen counts', async () => {
		renderBriefing();
		const watches = await screen.findByRole( 'region', {
			name: 'Your watches',
		} );
		expect(
			await within( watches ).findByText(
				'Entity · organization:kestrel-quillwork · 3 unseen'
			)
		).toBeInTheDocument();
		fireEvent.click(
			within( watches ).getByRole( 'button', { name: 'Kestrel account' } )
		);
		expect( handlers.onOpenWatch ).toHaveBeenCalledWith(
			'4c2e9a1f7b3d5e60'
		);
	} );

	it( 'invites the reader to act where a slice answers nothing', async () => {
		wire.replies.briefing = ( [ what ] ) => NONE[ what ];
		wire.replies.watches = { watches: [], available: true };
		renderBriefing();
		await screen.findByText(
			'Nothing about a client or the industry arrived in the last seven days.',
			{ exact: false }
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Search everything' } )
		);
		expect( handlers.onSearch ).toHaveBeenCalledTimes( 1 );

		await screen.findByText(
			'No client or lens moved off its baseline in the last seven days.',
			{ exact: false }
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Open lenses' } )
		);
		expect( handlers.onOpenLens ).toHaveBeenCalledWith( null );

		await screen.findByText(
			'Watch a client or a lens to see what is new for you here.',
			{ exact: false }
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'New watch' } ) );
		expect( handlers.onNewWatch ).toHaveBeenCalledTimes( 1 );
	} );
} );
