/* eslint-env jest */
/**
 * Briefing reads the two `briefing` slices and the reader's watches, each
 * through its own read: what arrived since the reader last looked, the
 * week's items grouped under the clients they are about, every client's and
 * lens's signals, each group collapsed to its heading and count, and each
 * watch with what it follows and its unseen count. Each that answers
 * nothing invites the reader to act.
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
		{
			entity_key: CLIENT,
			name: 'Kestrel Quillwork',
			state: 'asserted',
			role: 'subject',
			client: true,
		},
	],
};

const PRODUCT_BUG = {
	...ITEM,
	item_key: 'github:tern-mailer-crash-12',
	title: 'Tern Mailer crashes on send',
	url: 'https://github.example/tern-mailer/issues/12',
	kind: 'incident',
	importance: 8,
	entities: [
		{
			entity_key: 'product:tern-mailer',
			name: 'Tern Mailer',
			state: 'asserted',
			role: 'subject',
			client: false,
		},
	],
};

const CHANGES = {
	groups: [
		{ entity_key: null, name: null, role: null, items: [ ITEM ] },
		{
			entity_key: CLIENT,
			name: 'Kestrel Quillwork',
			role: 'client',
			items: [ CHURN ],
		},
		{
			entity_key: null,
			name: null,
			role: 'newspack_product',
			items: [ PRODUCT_BUG ],
		},
	],
	total: 61,
	summary: {
		since: 1792000000,
		items: 23,
		clients: 9,
		kinds: [
			{ kind: 'churned', items: 8, clients: 7 },
			{ kind: 'incident', items: 4, clients: 1 },
			{ kind: 'bug', items: 3, clients: 2 },
			{ kind: 'launch', items: 2, clients: 2 },
			{ kind: 'complaint', items: 5, clients: 3 },
		],
	},
	available: true,
};

/** The reader the page names, and where it keeps when they last looked. */
const USER = '6143';
const LAST_VIEW = 'newspack-intelligence:briefing:last-view:6143';

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
	changes: {
		groups: [],
		total: 0,
		summary: { since: 1793000000, items: 0, clients: 0, kinds: [] },
		available: true,
	},
	signals: { clients: [], lenses: [], available: true },
};

let wire;
let handlers;

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
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
		onSearchFor: jest.fn(),
	};
} );

afterEach( () => jest.restoreAllMocks() );

const renderBriefing = () =>
	render( <Briefing userId={ USER } { ...handlers } /> );

/**
 * Open a collapsed group by its toggle, named by its heading and its count.
 *
 * @param {HTMLElement} heading The group's heading.
 * @param {string}      count   The toggle's count.
 */
