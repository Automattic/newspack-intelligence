/* eslint-env jest */
/**
 * ItemRow renders one item from the graph: its title as a link to its own
 * URL when that URL is http(s), every string as text, a meta line of cells
 * (its source, its day and its kind as a badge, never joined by dots), its
 * subjects as at most three pills opening them and every other entity it
 * names behind "+N more", a mention saying so; Save, a bare glyph, only
 * for a caller who may curate, moving into the row's "More actions"
 * menu when the page is narrow, beside "Open profile" and "Copy link"; and
 * the exclusion only for a reader holding tune: a text action that holds
 * its write behind an Undo, puts the row back on Undo or a refusal, and
 * sends a pending write when the row leaves the page.
 */

import {
	render,
	screen,
	fireEvent,
	within,
	act,
	waitFor,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { speak } from '@wordpress/a11y';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { useIntelRead } from '../../hooks/useIntelCommand';
import ItemRow, { ItemPage, SaveButton } from '../ItemRow';
import { pageData } from '../../views/__tests__/fixtures';

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

/**
 * A read whose rendering says whether its answer is owed, remembered or
 * read, so a test sees what the cache holds for it.
 *
 * @param {Object}   props
 * @param {string}   props.scope   The read's scope.
 * @param {string}   props.command The read verb.
 * @param {string[]} props.args    Its tokens.
 * @return {import('react').ReactElement} Its state.
 */
function Probe( { scope, command, args } ) {
	const { loading, stale } = useIntelRead( scope, command, args );
	if ( loading ) {
		return <p>{ `${ command } owed` }</p>;
	}
	return <p>{ `${ command } ${ stale ? 'remembered' : 'read' }` }</p>;
}

/** A reply that never comes, so a read stays owed. */
const never = () => new Promise( () => {} );

const ITEM = {
	item_key: 'feed:inkfold-review-17',
	title: 'Acme Quillsheet wins <b>award</b>',
	url: 'https://inkfold-review.example/acme',
	source: 'feed',
	tier: 'public',
	item_ts: 1790035200,
	summary: 'The Acme Quillsheet took the regional prize.',
	kind: 'award',
	entities: [
		{
			entity_key: 'organization:acme-quillsheet',
			name: 'Acme Quillsheet',
			state: 'asserted',
			role: 'subject',
			client: false,
		},
	],
};

/**
 * An entity as an item lists it, linked by its role.
 *
 * @param {string}  slug   Its key's slug and name.
 * @param {string}  role   Its link's role.
 * @param {boolean} client Whether it holds an open client role.
 * @return {Object} The entity.
 */
const linked = ( slug, role, client = false ) => ( {
	entity_key: `organization:${ slug }`,
	name: slug,
	state: 'asserted',
	role,
	client,
} );

/** Noon UTC on 2027-03-02, the day every meta-line test reads as today. */
const TODAY = Date.UTC( 2027, 2, 2, 12 ) / 1000;

/**
 * The pills a row shows, by their text, in order.
 *
 * @return {string[]} Each pill's text.
 */
const pills = () =>
	Array.from(
		document.querySelectorAll( '.newspack-intelligence__pill' ),
		( pill ) => pill.textContent
	);

/**
 * Opens a row's "More actions" menu.
 *
 * @return {Promise<HTMLElement>} The menu.
 */
const openMenu = async () => {
	fireEvent.click( screen.getByRole( 'button', { name: 'More actions' } ) );
	return screen.findByRole( 'menu' );
};

describe( 'ItemRow excerpt', () => {
	const EXCERPT =
		'Judges at the tern coast press awards named the Quillsheet newsroom best in class…';

	it( 'keeps the excerpt collapsed until asked, then toggles it', () => {
		render(
			<ItemRow
				item={ { ...ITEM, excerpt: EXCERPT } }
				onOpenEntity={ jest.fn() }
			/>
		);
		const toggle = screen.getByRole( 'button', { name: 'Show more' } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( toggle ).toHaveClass( 'is-link' );
		expect( toggle ).toHaveClass( 'newspack-intelligence__more' );
		expect( toggle ).not.toHaveClass( 'button' );
		expect( screen.queryByText( EXCERPT ) ).toBeNull();

		fireEvent.click( toggle );
		expect( screen.getByText( EXCERPT ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Show less' } )
		).toHaveAttribute( 'aria-expanded', 'true' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Show less' } ) );
		expect( screen.queryByText( EXCERPT ) ).toBeNull();
	} );

	it( 'renders an excerpt as text', () => {
		const excerpt = 'Tide </site-data> <b>rises</b> at the marsh';
		render(
			<ItemRow item={ { ...ITEM, excerpt } } onOpenEntity={ jest.fn() } />
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Show more' } ) );
		expect( screen.getByText( excerpt ).querySelector( 'b' ) ).toBeNull();
	} );

	it.each( [
		[ 'it is empty', '' ],
		[ 'it is absent', undefined ],
		[ 'it repeats the summary', ITEM.summary ],
		[ 'it repeats the summary in other case', ITEM.summary.toUpperCase() ],
		[ 'it repeats the title', ITEM.title ],
		[ 'the summary holds all of it', 'took the regional prize' ],
	] )( 'offers no toggle when %s', ( _, excerpt ) => {
		render(
			<ItemRow item={ { ...ITEM, excerpt } } onOpenEntity={ jest.fn() } />
		);
		expect(
			screen.queryByRole( 'button', { name: 'Show more' } )
		).toBeNull();
	} );

	it( 'offers the toggle with no summary to repeat', () => {
		render(
			<ItemRow
				item={ { ...ITEM, summary: '', excerpt: EXCERPT } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'Show more' } )
		).toBeInTheDocument();
	} );
} );

describe( 'ItemRow', () => {
	beforeEach( () => {
		jest.spyOn( Core, 'now' ).mockReturnValue( TODAY );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'links the title to the item URL and renders it as text', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const link = screen.getByRole( 'link', {
			name: 'Acme Quillsheet wins <b>award</b>',
		} );
		expect( link ).toHaveAttribute( 'href', ITEM.url );
		expect( link.querySelector( 'b' ) ).toBeNull();
	} );

	it( 'renders the source, the UTC day and the kind as cells, each by its word, joined by no dot', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const meta = document.querySelector( '.newspack-intelligence__meta' );
		expect( meta.textContent ).not.toMatch( /·/ );
		expect(
			within( meta ).getByText( 'Web feed' ).closest( 'span' )
		).toHaveClass( 'newspack-intelligence__source' );
		const icon = meta.querySelector(
			'.newspack-intelligence__source-icon'
		);
		expect( icon ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( icon ).toHaveClass(
			'newspack-nodes-status-badge',
			'is-pill',
			'is-neutral'
		);
		expect( within( meta ).getByText( '22 Sep 2026' ) ).toHaveAttribute(
			'dateTime',
			'2026-09-22'
		);
		expect( icon ).toHaveTextContent( 'W' );
		expect( within( meta ).getByText( 'Prize' ).className ).toBe(
			'newspack-nodes-status-badge is-neutral'
		);
	} );

	it( 'names a day of this year by its day and month alone', () => {
		render(
			<ItemRow
				item={ { ...ITEM, item_ts: Date.UTC( 2027, 1, 14, 9 ) / 1000 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( screen.getByText( '14 Feb' ) ).toHaveAttribute(
			'dateTime',
			'2027-02-14'
		);
	} );

	it( 'renders a kind check that passed beside the kind, with its day', () => {
		render(
			<ItemRow
				item={ {
					...ITEM,
					kind: 'release',
					kind_check: {
						kind: 'release',
						verdict: 'passed',
						ts: 1790123456,
					},
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.getByText(
				'Version out passed its kind check on 23 Sep 2026'
			)
		).toBeInTheDocument();
	} );

	it( 'renders a kind check that failed, naming the kind it checked', () => {
		render(
			<ItemRow
				item={ {
					...ITEM,
					kind: 'other',
					kind_check: {
						kind: 'launch',
						verdict: 'failed',
						ts: 1790123456,
					},
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.getByText(
				'Going live failed its kind check on 23 Sep 2026'
			)
		).toBeInTheDocument();
	} );

	it( 'renders no kind check for an item never checked', () => {
		render(
			<ItemRow
				item={ { ...ITEM, kind_check: null } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( screen.getByText( 'Prize' ) ).toBeInTheDocument();
		expect( screen.queryByText( /kind check/ ) ).toBeNull();
	} );

	it( 'never links a URL that is not http or https', () => {
		render(
			<ItemRow
				item={ { ...ITEM, url: 'javascript:alert(1)' } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( screen.queryByRole( 'link' ) ).toBeNull();
		expect(
			screen.getByText( 'Acme Quillsheet wins <b>award</b>' )
		).toBeInTheDocument();
	} );

	it( 'opens a linked entity from its chip', () => {
		const onOpenEntity = jest.fn();
		render( <ItemRow item={ ITEM } onOpenEntity={ onOpenEntity } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-quillsheet'
		);
	} );

	/**
	 * The answer lists an item's subjects before its mentions, and a link to a
	 * proposed entity is itself `proposed`.
	 */
	it( 'drops a proposal sharing a name, keeping every entity that is not proposed', () => {
		const chipsOf = (
			[ firstKey, firstState ],
			[ secondKey, secondState ]
		) => {
			const onOpenEntity = jest.fn();
			const { unmount } = render(
				<ItemRow
					item={ {
						...ITEM,
						entities: [
							{
								entity_key: firstKey,
								name: 'Quillmere',
								state: firstState,
								role: 'subject',
								client: false,
							},
							{
								entity_key: 'person:ada-fenwick',
								name: 'Ada Fenwick',
								state: 'asserted',
								role: 'subject',
								client: false,
							},
							{
								entity_key: secondKey,
								name: 'Quillmere',
								state: secondState,
								role: 'subject',
								client: false,
							},
						],
					} }
					onOpenEntity={ onOpenEntity }
				/>
			);
			const shown = Array.from(
				document.querySelectorAll( '.newspack-intelligence__pill' )
			).map( ( chip ) => {
				fireEvent.click( chip );
				return [
					chip.textContent,
					onOpenEntity.mock.calls.at( -1 )[ 0 ],
				];
			} );
			unmount();
			return shown;
		};

		expect(
			chipsOf(
				[ 'product:quillmere', 'proposed' ],
				[ 'organization:quillmere', 'asserted' ]
			)
		).toEqual( [
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
			[ 'Quillmere', 'organization:quillmere' ],
		] );
		expect(
			chipsOf(
				[ 'product:quillmere', 'confirmed' ],
				[ 'organization:quillmere', 'asserted' ]
			)
		).toEqual( [
			[ 'Quillmere', 'product:quillmere' ],
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
			[ 'Quillmere', 'organization:quillmere' ],
		] );
		expect(
			chipsOf(
				[ 'organization:quillmere', 'proposed' ],
				[ 'product:quillmere', 'proposed' ]
			)
		).toEqual( [
			[ 'Quillmere', 'organization:quillmere' ],
			[ 'Ada Fenwick', 'person:ada-fenwick' ],
		] );
	} );

	it( 'pills its two subjects and holds its nine mentions behind "+9 more"', () => {
		const subjects = [ 'tern-desk', 'auk-post' ];
		const mentions = [ 1, 2, 3, 4, 5, 6, 7, 8, 9 ].map(
			( n ) => `gull-${ n }`
		);
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						...subjects.map( ( slug ) =>
							linked( slug, 'subject' )
						),
						...mentions.map( ( slug ) =>
							linked( slug, 'mention' )
						),
					],
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( pills() ).toEqual( subjects );
		const more = screen.getByRole( 'button', { name: '+9 more' } );
		expect( more ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( more ).toHaveClass( 'is-link' );
		expect( more ).toHaveClass( 'newspack-intelligence__more' );

		fireEvent.click( more );
		expect( pills() ).toEqual( [
			...subjects,
			...mentions.map( ( slug ) => `${ slug }, mentioned` ),
		] );
		fireEvent.click( screen.getByRole( 'button', { name: 'Show fewer' } ) );
		expect( pills() ).toEqual( subjects );
	} );

	it( 'leads with three subjects at most, the rest behind "+N more"', () => {
		const subjects = [ 'tern', 'auk', 'skua', 'gull', 'petrel' ];
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: subjects.map( ( slug ) =>
						linked( slug, 'subject' )
					),
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( pills() ).toEqual( [ 'tern', 'auk', 'skua' ] );
		fireEvent.click( screen.getByRole( 'button', { name: '+2 more' } ) );
		expect( pills() ).toEqual( subjects );
	} );

	it( 'never leads with a client it only mentions, which reads as mentioned behind "+N more"', () => {
		const onOpenEntity = jest.fn();
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						{
							...linked( 'harbor-ledger', 'mention', true ),
							name: 'Harbor Ledger',
						},
						linked( 'tern-desk', 'subject' ),
						linked( 'skua-times', 'publisher' ),
					],
				} }
				onOpenEntity={ onOpenEntity }
			/>
		);
		expect( pills() ).toEqual( [ 'tern-desk' ] );
		fireEvent.click( screen.getByRole( 'button', { name: '+1 more' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Harbor Ledger, mentioned' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:harbor-ledger'
		);
		expect( pills() ).toEqual( [
			'tern-desk',
			'Harbor Ledger, mentioned',
		] );
	} );

	it( 'truncates a long name inside its pill, its accessible name whole, "+N more" in the same row', () => {
		const long =
			'The Lantern Harbor Community Ledger and Evening Tidewatch Co';
		expect( long ).toHaveLength( 60 );
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						{ ...linked( 'lantern', 'subject' ), name: long },
						linked( 'gull-press', 'mention' ),
					],
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		const pill = screen.getByRole( 'button', { name: long } );
		expect( pill ).toHaveClass( 'newspack-intelligence__pill' );
		expect( within( pill ).getByText( long ) ).toHaveClass(
			'newspack-intelligence__pill-name'
		);
		expect(
			screen
				.getByRole( 'button', { name: '+1 more' } )
				.closest( '.newspack-intelligence__chips' )
		).toBe( pill.closest( '.newspack-intelligence__chips' ) );
	} );

	it( 'offers no toggle when it mentions nothing past its chips', () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		expect( screen.queryByRole( 'button', { name: /more$/ } ) ).toBeNull();
	} );

	it( 'offers save to folder only when given a save handler', () => {
		const onSave = jest.fn();
		const { rerender } = render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() } />
		);
		expect(
			screen.queryByRole( 'button', { name: /save to folder/i } )
		).toBeNull();
		rerender(
			<ItemRow
				item={ ITEM }
				onOpenEntity={ jest.fn() }
				onSave={ onSave }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /save to folder/i } )
		);
		expect( onSave ).toHaveBeenCalledWith( ITEM );
	} );

	it( 'offers a curator Save as a bare glyph, and a reader no Save at all', async () => {
		const onSave = jest.fn();
		const { unmount } = render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() } />
		);
		expect( screen.queryByRole( 'button', { name: /save/i } ) ).toBeNull();
		await openMenu();
		expect(
			screen.queryByRole( 'menuitem', { name: /save/i } )
		).toBeNull();
		unmount();

		render(
			<ItemRow
				item={ ITEM }
				onOpenEntity={ jest.fn() }
				onSave={ onSave }
			/>
		);
		const save = screen.getByRole( 'button', { name: 'Save to folder' } );
		expect( save ).toHaveTextContent( /^$/ );
		expect( save ).toHaveClass( 'newspack-intelligence__save' );
		fireEvent.click( save );
		expect( onSave ).toHaveBeenCalledWith( ITEM );
	} );

	describe( 'when narrow', () => {
		let viewport;

		beforeEach( () => {
			viewport = installViewport( true );
		} );

		afterEach( () => {
			viewport.restore();
		} );

		it( 'moves Save into More actions for a curator', async () => {
			const onSave = jest.fn();
			render(
				<ItemRow
					item={ ITEM }
					onOpenEntity={ jest.fn() }
					onSave={ onSave }
				/>
			);
			expect(
				screen.queryByRole( 'button', { name: 'Save to folder' } )
			).toBeNull();
			const menu = await openMenu();
			fireEvent.click(
				within( menu ).getByRole( 'menuitem', {
					name: 'Save to folder',
				} )
			);
			expect( onSave ).toHaveBeenCalledWith( ITEM );
		} );

		it( 'offers a reader no Save in More actions', async () => {
			render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
			const menu = await openMenu();
			expect(
				within( menu ).queryByRole( 'menuitem', { name: /save/i } )
			).toBeNull();
		} );
	} );

	it( 'opens its first subject’s profile from More actions', async () => {
		const onOpenEntity = jest.fn();
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						linked( 'gull-press', 'mention' ),
						linked( 'tern-desk', 'subject' ),
						linked( 'auk-post', 'subject' ),
					],
				} }
				onOpenEntity={ onOpenEntity }
			/>
		);
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Open profile' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith( 'organization:tern-desk' );
	} );

	it( 'opens the profile of the subject its first pill shows, a proposal sharing its name left out', async () => {
		const onOpenEntity = jest.fn();
		render(
			<ItemRow
				item={ {
					...ITEM,
					entities: [
						{
							entity_key: 'product:tern-desk',
							name: 'Tern Desk',
							state: 'proposed',
							role: 'subject',
							client: false,
						},
						{
							entity_key: 'organization:tern-desk',
							name: 'Tern Desk',
							state: 'asserted',
							role: 'subject',
							client: false,
						},
					],
				} }
				onOpenEntity={ onOpenEntity }
			/>
		);
		expect( pills() ).toEqual( [ 'Tern Desk' ] );
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Open profile' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith( 'organization:tern-desk' );
	} );

	describe( 'Copy link', () => {
		// The snackbar list measures its layout through scrollTo, which jsdom lacks.
		const { scrollTo } = window;

		beforeEach( () => {
			speak.mockClear();
			window.scrollTo = () => {};
		} );

		afterEach( () => {
			delete window.navigator.clipboard;
			window.scrollTo = scrollTo;
		} );

		/** How long a copy's snackbar stands, as spec §4 gives it. */
		const COPIED_MS = 4000;

		const copy = async () => {
			const menu = await openMenu();
			fireEvent.click(
				within( menu ).getByRole( 'menuitem', { name: 'Copy link' } )
			);
		};

		it( 'copies the item’s link and says so politely in a snackbar for 4 seconds', async () => {
			const writeText = jest.fn( () => Promise.resolve() );
			Object.defineProperty( window.navigator, 'clipboard', {
				value: { writeText },
				configurable: true,
			} );
			render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
			// Only timeouts are faked, so the snackbar's own clock is the one moved.
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
			try {
				await copy();
				expect( writeText ).toHaveBeenCalledWith( ITEM.url );
				const notice = await screen.findByText( 'Link copied.' );
				expect(
					notice.closest( '.components-snackbar-list' )
				).not.toBeNull();
				expect( speak ).toHaveBeenCalledWith(
					'Link copied.',
					'polite'
				);
				expect( speak ).not.toHaveBeenCalledWith(
					expect.anything(),
					'assertive'
				);
				await act( async () => {
					jest.advanceTimersByTime( COPIED_MS - 1 );
				} );
				expect(
					screen.getByText( 'Link copied.' )
				).toBeInTheDocument();
				await act( async () => {
					jest.advanceTimersByTime( 1 );
				} );
				expect( screen.queryByText( 'Link copied.' ) ).toBeNull();
			} finally {
				jest.useRealTimers();
			}
		} );

		it( 'says politely when the clipboard refuses the link', async () => {
			Object.defineProperty( window.navigator, 'clipboard', {
				value: {
					writeText: () => Promise.reject( new Error( 'denied' ) ),
				},
				configurable: true,
			} );
			render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
			await copy();
			expect(
				await screen.findByText( 'The link could not be copied.' )
			).toBeInTheDocument();
			expect( speak ).toHaveBeenCalledWith(
				'The link could not be copied.',
				'polite'
			);
			expect( speak ).not.toHaveBeenCalledWith(
				expect.anything(),
				'assertive'
			);
		} );

		it( 'says the link could not be copied where the page has no clipboard', async () => {
			expect( window.navigator.clipboard ).toBeUndefined();
			render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
			await copy();
			expect(
				await screen.findByText( 'The link could not be copied.' )
			).toBeInTheDocument();
		} );
	} );

	it( 'offers no profile without a subject, no link it would not open, and no menu with neither', () => {
		render(
			<ItemRow
				item={ {
					...ITEM,
					url: 'javascript:alert(1)',
					entities: [ linked( 'gull-press', 'mention' ) ],
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'More actions' } )
		).toBeNull();
	} );

	it( 'heads the row with the controls it is given', () => {
		render(
			<ItemRow item={ ITEM } onOpenEntity={ jest.fn() }>
				<button type="button">Mark seen</button>
			</ItemRow>
		);
		expect(
			screen
				.getByRole( 'button', { name: 'Mark seen' } )
				.closest( '.newspack-intelligence__item-head' )
		).not.toBeNull();
	} );
} );

describe( 'ItemPage', () => {
	const page = ( sort, onSort ) =>
		render(
			<ItemPage
				answer={ { items: [ ITEM ], total: 1, sort } }
				starts={ [ 0 ] }
				onStarts={ jest.fn() }
				onOpenEntity={ jest.fn() }
				onSort={ onSort }
			/>
		);

	it( 'says a page is ranked by importance and switches it to newest first', () => {
		const onSort = jest.fn();
		page( 'importance', onSort );
		expect(
			screen.getByText( 'Ranked by importance' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show newest first' } )
		);
		expect( onSort ).toHaveBeenCalledWith( 'time' );
	} );

	it( 'says a page is newest first and switches it to importance', () => {
		const onSort = jest.fn();
		page( 'time', onSort );
		expect( screen.getByText( 'Newest first' ) ).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Rank by importance' } )
		);
		expect( onSort ).toHaveBeenCalledWith( 'importance' );
	} );

	it( 'offers no switch to a page that does not sort', () => {
		page( undefined, undefined );
		expect( screen.queryByText( 'Ranked by importance' ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Show newest first' } )
		).toBeNull();
	} );
} );

describe( 'SaveButton', () => {
	const KEPT = {
		item_key: 'feed:quill-ledger.example:4471',
		url: 'https://quill-ledger.example/4471',
		title: 'Quill Ledger opens a bureau',
	};

	it( 'is a bare glyph, named and tooltipped for what it does', () => {
		const onSave = jest.fn();
		render( <SaveButton item={ KEPT } onSave={ onSave } /> );
		const save = screen.getByRole( 'button', { name: 'Save to folder' } );
		expect( save ).toHaveTextContent( /^$/ );
		expect( save ).toHaveClass( 'has-icon' );
		expect( save ).toHaveClass( 'newspack-intelligence__save' );
		for ( const boxed of [
			'button',
			'is-secondary',
			'is-tertiary',
			'is-primary',
		] ) {
			expect( save ).not.toHaveClass( boxed );
		}
		expect( save.querySelector( 'svg' ) ).not.toBeNull();
		fireEvent.click( save );
		expect( onSave ).toHaveBeenCalledWith( KEPT );
	} );

	it( 'renders nothing for a reader who cannot curate', () => {
		const { container } = render( <SaveButton item={ KEPT } /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'ItemRow exclusion', () => {
	const WIRE_COPY = {
		...ITEM,
		item_key: 'feed:tern-wire-copy-31',
		title: 'Tern wire copy of the prize story',
	};
	let wire;

	beforeEach( () => {
		Core.reset();
		window.NewspackNodesData = pageData();
		wire = intelWire();
		wire.replies.exclude_item = { ok: true, event_id: 'ev-x' };
	} );

	afterEach( () => {
		jest.useRealTimers();
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

	const renderRow = ( props = {} ) =>
		render(
			<ItemRow
				item={ WIRE_COPY }
				onOpenEntity={ jest.fn() }
				onSave={ jest.fn() }
				canTune={ true }
				{ ...props }
			/>
		);
	const exclude = () =>
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Exclude from every view' } )
		);

	it( 'offers a curator the exclusion and a reader none', () => {
		const { unmount } = renderRow();
		expect(
			screen.getByRole( 'button', { name: 'Exclude from every view' } )
		).toBeInTheDocument();
		unmount();
		renderRow( { canTune: false } );
		expect(
			screen.getByRole( 'button', { name: 'Save to folder' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Exclude from every view' } )
		).toBeNull();
	} );

	it( "names the exclusion in the design system's text-button role, a class of its own beside Save", () => {
		renderRow();
		const action = screen.getByRole( 'button', {
			name: 'Exclude from every view',
		} );
		expect( action ).toHaveTextContent( 'Exclude from every view' );
		expect( action ).toHaveAttribute( 'type', 'button' );
		expect( action ).toHaveClass(
			'button-link',
			'newspack-intelligence__exclude'
		);
		for ( const other of [
			'newspack-intelligence__save',
			'components-button',
			'is-link',
			'button',
		] ) {
			expect( action ).not.toHaveClass( other );
		}
	} );

	it( 'hides the row behind its Undo and sends the exclusion once the window closes', async () => {
		renderRow();
		fakeUndoClock();
		exclude();

		expect(
			screen.queryByRole( 'link', { name: WIRE_COPY.title } )
		).toBeNull();
		expect( screen.getByText( 'Excluded.' ) ).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith(
			`Excluded “${ WIRE_COPY.title }” from every view.`,
			'polite'
		);
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'exclude_item' ) ).toEqual( [] );
		await elapse( 1 );
		await waitFor( () =>
			expect( wire.sent( 'exclude_item' ) ).toEqual( [
				[ `--item=${ WIRE_COPY.item_key }` ],
			] )
		);
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		expect( screen.getByText( 'Excluded.' ) ).toBeInTheDocument();
	} );

	it( 'sends a pending exclusion when the row leaves the page', async () => {
		const { unmount } = renderRow();
		fakeUndoClock();
		exclude();
		unmount();

		await waitFor( () =>
			expect( wire.sent( 'exclude_item' ) ).toEqual( [
				[ `--item=${ WIRE_COPY.item_key }` ],
			] )
		);
	} );

	it( 'drops a remembered Briefing once an exclusion from a Search row lands', async () => {
		wire.replies.briefing = { groups: [], total: 0, available: true };
		const seeded = render(
			<Probe
				scope="briefing:changes"
				command="briefing"
				args={ [ 'changes' ] }
			/>
		);
		await screen.findByText( 'briefing read' );
		seeded.unmount();
		renderRow();
		fakeUndoClock();
		exclude();
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'exclude_item' ) ).toHaveLength( 1 )
		);
		await screen.findByText( 'Excluded.' );
		jest.useRealTimers();
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 300 ) )
		);
		wire.replies.briefing = never;
		render(
			<Probe
				scope="briefing:changes"
				command="briefing"
				args={ [ 'changes' ] }
			/>
		);
		expect( screen.getByText( 'briefing owed' ) ).toBeInTheDocument();
	} );

	it( 'puts the row back on Undo and sends nothing', async () => {
		renderRow();
		fakeUndoClock();
		exclude();
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );

		expect(
			screen.getByRole( 'link', { name: WIRE_COPY.title } )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		expect( wire.sent( 'exclude_item' ) ).toEqual( [] );
	} );

	it( 'puts the row back with the refusal when the exclusion is refused', async () => {
		wire.replies.exclude_item = new Error(
			`${ WIRE_COPY.item_key } is excluded from every view`
		);
		renderRow();
		fakeUndoClock();
		exclude();
		await elapse( UNDO_MS );

		expect(
			await screen.findByRole( 'link', { name: WIRE_COPY.title } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			`${ WIRE_COPY.item_key } is excluded from every view`
		);
	} );
} );
