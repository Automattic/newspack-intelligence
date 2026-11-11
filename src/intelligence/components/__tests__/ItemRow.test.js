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
 * sends a pending write when the row leaves the page. Why the item ranks
 * sits behind a closed "Why this ranks" disclosure, its scores in words
 * and never numbers; the meta line counts the story's other copies; and
 * "How this was read" in More actions opens the drawer under the row,
 * focus returning to the menu's toggle when it closes.
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
import ItemRowAlone, { ItemRows, SaveButton } from '../ItemRow';
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

/**
 * One row in a list of its own, as every list draws its rows.
 *
 * @param {Object} props The row's props.
 * @return {import('react').ReactElement} The list.
 */
function ItemRow( props ) {
	return (
		<ItemRows items={ [ props.item ] }>
			<ItemRowAlone { ...props } />
		</ItemRows>
	);
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

/** Fakes only the undo window's own timer; the wire runs as it does. */
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

/**
 * Moves the faked clock on.
 *
 * @param {number} ms Milliseconds.
 * @return {Promise<void>} Settles once React has caught up.
 */
const elapse = ( ms ) =>
	act( async () => {
		jest.advanceTimersByTime( ms );
	} );

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

	it( 'offers no profile without a subject and no link it would not open, leaving how it was read', async () => {
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
		const menu = await openMenu();
		expect(
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( entry ) => entry.textContent )
		).toEqual( [ 'How this was read' ] );
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

	it( 'asks an open copies list again once an exclusion of one of its copies lands, and drops it', async () => {
		const STORY = { ...ITEM, item_key: 'feed:tern-wire-story-30' };
		const copy = {
			item_key: WIRE_COPY.item_key,
			url: WIRE_COPY.url,
			title: WIRE_COPY.title,
			source: 'feed',
			day: '2027-02-27',
		};
		let excluded = false;
		wire.replies.copies = () => ( {
			item_key: STORY.item_key,
			copies: excluded ? [] : [ copy ],
			available: true,
		} );
		wire.replies.exclude_item = () => {
			excluded = true;
			return { ok: true, event_id: 'ev-x' };
		};
		render(
			<>
				<ItemRow
					item={ { ...STORY, copies: 1 } }
					onOpenEntity={ jest.fn() }
				/>
				<ItemRow
					item={ WIRE_COPY }
					onOpenEntity={ jest.fn() }
					canTune={ true }
				/>
			</>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'and 1 other copy' } )
		);
		const list = await screen.findByRole( 'list', {
			name: 'Other copies',
		} );
		expect(
			within( list ).getByRole( 'link', { name: WIRE_COPY.title } )
		).toBeInTheDocument();
		fakeUndoClock();
		exclude();
		await elapse( UNDO_MS );
		jest.useRealTimers();

		expect(
			await screen.findByText(
				'None of its other copies can be read now.'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'copies' ) ).toEqual( [
			[ STORY.item_key ],
			[ STORY.item_key ],
		] );
	} );

	it( 'asks an open How this was read again once an exclusion lands', async () => {
		wire.replies.trace = {
			item_key: ITEM.item_key,
			title: ITEM.title,
			url: ITEM.url,
			steps: [],
			available: true,
		};
		render(
			<>
				<ItemRow item={ ITEM } onOpenEntity={ jest.fn() } />
				<ItemRow
					item={ WIRE_COPY }
					onOpenEntity={ jest.fn() }
					canTune={ true }
				/>
			</>
		);
		const [ more ] = screen.getAllByRole( 'button', {
			name: 'More actions',
		} );
		fireEvent.click( more );
		fireEvent.click(
			within( await screen.findByRole( 'menu' ) ).getByRole( 'menuitem', {
				name: 'How this was read',
			} )
		);
		await waitFor( () =>
			expect( wire.sent( 'trace' ) ).toEqual( [ [ ITEM.item_key ] ] )
		);
		fakeUndoClock();
		exclude();
		await elapse( UNDO_MS );
		jest.useRealTimers();

		await waitFor( () =>
			expect( wire.sent( 'trace' ) ).toEqual( [
				[ ITEM.item_key ],
				[ ITEM.item_key ],
			] )
		);
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

describe( 'ItemRow entity removal', () => {
	const NAMED = {
		...ITEM,
		item_key: 'linear:prairie-renewal-44',
		title: 'Prairie Signal renewal call',
		entities: [
			linked( 'prairie-signal', 'subject', true ),
			linked( 'coastline-weekly', 'subject' ),
		],
	};
	let wire;

	beforeEach( () => {
		Core.reset();
		window.NewspackNodesData = pageData();
		wire = intelWire();
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		speak.mockClear();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	const renderRow = ( props = {} ) =>
		render(
			<ItemRow
				item={ NAMED }
				onOpenEntity={ jest.fn() }
				canTune={ true }
				{ ...props }
			/>
		);
	const remove = ( name ) =>
		fireEvent.click(
			screen.getByRole( 'button', {
				name: `Remove ${ name } from this item`,
			} )
		);

	it( 'offers a curator an × inside each chip, and a reader none', () => {
		const { unmount } = renderRow();
		for ( const name of [ 'prairie-signal', 'coastline-weekly' ] ) {
			const chip = screen.getByRole( 'button', { name } );
			expect( chip.parentElement ).toContainElement(
				screen.getByRole( 'button', {
					name: `Remove ${ name } from this item`,
				} )
			);
		}
		unmount();
		renderRow( { canTune: false } );
		expect(
			screen.queryByRole( 'button', { name: /^Remove / } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'prairie-signal' } )
		).toBeInTheDocument();
	} );

	it( 'takes the chip away at once, offers Undo, and rejects the link once the window closes', async () => {
		renderRow();
		fakeUndoClock();
		remove( 'prairie-signal' );

		expect( pills() ).toEqual( [ 'coastline-weekly' ] );
		expect( speak ).toHaveBeenCalledWith(
			'Removed prairie-signal from this item.',
			'polite'
		);
		expect(
			screen.getByRole( 'button', { name: 'Undo' } )
		).toBeInTheDocument();
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'More actions' } )
		);
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await elapse( 1 );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					'--item=linear:prairie-renewal-44',
					'--entity=organization:prairie-signal',
				],
			] )
		);
		expect( pills() ).toEqual( [ 'coastline-weekly' ] );
	} );

	it( 'holds every row’s removals on one write and one Undo, the latest undone first', async () => {
		const SECOND = {
			...NAMED,
			item_key: 'linear:coastline-renewal-61',
			title: 'Coastline Weekly renewal call',
			entities: [ linked( 'harbor-ledger', 'subject' ) ],
		};
		render(
			<ItemRows items={ [ NAMED, SECOND ] }>
				{ [ NAMED, SECOND ].map( ( item ) => (
					<ItemRowAlone
						key={ item.item_key }
						item={ item }
						onOpenEntity={ jest.fn() }
						canTune={ true }
					/>
				) ) }
			</ItemRows>
		);
		const writes = [ ...Core.registry.nodes.keys() ].filter( ( name ) =>
			name.startsWith( 'reject-link:' )
		);
		expect( writes.length ).toBeGreaterThan( 0 );
		fakeUndoClock();
		remove( 'prairie-signal' );
		remove( 'harbor-ledger' );

		expect(
			screen.getAllByRole( 'button', { name: 'Undo' } )
		).toHaveLength( 1 );
		expect(
			[ ...Core.registry.nodes.keys() ].filter( ( name ) =>
				name.startsWith( 'reject-link:' )
			)
		).toEqual( writes );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( pills() ).toEqual( [ 'coastline-weekly', 'harbor-ledger' ] );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					'--item=linear:prairie-renewal-44',
					'--entity=organization:prairie-signal',
				],
			] )
		);
	} );

	it( 'puts the chip back on Undo and sends nothing', async () => {
		renderRow();
		fakeUndoClock();
		remove( 'coastline-weekly' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );

		expect( pills() ).toEqual( [ 'prairie-signal', 'coastline-weekly' ] );
		await elapse( UNDO_MS );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'puts the chip back with the refusal when the rejection is refused', async () => {
		wire.replies.reject_link = new Error(
			'no link: linear:prairie-renewal-44 organization:prairie-signal'
		);
		renderRow();
		fakeUndoClock();
		remove( 'prairie-signal' );
		await elapse( UNDO_MS );

		await waitFor( () =>
			expect( pills() ).toEqual( [
				'prairie-signal',
				'coastline-weekly',
			] )
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'no link: linear:prairie-renewal-44 organization:prairie-signal'
		);
	} );
} );