const expand = ( heading, count ) => {
	const toggle = within( heading.closest( 'section' ) ).getByRole( 'button', {
		name: `${ heading.textContent } ${ count }`,
	} );
	expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	expect(
		document.getElementById( toggle.getAttribute( 'aria-controls' ) )
	).toBeInTheDocument();
	fireEvent.click( toggle );
	expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
};

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

	it( 'heads each group by its role, in the order the answer gives', async () => {
		renderBriefing();
		const changes = await screen.findByRole( 'region', {
			name: 'Last seven days',
		} );
		const groups = await within( changes ).findAllByRole( 'heading', {
			level: 3,
		} );
		expect(
			within( changes ).queryByRole( 'link', {
				name: 'Kestrel Quillwork asks to cancel',
			} )
		).not.toBeInTheDocument();
		groups.forEach( ( group ) => expand( group, '1 item' ) );
		await within( changes ).findByRole( 'link', {
			name: 'Kestrel Quillwork asks to cancel',
		} );
		expect( groups.map( ( g ) => g.textContent ) ).toEqual( [
			'Industry',
			'Kestrel Quillwork',
			'Newspack',
		] );
		expect(
			within( groups[ 2 ].closest( 'section' ) ).getByRole( 'link', {
				name: 'Tern Mailer crashes on send',
			} )
		).toHaveAttribute(
			'href',
			'https://github.example/tern-mailer/issues/12'
		);
		expect(
			within( changes ).getByText(
				'The 3 most important of 61 items about clients, Newspack and the industry in the last seven days.'
			)
		).toBeInTheDocument();
		expect(
			within( changes ).getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		fireEvent.click(
			within( groups[ 1 ] ).getByRole( 'button', {
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
		const groups = await within( signals ).findAllByRole( 'heading', {
			level: 3,
		} );
		expect(
			within( signals ).queryByText( '2 against a baseline of 4' )
		).not.toBeInTheDocument();
		groups.forEach( ( group ) => expand( group, '1 signal' ) );
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

	it( 'opens with what arrived since the reader last looked', async () => {
		window.localStorage.setItem( LAST_VIEW, '1792000000' );
		const before = Math.floor( Date.now() / 1000 );
		renderBriefing();
		const since = await screen.findByRole( 'region', {
			name: 'Since you last looked',
		} );
		expect(
			within( since )
				.getAllByRole( 'button' )
				.map( ( line ) => line.textContent )
		).toEqual( [
			'23 new items about 9 clients',
			'7 clients started offboarding',
			'4 incidents reported',
			'3 bugs filed',
			'2 launches',
		] );
		expect( wire.sent( 'briefing' ) ).toContainEqual( [
			'changes',
			'--since=1792000000',
		] );
		fireEvent.click(
			within( since ).getByRole( 'button', {
				name: '7 clients started offboarding',
			} )
		);
		expect( handlers.onSearchFor ).toHaveBeenCalledWith( {
			kind: 'churned',
			since: '2026-10-14',
		} );
		fireEvent.click(
			within( since ).getByRole( 'button', {
				name: '23 new items about 9 clients',
			} )
		);
		expect( handlers.onSearchFor ).toHaveBeenLastCalledWith( {
			since: '2026-10-14',
		} );
		const stored = Number( window.localStorage.getItem( LAST_VIEW ) );
		expect( stored ).toBeGreaterThanOrEqual( before );
		expect( stored ).toBeLessThanOrEqual( Date.now() / 1000 );
	} );

	it( 'says so when nothing arrived since the reader last looked', async () => {
		window.localStorage.setItem( LAST_VIEW, '1793000000' );
		wire.replies.briefing = ( [ what ] ) => NONE[ what ];
		renderBriefing();
		expect(
			await screen.findByText( 'Nothing new since you last looked.' )
		).toBeInTheDocument();
	} );

	it( 'shows the plain Briefing when storage will not be read', async () => {
		const read = jest
			.spyOn( window.Storage.prototype, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'site data blocked' );
			} );
		renderBriefing();
		await screen.findByRole( 'region', { name: 'Last seven days' } );
		await screen.findByRole( 'button', { name: 'Kestrel account' } );
		await screen.findAllByRole( 'heading', { level: 3 } );
		expect(
			screen.queryByRole( 'region', { name: 'Since you last looked' } )
		).not.toBeInTheDocument();
		expect( wire.sent( 'briefing' ) ).toContainEqual( [ 'changes' ] );
		expect( read ).toHaveBeenCalledWith( LAST_VIEW );
	} );

	it( 'reads only the reader’s own last look', async () => {
		window.localStorage.setItem(
			'newspack-intelligence:briefing:last-view:9999',
			'1792000000'
		);
		renderBriefing();
		await screen.findAllByRole( 'heading', { level: 3 } );
		expect(
			screen.queryByRole( 'region', { name: 'Since you last looked' } )
		).not.toBeInTheDocument();
		expect( wire.sent( 'briefing' ) ).toContainEqual( [ 'changes' ] );
	} );

	it( 'keeps the last look while the graph will not open', async () => {
		window.localStorage.setItem( LAST_VIEW, '1792000000' );
		wire.replies.briefing = ( [ what ] ) =>
			'changes' === what
				? { ...NONE.changes, available: false }
				: SIGNALS;
		renderBriefing();
		await screen.findByText(
			'The intelligence graph could not be opened, so this was not read.'
		);
		expect(
			screen.queryByRole( 'region', { name: 'Since you last looked' } )
		).not.toBeInTheDocument();
		expect( window.localStorage.getItem( LAST_VIEW ) ).toBe( '1792000000' );
	} );

	it( 'shows the plain Briefing on a first visit, and remembers it', async () => {
		renderBriefing();
		await screen.findAllByRole( 'heading', { level: 3 } );
		expect(
			screen.queryByRole( 'region', { name: 'Since you last looked' } )
		).not.toBeInTheDocument();
		expect( window.localStorage.getItem( LAST_VIEW ) ).toMatch( /^\d+$/ );
	} );

	it( 'invites the reader to act where a slice answers nothing', async () => {
		wire.replies.briefing = ( [ what ] ) => NONE[ what ];
		wire.replies.watches = { watches: [], available: true };
		renderBriefing();
		await screen.findByText(
			'Nothing about a client, Newspack or the industry arrived in the last seven days.',
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
