/* eslint-env jest */
/**
 * Every view speaks a reader's words: rendered from the shared fixtures with
 * no operator named, no text the page shows is a stored key, a snake_case
 * token, an entity key or the platform's name, and none reads a word the
 * vocabulary replaced.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';

jest.mock( '@newspack-nodes/debug-overlay', () => ( {
	__esModule: true,
	default: () => null,
} ) );

import { intelWire } from './intelWire';
import { HEALTHY } from './healthFixture';
import {
	ASK_DEFAULTS,
	COMPOSITIONS,
	ENTITY,
	GROUPS,
	ITEM,
	LENSES,
	VOCABULARY,
	pageData,
} from '../views/__tests__/fixtures';
import IntelligencePage from '../IntelligencePage';

/** Every key a word map words, and every value of a closed list. */
const KEYS = new Set(
	Object.values( VOCABULARY ).flatMap( ( one ) =>
		Array.isArray( one )
			? one.filter( ( value ) => 'string' === typeof value )
			: Object.keys( one )
	)
);

/** What no text may read: a word the vocabulary replaced. */
const REPLACED = [
	/Steady/,
	/\blens(es)?\b/i,
	/\bcollections?\b/i,
	/Assert role/,
];

const CHANGES = {
	order: 'importance',
	items: [
		{ ...ITEM, categories: [], otherwise: true },
		{
			...ITEM,
			item_key: 'github:tern-12',
			kind: 'bug',
			categories: [],
			otherwise: true,
		},
	],
	groups: [
		{
			role: 'otherwise',
			key: null,
			name: null,
			items: [ ITEM.item_key, 'github:tern-12' ],
		},
	],
	categories: [],
	otherwise: 2,
	total: 2,
	summary: {
		since: 1792000000,
		items: 2,
		clients: 1,
		kinds: [ { kind: 'churned', items: 1, clients: 1 } ],
	},
	available: true,
};

const SIGNAL = {
	axis: 'about',
	signal: 'spike',
	bucket: '2026-10-12',
	by: 'kind',
	value: 'release',
	count: 35,
	usual: { mean: 0.4, low: 0, high: 3 },
	trend: [ { bucket: '2026-10-12', count: 35, in_window: true } ],
	items: [ { item_key: ITEM.item_key, url: ITEM.url, title: ITEM.title } ],
};

const REVIEW = {
	links: [
		{
			item_key: 'github:example/plugin#912',
			entity_key: ENTITY.entity_key,
			name: ENTITY.name,
			type: 'organization',
			role: 'mention',
			method: 'ner_fuzzy',
			confidence: 0.61,
			evidence: 'Acme readers flagged the paywall bug twice.',
			title: 'Paywall bug reported by Acme',
			url: 'https://github.example/example/plugin/912',
			item_links: 1,
		},
	],
	entities: [
		{
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
		},
	],
};

const FOLDERS = {
	collections: [
		{
			collection_key: 'q4-client-wins',
			name: 'Q4 client wins',
			created_ts: 1790035200,
			items: 1,
			recent_ts: 1792497600,
		},
	],
	available: true,
};

const WATCHES = {
	watches: [
		{
			watch_key: 'acme-complaints',
			name: 'Acme complaints',
			target: { kind: 'entity', key: ENTITY.entity_key },
			digest: true,
			instant: false,
			inbox: true,
			unseen: 7,
		},
	],
	available: true,
};

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.sessionStorage.clear();
	window.NewspackNodesData = pageData();
	const wire = intelWire();
	wire.replies.search = ( args ) =>
		args.includes( '--group=theme' )
			? GROUPS
			: { items: [ ITEM ], total: 1, available: true };
	wire.replies.entity = ENTITY;
	wire.replies.timeline = { items: [ ITEM ], total: 1, available: true };
	wire.replies.related = { entities: [], available: true };
	wire.replies.claims = {
		claims: [
			{
				subject: ENTITY.entity_key,
				subject_name: ENTITY.name,
				predicate: 'part_of',
				object: 'organization:harbor-ledger',
				object_name: 'Harbor Ledger',
				confidence: 0.9,
				item_key: ITEM.item_key,
				url: ITEM.url,
				title: ITEM.title,
			},
		],
		available: true,
	};
	wire.replies.review = ( [ what ] ) => ( {
		[ what ]: REVIEW[ what ],
		total: REVIEW[ what ].length,
		available: true,
	} );
	wire.replies.theme_suggestions = { suggestions: [], available: true };
	wire.replies.collections = ( args ) =>
		0 === args.length
			? FOLDERS
			: {
					...FOLDERS.collections[ 0 ],
					items: [
						{
							item_key: ITEM.item_key,
							url: ITEM.url,
							title: ITEM.title,
							actor: 'user:editor',
							saved_ts: 1792411200,
							marks: [
								{ mark: 'newsletter', day: '2026-10-19' },
							],
						},
					],
					available: true,
			  };
	wire.replies.lenses = LENSES;
	wire.replies.trends = { rows: [], available: true };
	wire.replies.signals = {
		signals: [ SIGNAL ],
		trend: SIGNAL.trend,
		partial: { bucket: '2026-10-19', count: 1 },
		judged: true,
		bucket: 'week',
		baseline_buckets: 8,
		available: true,
	};
	wire.replies.watches = WATCHES;
	wire.replies.watch_matches = { items: [ ITEM ], total: 1, available: true };
	wire.replies.health = ( [ what ] ) => HEALTHY[ what ];
	wire.replies.briefing = ( [ what ] ) =>
		'changes' === what
			? CHANGES
			: {
					clients: [
						{
							entity_key: ENTITY.entity_key,
							name: ENTITY.name,
							signals: [ SIGNAL ],
						},
					],
					lenses: [],
					judged: true,
					available: true,
			  };
} );