describe( 'ItemRow is this about', () => {
	let wire;

	beforeEach( () => {
		Core.reset();
		window.NewspackNodesData = pageData();
		wire = intelWire();
		wire.replies.names = ( args ) => ( {
			entities:
				'--prefix=Harbor' === args[ 0 ]
					? [
							{
								entity_key: 'organization:harbor-ledger',
								name: 'Harbor Ledger',
							},
							{
								entity_key: 'organization:harbor-lights',
								name: 'Harbor Lights',
							},
					  ]
					: [],
			available: true,
		} );
		wire.replies.add_link = { ok: true, event_id: 'ev-a' };
		speak.mockClear();
	} );

	const renderRow = ( props = {} ) =>
		render(
			<ItemRow
				item={ ITEM }
				onOpenEntity={ jest.fn() }
				canTune={ true }
				{ ...props }
			/>
		);
	const ask = async () => {
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Is this about…' } )
		);
		return screen.findByRole( 'dialog' );
	};
	const pick = async ( dialog, name ) => {
		const field = within( dialog ).getByLabelText( 'Client or company' );
		fireEvent.change( field, { target: { value: 'Harbor' } } );
		await waitFor( () =>
			expect(
				field.list?.querySelectorAll( 'option' ) ?? []
			).toHaveLength( 2 )
		);
		fireEvent.change( field, { target: { value: name } } );
	};

	it( 'offers a curator "Is this about…" in More actions, and a reader none', async () => {
		const { unmount } = renderRow();
		expect(
			within( await openMenu() ).getByRole( 'menuitem', {
				name: 'Is this about…',
			} )
		).toBeInTheDocument();
		unmount();
		renderRow( { canTune: false } );
		expect(
			within( await openMenu() ).queryByRole( 'menuitem', {
				name: 'Is this about…',
			} )
		).toBeNull();
	} );

	it( 'links the item to the entity picked by name, as its subject', async () => {
		renderRow();
		const dialog = await ask();
		expect(
			within( dialog ).getByRole( 'heading', { name: 'Is this about…' } )
		).toBeInTheDocument();
		const link = within( dialog ).getByRole( 'button', {
			name: 'Link it',
		} );
		expect( link ).toBeDisabled();
		await pick( dialog, 'Harbor Lights' );
		expect(
			within( dialog ).getByRole( 'radio', { name: 'Headlined' } )
		).toBeChecked();
		fireEvent.click( link );

		await waitFor( () =>
			expect( wire.sent( 'add_link' ) ).toEqual( [
				[
					`--item=${ ITEM.item_key }`,
					'--entity=organization:harbor-lights',
					'--role=subject',
				],
			] )
		);
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		expect( speak ).toHaveBeenCalledWith(
			'Linked Harbor Lights to this item. It shows here once the item is read again.',
			'polite'
		);
	} );

	it( 'links it as mentioned when the curator says so', async () => {
		renderRow();
		const dialog = await ask();
		await pick( dialog, 'Harbor Ledger' );
		fireEvent.click(
			within( dialog ).getByRole( 'radio', { name: 'Named in passing' } )
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Link it' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'add_link' ) ).toEqual( [
				[
					`--item=${ ITEM.item_key }`,
					'--entity=organization:harbor-ledger',
					'--role=mention',
				],
			] )
		);
	} );

	it( 'keeps the dialog open with the refusal when the link is refused', async () => {
		wire.replies.add_link = new Error(
			`already confirmed: ${ ITEM.item_key } organization:harbor-ledger`
		);
		renderRow();
		const dialog = await ask();
		await pick( dialog, 'Harbor Ledger' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Link it' } )
		);

		expect(
			await within( dialog ).findByRole( 'alert' )
		).toHaveTextContent(
			`already confirmed: ${ ITEM.item_key } organization:harbor-ledger`
		);
		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
	} );

	it( 'closes on Cancel, returning focus to More actions, and sends nothing', async () => {
		renderRow();
		const dialog = await ask();
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'More actions' } )
		);
		expect( wire.sent( 'add_link' ) ).toEqual( [] );
	} );
} );