/**
 * Every text the page shows, trimmed, with every placeholder, outside the
 * substrate's shared header, whose wordmark names the platform.
 *
 * @return {string[]} The texts.
 */
function shown() {
	const walker = document.createTreeWalker(
		document.body,
		window.NodeFilter.SHOW_TEXT
	);
	const texts = [];
	while ( walker.nextNode() ) {
		const text = walker.currentNode.nodeValue.trim();
		const at = walker.currentNode.parentElement;
		if ( text && ! at.closest( 'style, .topology-header' ) ) {
			texts.push( text );
		}
	}
	document
		.querySelectorAll( '[placeholder]' )
		.forEach( ( el ) => texts.push( el.getAttribute( 'placeholder' ) ) );
	return texts;
}

/**
 * The texts the page shows that a reader should not read.
 *
 * @return {string[]} Each, with why.
 */
function unworded() {
	return shown().flatMap( ( text ) =>
		[
			KEYS.has( text ) && 'a key',
			/\b[a-z]+_[a-z_]+\b/.test( text ) && 'snake_case',
			/^[a-z]+:[a-z0-9-]+$/.test( text ) && 'an entity key',
			/newspack/i.test( text ) && 'the platform',
			...REPLACED.map(
				( pattern ) => pattern.test( text ) && `${ pattern }`
			),
		]
			.filter( Boolean )
			.map( ( why ) => `${ why }: ${ text }` )
	);
}

/** Open every collapsed group, so its items render. */
const expandAll = () =>
	document
		.querySelectorAll( 'button[aria-expanded="false"]' )
		.forEach( ( toggle ) => fireEvent.click( toggle ) );

const tab = ( name ) =>
	within( screen.getByRole( 'tablist', { name: 'Views' } ) ).getByRole(
		'tab',
		{ name }
	);

function renderPage( compositions = COMPOSITIONS ) {
	return render(
		<IntelligencePage
			canTune
			canManage
			userId="6143"
			vocabulary={ VOCABULARY }
			compositions={ compositions }
			askDefaults={ ASK_DEFAULTS }
		/>
	);
}

describe( 'the vocabulary', () => {
	it( 'names the tabs in a reader’s words', async () => {
		renderPage();
		await screen.findAllByRole( 'link', { name: ITEM.title } );
		await screen.findByRole( 'tab', { name: 'Review 1' } );
		await screen.findByRole( 'tab', { name: 'Watches 7' } );
		expect(
			within( screen.getByRole( 'tablist', { name: 'Views' } ) )
				.getAllByRole( 'tab' )
				.map( ( one ) => one.textContent )
		).toEqual( [
			'Briefing',
			'Search',
			'Team views',
			'Review 1',
			'Folders',
			'Watches 7',
		] );
	} );

	it( 'shows no key on the Briefing', async () => {
		renderPage();
		await screen.findAllByRole( 'link', { name: ITEM.title } );
		expandAll();
		await screen.findAllByRole( 'link', { name: ITEM.title } );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on Search', async () => {
		renderPage();
		fireEvent.click( tab( 'Search' ) );
		await screen.findByRole( 'link', { name: ITEM.title } );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on a profile', async () => {
		const profile = 'newspack-intelligence/entity';
		renderPage( {
			...COMPOSITIONS,
			[ profile ]: {
				...COMPOSITIONS[ profile ],
				blocks: [
					{
						name: 'newspack-intelligence/entity-facts',
						attributes: {
							show: [ 'counts', 'claims', 'details' ],
							limit: 10,
						},
						inner: [],
					},
				],
			},
		} );
		fireEvent.click( tab( 'Search' ) );
		fireEvent.click(
			(
				await screen.findAllByRole( 'button', { name: ENTITY.name } )
			)[ 0 ]
		);
		await screen.findByRole( 'heading', { name: ENTITY.name } );
		await screen.findByText( 'Harbor Ledger' );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on a team view', async () => {
		renderPage();
		fireEvent.click( tab( 'Team views' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Support desk 29 new' } )
		);
		await screen.findByText( 'site blocked from sending email' );
		expandAll();
		await screen.findAllByRole( 'link', {
			name: 'Acme mail bounces at the sender',
		} );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on either Review queue of proposals', async () => {
		renderPage();
		fireEvent.click(
			await screen.findByRole( 'tab', { name: 'Review 1' } )
		);
		await screen.findByText( ENTITY.name );
		expect( unworded() ).toEqual( [] );
		fireEvent.click(
			screen.getByRole( 'tab', { name: 'Suggested names 1' } )
		);
		await screen.findByRole( 'button', { name: 'Valley Times' } );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on Folders', async () => {
		renderPage();
		fireEvent.click( tab( 'Folders' ) );
		fireEvent.change( await screen.findByLabelText( 'Show' ), {
			target: { value: 'all' },
		} );
		await screen.findByRole( 'link', { name: ITEM.title } );
		expect( unworded() ).toEqual( [] );
	} );

	it( 'shows no key on Watches', async () => {
		renderPage();
		fireEvent.click( tab( 'Watches' ) );
		fireEvent.click(
			await screen.findByRole( 'button', { name: /Acme complaints/ } )
		);
		await screen.findByRole( 'link', { name: ITEM.title } );
		expect( unworded() ).toEqual( [] );
	} );
} );