describe( 'ItemRow ranking', () => {
	const SCORED = {
		...ITEM,
		importance: 8,
		relevance: 7,
		rank_reason: 'A rival press poached the paper’s whole sports desk.',
	};

	const why = () => screen.getByRole( 'button', { name: 'Why this ranks' } );

	const said = () => document.querySelector( '.newspack-intelligence__why' );

	it( 'keeps why it ranks behind a closed disclosure until asked', () => {
		render( <ItemRow item={ SCORED } onOpenEntity={ jest.fn() } /> );
		expect( why() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( why() ).toHaveClass( 'is-link', 'newspack-intelligence__more' );
		expect( screen.queryByText( SCORED.rank_reason ) ).toBeNull();
	} );

	it( 'reads the scores in words beside the reason, never as numbers', () => {
		render( <ItemRow item={ SCORED } onOpenEntity={ jest.fn() } /> );
		fireEvent.click( why() );
		expect( why() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( why() ).toHaveAttribute( 'aria-controls', said().id );
		expect( said() ).toHaveTextContent(
			'High importance, high relevance. A rival press poached the paper’s whole sports desk.'
		);
		expect( said().textContent ).not.toMatch( /\d/ );
	} );

	it.each( [
		[ 7, 6, 'High importance, medium relevance' ],
		[ 4, 3, 'Medium importance, low relevance' ],
		[ 0, 10, 'Low importance, high relevance' ],
	] )(
		'reads importance %d and relevance %d as "%s"',
		( importance, relevance, words ) => {
			render(
				<ItemRow
					item={ { ...SCORED, importance, relevance } }
					onOpenEntity={ jest.fn() }
				/>
			);
			fireEvent.click( why() );
			expect( said() ).toHaveTextContent( `${ words }.` );
		}
	);

	it( 'says no reason was recorded for an extraction that gave none', () => {
		render(
			<ItemRow
				item={ { ...SCORED, rank_reason: '' } }
				onOpenEntity={ jest.fn() }
			/>
		);
		fireEvent.click( why() );
		expect( said() ).toHaveTextContent(
			'High importance, high relevance. No reason recorded.'
		);
	} );

	it( 'offers no disclosure for an item never scored', () => {
		render(
			<ItemRow
				item={ {
					...SCORED,
					importance: null,
					relevance: null,
					rank_reason: null,
				} }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect(
			screen.queryByRole( 'button', { name: 'Why this ranks' } )
		).toBeNull();
	} );
} );

describe( 'ItemRow copies', () => {
	const meta = () =>
		document.querySelector( '.newspack-intelligence__meta' ).textContent;

	it( 'says how many other copies a story stands for in its meta line', () => {
		render(
			<ItemRow
				item={ { ...ITEM, copies: 3 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( meta() ).toContain( 'and 3 other copies' );
	} );

	it( 'says one other copy in the singular', () => {
		render(
			<ItemRow
				item={ { ...ITEM, copies: 1 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( meta() ).toContain( 'and 1 other copy' );
		expect( meta() ).not.toContain( 'copies' );
	} );

	it( 'says nothing of copies for an item with none', () => {
		render(
			<ItemRow
				item={ { ...ITEM, copies: 0 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( meta() ).not.toMatch( /cop(y|ies)/ );
	} );
} );

describe( 'ItemRow other copies', () => {
	let wire;

	const COPIES = {
		item_key: ITEM.item_key,
		copies: [
			{
				item_key: 'feed:harbor-copy-77',
				url: 'https://harbor-ledger.example/copy-77',
				title: 'Harbor Ledger renews, a wire copy',
				source: 'feed',
				day: '2025-10-14',
			},
			{
				item_key: 'linear:harbor-copy-78',
				url: 'https://linear.example/HL-78',
				title: 'Harbor Ledger renewal ticket',
				source: 'linear',
				day: '2025-10-12',
			},
		],
		available: true,
	};

	beforeEach( () => {
		Core.reset();
		window.NewspackNodesData = pageData();
		wire = intelWire();
		wire.replies.copies = COPIES;
	} );

	const toggle = () =>
		screen.getByRole( 'button', { name: 'and 2 other copies' } );

	it( 'reads nothing until the copies are opened', async () => {
		render(
			<ItemRow
				item={ { ...ITEM, copies: 2 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'false' );
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 50 ) )
		);
		expect( wire.sent( 'copies' ) ).toEqual( [] );
	} );

	it( 'opens the copies from the meta line, each with its source and day', async () => {
		render(
			<ItemRow
				item={ { ...ITEM, copies: 2 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		fireEvent.click( toggle() );
		expect( toggle() ).toHaveAttribute( 'aria-expanded', 'true' );
		const list = await screen.findByRole( 'list', {
			name: 'Other copies',
		} );
		const rows = within( list ).getAllByRole( 'listitem' );
		expect( rows ).toHaveLength( 2 );
		expect(
			within( rows[ 0 ] ).getByRole( 'link', {
				name: 'Harbor Ledger renews, a wire copy',
			} )
		).toHaveAttribute( 'href', 'https://harbor-ledger.example/copy-77' );
		expect( rows[ 0 ] ).toHaveTextContent( 'Web feed' );
		expect( rows[ 0 ] ).toHaveTextContent( '14 Oct 2025' );
		expect( rows[ 1 ] ).toHaveTextContent( 'Tracker' );
		expect( rows[ 1 ] ).toHaveTextContent( '12 Oct 2025' );
		expect( wire.sent( 'copies' ) ).toEqual( [ [ ITEM.item_key ] ] );

		fireEvent.click( toggle() );
		expect(
			screen.queryByRole( 'list', { name: 'Other copies' } )
		).toBeNull();
	} );

	it( 'says a refused read in place of the copies', async () => {
		wire.replies.copies = () => new Error( 'no item: harbor-copy' );
		render(
			<ItemRow
				item={ { ...ITEM, copies: 2 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		fireEvent.click( toggle() );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
	} );

	it( 'says when no copy reads now, as one excluded since', async () => {
		wire.replies.copies = { ...COPIES, copies: [] };
		render(
			<ItemRow
				item={ { ...ITEM, copies: 2 } }
				onOpenEntity={ jest.fn() }
			/>
		);
		fireEvent.click( toggle() );
		expect(
			await screen.findByText(
				'None of its other copies can be read now.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'ItemRow how this was read', () => {
	let wire;

	beforeEach( () => {
		Core.reset();
		wire = intelWire();
		wire.replies.trace = {
			item_key: ITEM.item_key,
			title: ITEM.title,
			url: ITEM.url,
			steps: [
				{
					step: 'recorded',
					at: 1790035500,
					actor: 'machine:source:feed@9.4.1',
					source: 'feed',
					tier: 'public',
				},
			],
			available: true,
		};
	} );

	const openHowRead = async () => {
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', {
				name: 'How this was read',
			} )
		);
		return screen.findByRole( 'region', { name: 'How this was read' } );
	};

	it( 'opens the drawer from More actions, asking how this item was read', async () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const drawer = await openHowRead();
		expect(
			await within( drawer ).findByText(
				'Recorded from Web feed; marked Anyone'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'trace' ) ).toEqual( [ [ ITEM.item_key ] ] );
	} );

	it( 'moves focus to the drawer’s heading', async () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const drawer = await openHowRead();
		await waitFor( () =>
			expect(
				within( drawer ).getByRole( 'heading', {
					name: 'How this was read',
				} )
			).toHaveFocus()
		);
	} );

	it( 'closes on Close, returning focus to More actions', async () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const drawer = await openHowRead();
		fireEvent.click(
			within( drawer ).getByRole( 'button', { name: 'Close' } )
		);
		expect(
			screen.queryByRole( 'region', { name: 'How this was read' } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'More actions' } )
		).toHaveFocus();
	} );

	it( 'closes on Escape, returning focus to More actions', async () => {
		render( <ItemRow item={ ITEM } onOpenEntity={ jest.fn() } /> );
		const drawer = await openHowRead();
		fireEvent.keyDown( drawer, { key: 'Escape' } );
		expect(
			screen.queryByRole( 'region', { name: 'How this was read' } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'More actions' } )
		).toHaveFocus();
	} );
} );
